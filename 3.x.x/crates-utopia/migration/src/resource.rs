//! Shared resource constants and base. PHP `Utopia\Migration\Resource`.

use serde_json::{Map, Value};

pub const STATUS_PENDING: &str = "pending";
pub const STATUS_SUCCESS: &str = "success";
pub const STATUS_ERROR: &str = "error";
pub const STATUS_SKIPPED: &str = "skip";
pub const STATUS_PROCESSING: &str = "processing";
pub const STATUS_WARNING: &str = "warning";
pub const STATUS_DISREGARDED: &str = "disregarded";

pub const TYPE_BUCKET: &str = "bucket";
pub const TYPE_TABLE: &str = "table";
pub const TYPE_DATABASE: &str = "database";
pub const TYPE_DATABASE_LEGACY: &str = "legacy";
pub const TYPE_DATABASE_TABLESDB: &str = "tablesdb";
pub const TYPE_DATABASE_DOCUMENTSDB: &str = "documentsdb";
pub const TYPE_DATABASE_VECTORSDB: &str = "vectorsdb";
pub const TYPE_ROW: &str = "row";
pub const TYPE_FILE: &str = "file";
pub const TYPE_USER: &str = "user";
pub const TYPE_TEAM: &str = "team";
pub const TYPE_MEMBERSHIP: &str = "membership";
pub const TYPE_FUNCTION: &str = "function";
pub const TYPE_SITE: &str = "site";
pub const TYPE_INDEX: &str = "index";
pub const TYPE_PROVIDER: &str = "provider";
pub const TYPE_TOPIC: &str = "topic";
pub const TYPE_COLUMN: &str = "column";
pub const TYPE_DEPLOYMENT: &str = "deployment";
pub const TYPE_SITE_DEPLOYMENT: &str = "site-deployment";
pub const TYPE_SITE_VARIABLE: &str = "site-variable";
pub const TYPE_HASH: &str = "hash";
pub const TYPE_AUTH_METHODS: &str = "auth-methods";
pub const TYPE_POLICIES: &str = "policies";
pub const TYPE_OAUTH2_PROVIDER: &str = "oauth2-provider";
pub const TYPE_ENVIRONMENT_VARIABLE: &str = "environment-variable";
pub const TYPE_PLATFORM: &str = "platform";
pub const TYPE_API_KEY: &str = "api-key";
pub const TYPE_WEBHOOK: &str = "webhook";
pub const TYPE_SMTP: &str = "smtp";
pub const TYPE_PROJECT_VARIABLE: &str = "project-variable";
pub const TYPE_PROJECT_PROTOCOLS: &str = "project-protocols";
pub const TYPE_PROJECT_LABELS: &str = "project-labels";
pub const TYPE_PROJECT_SERVICES: &str = "project-services";
pub const TYPE_PROJECT_EMAIL_TEMPLATE: &str = "project-email-template";
pub const TYPE_RULE: &str = "rule";
pub const TYPE_SUBSCRIBER: &str = "subscriber";
pub const TYPE_MESSAGE: &str = "message";
pub const TYPE_BACKUP_POLICY: &str = "backup-policy";
pub const TYPE_DOCUMENT: &str = "document";
pub const TYPE_ATTRIBUTE: &str = "attribute";
pub const TYPE_COLLECTION: &str = "collection";

pub const DATABASE_TYPE_RESOURCE_MAP: &[(&str, &str)] = &[
    (TYPE_DATABASE, TYPE_TABLE),
    (TYPE_DATABASE_DOCUMENTSDB, TYPE_COLLECTION),
    (TYPE_DATABASE_VECTORSDB, TYPE_COLLECTION),
];

#[must_use]
pub fn database_entity_type(root_resource_type: &str) -> Option<&'static str> {
    match root_resource_type {
        TYPE_DATABASE => Some(TYPE_TABLE),
        TYPE_DATABASE_DOCUMENTSDB | TYPE_DATABASE_VECTORSDB => Some(TYPE_COLLECTION),
        _ => None,
    }
}

pub const ALL_RESOURCES: &[&str] = &[
    TYPE_COLUMN,
    TYPE_BUCKET,
    TYPE_TABLE,
    TYPE_DATABASE,
    TYPE_DATABASE_VECTORSDB,
    TYPE_DATABASE_DOCUMENTSDB,
    TYPE_ROW,
    TYPE_FILE,
    TYPE_FUNCTION,
    TYPE_DEPLOYMENT,
    TYPE_SITE,
    TYPE_SITE_DEPLOYMENT,
    TYPE_SITE_VARIABLE,
    TYPE_HASH,
    TYPE_INDEX,
    TYPE_USER,
    TYPE_ENVIRONMENT_VARIABLE,
    TYPE_TEAM,
    TYPE_MEMBERSHIP,
    TYPE_AUTH_METHODS,
    TYPE_POLICIES,
    TYPE_OAUTH2_PROVIDER,
    TYPE_PLATFORM,
    TYPE_API_KEY,
    TYPE_WEBHOOK,
    TYPE_SMTP,
    TYPE_PROJECT_VARIABLE,
    TYPE_PROJECT_PROTOCOLS,
    TYPE_PROJECT_LABELS,
    TYPE_PROJECT_SERVICES,
    TYPE_PROJECT_EMAIL_TEMPLATE,
    TYPE_RULE,
    TYPE_PROVIDER,
    TYPE_TOPIC,
    TYPE_SUBSCRIBER,
    TYPE_MESSAGE,
    TYPE_BACKUP_POLICY,
    TYPE_DOCUMENT,
    TYPE_ATTRIBUTE,
    TYPE_COLLECTION,
];

#[must_use]
pub fn is_supported(types: &[&str], resources: &[&str]) -> bool {
    for t in types {
        if resources.contains(t) {
            return true;
        }
        let mapped = match *t {
            TYPE_ROW => TYPE_DOCUMENT,
            TYPE_COLUMN => TYPE_ATTRIBUTE,
            TYPE_TABLE => TYPE_COLLECTION,
            _ => continue,
        };
        if resources.contains(&mapped) {
            return true;
        }
    }
    false
}

#[derive(Debug, Clone, Default)]
pub struct ResourceBase {
    pub id: String,
    pub original_id: String,
    pub sequence: String,
    pub created_at: String,
    pub updated_at: String,
    pub status: String,
    pub message: String,
    pub permissions: Vec<String>,
}

impl ResourceBase {
    #[must_use]
    pub fn new(id: impl Into<String>) -> Self {
        Self {
            id: id.into(),
            status: STATUS_PENDING.to_owned(),
            ..Self::default()
        }
    }

    pub fn set_status(&mut self, status: impl Into<String>, message: impl Into<String>) {
        self.status = status.into();
        self.message = message.into();
    }
}

/// PHP `Utopia\Migration\Resource` instance surface.
pub trait Resource: Send + Sync {
    fn get_name(&self) -> &'static str;
    fn get_group(&self) -> &'static str;
    fn base(&self) -> &ResourceBase;
    fn base_mut(&mut self) -> &mut ResourceBase;

    fn get_id(&self) -> &str {
        &self.base().id
    }
    fn set_id(&mut self, id: impl Into<String>) {
        self.base_mut().id = id.into();
    }
    fn get_original_id(&self) -> &str {
        &self.base().original_id
    }
    fn set_original_id(&mut self, id: impl Into<String>) {
        self.base_mut().original_id = id.into();
    }
    fn get_sequence(&self) -> &str {
        &self.base().sequence
    }
    fn set_sequence(&mut self, sequence: impl Into<String>) {
        self.base_mut().sequence = sequence.into();
    }
    fn get_status(&self) -> &str {
        &self.base().status
    }
    fn set_status(&mut self, status: impl Into<String>, message: impl Into<String>) {
        self.base_mut().set_status(status, message);
    }
    fn get_message(&self) -> &str {
        &self.base().message
    }
    fn get_permissions(&self) -> &[String] {
        &self.base().permissions
    }
    fn set_permissions(&mut self, permissions: Vec<String>) {
        self.base_mut().permissions = permissions;
    }
    fn get_created_at(&self) -> &str {
        &self.base().created_at
    }
    fn get_updated_at(&self) -> &str {
        &self.base().updated_at
    }
    fn set_created_at(&mut self, date: impl Into<String>) {
        self.base_mut().created_at = date.into();
    }
    fn set_updated_at(&mut self, date: impl Into<String>) {
        self.base_mut().updated_at = date.into();
    }
    fn json_serialize(&self) -> Map<String, Value> {
        let mut m = Map::new();
        m.insert("id".into(), Value::String(self.get_id().to_owned()));
        m
    }
}

/// Type-erased resource for cache / transfer.
#[derive(Clone)]
pub enum AnyResource {
    Database(crate::resources::database::Database),
    Table(crate::resources::database::Table),
    Row(crate::resources::database::Row),
    Column(crate::resources::database::Column),
    Index(crate::resources::database::Index),
    Collection(crate::resources::database::Collection),
    Document(crate::resources::database::Document),
    Attribute(crate::resources::database::Attribute),
    User(crate::resources::auth::User),
    Team(crate::resources::auth::Team),
    File(crate::resources::storage::File),
    Bucket(crate::resources::storage::Bucket),
    Function(crate::resources::functions::Func),
    Deployment(crate::resources::functions::Deployment),
    Site(crate::resources::sites::Site),
    SiteDeployment(crate::resources::sites::Deployment),
    OAuth2Provider(crate::resources::auth::OAuth2Provider),
    Generic {
        name: &'static str,
        group: &'static str,
        base: ResourceBase,
        #[allow(dead_code)]
        extra: Map<String, Value>,
    },
}

impl Resource for AnyResource {
    fn get_name(&self) -> &'static str {
        match self {
            Self::Database(r) => r.get_name(),
            Self::Table(r) => r.get_name(),
            Self::Row(r) => r.get_name(),
            Self::Column(r) => r.get_name(),
            Self::Index(r) => r.get_name(),
            Self::Collection(r) => r.get_name(),
            Self::Document(r) => r.get_name(),
            Self::Attribute(r) => r.get_name(),
            Self::User(r) => r.get_name(),
            Self::Team(r) => r.get_name(),
            Self::File(r) => r.get_name(),
            Self::Bucket(r) => r.get_name(),
            Self::Function(r) => r.get_name(),
            Self::Deployment(r) => r.get_name(),
            Self::Site(r) => r.get_name(),
            Self::SiteDeployment(r) => r.get_name(),
            Self::OAuth2Provider(r) => r.get_name(),
            Self::Generic { name, .. } => name,
        }
    }
    fn get_group(&self) -> &'static str {
        match self {
            Self::Database(r) => r.get_group(),
            Self::Table(r) => r.get_group(),
            Self::Row(r) => r.get_group(),
            Self::Column(r) => r.get_group(),
            Self::Index(r) => r.get_group(),
            Self::Collection(r) => r.get_group(),
            Self::Document(r) => r.get_group(),
            Self::Attribute(r) => r.get_group(),
            Self::User(r) => r.get_group(),
            Self::Team(r) => r.get_group(),
            Self::File(r) => r.get_group(),
            Self::Bucket(r) => r.get_group(),
            Self::Function(r) => r.get_group(),
            Self::Deployment(r) => r.get_group(),
            Self::Site(r) => r.get_group(),
            Self::SiteDeployment(r) => r.get_group(),
            Self::OAuth2Provider(r) => r.get_group(),
            Self::Generic { group, .. } => group,
        }
    }
    fn base(&self) -> &ResourceBase {
        match self {
            Self::Database(r) => r.base(),
            Self::Table(r) => r.base(),
            Self::Row(r) => r.base(),
            Self::Column(r) => r.base(),
            Self::Index(r) => r.base(),
            Self::Collection(r) => r.base(),
            Self::Document(r) => r.base(),
            Self::Attribute(r) => r.base(),
            Self::User(r) => r.base(),
            Self::Team(r) => r.base(),
            Self::File(r) => r.base(),
            Self::Bucket(r) => r.base(),
            Self::Function(r) => r.base(),
            Self::Deployment(r) => r.base(),
            Self::Site(r) => r.base(),
            Self::SiteDeployment(r) => r.base(),
            Self::OAuth2Provider(r) => r.base(),
            Self::Generic { base, .. } => base,
        }
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        match self {
            Self::Database(r) => r.base_mut(),
            Self::Table(r) => r.base_mut(),
            Self::Row(r) => r.base_mut(),
            Self::Column(r) => r.base_mut(),
            Self::Index(r) => r.base_mut(),
            Self::Collection(r) => r.base_mut(),
            Self::Document(r) => r.base_mut(),
            Self::Attribute(r) => r.base_mut(),
            Self::User(r) => r.base_mut(),
            Self::Team(r) => r.base_mut(),
            Self::File(r) => r.base_mut(),
            Self::Bucket(r) => r.base_mut(),
            Self::Function(r) => r.base_mut(),
            Self::Deployment(r) => r.base_mut(),
            Self::Site(r) => r.base_mut(),
            Self::SiteDeployment(r) => r.base_mut(),
            Self::OAuth2Provider(r) => r.base_mut(),
            Self::Generic { base, .. } => base,
        }
    }
}

macro_rules! any_from {
    ($ty:ty, $variant:ident) => {
        impl From<$ty> for AnyResource {
            fn from(r: $ty) -> Self {
                Self::$variant(r)
            }
        }
    };
}

any_from!(crate::resources::database::Database, Database);
any_from!(crate::resources::database::Table, Table);
any_from!(crate::resources::database::Row, Row);
any_from!(crate::resources::database::Column, Column);
any_from!(crate::resources::database::Index, Index);
any_from!(crate::resources::database::Collection, Collection);
any_from!(crate::resources::database::Document, Document);
any_from!(crate::resources::database::Attribute, Attribute);
any_from!(crate::resources::auth::User, User);
any_from!(crate::resources::auth::Team, Team);
any_from!(crate::resources::storage::File, File);
any_from!(crate::resources::storage::Bucket, Bucket);
any_from!(crate::resources::functions::Func, Function);
any_from!(crate::resources::functions::Deployment, Deployment);
any_from!(crate::resources::sites::Site, Site);
any_from!(crate::resources::sites::Deployment, SiteDeployment);
any_from!(crate::resources::auth::OAuth2Provider, OAuth2Provider);

impl AnyResource {
    pub fn clear_payload(&mut self) {
        match self {
            Self::File(f) => f.set_data(String::new()),
            Self::Deployment(d) => d.set_data(String::new()),
            Self::SiteDeployment(d) => d.set_data(String::new()),
            _ => {}
        }
    }
}
