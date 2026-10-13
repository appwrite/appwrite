//! An Appwrite project as the destination: databases are written straight
//! to the project's databases, the rest through the Appwrite API or the
//! platform database.

mod api;
mod project;
mod schema;

use std::collections::HashSet;
use std::sync::Arc;

use async_trait::async_trait;
use indexmap::IndexMap;
use php_std::{Array, Value};
use utopia_database::{Database as Db, Document};

use crate::destination::{Callback, Destination};
use crate::destinations::on_duplicate::OnDuplicate;
use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::{ResourceRef, status, types};
use crate::sdk::Client;
use crate::source::{ResourceIds, Source};
use crate::sources::appwrite::reader::DatabaseResolver;
use crate::target::Target;
use crate::transfer::{Report, group};

/// Resolves the DSN written into a migrated database's `database`
/// attribute on the destination.
pub type DsnResolver = Arc<dyn Fn(&ResourceRef) -> String + Send + Sync>;

/// What an Overwrite run tracked for one table: the keys the source
/// imported, so the destination's other attributes and indexes can be
/// dropped.
struct Orphans {
    database: Document,
    table: Document,
    db: Db,
    attribute_keys: Vec<String>,
    index_keys: Vec<String>,
}

/// An Appwrite project as a destination.
///
/// PHP: `Utopia\Migration\Destinations\Appwrite`.
pub struct Appwrite {
    target: Target,
    project_id: String,
    key: String,
    client: Client,
    db_for_project: Db,
    databases_db: DatabaseResolver,
    collection_structure: Array,
    db_for_platform: Db,
    project_internal_id: String,
    on_duplicate: OnDuplicate,
    database_dsn: Option<DsnResolver>,
    collection_structures: Array,
    row_buffer: Vec<Document>,
    orphans_by_table: IndexMap<String, Orphans>,
    processed_two_way_pairs: HashSet<String>,
    provisioning_databases: IndexMap<String, ()>,
    database_status_supported: Option<bool>,
    /// Whether the source of the current run manages database status
    /// (`None`: no source yet, PHP's uninitialized `$source`).
    source_status: Option<bool>,
}

impl std::fmt::Debug for Appwrite {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Appwrite")
            .field("project_id", &self.project_id)
            .field("on_duplicate", &self.on_duplicate)
            .finish_non_exhaustive()
    }
}

/// The arguments of [`Appwrite::new`] beyond the connection.
pub struct Options {
    /// The project's database (PHP: `$dbForProject`).
    pub db_for_project: Db,
    /// The database a database's tables live in (PHP: `$getDatabasesDB`).
    pub databases_db: DatabaseResolver,
    /// The structure of a database's collection of tables: `attributes`
    /// and `indexes` (PHP: `$collectionStructure`).
    pub collection_structure: Array,
    /// The platform database (PHP: `$dbForPlatform`).
    pub db_for_platform: Db,
    /// The project's internal id (PHP: `$projectInternalId`).
    pub project_internal_id: String,
    /// What to do with resources that exist (PHP: `$onDuplicate`).
    pub on_duplicate: OnDuplicate,
    /// The DSN written into migrated databases (PHP: `$getDatabaseDSN`).
    pub database_dsn: Option<DsnResolver>,
    /// Structures by database type, used over `collection_structure` for
    /// that type (PHP: `$collectionStructures`).
    pub collection_structures: Array,
}

impl Appwrite {
    /// A destination of project `project` at `endpoint`, with API key `key`.
    /// Fails when `endpoint` is not an HTTP(S) URL.
    ///
    /// PHP: `new Appwrite($project, $endpoint, $key, $dbForProject,
    /// $getDatabasesDB, $collectionStructure, $dbForPlatform,
    /// $projectInternalId, $onDuplicate, $getDatabaseDSN, $collectionStructures)`.
    pub fn new(project: &str, endpoint: &str, key: &str, options: Options) -> Result<Self> {
        let mut client = Client::new();
        client.set_endpoint(endpoint)?;
        client.set_project(project);
        client.set_key(key);
        let mut target = Target::new();
        target.endpoint = endpoint.to_owned();
        Ok(Self {
            target,
            project_id: project.to_owned(),
            key: key.to_owned(),
            client,
            db_for_project: options.db_for_project,
            databases_db: options.databases_db,
            collection_structure: options.collection_structure,
            db_for_platform: options.db_for_platform,
            project_internal_id: options.project_internal_id,
            on_duplicate: options.on_duplicate,
            database_dsn: options.database_dsn,
            collection_structures: options.collection_structures,
            row_buffer: Vec::new(),
            orphans_by_table: IndexMap::new(),
            processed_two_way_pairs: HashSet::new(),
            provisioning_databases: IndexMap::new(),
            database_status_supported: None,
            source_status: None,
        })
    }

    /// The project id.
    pub fn project_id(&self) -> &str {
        &self.project_id
    }

    /// The API key.
    pub fn key(&self) -> &str {
        &self.key
    }

    /// The API client.
    pub fn client(&self) -> &Client {
        &self.client
    }

    /// Records whether the source manages database status, as PHP's
    /// `setSource()` does (the transfer's run sets it too).
    pub fn set_source_supports_database_status(&mut self, supports: bool) {
        self.source_status = Some(supports);
    }

    /// The DSN written into the destination's `database` attribute of
    /// `resource`: the resolver's, or blank (never the source's).
    ///
    /// PHP: `resolveDestinationDsn()` (private).
    pub fn resolve_destination_dsn(&self, resource: &ResourceRef) -> String {
        match &self.database_dsn {
            Some(resolver) => resolver(resource),
            None => String::new(),
        }
    }

    fn reset_run_state(&mut self) {
        self.row_buffer.clear();
        self.orphans_by_table.clear();
        self.processed_two_way_pairs.clear();
        self.provisioning_databases.clear();
    }

    /// The error of a resource that cannot be imported: its status and an
    /// error with the message.
    fn reject(&mut self, r: &ResourceRef, message: impl Into<String>) -> Result<bool> {
        let message = message.into();
        r.set_status(status::ERROR, message.clone());
        self.target.add_error(Exception::new(r.name(), r.group(), Some(r.id()), message, 0, None));
        Ok(false)
    }

    async fn import_one(&mut self, r: &ResourceRef, is_last: bool) -> Result<()> {
        match r.group() {
            group::DATABASES => self.import_database_resource(r, is_last).await,
            group::STORAGE => self.import_file_resource(r).await,
            group::AUTH => self.import_auth_resource(r).await,
            group::FUNCTIONS => self.import_function_resource(r).await,
            group::MESSAGING => self.import_messaging_resource(r).await,
            group::SITES => self.import_site_resource(r).await,
            group::INTEGRATIONS => self.import_integrations_resource(r).await,
            group::BACKUPS => {
                r.set_status(status::SKIPPED, "");
                Ok(())
            }
            group::PROJECTS => self.import_projects_resource(r).await,
            group::DOMAINS => self.import_domains_resource(r).await,
            _ => Err(Error::exception("Invalid resource group", Exception::CODE_VALIDATION)),
        }
    }

    /// Imports a resource of the databases group.
    ///
    /// PHP: `importDatabaseResource($resource, $isLast)`.
    pub async fn import_database_resource(&mut self, r: &ResourceRef, is_last: bool) -> Result<()> {
        let success = match r.name() {
            types::DATABASE | types::DATABASE_DOCUMENTSDB | types::DATABASE_VECTORSDB => {
                self.db_for_project.set_preserve_dates(false);
                let result = self.create_database(r).await;
                self.db_for_project.set_preserve_dates(true);
                result?
            }
            types::TABLE | types::COLLECTION => self.create_entity(r).await?,
            types::COLUMN | types::ATTRIBUTE => self.create_field(r).await?,
            types::INDEX => self.create_index(r).await?,
            types::ROW | types::DOCUMENT => self.create_record(r, is_last).await?,
            _ => false,
        };
        if success {
            r.set_status(status::SUCCESS, "");
        }
        Ok(())
    }
}

/// `$value` as a string when it is one, or the string of a number
/// (`stringifyDateValue()`).
fn stringify_date(value: &Value) -> Option<String> {
    match value {
        Value::Str(s) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        Value::Int(_) | Value::Float(_) => {
            value.to_php_string().ok().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned())
        }
        _ => None,
    }
}

/// A date as the database stores it: `value` (or `fallback`) parsed as PHP's
/// `DateTime` does (ten digits are a Unix timestamp), or now when neither is
/// a date string.
///
/// PHP: `normalizeDateTime()` (private).
pub(crate) fn normalize_date_time(value: &Value, fallback: &Value) -> Result<String> {
    use php_std::datetime::{DateTime, TzInfo};
    let resolved = stringify_date(value).or_else(|| stringify_date(fallback)).unwrap_or_default();
    if resolved.is_empty() || resolved == "0" {
        return Ok(utopia_database::datetime::now());
    }
    let numeric = php_std::value::numeric_bytes_ex(resolved.as_bytes()).is_some();
    let resolved = if numeric && resolved.len() == 10 && crate::resources::cast_int(&Value::from(resolved.as_str())) > 0
    {
        format!("@{resolved}")
    } else {
        resolved
    };
    let date = DateTime::new(resolved.as_bytes(), None, &TzInfo::utc()).map_err(|e| Error::Other {
        class: "DateMalformedStringException".into(),
        message: e.message().into_owned(),
        code: 0,
    })?;
    Ok(utopia_database::datetime::format(&date))
}

#[async_trait]
impl Destination for Appwrite {
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
            types::DATABASE_DOCUMENTSDB,
            types::DATABASE_VECTORSDB,
            types::TABLE,
            types::COLUMN,
            types::INDEX,
            types::ROW,
            types::DOCUMENT,
            types::ATTRIBUTE,
            types::COLLECTION,
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
            types::PROJECT_VARIABLE,
            types::PROJECT_PROTOCOLS,
            types::PROJECT_LABELS,
            types::PROJECT_SERVICES,
            types::PROJECT_EMAIL_TEMPLATE,
            types::BACKUP_POLICY,
            types::RULE,
        ]
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    async fn report(&mut self, resources: &[String], _resource_ids: &ResourceIds) -> Result<Report> {
        let resources: Vec<String> = if resources.is_empty() {
            self.supported_resources().into_iter().map(str::to_owned).collect()
        } else {
            resources.to_vec()
        };
        self.check_scopes(&resources).await?;
        Ok(Report::new())
    }

    async fn import(&mut self, resources: &[ResourceRef], callback: &mut Callback<'_>) -> Result<()> {
        if resources.is_empty() {
            return Ok(());
        }
        let total = resources.len();
        for (index, r) in resources.iter().enumerate() {
            r.set_status(status::PROCESSING, "");
            let is_last = index == total - 1;
            self.db_for_project.set_preserve_dates(true);
            self.db_for_platform.set_preserve_dates(true);
            let result = self.import_one(r, is_last).await;
            self.db_for_project.set_preserve_dates(false);
            self.db_for_platform.set_preserve_dates(false);
            if let Err(e) = result {
                r.set_status(status::ERROR, e.message());
                let code = e.code();
                self.target.add_error(Exception::new(r.name(), r.group(), Some(r.id()), e.message(), code, Some(e)));
            }
            self.target.cache()?.update(r);
        }
        callback(resources)
    }

    /// Orphans are cleaned only after a run that completed.
    async fn run(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut Callback<'_>,
        root_resource_id: &str,
        root_resource_type: &str,
    ) -> Result<()> {
        self.reset_run_state();
        self.source_status = Some(source.supports_database_status());
        crate::destination::run(self, source, resources, callback, root_resource_id, root_resource_type).await?;
        self.mark_provisioned_databases_ready().await;
        self.cleanup_overwrite_orphans().await
    }
}
