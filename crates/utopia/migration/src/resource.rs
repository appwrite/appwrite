//! What a transfer moves: [`Resource`], its common state and its kinds.
//!
//! A resource is shared ([`ResourceRef`], an `Arc`): a table is held by the
//! cache, by each of its columns and rows, and by the batch being imported,
//! and a status or sequence set through one holder is seen by all, as PHP
//! objects are. The common state ([`Base`]: id, sequence, status, ...) is
//! behind a lock; the kind's data ([`Kind`]) is fixed at construction except
//! for the few fields PHP mutates (chunks of files and deployments, a
//! column's options, a collection's dimension), which carry their own lock.

use std::sync::{Arc, Mutex, MutexGuard};

use php_std::{Array, Value};

use crate::resources::auth::{AuthMethods, Hash, Membership, OAuth2Provider, Policies, Team, User};
use crate::resources::backups::Policy;
use crate::resources::database::{Database, Field, Index, Row, Table};
use crate::resources::domains::Rule;
use crate::resources::functions::{Deployment, EnvVar, Func};
use crate::resources::integrations::{ApiKey, Platform};
use crate::resources::messaging::{Message, Provider, Subscriber, Topic};
use crate::resources::settings::{Labels, ProjectVariable, Protocols, Services, Smtp, Webhook};
use crate::resources::sites::{Site, SiteDeployment, SiteEnvVar};
use crate::resources::storage::{Bucket, File};
use crate::resources::templates::EmailTemplate;
use crate::transfer::group;

/// A shared resource.
pub type ResourceRef = Arc<Resource>;

/// Resource statuses (PHP: `Resource::STATUS_*`).
pub mod status {
    /// Not processed yet.
    pub const PENDING: &str = "pending";
    /// Transferred.
    pub const SUCCESS: &str = "success";
    /// Failed.
    pub const ERROR: &str = "error";
    /// Not transferred (not requested, or already on the destination).
    pub const SKIPPED: &str = "skip";
    /// Being imported.
    pub const PROCESSING: &str = "processing";
    /// Transferred with a warning.
    pub const WARNING: &str = "warning";
    /// Kept for the transfer's own use, not transferred.
    pub const DISREGARDED: &str = "disregarded";
}

/// Resource types (PHP: `Resource::TYPE_*`).
pub mod types {
    /// A storage bucket.
    pub const BUCKET: &str = "bucket";
    /// A table.
    pub const TABLE: &str = "table";
    /// A database.
    pub const DATABASE: &str = "database";
    /// A legacy database (`type` of a database document).
    pub const DATABASE_LEGACY: &str = "legacy";
    /// A TablesDB database (`type` of a database document).
    pub const DATABASE_TABLESDB: &str = "tablesdb";
    /// A DocumentsDB database.
    pub const DATABASE_DOCUMENTSDB: &str = "documentsdb";
    /// A VectorsDB database.
    pub const DATABASE_VECTORSDB: &str = "vectorsdb";
    /// A row.
    pub const ROW: &str = "row";
    /// A file.
    pub const FILE: &str = "file";
    /// A user.
    pub const USER: &str = "user";
    /// A team.
    pub const TEAM: &str = "team";
    /// A team membership.
    pub const MEMBERSHIP: &str = "membership";
    /// A function.
    pub const FUNCTION: &str = "function";
    /// A site.
    pub const SITE: &str = "site";
    /// An index.
    pub const INDEX: &str = "index";
    /// A messaging provider.
    pub const PROVIDER: &str = "provider";
    /// A messaging topic.
    pub const TOPIC: &str = "topic";
    /// A column.
    pub const COLUMN: &str = "column";
    /// A function deployment.
    pub const DEPLOYMENT: &str = "deployment";
    /// A site deployment.
    pub const SITE_DEPLOYMENT: &str = "site-deployment";
    /// A site variable.
    pub const SITE_VARIABLE: &str = "site-variable";
    /// A password hash.
    pub const HASH: &str = "hash";
    /// The project's auth methods.
    pub const AUTH_METHODS: &str = "auth-methods";
    /// The project's auth policies.
    pub const POLICIES: &str = "policies";
    /// An OAuth2 provider (one type for every provider).
    pub const OAUTH2_PROVIDER: &str = "oauth2-provider";
    /// A function variable.
    pub const ENVIRONMENT_VARIABLE: &str = "environment-variable";
    /// A platform.
    pub const PLATFORM: &str = "platform";
    /// An API key.
    pub const API_KEY: &str = "api-key";
    /// A webhook.
    pub const WEBHOOK: &str = "webhook";
    /// The project's SMTP settings.
    pub const SMTP: &str = "smtp";
    /// A project variable.
    pub const PROJECT_VARIABLE: &str = "project-variable";
    /// The project's protocols.
    pub const PROJECT_PROTOCOLS: &str = "project-protocols";
    /// The project's labels.
    pub const PROJECT_LABELS: &str = "project-labels";
    /// The project's services.
    pub const PROJECT_SERVICES: &str = "project-services";
    /// A project email template.
    pub const PROJECT_EMAIL_TEMPLATE: &str = "project-email-template";
    /// A domain rule.
    pub const RULE: &str = "rule";
    /// A topic subscriber.
    pub const SUBSCRIBER: &str = "subscriber";
    /// A message.
    pub const MESSAGE: &str = "message";
    /// A backup policy.
    pub const BACKUP_POLICY: &str = "backup-policy";
    /// A document (legacy name of a row).
    pub const DOCUMENT: &str = "document";
    /// An attribute (legacy name of a column).
    pub const ATTRIBUTE: &str = "attribute";
    /// A collection (legacy name of a table).
    pub const COLLECTION: &str = "collection";
}

/// Every resource type (PHP: `Resource::ALL_RESOURCES`).
pub const ALL_RESOURCES: &[&str] = &[
    types::COLUMN,
    types::BUCKET,
    types::TABLE,
    types::DATABASE,
    types::DATABASE_VECTORSDB,
    types::DATABASE_DOCUMENTSDB,
    types::ROW,
    types::FILE,
    types::FUNCTION,
    types::DEPLOYMENT,
    types::SITE,
    types::SITE_DEPLOYMENT,
    types::SITE_VARIABLE,
    types::HASH,
    types::INDEX,
    types::USER,
    types::ENVIRONMENT_VARIABLE,
    types::TEAM,
    types::MEMBERSHIP,
    types::AUTH_METHODS,
    types::POLICIES,
    types::OAUTH2_PROVIDER,
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
    types::PROVIDER,
    types::TOPIC,
    types::SUBSCRIBER,
    types::MESSAGE,
    types::BACKUP_POLICY,
    types::DOCUMENT,
    types::ATTRIBUTE,
    types::COLLECTION,
];

/// The resource names of one database family: its entity (table or
/// collection), field (column or attribute) and record (row or document).
///
/// PHP: an entry of `Resource::DATABASE_TYPE_RESOURCE_MAP`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct DatabaseResources {
    /// The database type (the map's key).
    pub database: &'static str,
    /// The entity type.
    pub entity: &'static str,
    /// The field type.
    pub field: &'static str,
    /// The record type.
    pub record: &'static str,
}

/// `Resource::DATABASE_TYPE_RESOURCE_MAP`, in its order.
pub const DATABASE_TYPE_RESOURCE_MAP: &[DatabaseResources] = &[
    DatabaseResources { database: types::DATABASE, entity: types::TABLE, field: types::COLUMN, record: types::ROW },
    DatabaseResources {
        database: types::DATABASE_DOCUMENTSDB,
        entity: types::COLLECTION,
        field: types::ATTRIBUTE,
        record: types::DOCUMENT,
    },
    DatabaseResources {
        database: types::DATABASE_VECTORSDB,
        entity: types::COLLECTION,
        field: types::ATTRIBUTE,
        record: types::DOCUMENT,
    },
];

/// The map entry of database type `database`.
pub fn database_resources(database: &str) -> Option<&'static DatabaseResources> {
    DATABASE_TYPE_RESOURCE_MAP.iter().find(|d| d.database == database)
}

/// The resource names scoped to one entity type: field, record and index.
///
/// PHP: an entry of `Resource::ENTITY_TYPE_RESOURCE_MAP`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct EntityResources {
    /// The entity type (the map's key).
    pub entity: &'static str,
    /// The field type.
    pub field: &'static str,
    /// The record type.
    pub record: &'static str,
    /// The index type.
    pub index: &'static str,
}

/// `Resource::ENTITY_TYPE_RESOURCE_MAP`, in its order.
pub const ENTITY_TYPE_RESOURCE_MAP: &[EntityResources] = &[
    EntityResources { entity: types::TABLE, field: types::COLUMN, record: types::ROW, index: types::INDEX },
    EntityResources {
        entity: types::COLLECTION,
        field: types::ATTRIBUTE,
        record: types::DOCUMENT,
        index: types::INDEX,
    },
];

/// The legacy name of a type, when it has one (`Resource::TYPE_MAP`).
fn legacy_name(t: &str) -> Option<&'static str> {
    match t {
        types::ROW => Some(types::DOCUMENT),
        types::COLUMN => Some(types::ATTRIBUTE),
        types::TABLE => Some(types::COLLECTION),
        _ => None,
    }
}

/// Whether any of `types` (or its legacy name) is in `resources`.
///
/// PHP: `Resource::isSupported($types, $resources)`.
pub fn is_supported<T: AsRef<str>, R: AsRef<str>>(types: &[T], resources: &[R]) -> bool {
    types.iter().any(|t| {
        let t = t.as_ref();
        resources.iter().any(|r| {
            let r = r.as_ref();
            r == t || legacy_name(t) == Some(r)
        })
    })
}

/// The state every resource has.
#[derive(Debug, Clone, PartialEq)]
pub struct Base {
    /// The id.
    pub id: String,
    /// The id the resource had on its source, when it changed.
    pub original_id: String,
    /// The cache sequence (set when the resource is first cached), or the
    /// destination's internal id once imported.
    pub sequence: String,
    /// When the resource was created on its source.
    pub created_at: String,
    /// When the resource was last updated on its source.
    pub updated_at: String,
    /// The transfer status ([`status`]).
    pub status: String,
    /// Why the resource has its status.
    pub message: String,
    /// Permission strings.
    pub permissions: Vec<String>,
}

impl Default for Base {
    fn default() -> Self {
        Self {
            id: String::new(),
            original_id: String::new(),
            sequence: String::new(),
            created_at: String::new(),
            updated_at: String::new(),
            status: status::PENDING.to_owned(),
            message: String::new(),
            permissions: Vec::new(),
        }
    }
}

/// The data of a resource of a type this crate does not define, added by a
/// consumer (PHP lets one extend `Resource`).
pub trait CustomResource: std::fmt::Debug + Send + Sync {
    /// The resource type (`getName()`).
    fn name(&self) -> &str;
    /// The resource group (`getGroup()`).
    fn group(&self) -> &str;
    /// The PHP class name the resource reports.
    fn class(&self) -> &str;
    /// The JSON form (`jsonSerialize()`), given the common state.
    fn json(&self, base: &Base) -> Value;
}

/// What a resource is: its type and the data of that type.
#[derive(Debug)]
#[non_exhaustive]
pub enum Kind {
    /// `Auth\AuthMethods`.
    AuthMethods(AuthMethods),
    /// `Auth\Hash`.
    Hash(Hash),
    /// `Auth\Membership`.
    Membership(Membership),
    /// `Auth\OAuth2\OAuth2Provider`.
    OAuth2Provider(OAuth2Provider),
    /// `Auth\Policies`.
    Policies(Policies),
    /// `Auth\Team`.
    Team(Team),
    /// `Auth\User`.
    User(User),
    /// `Backups\Policy`.
    BackupPolicy(Policy),
    /// `Database\Database`, `Database\DocumentsDB`, `Database\VectorsDB`.
    Database(Database),
    /// `Database\Table` and `Database\Collection`.
    Table(Table),
    /// `Database\Column` subclasses and `Database\Attribute`.
    Field(Field),
    /// `Database\Index`.
    Index(Index),
    /// `Database\Row` and `Database\Document`.
    Row(Row),
    /// `Domains\Rule`.
    Rule(Rule),
    /// `Functions\Deployment`.
    Deployment(Deployment),
    /// `Functions\EnvVar`.
    EnvVar(EnvVar),
    /// `Functions\Func`.
    Function(Func),
    /// `Integrations\ApiKey`.
    ApiKey(ApiKey),
    /// `Integrations\Platform`.
    Platform(Platform),
    /// `Messaging\Message`.
    Message(Message),
    /// `Messaging\Provider`.
    Provider(Provider),
    /// `Messaging\Subscriber`.
    Subscriber(Subscriber),
    /// `Messaging\Topic`.
    Topic(Topic),
    /// `Settings\Labels`.
    Labels(Labels),
    /// `Settings\ProjectVariable`.
    ProjectVariable(ProjectVariable),
    /// `Settings\Protocols`.
    Protocols(Protocols),
    /// `Settings\Services`.
    Services(Services),
    /// `Settings\SMTP`.
    Smtp(Smtp),
    /// `Settings\Webhook`.
    Webhook(Webhook),
    /// `Sites\Deployment`.
    SiteDeployment(SiteDeployment),
    /// `Sites\EnvVar`.
    SiteEnvVar(SiteEnvVar),
    /// `Sites\Site`.
    Site(Site),
    /// `Storage\Bucket`.
    Bucket(Bucket),
    /// `Storage\File`.
    File(File),
    /// `Templates\EmailTemplate`.
    EmailTemplate(EmailTemplate),
    /// A consumer's own resource type.
    Custom(Box<dyn CustomResource>),
}

/// One resource: its common state and its kind.
///
/// PHP: `Utopia\Migration\Resource` and its subclasses.
#[derive(Debug)]
pub struct Resource {
    base: Mutex<Base>,
    kind: Kind,
}

impl Resource {
    /// A resource of `kind` with `base` state.
    pub fn new(kind: Kind, base: Base) -> ResourceRef {
        Arc::new(Self { base: Mutex::new(base), kind })
    }

    /// A resource of `kind` with id `id` and default state.
    pub fn with_id(kind: Kind, id: impl Into<String>) -> ResourceRef {
        Self::new(kind, Base { id: id.into(), ..Base::default() })
    }

    /// The kind and its data.
    pub fn kind(&self) -> &Kind {
        &self.kind
    }

    /// The common state, locked.
    pub fn base(&self) -> MutexGuard<'_, Base> {
        self.base.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// A copy of the common state.
    pub fn base_snapshot(&self) -> Base {
        self.base().clone()
    }

    /// The id.
    pub fn id(&self) -> String {
        self.base().id.clone()
    }

    /// Sets the id.
    pub fn set_id(&self, id: impl Into<String>) {
        self.base().id = id.into();
    }

    /// The id on the source.
    pub fn original_id(&self) -> String {
        self.base().original_id.clone()
    }

    /// Sets the id on the source.
    pub fn set_original_id(&self, id: impl Into<String>) {
        self.base().original_id = id.into();
    }

    /// The sequence.
    pub fn sequence(&self) -> String {
        self.base().sequence.clone()
    }

    /// Sets the sequence.
    pub fn set_sequence(&self, sequence: impl Into<String>) {
        self.base().sequence = sequence.into();
    }

    /// The status.
    pub fn status(&self) -> String {
        self.base().status.clone()
    }

    /// Sets the status and its message.
    ///
    /// PHP: `setStatus($status, $message = '')`.
    pub fn set_status(&self, status: impl Into<String>, message: impl Into<String>) {
        let mut b = self.base();
        b.status = status.into();
        b.message = message.into();
    }

    /// The status message.
    pub fn message(&self) -> String {
        self.base().message.clone()
    }

    /// Sets the status message.
    pub fn set_message(&self, message: impl Into<String>) {
        self.base().message = message.into();
    }

    /// The permissions.
    pub fn permissions(&self) -> Vec<String> {
        self.base().permissions.clone()
    }

    /// Sets the permissions.
    pub fn set_permissions(&self, permissions: Vec<String>) {
        self.base().permissions = permissions;
    }

    /// When the resource was created on its source.
    pub fn created_at(&self) -> String {
        self.base().created_at.clone()
    }

    /// Sets when the resource was created.
    pub fn set_created_at(&self, date: impl Into<String>) {
        self.base().created_at = date.into();
    }

    /// When the resource was last updated on its source.
    pub fn updated_at(&self) -> String {
        self.base().updated_at.clone()
    }

    /// Sets when the resource was last updated.
    pub fn set_updated_at(&self, date: impl Into<String>) {
        self.base().updated_at = date.into();
    }

    /// The resource type ([`types`]).
    ///
    /// PHP: `getName()` (static on each class).
    pub fn name(&self) -> &str {
        match &self.kind {
            Kind::AuthMethods(_) => types::AUTH_METHODS,
            Kind::Hash(_) => types::HASH,
            Kind::Membership(_) => types::MEMBERSHIP,
            Kind::OAuth2Provider(_) => types::OAUTH2_PROVIDER,
            Kind::Policies(_) => types::POLICIES,
            Kind::Team(_) => types::TEAM,
            Kind::User(_) => types::USER,
            Kind::BackupPolicy(_) => types::BACKUP_POLICY,
            Kind::Database(d) => d.flavor.name(),
            Kind::Table(t) => {
                if t.collection {
                    types::COLLECTION
                } else {
                    types::TABLE
                }
            }
            Kind::Field(f) => {
                if f.is_attribute() {
                    types::ATTRIBUTE
                } else {
                    types::COLUMN
                }
            }
            Kind::Index(_) => types::INDEX,
            Kind::Row(r) => {
                if r.document {
                    types::DOCUMENT
                } else {
                    types::ROW
                }
            }
            Kind::Rule(_) => types::RULE,
            Kind::Deployment(_) => types::DEPLOYMENT,
            Kind::EnvVar(_) => types::ENVIRONMENT_VARIABLE,
            Kind::Function(_) => types::FUNCTION,
            Kind::ApiKey(_) => types::API_KEY,
            Kind::Platform(_) => types::PLATFORM,
            Kind::Message(_) => types::MESSAGE,
            Kind::Provider(_) => types::PROVIDER,
            Kind::Subscriber(_) => types::SUBSCRIBER,
            Kind::Topic(_) => types::TOPIC,
            Kind::Labels(_) => types::PROJECT_LABELS,
            Kind::ProjectVariable(_) => types::PROJECT_VARIABLE,
            Kind::Protocols(_) => types::PROJECT_PROTOCOLS,
            Kind::Services(_) => types::PROJECT_SERVICES,
            Kind::Smtp(_) => types::SMTP,
            Kind::Webhook(_) => types::WEBHOOK,
            Kind::SiteDeployment(_) => types::SITE_DEPLOYMENT,
            Kind::SiteEnvVar(_) => types::SITE_VARIABLE,
            Kind::Site(_) => types::SITE,
            Kind::Bucket(_) => types::BUCKET,
            Kind::File(_) => types::FILE,
            Kind::EmailTemplate(_) => types::PROJECT_EMAIL_TEMPLATE,
            Kind::Custom(c) => c.name(),
        }
    }

    /// The resource group ([`group`]).
    ///
    /// PHP: `getGroup()`.
    pub fn group(&self) -> &str {
        match &self.kind {
            Kind::AuthMethods(_)
            | Kind::Hash(_)
            | Kind::Membership(_)
            | Kind::OAuth2Provider(_)
            | Kind::Policies(_)
            | Kind::Team(_)
            | Kind::User(_) => group::AUTH,
            Kind::BackupPolicy(_) => group::BACKUPS,
            Kind::Database(_) | Kind::Table(_) | Kind::Field(_) | Kind::Index(_) | Kind::Row(_) => group::DATABASES,
            Kind::Rule(_) => group::DOMAINS,
            Kind::Deployment(_) | Kind::EnvVar(_) | Kind::Function(_) => group::FUNCTIONS,
            Kind::ApiKey(_) | Kind::Platform(_) | Kind::Smtp(_) | Kind::Webhook(_) => group::INTEGRATIONS,
            Kind::Message(_) | Kind::Provider(_) | Kind::Subscriber(_) | Kind::Topic(_) => group::MESSAGING,
            Kind::Labels(_)
            | Kind::ProjectVariable(_)
            | Kind::Protocols(_)
            | Kind::Services(_)
            | Kind::EmailTemplate(_) => group::PROJECTS,
            Kind::SiteDeployment(_) | Kind::SiteEnvVar(_) | Kind::Site(_) => group::SITES,
            Kind::Bucket(_) | Kind::File(_) => group::STORAGE,
            Kind::Custom(c) => c.group(),
        }
    }

    /// The PHP class of the resource (`$resource::class`).
    pub fn class(&self) -> &str {
        const NS: &str = "Utopia\\Migration\\Resources\\";
        let _ = NS;
        match &self.kind {
            Kind::AuthMethods(_) => "Utopia\\Migration\\Resources\\Auth\\AuthMethods",
            Kind::Hash(_) => "Utopia\\Migration\\Resources\\Auth\\Hash",
            Kind::Membership(_) => "Utopia\\Migration\\Resources\\Auth\\Membership",
            Kind::OAuth2Provider(_) => "Utopia\\Migration\\Resources\\Auth\\OAuth2\\OAuth2Provider",
            Kind::Policies(_) => "Utopia\\Migration\\Resources\\Auth\\Policies",
            Kind::Team(_) => "Utopia\\Migration\\Resources\\Auth\\Team",
            Kind::User(_) => "Utopia\\Migration\\Resources\\Auth\\User",
            Kind::BackupPolicy(_) => "Utopia\\Migration\\Resources\\Backups\\Policy",
            Kind::Database(d) => d.flavor.class(),
            Kind::Table(t) => {
                if t.collection {
                    "Utopia\\Migration\\Resources\\Database\\Collection"
                } else {
                    "Utopia\\Migration\\Resources\\Database\\Table"
                }
            }
            Kind::Field(f) => f.class.php_class(),
            Kind::Index(_) => "Utopia\\Migration\\Resources\\Database\\Index",
            Kind::Row(r) => {
                if r.document {
                    "Utopia\\Migration\\Resources\\Database\\Document"
                } else {
                    "Utopia\\Migration\\Resources\\Database\\Row"
                }
            }
            Kind::Rule(_) => "Utopia\\Migration\\Resources\\Domains\\Rule",
            Kind::Deployment(_) => "Utopia\\Migration\\Resources\\Functions\\Deployment",
            Kind::EnvVar(_) => "Utopia\\Migration\\Resources\\Functions\\EnvVar",
            Kind::Function(_) => "Utopia\\Migration\\Resources\\Functions\\Func",
            Kind::ApiKey(_) => "Utopia\\Migration\\Resources\\Integrations\\ApiKey",
            Kind::Platform(_) => "Utopia\\Migration\\Resources\\Integrations\\Platform",
            Kind::Message(_) => "Utopia\\Migration\\Resources\\Messaging\\Message",
            Kind::Provider(_) => "Utopia\\Migration\\Resources\\Messaging\\Provider",
            Kind::Subscriber(_) => "Utopia\\Migration\\Resources\\Messaging\\Subscriber",
            Kind::Topic(_) => "Utopia\\Migration\\Resources\\Messaging\\Topic",
            Kind::Labels(_) => "Utopia\\Migration\\Resources\\Settings\\Labels",
            Kind::ProjectVariable(_) => "Utopia\\Migration\\Resources\\Settings\\ProjectVariable",
            Kind::Protocols(_) => "Utopia\\Migration\\Resources\\Settings\\Protocols",
            Kind::Services(_) => "Utopia\\Migration\\Resources\\Settings\\Services",
            Kind::Smtp(_) => "Utopia\\Migration\\Resources\\Settings\\SMTP",
            Kind::Webhook(_) => "Utopia\\Migration\\Resources\\Settings\\Webhook",
            Kind::SiteDeployment(_) => "Utopia\\Migration\\Resources\\Sites\\Deployment",
            Kind::SiteEnvVar(_) => "Utopia\\Migration\\Resources\\Sites\\EnvVar",
            Kind::Site(_) => "Utopia\\Migration\\Resources\\Sites\\Site",
            Kind::Bucket(_) => "Utopia\\Migration\\Resources\\Storage\\Bucket",
            Kind::File(_) => "Utopia\\Migration\\Resources\\Storage\\File",
            Kind::EmailTemplate(_) => "Utopia\\Migration\\Resources\\Templates\\EmailTemplate",
            Kind::Custom(c) => c.class(),
        }
    }

    /// The JSON form of the resource (nested resources in their JSON form).
    ///
    /// PHP: `jsonSerialize()`.
    pub fn json(&self) -> Value {
        let base = self.base_snapshot();
        let mut a = Array::new();
        let mut put = |k: &str, v: Value| {
            a.set(k.into(), v);
        };
        macro_rules! s {
            ($v:expr) => {
                Value::from($v.as_str())
            };
        }
        match &self.kind {
            Kind::AuthMethods(m) => {
                put("id", s!(base.id));
                put("emailPassword", Value::Bool(m.email_password));
                put("magicURL", Value::Bool(m.magic_url));
                put("emailOtp", Value::Bool(m.email_otp));
                put("anonymous", Value::Bool(m.anonymous));
                put("invites", Value::Bool(m.invites));
                put("jwt", Value::Bool(m.jwt));
                put("phone", Value::Bool(m.phone));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Hash(h) => {
                put("hash", s!(h.hash));
                put("salt", s!(h.salt));
                put("algorithm", s!(h.algorithm));
                put("separator", s!(h.separator));
                put("signingKey", s!(h.signing_key));
                put("passwordCpu", Value::Int(h.password_cpu));
                put("passwordMemory", Value::Int(h.password_memory));
                put("passwordParallel", Value::Int(h.password_parallel));
                put("passwordLength", Value::Int(h.password_length));
            }
            Kind::Membership(m) => {
                put("id", s!(base.id));
                put("team", m.team.json());
                put("user", m.user.json());
                put("roles", Value::Array(m.roles.clone()));
                put("active", Value::Bool(m.active));
            }
            Kind::OAuth2Provider(p) => {
                put("id", s!(base.id));
                put("providerKey", s!(p.provider_key));
                put("enabled", Value::Bool(p.enabled));
                put("settings", Value::Array(p.settings.clone()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Policies(p) => {
                put("id", s!(base.id));
                put("passwordHistory", Value::Int(p.password_history));
                put("sessionDuration", Value::Int(p.session_duration));
                put("sessionsLimit", Value::Int(p.sessions_limit));
                put("userLimit", Value::Int(p.user_limit));
                put("passwordDictionary", Value::Bool(p.password_dictionary));
                put("personalDataCheck", Value::Bool(p.personal_data_check));
                put("sessionAlerts", Value::Bool(p.session_alerts));
                put("sessionInvalidation", Value::Bool(p.session_invalidation));
                put("membershipsUserId", Value::Bool(p.memberships_user_id));
                put("membershipsUserEmail", Value::Bool(p.memberships_user_email));
                put("membershipsUserName", Value::Bool(p.memberships_user_name));
                put("membershipsUserMfa", Value::Bool(p.memberships_user_mfa));
                put("membershipsUserPhone", Value::Bool(p.memberships_user_phone));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Team(t) => {
                put("id", s!(base.id));
                put("name", s!(t.name));
                put("preferences", Value::Array(t.preferences.clone()));
                put("members", Value::Array(t.members.clone()));
            }
            Kind::User(u) => {
                put("id", s!(base.id));
                put("email", opt_str(&u.email));
                put("username", opt_str(&u.username));
                put("passwordHash", u.password_hash.as_ref().map_or(Value::Null, |h| h.json()));
                put("phone", opt_str(&u.phone));
                put("labels", Value::Array(u.labels.clone()));
                put("oauthProvider", s!(u.oauth_provider));
                put("emailVerified", Value::Bool(u.email_verified));
                put("phoneVerified", Value::Bool(u.phone_verified));
                put("disabled", Value::Bool(u.disabled));
                put("preferences", Value::Array(u.preferences.clone()));
                put("targets", Value::Array(u.targets.clone()));
            }
            Kind::BackupPolicy(p) => {
                put("id", s!(base.id));
                put("name", s!(p.name));
                put("services", Value::Array(p.services.clone()));
                put("retention", Value::Int(p.retention));
                put("schedule", s!(p.schedule));
                put("enabled", Value::Bool(p.enabled));
                put("resourceId", s!(p.resource_id));
                put("resourceType", s!(p.resource_type));
            }
            Kind::Database(d) => {
                put("id", s!(base.id));
                put("name", s!(d.name));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
                put("enabled", Value::Bool(d.enabled));
                put("type", s!(d.database_type));
                put("database", opt_str(&d.database));
                put("status", opt_str(&d.status));
            }
            Kind::Table(t) => {
                put("database", t.database.json());
                put("id", s!(base.id));
                put("name", s!(t.name));
                put("rowSecurity", Value::Bool(t.row_security));
                put("permissions", str_list(&base.permissions));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
                put("enabled", Value::Bool(t.enabled));
                if t.collection
                    && let Some(d) = t.dimension()
                {
                    put("dimension", Value::Int(d));
                }
            }
            Kind::Field(f) => {
                put("key", s!(f.key));
                put("table", f.table.json());
                put("type", Value::from(f.field_type()));
                put("size", Value::Int(f.size));
                put("required", Value::Bool(f.required));
                put("default", f.default.clone());
                put("array", Value::Bool(f.array));
                put("signed", Value::Bool(f.signed));
                put("format", s!(f.format));
                put("formatOptions", Value::Array(f.format_options.clone()));
                put("filters", str_list(&f.filters));
                put("options", Value::Array(f.options()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Index(i) => {
                put("id", s!(base.id));
                put("key", s!(i.key));
                put("table", i.table.json());
                put("type", s!(i.index_type));
                put("columns", Value::Array(i.columns.clone()));
                put("lengths", Value::Array(i.lengths.clone()));
                put("orders", Value::Array(i.orders.clone()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Row(r) => {
                put("id", s!(base.id));
                put("table", r.table.json());
                put("data", Value::Array(r.data.clone()));
                put("permissions", str_list(&base.permissions));
            }
            Kind::Rule(r) => {
                put("id", s!(base.id));
                put("domain", s!(r.domain));
                put("type", s!(r.rule_type));
                put("trigger", s!(r.trigger));
                put("redirectUrl", s!(r.redirect_url));
                put("redirectStatusCode", Value::Int(r.redirect_status_code));
                put("deploymentResourceType", s!(r.deployment_resource_type));
                put("deploymentResourceId", s!(r.deployment_resource_id));
                put("deploymentVcsProviderBranch", s!(r.deployment_vcs_provider_branch));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Deployment(d) => {
                let c = d.chunk();
                put("id", s!(base.id));
                put("func", d.func.json());
                put("size", Value::Int(d.size));
                put("entrypoint", s!(d.entrypoint));
                put("start", Value::Int(c.start));
                put("end", Value::Int(c.end));
                put("data", Value::Str(php_std::Str::from(c.data.clone())));
                put("activated", Value::Bool(d.activated));
            }
            Kind::EnvVar(v) => {
                put("id", s!(base.id));
                put("func", v.func.json());
                put("key", s!(v.key));
                put("value", s!(v.value));
            }
            Kind::Function(f) => {
                put("id", s!(base.id));
                put("name", s!(f.name));
                put("execute", Value::Array(f.execute.clone()));
                put("enabled", Value::Bool(f.enabled));
                put("runtime", s!(f.runtime));
                put("events", Value::Array(f.events.clone()));
                put("schedule", s!(f.schedule));
                put("timeout", Value::Int(f.timeout));
                put("activeDeployment", s!(f.active_deployment));
                put("entrypoint", s!(f.entrypoint));
                put("commands", s!(f.commands));
                put("logging", Value::Bool(f.logging));
                put("scopes", Value::Array(f.scopes.clone()));
                put("specification", s!(f.specification));
                put("runtimeSpecification", s!(f.specification));
                put("buildSpecification", s!(f.build_specification));
            }
            Kind::ApiKey(k) => {
                put("id", s!(base.id));
                put("name", s!(k.name));
                put("scopes", Value::Array(k.scopes.clone()));
                put("expire", s!(k.expire));
                put("accessedAt", s!(k.accessed_at));
                put("sdks", Value::Array(k.sdks.clone()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Platform(p) => {
                put("id", s!(base.id));
                put("type", s!(p.platform_type));
                put("name", s!(p.name));
                put("key", s!(p.key));
                put("store", s!(p.store));
                put("hostname", s!(p.hostname));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Message(m) => {
                put("id", s!(base.id));
                put("providerType", s!(m.provider_type));
                put("topics", Value::Array(m.topics.clone()));
                put("users", Value::Array(m.users.clone()));
                put("targets", Value::Array(m.targets.clone()));
                put("data", Value::Array(m.data.clone()));
                put("messageStatus", s!(m.message_status));
                put("scheduledAt", s!(m.scheduled_at));
                put("deliveredAt", s!(m.delivered_at));
                put("deliveryErrors", Value::Array(m.delivery_errors.clone()));
                put("deliveredTotal", Value::Int(m.delivered_total));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Provider(p) => {
                put("id", s!(base.id));
                put("name", s!(p.name));
                put("provider", s!(p.provider));
                put("type", s!(p.provider_type));
                put("enabled", Value::Bool(p.enabled));
                put("credentials", Value::Array(p.credentials.clone()));
                put("options", Value::Array(p.options.clone()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Subscriber(sb) => {
                put("id", s!(base.id));
                put("topicId", s!(sb.topic_id));
                put("targetId", s!(sb.target_id));
                put("userId", s!(sb.user_id));
                put("userName", s!(sb.user_name));
                put("providerType", s!(sb.provider_type));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Topic(t) => {
                put("id", s!(base.id));
                put("name", s!(t.name));
                put("subscribe", Value::Array(t.subscribe.clone()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Labels(l) => {
                put("id", s!(base.id));
                put("labels", Value::Array(l.labels.clone()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::ProjectVariable(v) => {
                put("id", s!(base.id));
                put("key", s!(v.key));
                put("value", s!(v.value));
                put("secret", Value::Bool(v.secret));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Protocols(p) => {
                put("id", s!(base.id));
                put("rest", Value::Bool(p.rest));
                put("graphql", Value::Bool(p.graphql));
                put("websocket", Value::Bool(p.websocket));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Services(sv) => {
                put("id", s!(base.id));
                put("services", Value::Array(sv.services.clone()));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Smtp(m) => {
                put("id", s!(base.id));
                put("enabled", Value::Bool(m.enabled));
                put("senderName", s!(m.sender_name));
                put("senderEmail", s!(m.sender_email));
                put("replyToName", s!(m.reply_to_name));
                put("replyToEmail", s!(m.reply_to_email));
                put("host", s!(m.host));
                put("port", Value::Int(m.port));
                put("username", s!(m.username));
                put("secure", s!(m.secure));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Webhook(w) => {
                put("id", s!(base.id));
                put("name", s!(w.name));
                put("url", s!(w.url));
                put("events", Value::Array(w.events.clone()));
                put("security", Value::Bool(w.security));
                put("httpUser", s!(w.http_user));
                put("httpPass", s!(w.http_pass));
                put("enabled", Value::Bool(w.enabled));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::SiteDeployment(d) => {
                let c = d.chunk();
                put("id", s!(base.id));
                put("site", d.site.json());
                put("size", Value::Int(d.size));
                put("start", Value::Int(c.start));
                put("end", Value::Int(c.end));
                put("data", Value::Str(php_std::Str::from(c.data.clone())));
                put("activated", Value::Bool(d.activated));
            }
            Kind::SiteEnvVar(v) => {
                put("id", s!(base.id));
                put("site", v.site.json());
                put("key", s!(v.key));
                put("value", s!(v.value));
            }
            Kind::Site(st) => {
                put("id", s!(base.id));
                put("name", s!(st.name));
                put("framework", s!(st.framework));
                put("buildRuntime", s!(st.build_runtime));
                put("enabled", Value::Bool(st.enabled));
                put("logging", Value::Bool(st.logging));
                put("timeout", Value::Int(st.timeout));
                put("installCommand", s!(st.install_command));
                put("buildCommand", s!(st.build_command));
                put("outputDirectory", s!(st.output_directory));
                put("adapter", s!(st.adapter));
                put("fallbackFile", s!(st.fallback_file));
                put("specification", s!(st.specification));
                put("runtimeSpecification", s!(st.specification));
                put("activeDeployment", s!(st.active_deployment));
                put("buildSpecification", s!(st.build_specification));
            }
            Kind::Bucket(b) => {
                put("id", s!(base.id));
                put("name", s!(b.name));
                put("fileSecurity", Value::Bool(b.file_security));
                put("enabled", Value::Bool(b.enabled));
                put("maxFileSize", b.max_file_size.map_or(Value::Null, Value::Int));
                put("allowedFileExtensions", Value::Array(b.allowed_file_extensions.clone()));
                put("compression", s!(b.compression));
                put("encryption", Value::Bool(b.encryption));
                put("antiVirus", Value::Bool(b.anti_virus));
                put("updateLimits", Value::Bool(b.update_limits));
                put("transformations", Value::Bool(b.transformations));
            }
            Kind::File(f) => {
                let c = f.chunk();
                put("id", s!(base.id));
                put("bucket", f.bucket.json());
                put("name", s!(f.name));
                put("signature", s!(f.signature));
                put("mimeType", s!(f.mime_type));
                put("permissions", str_list(&base.permissions));
                put("size", Value::Int(f.size));
                put("start", Value::Int(c.start));
                put("end", Value::Int(c.end));
            }
            Kind::EmailTemplate(t) => {
                put("id", s!(base.id));
                put("templateId", s!(t.template_id));
                put("locale", s!(t.locale));
                put("subject", s!(t.subject));
                put("message", s!(t.body));
                put("senderName", s!(t.sender_name));
                put("senderEmail", s!(t.sender_email));
                put("replyToEmail", s!(t.reply_to_email));
                put("replyToName", s!(t.reply_to_name));
                put("createdAt", s!(base.created_at));
                put("updatedAt", s!(base.updated_at));
            }
            Kind::Custom(c) => return c.json(&base),
        }
        Value::Array(a)
    }

    /// The table of a column, attribute, index or row.
    pub fn table(&self) -> Option<&ResourceRef> {
        match &self.kind {
            Kind::Field(f) => Some(&f.table),
            Kind::Index(i) => Some(&i.table),
            Kind::Row(r) => Some(&r.table),
            _ => None,
        }
    }

    /// The database of a table or collection.
    pub fn database(&self) -> Option<&ResourceRef> {
        match &self.kind {
            Kind::Table(t) => Some(&t.database),
            _ => None,
        }
    }

    /// The database data of a database resource.
    pub fn as_database(&self) -> Option<&Database> {
        match &self.kind {
            Kind::Database(d) => Some(d),
            _ => None,
        }
    }

    /// The table data of a table or collection.
    pub fn as_table(&self) -> Option<&Table> {
        match &self.kind {
            Kind::Table(t) => Some(t),
            _ => None,
        }
    }

    /// The field data of a column or attribute.
    pub fn as_field(&self) -> Option<&Field> {
        match &self.kind {
            Kind::Field(f) => Some(f),
            _ => None,
        }
    }

    /// The row data of a row or document.
    pub fn as_row(&self) -> Option<&Row> {
        match &self.kind {
            Kind::Row(r) => Some(r),
            _ => None,
        }
    }

    /// Clears the chunk data of a file or deployment (the cache does so to
    /// keep memory bounded).
    pub fn clear_data(&self) {
        match &self.kind {
            Kind::File(f) => f.chunk_mut().data = bytes::Bytes::new(),
            Kind::Deployment(d) => d.chunk_mut().data = bytes::Bytes::new(),
            Kind::SiteDeployment(d) => d.chunk_mut().data = bytes::Bytes::new(),
            _ => {}
        }
    }

    /// PHP's loose object comparison (`==`): same class, equal state.
    pub fn loose_eq(&self, other: &Resource) -> bool {
        if std::ptr::eq(self, other) {
            return true;
        }
        self.class() == other.class() && self.base_snapshot() == other.base_snapshot() && {
            let (a, b) = (self.json(), other.json());
            a.loose_eq(&b)
        }
    }
}

/// `null` or the string.
pub(crate) fn opt_str(v: &Option<String>) -> Value {
    v.as_deref().map_or(Value::Null, Value::from)
}

/// A list of strings as a PHP list.
pub(crate) fn str_list(v: &[String]) -> Value {
    Value::Array(v.iter().map(|s| Value::from(s.as_str())).collect())
}

/// A chunk of a file or deployment being transferred.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Chunk {
    /// The offset of the first byte.
    pub start: i64,
    /// The offset of the last byte.
    pub end: i64,
    /// The bytes.
    pub data: bytes::Bytes,
}

/// Locks a mutex, ignoring poisoning.
pub(crate) fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}
