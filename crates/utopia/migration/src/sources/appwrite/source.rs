//! An Appwrite project as the source.

use async_trait::async_trait;
use php_std::{Array, ArrayKey, Value};
use utopia_database::Database as Db;

use super::api_reader::ApiReader;
use super::reader::{Cursor, DatabaseReader, DatabaseResolver, Reader, ReaderQuery};
use super::{get_column, get_database, get_entity, get_record};
use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::Chunk;
use crate::resource::{
    DATABASE_TYPE_RESOURCE_MAP, ENTITY_TYPE_RESOURCE_MAP, Kind, ResourceRef, database_resources, is_supported, status,
    types,
};
use crate::resources::auth::{AuthMethods, Hash, Membership, OAuth2Provider, Policies, Team, User};
use crate::resources::database::{Field, Index};
use crate::resources::domains::Rule;
use crate::resources::functions::{Deployment, EnvVar, Func};
use crate::resources::integrations::{ApiKey, Platform};
use crate::resources::messaging::{Message, Provider, Subscriber, Topic};
use crate::resources::settings::{Labels, ProjectVariable, Protocols, Services, Smtp, Webhook};
use crate::resources::sites::{Site, SiteDeployment, SiteEnvVar};
use crate::resources::storage::{Bucket, File};
use crate::resources::templates::EmailTemplate;
use crate::resources::{cast_bool, cast_int, cast_string, php_empty};
use crate::sdk::{Client, Model, query};
use crate::source::{Emitter, ResourceIds, Sink, Source};
use crate::target::{Body, Target};
use crate::transfer::{Report, ResourceSelector, STORAGE_MAX_CHUNK_SIZE, group};

/// Read databases through the API.
pub const SOURCE_API: &str = "api";
/// Read databases straight from the project's database.
pub const SOURCE_DATABASE: &str = "database";
const DEFAULT_PAGE_LIMIT: i64 = 25;

fn v(s: &str) -> Value {
    Value::from(s)
}

/// `$value ?? $default`.
fn or(value: Value, default: Value) -> Value {
    if matches!(value, Value::Null) { default } else { value }
}

/// `$model->prop ?? ''` as a string.
fn s(m: &Model, prop: &str) -> String {
    m.string(prop)
}

fn arr(value: Value) -> Array {
    match value {
        Value::Array(a) => a,
        _ => Array::new(),
    }
}

fn strings(a: &Array) -> Vec<String> {
    a.values().map(cast_string).collect()
}

/// The error of a group export: the message and code of `e` (500 when it
/// has none).
fn group_error(name: &str, grp: &str, e: Error, internal: bool) -> Exception {
    let code = if internal { crate::error::Code::Int(e.code().or_internal()) } else { e.code() };
    Exception::new(name, grp, None, e.message(), code, Some(e))
}

/// An Appwrite project as a source.
///
/// PHP: `Utopia\Migration\Sources\Appwrite`.
pub struct Appwrite {
    target: Target,
    project_id: String,
    key: String,
    client: Client,
    reader: Box<dyn Reader>,
    source: String,
    queries: Vec<ReaderQuery>,
    resource_child_id: Option<String>,
}

impl std::fmt::Debug for Appwrite {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Appwrite")
            .field("project_id", &self.project_id)
            .field("source", &self.source)
            .finish_non_exhaustive()
    }
}

impl Appwrite {
    /// A source of project `project_id` at `endpoint` with API key `key`,
    /// reading databases through the API (`source` [`SOURCE_API`]) or from
    /// `db` ([`SOURCE_DATABASE`], rows from `resolver`'s databases); `queries`
    /// filter the rows exported.
    ///
    /// PHP: `new Appwrite($projectId, $endpoint, $key, $getDatabasesDB,
    /// $source, $dbForProject, $queries)`.
    pub fn new(
        project_id: &str,
        endpoint: &str,
        key: &str,
        resolver: Option<DatabaseResolver>,
        source: &str,
        db: Option<Db>,
        queries: Vec<ReaderQuery>,
    ) -> Result<Self> {
        let mut client = Client::new();
        client.set_endpoint(endpoint)?;
        client.set_project(project_id);
        client.set_key(key);
        client.set_connect_timeout(crate::target::CONNECT_TIMEOUT);
        client.set_timeout(crate::target::REQUEST_TIMEOUT);
        let mut target = Target::new();
        target.endpoint = endpoint.to_owned();
        target.headers.insert("x-appwrite-project".to_owned(), project_id.to_owned());
        target.headers.insert("x-appwrite-key".to_owned(), key.to_owned());
        let reader: Box<dyn Reader> = match source {
            SOURCE_API => Box::new(ApiReader::new(client.clone())),
            SOURCE_DATABASE => {
                let Some(db) = db else {
                    return Err(Error::exception(
                        "Database is required for database source",
                        Exception::CODE_VALIDATION,
                    ));
                };
                Box::new(DatabaseReader::new(db, resolver, Some(project_id.to_owned())))
            }
            _ => return Err(Error::exception("Unknown source", Exception::CODE_VALIDATION)),
        };
        Ok(Self {
            target,
            project_id: project_id.to_owned(),
            key: key.to_owned(),
            client,
            reader,
            source: source.to_owned(),
            queries,
            resource_child_id: None,
        })
    }

    /// Where databases are read from (`api` or `database`).
    ///
    /// PHP: `getSourceType()`.
    pub fn source_type(&self) -> &str {
        &self.source
    }

    /// The API key.
    pub fn key(&self) -> &str {
        &self.key
    }

    async fn api(&self, service: &str, method: &str, args: &[(&str, Value)]) -> Result<Model> {
        self.client.invoke(service, method, args).await
    }

    fn root(&self) -> (&str, &str) {
        (&self.target.root_resource_id, &self.target.root_resource_type)
    }

    /// `buildQueries()`: the ids of `resource_ids[type]`, then a cursor or a limit.
    fn build_queries(resource_type: &str, resource_ids: &ResourceIds, cursor: Option<&str>, limit: i64) -> Value {
        let mut q = Vec::new();
        if let Some(ids) = resource_ids.get(resource_type)
            && cast_bool(ids)
        {
            q.push(query::equal("$id", ids.clone()));
        }
        match cursor.filter(|c| !c.is_empty() && *c != "0") {
            Some(c) => q.push(query::cursor_after(c)),
            None => q.push(query::limit(limit)),
        }
        query::list(q)
    }

    /// The queries of a paged export: the limit, the root filter for
    /// `root_type`, and the cursor.
    fn page(&self, batch: i64, root_type: &str, last: Option<&str>) -> Value {
        let mut q = vec![query::limit(batch)];
        let (root_id, rtype) = self.root();
        if !root_id.is_empty() && rtype == root_type {
            q.push(query::equal("$id", v(root_id)));
            q.push(query::limit(1));
        }
        if let Some(last) = last.filter(|l| !l.is_empty() && *l != "0") {
            q.push(query::cursor_after(last));
        }
        query::list(q)
    }

    async fn call(
        &self,
        method: &str,
        path: &str,
        headers: &[(&str, String)],
    ) -> Result<(Body, indexmap::IndexMap<String, String>)> {
        self.target.call(method, path, headers, &Array::new()).await
    }
}

/// A report entry that reads 0 when the API fails with a 5xx.
fn tolerate_5xx(e: &Error) -> bool {
    let code = e.code().as_int();
    (500..600).contains(&code)
}

impl Appwrite {
    async fn report_auth(&self, resources: &[String], report: &mut Report, ids: &ResourceIds) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        let need_teams = has(types::TEAM) || has(types::MEMBERSHIP);
        let mut teams_total = Value::Int(0);
        let mut teams: Vec<Model> = Vec::new();
        if has(types::USER) {
            let list =
                self.api("Users", "list", &[("queries", Self::build_queries(types::USER, ids, None, 1))]).await?;
            report.insert(types::USER.to_owned(), list.value("total"));
        }
        if need_teams {
            if has(types::MEMBERSHIP) {
                let mut last: Option<String> = None;
                loop {
                    let params = Self::build_queries(types::TEAM, ids, last.as_deref(), DEFAULT_PAGE_LIMIT);
                    let list = self.api("Teams", "list", &[("queries", params)]).await?;
                    teams_total = list.value("total");
                    let current: Vec<Model> = list.models("teams").into_iter().cloned().collect();
                    last = current.last().map(|t| s(t, "id"));
                    let n = current.len() as i64;
                    teams.extend(current);
                    if n < DEFAULT_PAGE_LIMIT {
                        break;
                    }
                }
            } else {
                let list =
                    self.api("Teams", "list", &[("queries", Self::build_queries(types::TEAM, ids, None, 1))]).await?;
                teams_total = list.value("total");
            }
        }
        if has(types::TEAM) {
            report.insert(types::TEAM.to_owned(), teams_total);
        }
        if has(types::MEMBERSHIP) {
            let mut total = 0;
            for team in &teams {
                let m = self
                    .api(
                        "Teams",
                        "listMemberships",
                        &[("teamId", v(&s(team, "id"))), ("queries", query::list(vec![query::limit(1)]))],
                    )
                    .await?;
                total += cast_int(&m.value("total"));
            }
            report.insert(types::MEMBERSHIP.to_owned(), Value::Int(total));
        }
        if has(types::AUTH_METHODS) {
            report.insert(types::AUTH_METHODS.to_owned(), Value::Int(1));
        }
        if has(types::OAUTH2_PROVIDER) {
            let n = self.oauth2_provider_resources(None).await?.len() as i64;
            report.insert(types::OAUTH2_PROVIDER.to_owned(), Value::Int(n));
        }
        if has(types::POLICIES) {
            report.insert(types::POLICIES.to_owned(), Value::Int(1));
        }
        Ok(())
    }

    async fn report_storage(&self, resources: &[String], report: &mut Report, ids: &ResourceIds) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        if has(types::BUCKET) {
            let list = self
                .api("Storage", "listBuckets", &[("queries", Self::build_queries(types::BUCKET, ids, None, 1))])
                .await?;
            report.insert(types::BUCKET.to_owned(), list.value("total"));
        }
        if has(types::FILE) {
            let mut buckets: Vec<Model> = Vec::new();
            let mut last: Option<String> = None;
            loop {
                let q = Self::build_queries(types::BUCKET, ids, last.as_deref(), DEFAULT_PAGE_LIMIT);
                let list = self.api("Storage", "listBuckets", &[("queries", q)]).await?;
                let current: Vec<Model> = list.models("buckets").into_iter().cloned().collect();
                let n = current.len() as i64;
                buckets.extend(current);
                last = buckets.last().map(|b| s(b, "id"));
                if n < DEFAULT_PAGE_LIMIT {
                    break;
                }
            }
            let mut files = 0i64;
            let mut size = Value::Int(0);
            for bucket in &buckets {
                let list = self
                    .api(
                        "Storage",
                        "listFiles",
                        &[("bucketId", v(&s(bucket, "id"))), ("queries", query::list(vec![query::limit(1)]))],
                    )
                    .await?;
                size = add_numbers(&size, &or(bucket.value("totalSize"), Value::Int(0)));
                files += cast_int(&list.value("total"));
            }
            report.insert(types::FILE.to_owned(), Value::Int(files));
            report.insert("size".to_owned(), divide(&divide(&size, 1000), 1000));
        }
        Ok(())
    }

    async fn report_functions(&self, resources: &[String], report: &mut Report, ids: &ResourceIds) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        let need = has(types::DEPLOYMENT) || has(types::ENVIRONMENT_VARIABLE);
        if !need && has(types::FUNCTION) {
            let list = self
                .api("Functions", "list", &[("queries", Self::build_queries(types::FUNCTION, ids, None, 1))])
                .await?;
            report.insert(types::FUNCTION.to_owned(), list.value("total"));
            return Ok(());
        }
        let mut functions: Vec<Model> = Vec::new();
        let mut total = Value::Int(0);
        if need {
            let mut last: Option<String> = None;
            loop {
                let q = Self::build_queries(types::FUNCTION, ids, last.as_deref(), DEFAULT_PAGE_LIMIT);
                let list = self.api("Functions", "list", &[("queries", q)]).await?;
                total = list.value("total");
                let current: Vec<Model> = list.models("functions").into_iter().cloned().collect();
                let n = current.len() as i64;
                last = current.last().map(|f| s(f, "id"));
                functions.extend(current);
                if n < DEFAULT_PAGE_LIMIT {
                    break;
                }
            }
        }
        if has(types::FUNCTION) {
            report.insert(types::FUNCTION.to_owned(), total);
        }
        if has(types::DEPLOYMENT) {
            let n = functions.iter().filter(|f| !php_empty(&f.value("deploymentId"))).count() as i64;
            report.insert(types::DEPLOYMENT.to_owned(), Value::Int(n));
        }
        if has(types::ENVIRONMENT_VARIABLE) {
            // A function list's `vars` also holds a site's variables when the
            // two share an internal ID, so each function's are listed.
            let mut n = 0i64;
            for f in &functions {
                let list = self.api("Functions", "listVariables", &[("functionId", f.value("id"))]).await?;
                n += cast_int(&list.value("total"));
            }
            report.insert(types::ENVIRONMENT_VARIABLE.to_owned(), Value::Int(n));
        }
        Ok(())
    }

    async fn report_sites(&self, resources: &[String], report: &mut Report, ids: &ResourceIds) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        let need = has(types::SITE_DEPLOYMENT) || has(types::SITE_VARIABLE);
        if !need && has(types::SITE) {
            match self.api("Sites", "list", &[("queries", Self::build_queries(types::SITE, ids, None, 1))]).await {
                Ok(list) => report.insert(types::SITE.to_owned(), list.value("total")),
                Err(e) if tolerate_5xx(&e) => report.insert(types::SITE.to_owned(), Value::Int(0)),
                Err(e) => return Err(e),
            };
            return Ok(());
        }
        let mut sites: Vec<Model> = Vec::new();
        let mut total = Value::Int(0);
        if need {
            let result: Result<()> = async {
                let mut last: Option<String> = None;
                loop {
                    let q = Self::build_queries(types::SITE, ids, last.as_deref(), DEFAULT_PAGE_LIMIT);
                    let list = self.api("Sites", "list", &[("queries", q)]).await?;
                    total = list.value("total");
                    let current: Vec<Model> = list.models("sites").into_iter().cloned().collect();
                    let n = current.len() as i64;
                    let next = current.last().map(|s_| s(s_, "id"));
                    sites.extend(current);
                    if n == 0 || n < DEFAULT_PAGE_LIMIT {
                        break;
                    }
                    last = next;
                }
                Ok(())
            }
            .await;
            if let Err(e) = result {
                if tolerate_5xx(&e) {
                    sites.clear();
                    total = Value::Int(0);
                } else {
                    return Err(e);
                }
            }
        }
        if has(types::SITE) {
            report.insert(types::SITE.to_owned(), total);
        }
        if has(types::SITE_DEPLOYMENT) {
            let n = sites.iter().filter(|s_| !php_empty(&s_.value("deploymentId"))).count() as i64;
            report.insert(types::SITE_DEPLOYMENT.to_owned(), Value::Int(n));
        }
        if has(types::SITE_VARIABLE) {
            report.insert(types::SITE_VARIABLE.to_owned(), Value::Int(0));
            let mut total = 0;
            for site in &sites {
                match self.api("Sites", "listVariables", &[("siteId", v(&s(site, "id")))]).await {
                    Ok(list) => total += cast_int(&or(list.value("total"), Value::Int(0))),
                    Err(e) if tolerate_5xx(&e) => {
                        total = 0;
                        break;
                    }
                    Err(e) => return Err(e),
                }
            }
            report.insert(types::SITE_VARIABLE.to_owned(), Value::Int(total));
        }
        Ok(())
    }

    async fn report_messaging(&self, resources: &[String], report: &mut Report, ids: &ResourceIds) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        for (t, method) in [(types::PROVIDER, "listProviders"), (types::TOPIC, "listTopics")] {
            if has(t) {
                match self.api("Messaging", method, &[("queries", Self::build_queries(t, ids, None, 1))]).await {
                    Ok(list) => report.insert(t.to_owned(), list.value("total")),
                    Err(e) if tolerate_5xx(&e) => report.insert(t.to_owned(), Value::Int(0)),
                    Err(e) => return Err(e),
                };
            }
        }
        if has(types::SUBSCRIBER) {
            let mut total = 0i64;
            let result: Result<()> = async {
                let mut last: Option<String> = None;
                loop {
                    let mut q = vec![query::limit(DEFAULT_PAGE_LIMIT)];
                    if let Some(l) = last.as_deref().filter(|l| !l.is_empty() && *l != "0") {
                        q.push(query::cursor_after(l));
                    }
                    let topics = self.api("Messaging", "listTopics", &[("queries", query::list(q))]).await?;
                    let list = topics.models("topics");
                    if cast_int(&topics.value("total")) == 0 || list.is_empty() {
                        break;
                    }
                    for topic in &list {
                        let subscribers = self
                            .api(
                                "Messaging",
                                "listSubscribers",
                                &[("topicId", v(&s(topic, "id"))), ("queries", query::list(vec![query::limit(1)]))],
                            )
                            .await?;
                        total += cast_int(&subscribers.value("total"));
                        last = Some(s(topic, "id"));
                    }
                    if (list.len() as i64) < DEFAULT_PAGE_LIMIT {
                        break;
                    }
                }
                Ok(())
            }
            .await;
            if let Err(e) = result {
                if tolerate_5xx(&e) {
                    total = 0;
                } else {
                    return Err(e);
                }
            }
            report.insert(types::SUBSCRIBER.to_owned(), Value::Int(total));
        }
        if has(types::MESSAGE) {
            match self
                .api("Messaging", "listMessages", &[("queries", Self::build_queries(types::MESSAGE, ids, None, 1))])
                .await
            {
                Ok(list) => report.insert(types::MESSAGE.to_owned(), list.value("total")),
                Err(e) if tolerate_5xx(&e) => report.insert(types::MESSAGE.to_owned(), Value::Int(0)),
                Err(e) => return Err(e),
            };
        }
        Ok(())
    }

    async fn report_integrations(&self, resources: &[String], report: &mut Report, ids: &ResourceIds) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        for (t, service, method) in [
            (types::PLATFORM, "Project", "listPlatforms"),
            (types::API_KEY, "Project", "listKeys"),
            (types::WEBHOOK, "Webhooks", "list"),
        ] {
            if has(t) {
                let total = match self.api(service, method, &[("queries", Self::build_queries(t, ids, None, 1))]).await
                {
                    Ok(list) => list.value("total"),
                    Err(_) => Value::Int(0),
                };
                report.insert(t.to_owned(), total);
            }
        }
        if has(types::SMTP) {
            report.insert(types::SMTP.to_owned(), Value::Int(1));
        }
        Ok(())
    }

    async fn report_projects(&self, resources: &[String], report: &mut Report, ids: &ResourceIds) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        if has(types::PROJECT_VARIABLE) {
            let q = Self::build_queries(types::PROJECT_VARIABLE, ids, None, 1);
            match self.api("Project", "listVariables", &[("queries", q)]).await {
                Ok(list) => report.insert(types::PROJECT_VARIABLE.to_owned(), list.value("total")),
                Err(e) if tolerate_5xx(&e) => report.insert(types::PROJECT_VARIABLE.to_owned(), Value::Int(0)),
                Err(e) => return Err(e),
            };
        }
        for t in [types::PROJECT_PROTOCOLS, types::PROJECT_LABELS, types::PROJECT_SERVICES] {
            if has(t) {
                report.insert(t.to_owned(), Value::Int(1));
            }
        }
        if has(types::PROJECT_EMAIL_TEMPLATE) {
            let total = match self
                .api(
                    "Project",
                    "listEmailTemplates",
                    &[("queries", query::list(vec![query::limit(1)])), ("total", Value::Bool(true))],
                )
                .await
            {
                Ok(list) => list.value("total"),
                Err(_) => Value::Int(0),
            };
            report.insert(types::PROJECT_EMAIL_TEMPLATE.to_owned(), total);
        }
        Ok(())
    }

    async fn report_domains(&self, resources: &[String], report: &mut Report) -> Result<()> {
        if resources.iter().any(|r| r == types::RULE) {
            match self.api("Proxy", "listRules", &[("queries", query::list(vec![query::limit(1)]))]).await {
                Ok(list) => report.insert(types::RULE.to_owned(), list.value("total")),
                Err(e) if tolerate_5xx(&e) => report.insert(types::RULE.to_owned(), Value::Int(0)),
                Err(e) => return Err(e),
            };
        }
        Ok(())
    }

    async fn report_all(&mut self, resources: &[String], ids: &ResourceIds) -> Result<Report> {
        let mut report = Report::new();
        self.report_auth(resources, &mut report, ids).await?;
        self.reader.report(resources, &mut report, ids).await?;
        self.report_storage(resources, &mut report, ids).await?;
        self.report_functions(resources, &mut report, ids).await?;
        self.report_messaging(resources, &mut report, ids).await?;
        self.report_sites(resources, &mut report, ids).await?;
        self.report_integrations(resources, &mut report, ids).await?;
        if resources.iter().any(|r| r == types::BACKUP_POLICY) {
            report.insert(types::BACKUP_POLICY.to_owned(), Value::Int(0));
        }
        self.report_projects(resources, &mut report, ids).await?;
        self.report_domains(resources, &mut report).await?;
        let (body, _) = self
            .call("GET", "/health/version", &[("x-appwrite-key", String::new()), ("x-appwrite-project", String::new())])
            .await?;
        report.insert("version".to_owned(), body.array().get_str("version").cloned().unwrap_or(Value::Null));
        Ok(report)
    }
}

/// `$a + $b` of two numbers.
fn add_numbers(a: &Value, b: &Value) -> Value {
    match (a, b) {
        (Value::Int(x), Value::Int(y)) => x.checked_add(*y).map_or(Value::Float(*x as f64 + *y as f64), Value::Int),
        _ => Value::Float(a.to_double() + b.to_double()),
    }
}

/// `$a / $n`: an integer when it divides exactly.
fn divide(a: &Value, n: i64) -> Value {
    match a {
        Value::Int(x) if x % n == 0 => Value::Int(x / n),
        _ => Value::Float(a.to_double() / n as f64),
    }
}

impl Appwrite {
    async fn export_auth(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        if has(types::USER)
            && let Err(e) = self.export_users(batch, out).await
        {
            self.target.add_error(group_error(types::USER, group::AUTH, e, true));
        }
        if has(types::TEAM)
            && let Err(e) = self.export_teams(batch, out).await
        {
            self.target.add_error(group_error(types::TEAM, group::AUTH, e, true));
        }
        if has(types::MEMBERSHIP)
            && let Err(e) = self.export_memberships(batch, out).await
        {
            self.target.add_error(group_error(types::MEMBERSHIP, group::AUTH, e, true));
        }
        if has(types::AUTH_METHODS)
            && let Err(e) = self.export_auth_methods(out).await
        {
            self.target.add_error(group_error(types::AUTH_METHODS, group::AUTH, e, true));
        }
        if has(types::OAUTH2_PROVIDER) {
            let result = self.export_oauth2_providers(out).await;
            if let Err(e) = result {
                self.target.add_error(group_error(types::OAUTH2_PROVIDER, group::AUTH, e, true));
            }
        }
        if has(types::POLICIES)
            && let Err(e) = self.export_policies(out).await
        {
            self.target.add_error(group_error(types::POLICIES, group::AUTH, e, true));
        }
        Ok(())
    }

    async fn export_policies(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let this = &*self;
        let policy = |id: &'static str| async move { this.api("Project", "getPolicy", &[("policyId", v(id))]).await };
        let history = policy("password-history").await?;
        let dictionary = policy("password-dictionary").await?;
        let personal = policy("password-personal-data").await?;
        let alert = policy("session-alert").await?;
        let duration = policy("session-duration").await?;
        let invalidation = policy("session-invalidation").await?;
        let limit = policy("session-limit").await?;
        let users = policy("user-limit").await?;
        let privacy = policy("membership-privacy").await?;
        // `getPolicyIfSupported()`: a source older than a policy rejects its
        // ID (400 or 404), so the policy is left to the destination.
        let supported = |id: &'static str| async move {
            match policy(id).await {
                Ok(model) => Ok(Some(model)),
                Err(Error::Appwrite { code: 400 | 404, .. }) => Ok(None),
                Err(e) => Err(e),
            }
        };
        let strength = supported("password-strength").await?;
        let factors = supported("mfa-factors").await?;
        let aliased = supported("deny-aliased-email").await?;
        let disposable = supported("deny-disposable-email").await?;
        let free = supported("deny-free-email").await?;
        let corporate = supported("deny-corporate-email").await?;
        let fields = |model: &Option<Model>, keys: &[&str]| {
            model.as_ref().map(|m| {
                let mut a = Array::new();
                for key in keys {
                    a.set(ArrayKey::from(*key), m.value(key));
                }
                a
            })
        };
        let enabled = |model: &Option<Model>| model.as_ref().map(|m| cast_bool(&m.value("enabled")));
        let policies = Policies {
            password_history: cast_int(&history.value("total")),
            session_duration: cast_int(&duration.value("duration")),
            sessions_limit: cast_int(&limit.value("total")),
            user_limit: cast_int(&users.value("total")),
            password_dictionary: cast_bool(&dictionary.value("enabled")),
            personal_data_check: cast_bool(&personal.value("enabled")),
            session_alerts: cast_bool(&alert.value("enabled")),
            session_invalidation: cast_bool(&invalidation.value("enabled")),
            memberships_user_id: cast_bool(&privacy.value("userId")),
            memberships_user_email: cast_bool(&privacy.value("userEmail")),
            memberships_user_name: cast_bool(&privacy.value("userName")),
            memberships_user_mfa: cast_bool(&privacy.value("userMFA")),
            memberships_user_phone: cast_bool(&privacy.value("userPhone")),
            memberships_user_accessed_at: match privacy.value("userAccessedAt") {
                Value::Null => None,
                v => Some(cast_bool(&v)),
            },
            password_strength: fields(&strength, &["min", "uppercase", "lowercase", "number", "symbols"]),
            mfa_factors: fields(&factors, &["totp", "email", "phone", "custom"]),
            deny_aliased_email: enabled(&aliased),
            deny_disposable_email: enabled(&disposable),
            deny_free_email: enabled(&free),
            deny_corporate_email: enabled(&corporate),
        };
        let r = policies.resource(self.project_id.clone(), "", "");
        out.emit(vec![r]).await
    }

    async fn export_auth_methods(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let project = self.api("Project", "get", &[]).await?;
        let mut by_id: indexmap::IndexMap<String, Value> = indexmap::IndexMap::new();
        for method in project.models("authMethods") {
            by_id.insert(s(method, "id"), method.value("enabled"));
        }
        let get = |k: &str| cast_bool(&or(by_id.get(k).cloned().unwrap_or(Value::Null), Value::Bool(true)));
        let methods = AuthMethods {
            email_password: get("email-password"),
            magic_url: get("magic-url"),
            email_otp: get("email-otp"),
            anonymous: get("anonymous"),
            invites: get("invites"),
            jwt: get("jwt"),
            phone: get("phone"),
        };
        let r = methods.resource(self.project_id.clone(), s(&project, "createdAt"), s(&project, "updatedAt"));
        out.emit(vec![r]).await
    }

    async fn oauth2_provider_resources(&self, mut errors: Option<&mut Vec<Exception>>) -> Result<Vec<ResourceRef>> {
        let list = self.api("Project", "listOAuth2Providers", &[]).await?;
        let mut out = Vec::new();
        for provider in arr(list.value("providers")).values() {
            let Value::Array(p) = provider else { continue };
            let key = match p.get_str("$id") {
                None | Some(Value::Null) => String::new(),
                Some(k) => cast_string(k),
            };
            if key.is_empty() {
                continue;
            }
            let mut payload = p.clone();
            payload.set("id".into(), v(&format!("{}-{key}", self.project_id)));
            match OAuth2Provider::from_array(&key, &payload)? {
                None => {
                    if let Some(errors) = errors.as_deref_mut() {
                        errors.push(Exception::new(
                            types::OAUTH2_PROVIDER,
                            group::AUTH,
                            None,
                            format!("No migration resource for OAuth2 provider '{key}'; skipped."),
                            Exception::CODE_INTERNAL,
                            None,
                        ));
                    }
                }
                Some(r) => {
                    let configured = match r.kind() {
                        Kind::OAuth2Provider(p) => p.is_configured(),
                        _ => false,
                    };
                    if configured {
                        out.push(r);
                    }
                }
            }
        }
        Ok(out)
    }

    async fn export_oauth2_providers(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let mut errors = Vec::new();
        let result = self.oauth2_provider_resources(Some(&mut errors)).await;
        for e in errors {
            self.target.add_error(e);
        }
        let resources = result?;
        if !resources.is_empty() {
            out.emit(resources).await?;
        }
        Ok(())
    }

    async fn export_users(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response =
                self.api("Users", "list", &[("queries", self.page(batch, types::USER, last.as_deref()))]).await?;
            if cast_int(&response.value("total")) == 0 {
                break;
            }
            let mut users = Vec::new();
            for user in response.models("users") {
                let opt = |prop: &str| {
                    let value = user.value(prop);
                    if php_empty(&value) { None } else { Some(cast_string(&value)) }
                };
                let password = user.value("password");
                let hash = if cast_bool(&password) {
                    let mut h = Hash::new(cast_string(&password));
                    h.algorithm = cast_string(&user.value("hash"));
                    Some(h.resource())
                } else {
                    None
                };
                let prefs = user.model("prefs").map_or(Value::Null, |p| p.value("data"));
                let targets: Array = match user.get("targets") {
                    Some(crate::sdk::MValue::List(items)) => {
                        let mut a = Array::new();
                        for (k, t) in items {
                            a.set(k.clone(), t.serialize());
                        }
                        a
                    }
                    _ => Array::new(),
                };
                let u = User {
                    email: opt("email"),
                    username: opt("name"),
                    password_hash: hash,
                    phone: opt("phone"),
                    labels: arr(or(user.value("labels"), Value::Array(Array::new()))),
                    oauth_provider: String::new(),
                    email_verified: cast_bool(&or(user.value("emailVerification"), Value::Bool(false))),
                    phone_verified: cast_bool(&or(user.value("phoneVerification"), Value::Bool(false))),
                    disabled: !cast_bool(&user.value("status")),
                    preferences: arr(or(prefs, Value::Array(Array::new()))),
                    targets,
                };
                users.push(u.resource(s(user, "id")));
                last = Some(s(user, "id"));
            }
            let n = users.len() as i64;
            out.emit(users).await?;
            if n < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_teams(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response =
                self.api("Teams", "list", &[("queries", self.page(batch, types::TEAM, last.as_deref()))]).await?;
            if cast_int(&response.value("total")) == 0 {
                break;
            }
            let mut teams = Vec::new();
            for team in response.models("teams") {
                let prefs = team.model("prefs").map_or(Value::Null, |p| p.value("data"));
                let t = Team { name: s(team, "name"), preferences: arr(prefs), members: Array::new() };
                teams.push(t.resource(s(team, "id")));
                last = Some(s(team, "id"));
            }
            let n = teams.len() as i64;
            out.emit(teams).await?;
            if n < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_memberships(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let cache = self.target.cache()?;
        let teams = cache.resources(types::TEAM);
        let mut users: indexmap::IndexMap<String, ResourceRef> = indexmap::IndexMap::new();
        for u in cache.resources(types::USER) {
            users.insert(u.id(), u);
        }
        for team in teams {
            let mut last: Option<String> = None;
            loop {
                let mut q = vec![query::limit(batch)];
                if let Some(l) = last.as_deref() {
                    q.push(query::cursor_after(l));
                }
                let response = self
                    .api("Teams", "listMemberships", &[("teamId", v(&team.id())), ("queries", query::list(q))])
                    .await?;
                if cast_int(&response.value("total")) == 0 {
                    break;
                }
                let mut memberships = Vec::new();
                for m in response.models("memberships") {
                    let Some(user) = users.get(&s(m, "userId")).cloned() else {
                        return Err(Error::exception("User not found", Exception::CODE_NOT_FOUND));
                    };
                    let membership = Membership {
                        team: team.clone(),
                        user,
                        roles: arr(m.value("roles")),
                        active: cast_bool(&m.value("confirm")),
                    };
                    memberships.push(membership.resource(s(m, "id")));
                    last = Some(s(m, "id"));
                }
                let n = memberships.len() as i64;
                out.emit(memberships).await?;
                if n < batch {
                    break;
                }
            }
        }
        Ok(())
    }
}

impl Appwrite {
    async fn export_databases_group(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let database_types: Vec<&str> = DATABASE_TYPE_RESOURCE_MAP.iter().map(|d| d.database).collect();
        if is_supported(&database_types, resources)
            && let Err(e) = self.export_databases(batch, resources, out).await
        {
            self.target.add_error(group_error(types::DATABASE, group::DATABASES, e, true));
            return Ok(());
        }
        for d in DATABASE_TYPE_RESOURCE_MAP {
            if resources.iter().any(|r| r == d.entity)
                && let Err(e) = self.export_entities(d.database, batch, out).await
            {
                self.target.add_error(group_error(d.entity, group::DATABASES, e, true));
                return Ok(());
            }
        }
        for (field, kind) in [("field", 0), ("index", 1), ("record", 2)] {
            for e in ENTITY_TYPE_RESOURCE_MAP {
                let name = match field {
                    "field" => e.field,
                    "index" => e.index,
                    _ => e.record,
                };
                if !resources.iter().any(|r| r == name) {
                    continue;
                }
                let result = match kind {
                    0 => self.export_fields(e.entity, batch, out).await,
                    1 => self.export_indexes(e.entity, batch, out).await,
                    _ => self.export_records(e.entity, e.field, batch, out).await,
                };
                if let Err(err) = result {
                    self.target.add_error(group_error(name, group::DATABASES, err, true));
                    return Ok(());
                }
            }
        }
        Ok(())
    }

    async fn export_databases(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<ResourceRef> = None;
        loop {
            let mut queries = vec![self.reader.query_limit(batch)];
            let (root_id, root_type) = self.root();
            if !root_id.is_empty() && database_resources(root_type).is_some() {
                queries.push(self.reader.query_equal("$id", vec![v(root_id)]));
                queries.push(self.reader.query_limit(1));
            }
            if let Some(l) = last.clone() {
                queries.push(self.reader.query_cursor_after(Cursor::Resource(l)).await?);
            }
            let response = self.reader.list_databases(queries).await?;
            let mut databases = Vec::new();
            for database in &response {
                let get = |k: &str| database.get_str(k).cloned().unwrap_or(Value::Null);
                let original = or(get("type"), v(types::DATABASE_LEGACY));
                let original_s = cast_string(&original);
                let family = if original_s == types::DATABASE_LEGACY || original_s == types::DATABASE_TABLESDB {
                    types::DATABASE.to_owned()
                } else {
                    original_s.clone()
                };
                if is_supported(&[family.as_str()], resources) {
                    let mut a = Array::new();
                    a.set("id".into(), get("$id"));
                    a.set("name".into(), get("name"));
                    a.set("createdAt".into(), get("$createdAt"));
                    a.set("updatedAt".into(), get("$updatedAt"));
                    a.set("type".into(), original.clone());
                    a.set("database".into(), get("database"));
                    a.set("enabled".into(), or(get("enabled"), Value::Bool(true)));
                    databases.push(get_database(&family, &a)?);
                }
            }
            if databases.is_empty() {
                break;
            }
            last = databases.last().cloned();
            let n = databases.len() as i64;
            out.emit(databases).await?;
            if n < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_entities(&mut self, database_name: &str, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let databases = self.target.cache()?.resources(database_name);
        for database in databases {
            let mut last: Option<ResourceRef> = None;
            loop {
                let mut queries = vec![self.reader.query_limit(batch)];
                let (root_id, root_type) =
                    (self.target.root_resource_id.clone(), self.target.root_resource_type.clone());
                let child = self.resource_child_id.clone().unwrap_or_default();
                if !root_id.is_empty() && !child.is_empty() && root_type == database_name {
                    queries.push(self.reader.query_equal("$id", vec![v(&child)]));
                    queries.push(self.reader.query_limit(1));
                } else if !root_id.is_empty() && root_type == types::TABLE {
                    queries.push(self.reader.query_equal("$id", vec![v(&root_id)]));
                    queries.push(self.reader.query_limit(1));
                } else if let Some(l) = last.clone() {
                    queries.push(self.reader.query_cursor_after(Cursor::Resource(l)).await?);
                }
                let response = self.reader.list_tables(&database, queries).await?;
                let Some(d) = database.as_database() else { break };
                let mut tables = Vec::new();
                for table in &response {
                    let get = |k: &str| table.get_str(k).cloned().unwrap_or(Value::Null);
                    let row_security = or(or(get("rowSecurity"), get("documentSecurity")), Value::Bool(false));
                    let mut db = Array::new();
                    db.set("id".into(), v(&database.id()));
                    db.set("name".into(), v(&d.name));
                    db.set("type".into(), v(&d.database_type));
                    db.set("database".into(), d.database.as_deref().map_or(Value::Null, v));
                    let mut a = Array::new();
                    a.set("id".into(), get("$id"));
                    a.set("name".into(), get("name"));
                    a.set("rowSecurity".into(), row_security.clone());
                    a.set("documentSecurity".into(), row_security);
                    a.set("permissions".into(), get("$permissions"));
                    a.set("createdAt".into(), get("$createdAt"));
                    a.set("updatedAt".into(), get("$updatedAt"));
                    a.set("enabled".into(), or(get("enabled"), Value::Bool(true)));
                    a.set("dimension".into(), get("dimension"));
                    a.set("database".into(), Value::Array(db));
                    tables.push(get_entity(database_name, &a)?);
                }
                if tables.is_empty() {
                    break;
                }
                let n = tables.len() as i64;
                last = tables.last().cloned();
                out.emit(tables).await?;
                if n < batch {
                    break;
                }
            }
        }
        Ok(())
    }

    async fn export_fields(&mut self, entity_type: &str, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let entities = self.target.cache()?.resources(entity_type);
        for table in entities {
            let mut last: Option<ResourceRef> = None;
            loop {
                let mut queries = vec![self.reader.query_limit(batch)];
                if let Some(l) = last.clone() {
                    queries.push(self.reader.query_cursor_after(Cursor::Resource(l)).await?);
                }
                let response = self.reader.list_columns(&table, queries).await?;
                let vectors = table
                    .database()
                    .and_then(|d| d.as_database())
                    .is_some_and(|d| d.database_type == types::DATABASE_VECTORSDB);
                let mut columns = Vec::new();
                for column in &response {
                    let get = |k: &str| column.get_str(k).cloned().unwrap_or(Value::Null);
                    if get("type").strict_eq(&v(Db::VAR_RELATIONSHIP))
                        && get("side").strict_eq(&v(Db::RELATION_SIDE_CHILD))
                    {
                        continue;
                    }
                    let c = get_column(&table, column)?;
                    columns.push(if vectors { Field::attribute_of(&c).expect("a column") } else { c });
                }
                if columns.is_empty() {
                    break;
                }
                let n = columns.len() as i64;
                last = columns.last().cloned();
                out.emit(columns).await?;
                if n < batch {
                    break;
                }
            }
        }
        Ok(())
    }

    async fn export_indexes(&mut self, entity_type: &str, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let entities = self.target.cache()?.resources(entity_type);
        for table in entities {
            let mut last: Option<ResourceRef> = None;
            loop {
                let mut queries = vec![self.reader.query_limit(batch)];
                if let Some(l) = last.clone() {
                    queries.push(self.reader.query_cursor_after(Cursor::Resource(l)).await?);
                }
                let response = self.reader.list_indexes(&table, queries).await?;
                let mut indexes = Vec::new();
                for index in &response {
                    let get = |k: &str| index.get_str(k).cloned().unwrap_or(Value::Null);
                    let date = |k: &str| {
                        let value = get(k);
                        if php_empty(&value) { utopia_database::datetime::now() } else { cast_string(&value) }
                    };
                    let created = date("$createdAt");
                    let updated = date("$updatedAt");
                    let i = Index {
                        key: cast_string(&get("key")),
                        table: table.clone(),
                        index_type: cast_string(&get("type")),
                        columns: arr(or(get("columns"), get("attributes"))),
                        lengths: arr(or(get("lengths"), Value::Array(Array::new()))),
                        orders: arr(or(get("orders"), Value::Array(Array::new()))),
                    };
                    indexes.push(i.resource(cast_string(&get("$id")), created, updated));
                }
                if indexes.is_empty() {
                    break;
                }
                let n = indexes.len() as i64;
                last = indexes.last().cloned();
                out.emit(indexes).await?;
                if n < batch {
                    break;
                }
            }
        }
        Ok(())
    }

    async fn export_records(
        &mut self,
        entity_name: &str,
        field_name: &str,
        batch: i64,
        out: &mut Emitter<'_>,
    ) -> Result<()> {
        let cache = self.target.cache()?;
        let entities = cache.resources(entity_name);
        for table in entities {
            let mut last: Option<ResourceRef> = None;
            loop {
                let mut queries = vec![self.reader.query_limit(batch)];
                queries.extend(self.queries.iter().cloned());
                if let Some(l) = last.clone() {
                    queries.push(self.reader.query_cursor_after(Cursor::Resource(l)).await?);
                }
                let selects: Vec<String> =
                    ["*", "$id", "$permissions", "$updatedAt", "$createdAt"].iter().map(|s| (*s).to_owned()).collect();
                let mut many_to_many = Vec::new();
                if self.reader.support_for_attributes() {
                    for attribute in cache.resources(field_name) {
                        if let Some(f) = attribute.as_field()
                            && f.table.id() == table.id()
                            && f.field_type() == crate::resources::database::column::RELATIONSHIP
                            && f.side().strict_eq(&v("parent"))
                            && f.relation_type().loose_eq(&v("manyToMany"))
                        {
                            many_to_many.push(f.key.clone());
                        }
                    }
                }
                queries.push(self.reader.query_select(selects));
                let response = self.reader.list_rows(&table, queries).await?;
                let Some(t) = table.as_table() else { break };
                let d = table.database().and_then(|d| d.as_database().cloned());
                let mut rows = Vec::new();
                for row in &response {
                    let mut row = row.clone();
                    if !many_to_many.is_empty() {
                        let mut stack = vec!["$id".to_owned()];
                        for relation in &many_to_many {
                            stack.push(format!("{relation}.$id"));
                        }
                        let id = cast_string(row.get_str("$id").unwrap_or(&Value::Null));
                        let item = self.reader.get_row(&table, &id, vec![self.reader.query_select(stack)]).await?;
                        for key in &many_to_many {
                            let mut ids = Array::new();
                            if let Some(Value::Array(related)) = item.get_str(key) {
                                for r in related.values() {
                                    if let Value::Array(r) = r
                                        && let Some(rid) = r.get_str("$id").filter(|x| !matches!(x, Value::Null))
                                    {
                                        let _ = ids.push(rid.clone());
                                    }
                                }
                            }
                            row.set(ArrayKey::from(key.as_str()), Value::Array(ids));
                        }
                    }
                    let id = row.get_str("$id").cloned().unwrap_or(Value::Null);
                    let permissions = row.get_str("$permissions").cloned().unwrap_or(Value::Null);
                    for k in ["$id", "$permissions", "$databaseId", "$sequence", "$collection", "$tableId", "$table"] {
                        row.remove(&k.into());
                    }
                    let mut db = Array::new();
                    db.set("id".into(), v(&table.database().map(|d| d.id()).unwrap_or_default()));
                    db.set("name".into(), d.as_ref().map_or(Value::Null, |d| v(&d.name)));
                    db.set("type".into(), d.as_ref().map_or(Value::Null, |d| v(&d.database_type)));
                    db.set("database".into(), d.as_ref().and_then(|d| d.database.as_deref()).map_or(Value::Null, v));
                    let mut tbl = Array::new();
                    tbl.set("id".into(), v(&table.id()));
                    tbl.set("name".into(), v(&t.name));
                    tbl.set("rowSecurity".into(), Value::Bool(t.row_security));
                    tbl.set(
                        "permissions".into(),
                        Value::Array(Array::from_list(table.permissions().iter().map(|p| v(p)).collect())),
                    );
                    tbl.set("database".into(), Value::Array(db));
                    let mut record = Array::new();
                    record.set("id".into(), id);
                    record.set("table".into(), Value::Array(tbl));
                    record.set("data".into(), Value::Array(row));
                    record.set("permissions".into(), permissions);
                    let database_type = d.as_ref().map(|d| d.database_type.clone()).unwrap_or_default();
                    let r = get_record(&database_type, &record)?;
                    last = Some(r.clone());
                    rows.push(r);
                }
                out.emit(rows).await?;
                if (response.len() as i64) < batch {
                    break;
                }
            }
        }
        Ok(())
    }
}

impl Appwrite {
    async fn export_storage(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        if resources.iter().any(|r| r == types::BUCKET)
            && let Err(e) = self.export_buckets(out).await
        {
            self.target.add_error(group_error(types::BUCKET, group::STORAGE, e, true));
        }
        if resources.iter().any(|r| r == types::FILE)
            && let Err(e) = self.export_files(batch, out).await
        {
            self.target.add_error(group_error(types::FILE, group::STORAGE, e, true));
        }
        Ok(())
    }

    async fn export_buckets(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let mut q = Vec::new();
        let (root_id, root_type) = self.root();
        if !root_id.is_empty() && root_type == types::BUCKET {
            q.push(query::equal("$id", v(root_id)));
            q.push(query::limit(1));
        }
        let list = self.api("Storage", "listBuckets", &[("queries", query::list(q))]).await?;
        let mut buckets = Vec::new();
        for b in list.models("buckets") {
            let bucket = Bucket {
                name: s(b, "name"),
                file_security: cast_bool(&b.value("fileSecurity")),
                enabled: cast_bool(&b.value("enabled")),
                max_file_size: match b.value("maximumFileSize") {
                    Value::Null => None,
                    x => Some(cast_int(&x)),
                },
                allowed_file_extensions: arr(b.value("allowedFileExtensions")),
                compression: s(b, "compression"),
                encryption: cast_bool(&b.value("encryption")),
                anti_virus: cast_bool(&b.value("antivirus")),
                update_limits: false,
                transformations: cast_bool(&or(b.value("transformations"), Value::Bool(false))),
            };
            buckets.push(bucket.resource(s(b, "id"), strings(&arr(b.value("permissions")))));
        }
        if buckets.is_empty() {
            return Ok(());
        }
        out.emit(buckets).await
    }

    async fn export_files(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let buckets = self.target.cache()?.resources(types::BUCKET);
        for bucket in buckets {
            let mut last: Option<String> = None;
            loop {
                let mut q = vec![query::limit(batch)];
                if let Some(l) = last.as_deref() {
                    q.push(query::cursor_after(l));
                }
                let response = self
                    .api("Storage", "listFiles", &[("bucketId", v(&bucket.id())), ("queries", query::list(q))])
                    .await?;
                let files = response.models("files");
                for f in &files {
                    let file = File::new(
                        bucket.clone(),
                        s(f, "name"),
                        s(f, "signature"),
                        s(f, "mimeType"),
                        cast_int(&f.value("sizeOriginal")),
                        Chunk::default(),
                    )
                    .with_folder(s(f, "folder"))
                    .resource(s(f, "id"), strings(&arr(f.value("permissions"))));
                    if let Err(e) = self.export_file_data(&file, out).await {
                        let code = e.code();
                        self.target.add_error(Exception::new(
                            types::FILE,
                            group::STORAGE,
                            Some(s(f, "id")),
                            e.message(),
                            code,
                            None,
                        ));
                    }
                    last = Some(s(f, "id"));
                }
                if (files.len() as i64) < batch {
                    break;
                }
            }
        }
        Ok(())
    }

    async fn export_file_data(&mut self, file: &ResourceRef, out: &mut Emitter<'_>) -> Result<()> {
        let Kind::File(f) = file.kind() else { return Ok(()) };
        let mut start = 0i64;
        let mut end = STORAGE_MAX_CHUNK_SIZE - 1;
        let size = f.size;
        if end >= size {
            end = size - 1;
        }
        while start < size {
            let path = format!("/storage/buckets/{}/files/{}/download", f.bucket.id(), file.id());
            let (body, _) = self.call("GET", &path, &[("range", format!("bytes={start}-{end}"))]).await?;
            {
                let mut c = f.chunk_mut();
                c.data = body_bytes(body)?;
                c.start = start;
                c.end = end;
            }
            out.emit(vec![file.clone()]).await?;
            start += STORAGE_MAX_CHUNK_SIZE;
            end += STORAGE_MAX_CHUNK_SIZE;
            if end >= size {
                end = size - 1;
            }
        }
        Ok(())
    }
}

/// The data of a downloaded chunk (`setData()` takes a string: a decoded
/// JSON body is a `TypeError`).
fn body_bytes(body: Body) -> Result<bytes::Bytes> {
    match body {
        Body::Raw(b) => Ok(b),
        Body::Json(Value::Str(s)) => Ok(bytes::Bytes::copy_from_slice(s.as_bytes())),
        Body::Json(v) => Err(Error::TypeError(format!(
            "Utopia\\Migration\\Resources\\Storage\\File::setData(): Argument #1 ($data) must be of type string, {} given",
            v.type_name()
        ))),
    }
}

impl Appwrite {
    async fn export_functions_group(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        if resources.iter().any(|r| r == types::FUNCTION)
            && let Err(e) = self.export_functions(batch, out).await
        {
            self.target.add_error(group_error(types::FUNCTION, group::FUNCTIONS, e, true));
        }
        let only_active = !resources.iter().any(|r| r == types::DEPLOYMENT);
        if let Err(e) = self.export_deployments(batch, only_active, out).await {
            self.target.add_error(group_error(types::DEPLOYMENT, group::FUNCTIONS, e, true));
        }
        Ok(())
    }

    async fn export_functions(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api("Functions", "list", &[("queries", self.page(batch, types::FUNCTION, last.as_deref()))])
                .await?;
            if cast_int(&response.value("total")) == 0 && matches!(response.value("total"), Value::Int(0)) {
                return Ok(());
            }
            let mut converted = Vec::new();
            let mut count = 0i64;
            for f in response.models("functions") {
                let func = Func {
                    name: s(f, "name"),
                    runtime: s(f, "runtime"),
                    execute: arr(f.value("execute")),
                    enabled: cast_bool(&f.value("enabled")),
                    events: arr(f.value("events")),
                    schedule: s(f, "schedule"),
                    timeout: cast_int(&f.value("timeout")),
                    active_deployment: cast_string(&or(f.value("deploymentId"), v(""))),
                    entrypoint: s(f, "entrypoint"),
                    commands: cast_string(&or(f.value("commands"), v(""))),
                    logging: cast_bool(&or(f.value("logging"), Value::Bool(true))),
                    scopes: arr(or(f.value("scopes"), Value::Array(Array::new()))),
                    specification: elvis(f.value("runtimeSpecification")),
                    build_specification: elvis(f.value("buildSpecification")),
                    interval: cast_int(&or(f.value("interval"), Value::Int(0))),
                    deployment_retention: cast_int(&f.value("deploymentRetention")),
                }
                .resource(s(f, "id"));
                converted.push(func.clone());
                count += 1;
                last = Some(func.id());
                let vars = self.api("Functions", "listVariables", &[("functionId", f.value("id"))]).await?;
                for var in vars.models("variables") {
                    let e = EnvVar {
                        func: func.clone(),
                        key: s(var, "key"),
                        value: s(var, "value"),
                        secret: cast_bool(&var.value("secret")),
                    };
                    converted.push(e.resource(s(var, "id")));
                }
            }
            out.emit(converted).await?;
            if count < batch {
                return Ok(());
            }
        }
    }

    async fn export_deployments(&mut self, batch: i64, only_active: bool, out: &mut Emitter<'_>) -> Result<()> {
        let functions = self.target.cache()?.resources(types::FUNCTION);
        for func in functions {
            let Kind::Function(f) = func.kind() else { continue };
            if only_active {
                let active = f.active_deployment.clone();
                if active.is_empty() || active == "0" {
                    continue;
                }
                let deployment = self
                    .api("Functions", "getDeployment", &[("functionId", v(&func.id())), ("deploymentId", v(&active))])
                    .await?;
                if let Err(e) = self.export_deployment_data(&func, &deployment, false, out).await {
                    func.set_status(status::ERROR, e.message());
                }
                continue;
            }
            let mut last: Option<String> = None;
            loop {
                let mut q = vec![query::limit(batch)];
                if let Some(l) = last.as_deref() {
                    q.push(query::cursor_after(l));
                }
                let response = self
                    .api("Functions", "listDeployments", &[("functionId", v(&func.id())), ("queries", query::list(q))])
                    .await?;
                let deployments = response.models("deployments");
                for d in &deployments {
                    if let Err(e) = self.export_deployment_data(&func, d, false, out).await {
                        func.set_status(status::ERROR, e.message());
                    }
                    last = Some(s(d, "id"));
                }
                if (deployments.len() as i64) < batch {
                    break;
                }
            }
        }
        Ok(())
    }

    /// Downloads a deployment of a function (`site: false`) or a site, in
    /// chunks when the API reports its length.
    ///
    /// PHP: `exportDeploymentData()` and `exportSiteDeploymentData()`.
    async fn export_deployment_data(
        &mut self,
        parent: &ResourceRef,
        deployment: &Model,
        site: bool,
        out: &mut Emitter<'_>,
    ) -> Result<()> {
        let base = if site { "sites" } else { "functions" };
        let id = s(deployment, "id");
        let path = format!("/{base}/{}/deployments/{id}/download", parent.id());
        let mut start = 0i64;
        let mut end = STORAGE_MAX_CHUNK_SIZE - 1;
        let (_, headers) = self.call("HEAD", &path, &[]).await?;
        let make = |size: i64, start: i64, end: i64, data: bytes::Bytes| -> ResourceRef {
            let chunk = Chunk { start, end, data };
            let r = if site {
                let active = match parent.kind() {
                    Kind::Site(s_) => s_.active_deployment == id,
                    _ => false,
                };
                SiteDeployment::new(parent.clone(), size, chunk, active).resource(id.clone())
            } else {
                Deployment::new(
                    parent.clone(),
                    size,
                    s(deployment, "entrypoint"),
                    chunk,
                    cast_bool(&deployment.value("activate")),
                )
                .resource(id.clone())
            };
            r.set_sequence(r.id());
            r
        };
        let Some(length) = headers.get("content-length").cloned() else {
            let (body, _) = self.call("GET", &path, &[]).await?;
            let data = body_bytes(body)?;
            let size = data.len() as i64;
            if end > size {
                end = size - 1;
            }
            let r = make(size, start, end, data);
            return out.emit(vec![r]).await;
        };
        let file_size = crate::resources::cast_int(&Value::from(length.as_str()));
        if end >= file_size {
            end = file_size - 1;
        }
        let r = make(file_size, start, end, bytes::Bytes::new());
        let chunk_path = format!("/{base}/{}/deployments/{}/download", parent.id(), r.sequence());
        while start < file_size {
            let (body, _) = self.call("GET", &chunk_path, &[("range", format!("bytes={start}-{end}"))]).await?;
            let data = body_bytes(body)?;
            match r.kind() {
                Kind::Deployment(d) => {
                    let mut c = d.chunk_mut();
                    c.data = data;
                    c.start = start;
                    c.end = end;
                }
                Kind::SiteDeployment(d) => {
                    let mut c = d.chunk_mut();
                    c.data = data;
                    c.start = start;
                    c.end = end;
                }
                _ => {}
            }
            out.emit(vec![r.clone()]).await?;
            start += STORAGE_MAX_CHUNK_SIZE;
            end += STORAGE_MAX_CHUNK_SIZE;
            if end >= file_size {
                end = file_size - 1;
            }
        }
        Ok(())
    }

    async fn export_sites_group(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        if resources.iter().any(|r| r == types::SITE)
            && let Err(e) = self.export_sites(batch, out).await
        {
            self.target.add_error(group_error(types::SITE, group::SITES, e, true));
        }
        let only_active = !resources.iter().any(|r| r == types::SITE_DEPLOYMENT);
        if let Err(e) = self.export_site_deployments(batch, only_active, out).await {
            self.target.add_error(group_error(types::SITE_DEPLOYMENT, group::SITES, e, true));
        }
        Ok(())
    }

    async fn export_sites(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response =
                self.api("Sites", "list", &[("queries", self.page(batch, types::SITE, last.as_deref()))]).await?;
            if matches!(response.value("total"), Value::Int(0)) {
                return Ok(());
            }
            let mut converted = Vec::new();
            let mut count = 0i64;
            for st in response.models("sites") {
                let site = Site {
                    name: s(st, "name"),
                    framework: s(st, "framework"),
                    build_runtime: s(st, "buildRuntime"),
                    enabled: cast_bool(&st.value("enabled")),
                    logging: cast_bool(&st.value("logging")),
                    timeout: cast_int(&st.value("timeout")),
                    install_command: cast_string(&or(st.value("installCommand"), v(""))),
                    build_command: cast_string(&or(st.value("buildCommand"), v(""))),
                    output_directory: cast_string(&or(st.value("outputDirectory"), v(""))),
                    adapter: cast_string(&or(st.value("adapter"), v("static"))),
                    fallback_file: cast_string(&or(st.value("fallbackFile"), v(""))),
                    specification: elvis(st.value("runtimeSpecification")),
                    active_deployment: cast_string(&or(st.value("deploymentId"), v(""))),
                    build_specification: elvis(st.value("buildSpecification")),
                    start_command: s(st, "startCommand"),
                    deployment_retention: cast_int(&st.value("deploymentRetention")),
                    scopes: arr(st.value("scopes")),
                }
                .resource(s(st, "id"));
                converted.push(site.clone());
                count += 1;
                last = Some(site.id());
                let variables = self.api("Sites", "listVariables", &[("siteId", v(&site.id()))]).await?;
                for var in variables.models("variables") {
                    let e = SiteEnvVar {
                        site: site.clone(),
                        key: s(var, "key"),
                        value: s(var, "value"),
                        secret: cast_bool(&var.value("secret")),
                    };
                    converted.push(e.resource(s(var, "id")));
                }
            }
            out.emit(converted).await?;
            if count < batch {
                return Ok(());
            }
        }
    }

    async fn export_site_deployments(&mut self, batch: i64, only_active: bool, out: &mut Emitter<'_>) -> Result<()> {
        let sites = self.target.cache()?.resources(types::SITE);
        for site in sites {
            let Kind::Site(st) = site.kind() else { continue };
            if only_active {
                let active = st.active_deployment.clone();
                if active.is_empty() || active == "0" {
                    continue;
                }
                let deployment = self
                    .api("Sites", "getDeployment", &[("siteId", v(&site.id())), ("deploymentId", v(&active))])
                    .await?;
                if let Err(e) = self.export_deployment_data(&site, &deployment, true, out).await {
                    site.set_status(status::ERROR, e.message());
                }
                continue;
            }
            let mut last: Option<String> = None;
            loop {
                let mut q = vec![query::limit(batch)];
                if let Some(l) = last.as_deref() {
                    q.push(query::cursor_after(l));
                }
                let response = self
                    .api("Sites", "listDeployments", &[("siteId", v(&site.id())), ("queries", query::list(q))])
                    .await?;
                let deployments = response.models("deployments");
                for d in &deployments {
                    if let Err(e) = self.export_deployment_data(&site, d, true, out).await {
                        site.set_status(status::ERROR, e.message());
                    }
                    last = Some(s(d, "id"));
                }
                if (deployments.len() as i64) < batch {
                    break;
                }
            }
        }
        Ok(())
    }
}

/// `$value ?: ''`.
fn elvis(value: Value) -> String {
    if php_empty(&value) { String::new() } else { cast_string(&value) }
}

impl Appwrite {
    async fn export_messaging(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        if has(types::PROVIDER)
            && let Err(e) = self.export_providers(batch, out).await
        {
            self.target.add_error(group_error(types::PROVIDER, group::MESSAGING, e, false));
        }
        if has(types::TOPIC)
            && let Err(e) = self.export_topics(batch, out).await
        {
            self.target.add_error(group_error(types::TOPIC, group::MESSAGING, e, false));
        }
        if has(types::SUBSCRIBER)
            && let Err(e) = self.export_subscribers(batch, out).await
        {
            self.target.add_error(group_error(types::SUBSCRIBER, group::MESSAGING, e, false));
        }
        if has(types::MESSAGE)
            && let Err(e) = self.export_messages(batch, out).await
        {
            self.target.add_error(group_error(types::MESSAGE, group::MESSAGING, e, false));
        }
        Ok(())
    }

    async fn export_providers(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api("Messaging", "listProviders", &[("queries", self.page(batch, types::PROVIDER, last.as_deref()))])
                .await?;
            if cast_int(&response.value("total")) == 0 {
                break;
            }
            let mut list = Vec::new();
            for p in response.models("providers") {
                let provider = Provider {
                    name: s(p, "name"),
                    provider: s(p, "provider"),
                    provider_type: s(p, "type"),
                    enabled: cast_bool(&p.value("enabled")),
                    credentials: arr(or(p.value("credentials"), Value::Array(Array::new()))),
                    options: arr(or(p.value("options"), Value::Array(Array::new()))),
                };
                list.push(provider.resource(s(p, "id"), s(p, "createdAt"), s(p, "updatedAt")));
                last = Some(s(p, "id"));
            }
            let n = list.len() as i64;
            out.emit(list).await?;
            if n < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_topics(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api("Messaging", "listTopics", &[("queries", self.page(batch, types::TOPIC, last.as_deref()))])
                .await?;
            if cast_int(&response.value("total")) == 0 {
                break;
            }
            let mut list = Vec::new();
            for t in response.models("topics") {
                let topic =
                    Topic { name: s(t, "name"), subscribe: arr(or(t.value("subscribe"), Value::Array(Array::new()))) };
                list.push(topic.resource(s(t, "id"), s(t, "createdAt"), s(t, "updatedAt")));
                last = Some(s(t, "id"));
            }
            let n = list.len() as i64;
            out.emit(list).await?;
            if n < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_subscribers(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let topics = self.target.cache()?.resources(types::TOPIC);
        for topic in topics {
            let mut last: Option<String> = None;
            loop {
                let mut q = vec![query::limit(batch)];
                if let Some(l) = last.as_deref() {
                    q.push(query::cursor_after(l));
                }
                let response = self
                    .api("Messaging", "listSubscribers", &[("topicId", v(&topic.id())), ("queries", query::list(q))])
                    .await?;
                if cast_int(&response.value("total")) == 0 {
                    break;
                }
                let mut list = Vec::new();
                for sub in response.models("subscribers") {
                    let subscriber = Subscriber {
                        topic_id: s(sub, "topicId"),
                        target_id: s(sub, "targetId"),
                        user_id: s(sub, "userId"),
                        user_name: s(sub, "userName"),
                        provider_type: s(sub, "providerType"),
                    };
                    list.push(subscriber.resource(s(sub, "id"), s(sub, "createdAt"), s(sub, "updatedAt")));
                    last = Some(s(sub, "id"));
                }
                let n = list.len() as i64;
                out.emit(list).await?;
                if n < batch {
                    break;
                }
            }
        }
        Ok(())
    }

    async fn export_messages(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api("Messaging", "listMessages", &[("queries", self.page(batch, types::MESSAGE, last.as_deref()))])
                .await?;
            if cast_int(&response.value("total")) == 0 {
                break;
            }
            let mut list = Vec::new();
            for m in response.models("messages") {
                let message = Message {
                    provider_type: s(m, "providerType"),
                    topics: arr(or(m.value("topics"), Value::Array(Array::new()))),
                    users: arr(or(m.value("users"), Value::Array(Array::new()))),
                    targets: arr(or(m.value("targets"), Value::Array(Array::new()))),
                    data: arr(or(m.value("data"), Value::Array(Array::new()))),
                    message_status: s(m, "status"),
                    scheduled_at: s(m, "scheduledAt"),
                    delivered_at: s(m, "deliveredAt"),
                    delivery_errors: arr(or(m.value("deliveryErrors"), Value::Array(Array::new()))),
                    delivered_total: cast_int(&or(m.value("deliveredTotal"), Value::Int(0))),
                };
                list.push(message.resource(s(m, "id"), s(m, "createdAt"), s(m, "updatedAt")));
                last = Some(s(m, "id"));
            }
            let n = list.len() as i64;
            out.emit(list).await?;
            if n < batch {
                break;
            }
        }
        Ok(())
    }
}

impl Appwrite {
    async fn export_integrations(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        if has(types::PLATFORM)
            && let Err(e) = self.export_platforms(batch, out).await
        {
            self.target.add_error(group_error(types::PLATFORM, group::INTEGRATIONS, e, false));
        }
        if has(types::API_KEY)
            && let Err(e) = self.export_api_keys(batch, out).await
        {
            self.target.add_error(group_error(types::API_KEY, group::INTEGRATIONS, e, false));
        }
        if has(types::WEBHOOK)
            && let Err(e) = self.export_webhooks(batch, out).await
        {
            self.target.add_error(group_error(types::WEBHOOK, group::INTEGRATIONS, e, false));
        }
        if has(types::SMTP)
            && let Err(e) = self.export_smtp(out).await
        {
            self.target.add_error(group_error(types::SMTP, group::INTEGRATIONS, e, true));
        }
        Ok(())
    }

    /// The queries of an integrations page: the limit, the root filter, and
    /// the cursor (after the root filter).
    fn integration_page(&self, batch: i64, root_type: &str, last: Option<&str>) -> Value {
        let mut q = vec![query::limit(batch)];
        let (root_id, rtype) = self.root();
        if !root_id.is_empty() && rtype == root_type {
            q.push(query::equal("$id", v(root_id)));
            q.push(query::limit(1));
        }
        if let Some(l) = last {
            q.push(query::cursor_after(l));
        }
        query::list(q)
    }

    async fn export_platforms(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api(
                    "Project",
                    "listPlatforms",
                    &[("queries", self.integration_page(batch, types::PLATFORM, last.as_deref()))],
                )
                .await?;
            if matches!(response.value("total"), Value::Int(0)) {
                break;
            }
            let raw = arr(response.value("platforms"));
            let mut list = Vec::new();
            for p in raw.values() {
                let p = arr(p.clone());
                let get = |k: &str| cast_string(&or(p.get_str(k).cloned().unwrap_or(Value::Null), v("")));
                let platform = Platform {
                    platform_type: get("type"),
                    name: get("name"),
                    key: get("key"),
                    store: get("store"),
                    hostname: get("hostname"),
                };
                list.push(platform.resource(get("$id"), get("$createdAt"), get("$updatedAt")));
                last = p.get_str("$id").filter(|x| !matches!(x, Value::Null)).map(cast_string);
            }
            out.emit(list).await?;
            if (raw.len() as i64) < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_api_keys(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api(
                    "Project",
                    "listKeys",
                    &[("queries", self.integration_page(batch, types::API_KEY, last.as_deref()))],
                )
                .await?;
            if matches!(response.value("total"), Value::Int(0)) {
                break;
            }
            let keys = response.models("keys");
            let mut list = Vec::new();
            for k in &keys {
                let key = ApiKey {
                    name: s(k, "name"),
                    scopes: arr(k.value("scopes")),
                    expire: s(k, "expire"),
                    accessed_at: s(k, "accessedAt"),
                    sdks: arr(k.value("sdks")),
                };
                list.push(key.resource(s(k, "id"), s(k, "createdAt"), s(k, "updatedAt")));
                last = Some(s(k, "id"));
            }
            out.emit(list).await?;
            if (keys.len() as i64) < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_webhooks(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api("Webhooks", "list", &[("queries", self.integration_page(batch, types::WEBHOOK, last.as_deref()))])
                .await?;
            if matches!(response.value("total"), Value::Int(0)) {
                break;
            }
            let hooks = response.models("webhooks");
            let mut list = Vec::new();
            for w in &hooks {
                let hook = Webhook {
                    name: s(w, "name"),
                    url: s(w, "url"),
                    events: arr(w.value("events")),
                    security: cast_bool(&w.value("tls")),
                    http_user: s(w, "authUsername"),
                    http_pass: s(w, "authPassword"),
                    enabled: cast_bool(&w.value("enabled")),
                };
                list.push(hook.resource(s(w, "id"), s(w, "createdAt"), s(w, "updatedAt")));
                last = Some(s(w, "id"));
            }
            out.emit(list).await?;
            if (hooks.len() as i64) < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_smtp(&mut self, out: &mut Emitter<'_>) -> Result<()> {
        let p = self.api("Project", "get", &[]).await?;
        let smtp = Smtp {
            enabled: cast_bool(&p.value("smtpEnabled")),
            sender_name: s(&p, "smtpSenderName"),
            sender_email: s(&p, "smtpSenderEmail"),
            reply_to_name: s(&p, "smtpReplyToName"),
            reply_to_email: s(&p, "smtpReplyToEmail"),
            host: s(&p, "smtpHost"),
            port: cast_int(&p.value("smtpPort")),
            username: s(&p, "smtpUsername"),
            secure: s(&p, "smtpSecure"),
        };
        let r = smtp.resource(self.project_id.clone(), s(&p, "createdAt"), s(&p, "updatedAt"));
        out.emit(vec![r]).await
    }

    async fn export_projects(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        if has(types::PROJECT_VARIABLE)
            && let Err(e) = self.export_project_variables(batch, out).await
        {
            self.target.add_error(group_error(types::PROJECT_VARIABLE, group::PROJECTS, e, true));
        }
        if has(types::PROJECT_PROTOCOLS) {
            let result: Result<()> =
                async {
                    let p = self.api("Project", "get", &[]).await?;
                    let mut by_id: indexmap::IndexMap<String, Value> = indexmap::IndexMap::new();
                    for protocol in p.models("protocols") {
                        by_id.insert(s(protocol, "id"), protocol.value("enabled"));
                    }
                    let get = |k: &str| cast_bool(&or(by_id.get(k).cloned().unwrap_or(Value::Null), Value::Bool(true)));
                    let r = Protocols { rest: get("rest"), graphql: get("graphql"), websocket: get("websocket") }
                        .resource(self.project_id.clone(), s(&p, "createdAt"), s(&p, "updatedAt"));
                    out.emit(vec![r]).await
                }
                .await;
            if let Err(e) = result {
                self.target.add_error(group_error(types::PROJECT_PROTOCOLS, group::PROJECTS, e, true));
            }
        }
        if has(types::PROJECT_LABELS) {
            let result: Result<()> = async {
                let p = self.api("Project", "get", &[]).await?;
                let r = Labels { labels: arr(p.value("labels")) }.resource(
                    self.project_id.clone(),
                    s(&p, "createdAt"),
                    s(&p, "updatedAt"),
                );
                out.emit(vec![r]).await
            }
            .await;
            if let Err(e) = result {
                self.target.add_error(group_error(types::PROJECT_LABELS, group::PROJECTS, e, true));
            }
        }
        if has(types::PROJECT_SERVICES) {
            let result: Result<()> = async {
                let p = self.api("Project", "get", &[]).await?;
                let mut by_id = Array::new();
                for service in p.models("services") {
                    by_id.set(
                        ArrayKey::from(s(service, "id").as_str()),
                        Value::Bool(cast_bool(&service.value("enabled"))),
                    );
                }
                let r = Services { services: by_id }.resource(
                    self.project_id.clone(),
                    s(&p, "createdAt"),
                    s(&p, "updatedAt"),
                );
                out.emit(vec![r]).await
            }
            .await;
            if let Err(e) = result {
                self.target.add_error(group_error(types::PROJECT_SERVICES, group::PROJECTS, e, true));
            }
        }
        if has(types::PROJECT_EMAIL_TEMPLATE)
            && let Err(e) = self.export_email_templates(batch, out).await
        {
            self.target.add_error(group_error(types::PROJECT_EMAIL_TEMPLATE, group::PROJECTS, e, true));
        }
        Ok(())
    }

    async fn export_email_templates(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut offset = 0;
        loop {
            let response = self
                .api(
                    "Project",
                    "listEmailTemplates",
                    &[("queries", query::list(vec![query::limit(batch), query::offset(offset)]))],
                )
                .await?;
            let templates = response.models("templates");
            if templates.is_empty() {
                break;
            }
            let mut list = Vec::new();
            for t in &templates {
                let id = format!("email.{}-{}", s(t, "templateId"), s(t, "locale"));
                let template = EmailTemplate {
                    template_id: s(t, "templateId"),
                    locale: s(t, "locale"),
                    subject: s(t, "subject"),
                    body: s(t, "message"),
                    sender_name: s(t, "senderName"),
                    sender_email: s(t, "senderEmail"),
                    reply_to_email: s(t, "replyToEmail"),
                    reply_to_name: s(t, "replyToName"),
                };
                list.push(template.resource(id, "", ""));
            }
            out.emit(list).await?;
            if (templates.len() as i64) < batch {
                break;
            }
            offset += batch;
        }
        Ok(())
    }

    async fn export_project_variables(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let response = self
                .api(
                    "Project",
                    "listVariables",
                    &[("queries", self.integration_page(batch, types::PROJECT_VARIABLE, last.as_deref()))],
                )
                .await?;
            if matches!(response.value("total"), Value::Int(0)) {
                break;
            }
            let variables = response.models("variables");
            let mut list = Vec::new();
            for var in &variables {
                let r = ProjectVariable {
                    key: s(var, "key"),
                    value: s(var, "value"),
                    secret: cast_bool(&var.value("secret")),
                }
                .resource(s(var, "id"), s(var, "createdAt"), s(var, "updatedAt"));
                list.push(r);
                last = Some(s(var, "id"));
            }
            out.emit(list).await?;
            if (variables.len() as i64) < batch {
                break;
            }
        }
        Ok(())
    }

    async fn export_domains(&mut self, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        if resources.iter().any(|r| r == types::RULE)
            && let Err(e) = self.export_rules(batch, out).await
        {
            self.target.add_error(group_error(types::RULE, group::DOMAINS, e, true));
        }
        Ok(())
    }

    async fn export_rules(&mut self, batch: i64, out: &mut Emitter<'_>) -> Result<()> {
        let mut last: Option<String> = None;
        loop {
            let mut q = vec![query::limit(batch)];
            if let Some(l) = last.as_deref() {
                q.push(query::cursor_after(l));
            }
            let response = self.api("Proxy", "listRules", &[("queries", query::list(q))]).await?;
            if matches!(response.value("total"), Value::Int(0)) {
                break;
            }
            let rules = response.models("rules");
            let mut list = Vec::new();
            for r in &rules {
                let deployment_type = r.value("deploymentResourceType");
                let rule = Rule {
                    domain: s(r, "domain"),
                    rule_type: s(r, "type"),
                    trigger: s(r, "trigger"),
                    redirect_url: s(r, "redirectUrl"),
                    redirect_status_code: cast_int(&r.value("redirectStatusCode")),
                    deployment_resource_type: if crate::resources::cast_bool(&deployment_type) {
                        cast_string(&deployment_type)
                    } else {
                        String::new()
                    },
                    deployment_resource_id: s(r, "deploymentResourceId"),
                    deployment_vcs_provider_branch: s(r, "deploymentVcsProviderBranch"),
                };
                list.push(rule.resource(s(r, "id"), s(r, "createdAt"), s(r, "updatedAt")));
                last = Some(s(r, "id"));
            }
            out.emit(list).await?;
            if (rules.len() as i64) < batch {
                break;
            }
        }
        Ok(())
    }
}

#[async_trait]
impl Source for Appwrite {
    fn name(&self) -> &str {
        "Appwrite"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        vec![
            types::USER,
            types::TEAM,
            types::MEMBERSHIP,
            types::AUTH_METHODS,
            types::POLICIES,
            types::OAUTH2_PROVIDER,
            types::DATABASE,
            types::TABLE,
            types::COLUMN,
            types::INDEX,
            types::ROW,
            types::DOCUMENT,
            types::ATTRIBUTE,
            types::COLLECTION,
            types::DATABASE_DOCUMENTSDB,
            types::DATABASE_VECTORSDB,
            types::BUCKET,
            types::FILE,
            types::FUNCTION,
            types::DEPLOYMENT,
            types::ENVIRONMENT_VARIABLE,
            types::PROVIDER,
            types::TOPIC,
            types::SUBSCRIBER,
            types::MESSAGE,
            types::SITE,
            types::SITE_DEPLOYMENT,
            types::SITE_VARIABLE,
            types::PLATFORM,
            types::API_KEY,
            types::WEBHOOK,
            types::SMTP,
            types::BACKUP_POLICY,
            types::PROJECT_VARIABLE,
            types::PROJECT_PROTOCOLS,
            types::PROJECT_LABELS,
            types::PROJECT_SERVICES,
            types::PROJECT_EMAIL_TEMPLATE,
            types::RULE,
        ]
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    fn supports_database_status(&self) -> bool {
        self.source == SOURCE_DATABASE
    }

    fn batch_size(&self, grp: &str) -> i64 {
        if grp == group::DATABASES { if self.source == SOURCE_API { 500 } else { 1000 } } else { 100 }
    }

    async fn report(&mut self, resources: &[String], resource_ids: &ResourceIds) -> Result<Report> {
        Target::validate_resource_ids(resource_ids)?;
        let resources: Vec<String> = if resources.is_empty() {
            self.supported_resources().into_iter().map(str::to_owned).collect()
        } else {
            resources.to_vec()
        };
        let report = match self.report_all(&resources, resource_ids).await {
            Ok(r) => r,
            Err(e) => {
                let code = e.code();
                let message = if code.as_int() == 403 { "Missing required scopes.".to_owned() } else { e.message() };
                return Err(Error::exception_with(message, code, e));
            }
        };
        self.target.previous_report = report.clone();
        Ok(report)
    }

    async fn export_group(&mut self, grp: &str, batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        match grp {
            group::AUTH => self.export_auth(batch, resources, out).await,
            group::DATABASES => self.export_databases_group(batch, resources, out).await,
            group::STORAGE => self.export_storage(batch, resources, out).await,
            group::FUNCTIONS => self.export_functions_group(batch, resources, out).await,
            group::MESSAGING => self.export_messaging(batch, resources, out).await,
            group::SITES => self.export_sites_group(batch, resources, out).await,
            group::INTEGRATIONS => self.export_integrations(batch, resources, out).await,
            group::BACKUPS => Ok(()),
            group::PROJECTS => self.export_projects(batch, resources, out).await,
            group::DOMAINS => self.export_domains(batch, resources, out).await,
            _ => Ok(()),
        }
    }

    async fn run(
        &mut self,
        resources: &[String],
        sink: &mut dyn Sink,
        root_resource_id: &str,
        root_resource_type: &str,
    ) -> Result<()> {
        let previous = self.resource_child_id.clone();
        let mut root_id = root_resource_id.to_owned();
        if self.resource_child_id.is_none() {
            self.resource_child_id = Some(String::new());
            if !root_id.is_empty()
                && database_resources(root_resource_type).is_some()
                && let Some((db, child)) = root_resource_id.split_once(':')
            {
                root_id = db.to_owned();
                self.resource_child_id = Some(child.to_owned());
            }
        }
        let result = crate::source::run(self, resources, sink, &root_id, root_resource_type).await;
        self.resource_child_id = previous;
        result
    }

    async fn run_with_resource_selector(
        &mut self,
        resources: &[String],
        sink: &mut dyn Sink,
        selector: &ResourceSelector,
    ) -> Result<()> {
        let previous = self.resource_child_id.clone();
        self.resource_child_id =
            Some(if selector.parent_resource_id.is_empty() { String::new() } else { selector.resource_id.clone() });
        let result = self.run(resources, sink, selector.scope_id(), selector.scope_type()).await;
        self.resource_child_id = previous;
        result
    }
}
