//! The transfer: runs a source into a destination, and reports on it.

use std::sync::Arc;

use indexmap::IndexMap;
use php_std::{ArrayKey, Value};

use crate::cache::{Cache, Entry};
use crate::destination::{Callback, Destination};
use crate::error::{Error, Result};
use crate::resource::{status, types};
use crate::source::Source;

/// Resource groups (PHP: `Transfer::GROUP_*`).
pub mod group {
    /// General settings.
    pub const GENERAL: &str = "general";
    /// Users, teams and auth settings.
    pub const AUTH: &str = "auth";
    /// Buckets and files.
    pub const STORAGE: &str = "storage";
    /// Functions.
    pub const FUNCTIONS: &str = "functions";
    /// Sites.
    pub const SITES: &str = "sites";
    /// Every database resource.
    pub const DATABASES: &str = "databases";
    /// TablesDB resources.
    pub const DATABASES_TABLES_DB: &str = "tablesdb";
    /// DocumentsDB resources.
    pub const DATABASES_DOCUMENTS_DB: &str = "documentsdb";
    /// VectorsDB resources.
    pub const DATABASES_VECTOR_DB: &str = "vectorsdb";
    /// Platforms, keys, webhooks and SMTP.
    pub const INTEGRATIONS: &str = "integrations";
    /// Messaging.
    pub const MESSAGING: &str = "messaging";
    /// Backup policies.
    pub const BACKUPS: &str = "backups";
    /// Project settings.
    pub const PROJECTS: &str = "projects";
    /// Domain rules.
    pub const DOMAINS: &str = "domains";

    use crate::resource::types as t;

    /// `Transfer::GROUP_AUTH_RESOURCES`.
    pub const AUTH_RESOURCES: &[&str] =
        &[t::USER, t::TEAM, t::MEMBERSHIP, t::HASH, t::AUTH_METHODS, t::POLICIES, t::OAUTH2_PROVIDER];
    /// `Transfer::GROUP_STORAGE_RESOURCES`.
    pub const STORAGE_RESOURCES: &[&str] = &[t::FILE, t::BUCKET];
    /// `Transfer::GROUP_FUNCTIONS_RESOURCES`.
    pub const FUNCTIONS_RESOURCES: &[&str] = &[t::FUNCTION, t::ENVIRONMENT_VARIABLE, t::DEPLOYMENT];
    /// `Transfer::GROUP_SITES_RESOURCES`.
    pub const SITES_RESOURCES: &[&str] = &[t::SITE, t::SITE_VARIABLE, t::SITE_DEPLOYMENT];
    /// `Transfer::GROUP_TABLESDB_RESOURCES`.
    pub const TABLESDB_RESOURCES: &[&str] = &[t::DATABASE, t::TABLE, t::INDEX, t::COLUMN, t::ROW];
    /// `Transfer::GROUP_INTEGRATIONS_RESOURCES`.
    pub const INTEGRATIONS_RESOURCES: &[&str] = &[t::PLATFORM, t::API_KEY, t::WEBHOOK, t::SMTP];
    /// `Transfer::GROUP_DOCUMENTSDB_RESOURCES`.
    pub const DOCUMENTSDB_RESOURCES: &[&str] = &[t::DATABASE_DOCUMENTSDB, t::COLLECTION, t::INDEX, t::DOCUMENT];
    /// `Transfer::GROUP_VECTORSDB_RESOURCES`.
    pub const VECTORSDB_RESOURCES: &[&str] =
        &[t::DATABASE_VECTORSDB, t::COLLECTION, t::ATTRIBUTE, t::INDEX, t::DOCUMENT];
    /// `Transfer::GROUP_DATABASES_RESOURCES`.
    pub const DATABASES_RESOURCES: &[&str] = &[
        t::DATABASE,
        t::DATABASE_DOCUMENTSDB,
        t::DATABASE_VECTORSDB,
        t::TABLE,
        t::INDEX,
        t::COLUMN,
        t::ROW,
        t::DOCUMENT,
        t::COLLECTION,
        t::ATTRIBUTE,
    ];
    /// `Transfer::GROUP_PROJECTS_RESOURCES`.
    pub const PROJECTS_RESOURCES: &[&str] =
        &[t::PROJECT_VARIABLE, t::PROJECT_PROTOCOLS, t::PROJECT_LABELS, t::PROJECT_SERVICES, t::PROJECT_EMAIL_TEMPLATE];
    /// `Transfer::GROUP_BACKUPS_RESOURCES`.
    pub const BACKUPS_RESOURCES: &[&str] = &[t::BACKUP_POLICY];
    /// `Transfer::GROUP_DOMAINS_RESOURCES`.
    pub const DOMAINS_RESOURCES: &[&str] = &[t::RULE];
    /// `Transfer::GROUP_MESSAGING_RESOURCES`.
    pub const MESSAGING_RESOURCES: &[&str] = &[t::PROVIDER, t::TOPIC, t::SUBSCRIBER, t::MESSAGE];
}

/// `Transfer::ALL_PUBLIC_RESOURCES`.
pub const ALL_PUBLIC_RESOURCES: &[&str] = &[
    types::USER,
    types::TEAM,
    types::MEMBERSHIP,
    types::AUTH_METHODS,
    types::POLICIES,
    types::OAUTH2_PROVIDER,
    types::FILE,
    types::BUCKET,
    types::FUNCTION,
    types::ENVIRONMENT_VARIABLE,
    types::DEPLOYMENT,
    types::SITE,
    types::SITE_VARIABLE,
    types::SITE_DEPLOYMENT,
    types::DATABASE,
    types::TABLE,
    types::INDEX,
    types::COLUMN,
    types::ROW,
    types::PROVIDER,
    types::TOPIC,
    types::SUBSCRIBER,
    types::MESSAGE,
    types::BACKUP_POLICY,
    types::PLATFORM,
    types::API_KEY,
    types::WEBHOOK,
    types::SMTP,
    types::PROJECT_VARIABLE,
    types::PROJECT_PROTOCOLS,
    types::PROJECT_LABELS,
    types::PROJECT_SERVICES,
    types::PROJECT_EMAIL_TEMPLATE,
    types::RULE,
    types::DOCUMENT,
    types::ATTRIBUTE,
    types::COLLECTION,
];

/// The types that can be a transfer's single root (`Transfer::ROOT_RESOURCES`).
pub const ROOT_RESOURCES: &[&str] = &[
    types::BUCKET,
    types::DATABASE,
    types::DATABASE_DOCUMENTSDB,
    types::DATABASE_VECTORSDB,
    types::FUNCTION,
    types::SITE,
    types::USER,
    types::TEAM,
    types::PLATFORM,
    types::API_KEY,
    types::PROVIDER,
    types::TOPIC,
    types::MESSAGE,
];

/// Files and deployments move in chunks of this many bytes (5 MiB).
pub const STORAGE_MAX_CHUNK_SIZE: i64 = 1024 * 1024 * 5;

/// The resources groups map onto, in the order sources export them
/// (`Source::exportResources()`'s mapping).
pub const EXPORT_GROUPS: &[(&str, &[&str])] = &[
    (group::AUTH, group::AUTH_RESOURCES),
    (group::DATABASES, group::DATABASES_RESOURCES),
    (group::STORAGE, group::STORAGE_RESOURCES),
    (group::FUNCTIONS, group::FUNCTIONS_RESOURCES),
    (group::MESSAGING, group::MESSAGING_RESOURCES),
    (group::SITES, group::SITES_RESOURCES),
    (group::INTEGRATIONS, group::INTEGRATIONS_RESOURCES),
    (group::BACKUPS, group::BACKUPS_RESOURCES),
    (group::PROJECTS, group::PROJECTS_RESOURCES),
    (group::DOMAINS, group::DOMAINS_RESOURCES),
];

/// The resource types of the given service groups, in order.
///
/// PHP: `Transfer::extractServices($services)`; an unknown group is an
/// error.
pub fn extract_services<S: AsRef<str>>(services: &[S]) -> Result<Vec<&'static str>> {
    let mut out = Vec::new();
    for s in services {
        let list: &[&str] = match s.as_ref() {
            group::FUNCTIONS => group::FUNCTIONS_RESOURCES,
            group::SITES => group::SITES_RESOURCES,
            group::STORAGE => group::STORAGE_RESOURCES,
            group::GENERAL => &[],
            group::AUTH => group::AUTH_RESOURCES,
            group::DATABASES => group::DATABASES_RESOURCES,
            group::INTEGRATIONS => group::INTEGRATIONS_RESOURCES,
            group::DATABASES_TABLES_DB => group::TABLESDB_RESOURCES,
            group::DATABASES_DOCUMENTS_DB => group::DOCUMENTSDB_RESOURCES,
            group::DATABASES_VECTOR_DB => group::VECTORSDB_RESOURCES,
            group::MESSAGING => group::MESSAGING_RESOURCES,
            group::BACKUPS => group::BACKUPS_RESOURCES,
            group::PROJECTS => group::PROJECTS_RESOURCES,
            group::DOMAINS => group::DOMAINS_RESOURCES,
            _ => return Err(Error::exception("No service group found", 0)),
        };
        out.extend_from_slice(list);
    }
    Ok(out)
}

/// A resource named by Appwrite's canonical relation fields: the resource
/// and, for a child (a table of a database), its parent.
///
/// PHP: `Utopia\Migration\ResourceSelector`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct ResourceSelector {
    /// The resource id.
    pub resource_id: String,
    /// The resource's internal id.
    pub resource_internal_id: String,
    /// The resource type.
    pub resource_type: String,
    /// The parent's id, `''` for a root resource.
    pub parent_resource_id: String,
    /// The parent's internal id.
    pub parent_resource_internal_id: String,
    /// The parent's type.
    pub parent_resource_type: String,
}

impl ResourceSelector {
    /// The root of the selection: the parent, else the resource.
    ///
    /// PHP: `getScopeId()`.
    pub fn scope_id(&self) -> &str {
        if self.parent_resource_id.is_empty() { &self.resource_id } else { &self.parent_resource_id }
    }

    /// The root's type.
    ///
    /// PHP: `getScopeType()`.
    pub fn scope_type(&self) -> &str {
        if self.parent_resource_type.is_empty() { &self.resource_type } else { &self.parent_resource_type }
    }
}

/// A source's report: resource type => count (`size` in MB, `version` a
/// string).
pub type Report = IndexMap<String, Value>;

/// Per-status counts of one resource type.
pub type Counters = IndexMap<String, i64>;

/// One line of [`Transfer::report`].
#[derive(Debug, Clone, PartialEq)]
pub struct ReportEntry {
    /// The resource type.
    pub resource: String,
    /// The resource id (for rows and documents, the status key of a count).
    pub id: Value,
    /// The status (for rows and documents, the count).
    pub status: String,
    /// The status message.
    pub message: String,
}

/// Moves resources from a [`Source`] into a [`Destination`], sharing one
/// [`Cache`] between them, and reports per-status counters.
///
/// PHP: `Utopia\Migration\Transfer`.
pub struct Transfer {
    source: Box<dyn Source>,
    destination: Box<dyn Destination>,
    cache: Arc<Cache>,
    resources: Vec<String>,
    selector: Option<ResourceSelector>,
}

impl std::fmt::Debug for Transfer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Transfer").field("resources", &self.resources).finish_non_exhaustive()
    }
}

const STATUSES: &[&str] =
    &[status::PENDING, status::SUCCESS, status::ERROR, status::SKIPPED, status::PROCESSING, status::WARNING];

impl Transfer {
    /// A transfer from `source` to `destination` with a new cache, which both
    /// are given.
    pub fn new(mut source: Box<dyn Source>, mut destination: Box<dyn Destination>) -> Self {
        let cache = Arc::new(Cache::new());
        source.target_mut().register_cache(cache.clone());
        destination.target_mut().register_cache(cache.clone());
        Self { source, destination, cache, resources: Vec::new(), selector: None }
    }

    /// The source.
    pub fn source(&self) -> &dyn Source {
        &*self.source
    }

    /// The source, mutably.
    pub fn source_mut(&mut self) -> &mut dyn Source {
        &mut *self.source
    }

    /// The destination.
    pub fn destination(&self) -> &dyn Destination {
        &*self.destination
    }

    /// The destination, mutably.
    pub fn destination_mut(&mut self) -> &mut dyn Destination {
        &mut *self.destination
    }

    /// The shared cache.
    ///
    /// PHP: `getCache()`.
    pub fn cache(&self) -> &Arc<Cache> {
        &self.cache
    }

    /// Per-status counts of each requested resource type: the source's
    /// report as pending, the cache's statuses, and one error per source or
    /// destination error of the type; types with all counts zero are left
    /// out.
    ///
    /// PHP: `getStatusCounters()`.
    pub fn status_counters(&self) -> IndexMap<String, Counters> {
        let mut out: IndexMap<String, Counters> = IndexMap::new();
        for r in &self.resources {
            out.insert(r.clone(), STATUSES.iter().map(|s| ((*s).to_owned(), 0)).collect());
        }
        for (resource, data) in &self.source.target().previous_report {
            if resource != "size"
                && resource != "version"
                && let Some(c) = out.get_mut(resource)
            {
                c.insert(status::PENDING.to_owned(), crate::resources::cast_int(data));
            }
        }
        for (resource_type, entries) in self.cache.all() {
            for (key, entry) in entries {
                match entry {
                    Entry::Count(count) if resource_type == types::ROW || resource_type == types::DOCUMENT => {
                        let Some(c) = out.get_mut(&resource_type) else { continue };
                        let count = php_std::format::str_to_int(count.as_bytes());
                        let k = match &key {
                            ArrayKey::Int(i) => i.to_string(),
                            ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                        };
                        c.insert(k, count);
                        let pending = c[status::PENDING];
                        if pending > 0 {
                            c.insert(status::PENDING.to_owned(), pending - pending.min(count));
                        }
                    }
                    Entry::Count(_) => {}
                    Entry::Resource(r) => {
                        if let Some(c) = out.get_mut(r.name()) {
                            let s = r.status();
                            *c.entry(s).or_insert(0) += 1;
                            if c[status::PENDING] > 0 {
                                *c.get_mut(status::PENDING).expect("seeded") -= 1;
                            }
                        }
                    }
                }
            }
        }
        for e in self.destination.target().errors.iter().chain(self.source.target().errors.iter()) {
            if let Some(c) = out.get_mut(&e.resource_group) {
                *c.get_mut(status::ERROR).expect("seeded") += 1;
            }
        }
        out.retain(|_, c| c.values().any(|v| *v > 0));
        out
    }

    /// Transfers `resources` (types, lowercased) from the source into the
    /// destination, calling `callback` with each imported batch. With a
    /// `root_resource_id`, only that root resource (of `root_resource_type`)
    /// and its children move, and exactly one root type must be requested.
    ///
    /// PHP: `run($resources, $callback, $rootResourceId, $rootResourceType)`.
    pub async fn run<S: AsRef<str>>(
        &mut self,
        resources: &[S],
        callback: &mut Callback<'_>,
        root_resource_id: Option<&str>,
        root_resource_type: Option<&str>,
    ) -> Result<()> {
        let computed: Vec<String> = resources
            .iter()
            .map(|r| String::from_utf8_lossy(&php_std::string::strtolower(r.as_ref().as_bytes())).into_owned())
            .collect();
        let root_id = root_resource_id.unwrap_or("");
        let root_type = root_resource_type.unwrap_or("");
        if !root_id.is_empty() {
            if root_type.is_empty() {
                return Err(Error::exception("Resource type must be set when resource ID is set.", 0));
            }
            if !ROOT_RESOURCES.contains(&root_type) {
                return Err(Error::exception(
                    format!("Got {root_type} Resource type must be one of {}", ROOT_RESOURCES.join(", ")),
                    0,
                ));
            }
            let roots = computed.iter().filter(|r| ROOT_RESOURCES.contains(&r.as_str())).count();
            if roots > 1 {
                return Err(Error::exception(
                    "Multiple root resources found. Only one root resource can be transferred at a time if using $rootResourceId.",
                    0,
                ));
            }
            if roots == 0 {
                return Err(Error::exception("No root resources found.", 0));
            }
        }
        self.resources = computed.clone();
        if let Some(selector) = self.selector.clone() {
            return self
                .destination
                .run_with_resource_selector(&mut *self.source, &computed, callback, &selector)
                .await;
        }
        self.destination.run(&mut *self.source, &computed, callback, root_id, root_type).await
    }

    /// Transfers `resources` of the resource `selector` names.
    ///
    /// PHP: `runWithResourceSelector()`.
    pub async fn run_with_resource_selector<S: AsRef<str>>(
        &mut self,
        resources: &[S],
        callback: &mut Callback<'_>,
        selector: ResourceSelector,
    ) -> Result<()> {
        let previous = self.selector.replace(selector.clone());
        let result = self.run(resources, callback, Some(selector.scope_id()), Some(selector.scope_type())).await;
        self.selector = previous;
        result
    }

    /// The resource currently being transferred; never set, as in PHP,
    /// where reading it is an error.
    ///
    /// PHP: `getCurrentResource()`.
    pub fn current_resource(&self) -> Result<String> {
        Err(Error::Other {
            class: "Error".into(),
            message: "Typed property Utopia\\Migration\\Transfer::$currentResource must not be accessed before initialization".into(),
            code: 0,
        })
    }

    /// Every cached resource with its status and message, or only those in
    /// `status_level`. Rows and documents are cached as per-status counts,
    /// so their lines carry the status as the id and the count as the status
    /// (and match a status level only when the count equals it).
    ///
    /// PHP: `getReport($statusLevel)`.
    pub fn report(&self, status_level: &str) -> Vec<ReportEntry> {
        let mut out = Vec::new();
        for (t, entries) in self.cache.all() {
            for (key, entry) in entries {
                match entry {
                    Entry::Count(count) if t == types::ROW || t == types::DOCUMENT => {
                        if !status_level.is_empty() && count != status_level {
                            continue;
                        }
                        out.push(ReportEntry {
                            resource: t.clone(),
                            id: match key {
                                ArrayKey::Int(i) => Value::Int(i),
                                ArrayKey::Str(s) => Value::Str(s),
                            },
                            status: count,
                            message: String::new(),
                        });
                    }
                    Entry::Count(_) => {}
                    Entry::Resource(r) => {
                        let s = r.status();
                        if !status_level.is_empty() && s != status_level {
                            continue;
                        }
                        out.push(ReportEntry {
                            resource: t.clone(),
                            id: Value::from(r.id().as_str()),
                            status: s,
                            message: r.message(),
                        });
                    }
                }
            }
        }
        out
    }
}
