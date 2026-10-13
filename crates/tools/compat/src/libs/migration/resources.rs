//! Resources: construction by class and named arguments (PHP `new $class(...)`),
//! `fromArray()`, and every getter (Values::describe).

use bytes::Bytes;
use php_std::{Array, Value};
use serde_json::{Map, Value as Json};
use utopia_migration::resource::{Base, Chunk, Kind, Resource, ResourceRef};
use utopia_migration::resources::auth::{AuthMethods, Hash, Membership, OAuth2Provider, Policies, Team, User};
use utopia_migration::resources::backups::Policy;
use utopia_migration::resources::database::{
    Database, DatabaseFlavor, Field, FieldClass, FieldSpec, Index, Row, Table, columns,
};
use utopia_migration::resources::domains::Rule;
use utopia_migration::resources::functions::{Deployment, EnvVar, Func};
use utopia_migration::resources::integrations::{ApiKey, Platform};
use utopia_migration::resources::messaging::{Message, Provider, Subscriber, Topic};
use utopia_migration::resources::settings::{Labels, ProjectVariable, Protocols, Services, Smtp, Webhook};
use utopia_migration::resources::sites::{Site, SiteDeployment, SiteEnvVar};
use utopia_migration::resources::storage::{Bucket, File};
use utopia_migration::resources::templates::EmailTemplate;

use super::codec::{decode, decode_array, encode, text};
use crate::adapter::{Fault, Session};

/// Named constructor arguments.
pub struct Named<'a> {
    map: &'a Map<String, Json>,
    session: &'a Session,
}

impl<'a> Named<'a> {
    pub fn new(json: &'a Json, session: &'a Session) -> Result<Self, Fault> {
        static EMPTY: std::sync::LazyLock<Map<String, Json>> = std::sync::LazyLock::new(Map::new);
        match json {
            Json::Object(map) => Ok(Self { map, session }),
            Json::Null => Ok(Self { map: &EMPTY, session }),
            Json::Array(a) if a.is_empty() => Ok(Self { map: &EMPTY, session }),
            other => Err(Fault::new(format!("expected named arguments, got {other}"))),
        }
    }

    fn get(&self, name: &str) -> Option<&'a Json> {
        self.map.get(name)
    }

    pub fn string(&self, name: &str, default: &str) -> Result<String, Fault> {
        match self.get(name) {
            None => Ok(default.to_owned()),
            Some(v) => Ok(text(&decode(v)?)),
        }
    }

    pub fn required_string(&self, name: &str) -> Result<String, Fault> {
        self.get(name)
            .map(|v| decode(v).map(|v| text(&v)))
            .ok_or_else(|| Fault::new(format!("missing argument `{name}`")))?
    }

    pub fn opt_string(&self, name: &str, default: Option<&str>) -> Result<Option<String>, Fault> {
        match self.get(name) {
            None => Ok(default.map(str::to_owned)),
            Some(Json::Null) => Ok(None),
            Some(v) => Ok(Some(text(&decode(v)?))),
        }
    }

    pub fn bool(&self, name: &str, default: bool) -> Result<bool, Fault> {
        match self.get(name) {
            None => Ok(default),
            Some(v) => v.as_bool().ok_or_else(|| Fault::new(format!("argument `{name}` must be a boolean"))),
        }
    }

    pub fn int(&self, name: &str, default: i64) -> Result<i64, Fault> {
        match self.get(name) {
            None => Ok(default),
            Some(v) => v.as_i64().ok_or_else(|| Fault::new(format!("argument `{name}` must be an integer"))),
        }
    }

    pub fn opt_int(&self, name: &str) -> Result<Option<i64>, Fault> {
        match self.get(name) {
            None | Some(Json::Null) => Ok(None),
            Some(v) => v.as_i64().map(Some).ok_or_else(|| Fault::new(format!("argument `{name}` must be an integer"))),
        }
    }

    pub fn opt_float(&self, name: &str) -> Result<Option<f64>, Fault> {
        match self.get(name) {
            None | Some(Json::Null) => Ok(None),
            Some(v) => crate::adapter::float(v)
                .map(Some)
                .ok_or_else(|| Fault::new(format!("argument `{name}` must be a number"))),
        }
    }

    pub fn array(&self, name: &str) -> Result<Array, Fault> {
        match self.get(name) {
            None => Ok(Array::new()),
            Some(v) => decode_array(v),
        }
    }

    pub fn opt_array(&self, name: &str) -> Result<Option<Array>, Fault> {
        match self.get(name) {
            None | Some(Json::Null) => Ok(None),
            Some(v) => decode_array(v).map(Some),
        }
    }

    pub fn value(&self, name: &str) -> Result<Value, Fault> {
        match self.get(name) {
            None => Ok(Value::Null),
            Some(v) => decode(v),
        }
    }

    pub fn strings(&self, name: &str) -> Result<Vec<String>, Fault> {
        Ok(self.array(name)?.values().map(text).collect())
    }

    pub fn bytes(&self, name: &str) -> Result<Bytes, Fault> {
        match self.get(name) {
            None => Ok(Bytes::new()),
            Some(v) => crate::adapter::bytes(v)
                .map(Bytes::from)
                .ok_or_else(|| Fault::new(format!("argument `{name}` must be bytes"))),
        }
    }

    pub fn resource(&self, name: &str) -> Result<ResourceRef, Fault> {
        let v = self.get(name).ok_or_else(|| Fault::new(format!("missing argument `{name}`")))?;
        resource(self.session, v)
    }

    pub fn opt_resource(&self, name: &str) -> Result<Option<ResourceRef>, Fault> {
        match self.get(name) {
            None | Some(Json::Null) => Ok(None),
            Some(v) => resource(self.session, v).map(Some),
        }
    }
}

/// The resource of a handle.
pub fn resource(session: &Session, handle: &Json) -> Result<ResourceRef, Fault> {
    session.get::<ResourceRef>(handle).cloned()
}

/// The resources of a list of handles.
pub fn resources(session: &Session, handles: &Json) -> Result<Vec<ResourceRef>, Fault> {
    handles
        .as_array()
        .ok_or_else(|| Fault::new("expected a list of resource handles"))?
        .iter()
        .map(|h| resource(session, h))
        .collect()
}

fn dated(n: &Named<'_>, id: String) -> Result<Base, Fault> {
    Ok(Base { id, created_at: n.string("createdAt", "")?, updated_at: n.string("updatedAt", "")?, ..Base::default() })
}

fn column_class(class: &str) -> Option<FieldClass> {
    class.strip_prefix("Database\\Columns\\").and_then(FieldClass::from_short)
}

/// `new <class>(...$args)` for a class under `Utopia\Migration\Resources\`.
pub fn construct(class: &str, args: &Json, session: &Session) -> Result<ResourceRef, Fault> {
    let n = Named::new(args, session)?;
    let id = |n: &Named<'_>| n.required_string("id");
    if let Some(field_class) = column_class(class) {
        return construct_column(field_class, &n);
    }
    Ok(match class {
        "Auth\\AuthMethods" => {
            let m = AuthMethods {
                email_password: n.bool("emailPassword", true)?,
                magic_url: n.bool("magicURL", true)?,
                email_otp: n.bool("emailOtp", true)?,
                anonymous: n.bool("anonymous", true)?,
                invites: n.bool("invites", true)?,
                jwt: n.bool("jwt", true)?,
                phone: n.bool("phone", true)?,
            };
            Resource::new(Kind::AuthMethods(m), dated(&n, id(&n)?)?)
        }
        "Auth\\Hash" => Hash {
            hash: n.required_string("hash")?,
            salt: n.string("salt", "")?,
            algorithm: n.string("algorithm", Hash::ALGORITHM_SHA256)?,
            separator: n.string("separator", "")?,
            signing_key: n.string("signingKey", "")?,
            password_cpu: n.int("passwordCpu", 0)?,
            password_memory: n.int("passwordMemory", 0)?,
            password_parallel: n.int("passwordParallel", 0)?,
            password_length: n.int("passwordLength", 0)?,
        }
        .resource(),
        "Auth\\Membership" => Membership {
            team: n.resource("team")?,
            user: n.resource("user")?,
            roles: n.array("roles")?,
            active: n.bool("active", true)?,
        }
        .resource(id(&n)?),
        "Auth\\OAuth2\\OAuth2Provider" => Resource::new(
            Kind::OAuth2Provider(OAuth2Provider {
                provider_key: n.required_string("providerKey")?,
                enabled: n.bool("enabled", false)?,
                settings: n.array("settings")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Auth\\Policies" => {
            let d = Policies::default();
            let p = Policies {
                password_history: n.int("passwordHistory", d.password_history)?,
                session_duration: n.int("sessionDuration", d.session_duration)?,
                sessions_limit: n.int("sessionsLimit", d.sessions_limit)?,
                user_limit: n.int("userLimit", d.user_limit)?,
                password_dictionary: n.bool("passwordDictionary", false)?,
                personal_data_check: n.bool("personalDataCheck", false)?,
                session_alerts: n.bool("sessionAlerts", false)?,
                session_invalidation: n.bool("sessionInvalidation", false)?,
                memberships_user_id: n.bool("membershipsUserId", true)?,
                memberships_user_email: n.bool("membershipsUserEmail", true)?,
                memberships_user_name: n.bool("membershipsUserName", true)?,
                memberships_user_mfa: n.bool("membershipsUserMfa", true)?,
                memberships_user_phone: n.bool("membershipsUserPhone", true)?,
            };
            Resource::new(Kind::Policies(p), dated(&n, id(&n)?)?)
        }
        "Auth\\Team" => Team {
            name: n.required_string("name")?,
            preferences: n.array("preferences")?,
            members: n.array("members")?,
        }
        .resource(id(&n)?),
        "Auth\\User" => User {
            email: n.opt_string("email", Some(""))?,
            username: n.opt_string("username", Some(""))?,
            password_hash: n.opt_resource("passwordHash")?,
            phone: n.opt_string("phone", None)?,
            labels: n.array("labels")?,
            oauth_provider: n.string("oauthProvider", "")?,
            email_verified: n.bool("emailVerified", false)?,
            phone_verified: n.bool("phoneVerified", false)?,
            disabled: n.bool("disabled", false)?,
            preferences: n.array("preferences")?,
            targets: n.array("targets")?,
        }
        .resource(id(&n)?),
        "Backups\\Policy" => Policy {
            name: n.string("name", "")?,
            services: n.array("services")?,
            retention: n.int("retention", 0)?,
            schedule: n.string("schedule", "")?,
            enabled: n.bool("enabled", true)?,
            resource_id: n.string("resourceId", "")?,
            resource_type: n.string("resourceType", "")?,
        }
        .resource(n.string("id", "")?),
        "Database\\Database" | "Database\\DocumentsDB" | "Database\\VectorsDB" => {
            let flavor = match class {
                "Database\\DocumentsDB" => DatabaseFlavor::DocumentsDb,
                "Database\\VectorsDB" => DatabaseFlavor::VectorsDb,
                _ => DatabaseFlavor::Database,
            };
            let mut base = dated(&n, n.string("id", "")?)?;
            base.original_id = n.string("originalId", "")?;
            Database {
                flavor,
                name: n.string("name", "")?,
                enabled: n.bool("enabled", true)?,
                database_type: n.string("type", "")?,
                database: n.opt_string("database", Some(""))?,
                status: n.opt_string("databaseStatus", None)?,
            }
            .resource(base)
        }
        "Database\\Table" | "Database\\Collection" => Table::new(
            class == "Database\\Collection",
            n.resource("database")?,
            n.required_string("name")?,
            n.bool("rowSecurity", false)?,
            n.bool("enabled", true)?,
        )
        .resource(id(&n)?, n.strings("permissions")?, n.string("createdAt", "")?, n.string("updatedAt", "")?),
        "Database\\Attribute" => {
            let mut f = Field::new(
                FieldClass::Attribute,
                n.required_string("key")?,
                n.resource("table")?,
                FieldSpec {
                    size: n.int("size", 0)?,
                    required: n.bool("required", false)?,
                    default: n.value("default")?,
                    array: n.bool("array", false)?,
                    signed: n.bool("signed", false)?,
                    format: n.string("format", "")?,
                    format_options: n.array("formatOptions")?,
                    filters: n.strings("filters")?,
                    options: n.array("options")?,
                },
            );
            f.attribute_type = n.string("fieldType", "")?;
            f.resource(n.string("createdAt", "")?, n.string("updatedAt", "")?)
        }
        "Database\\Index" => Index {
            key: n.required_string("key")?,
            table: n.resource("table")?,
            index_type: n.string("type", "")?,
            columns: n.array("columns")?,
            lengths: n.array("lengths")?,
            orders: n.array("orders")?,
        }
        .resource(id(&n)?, n.string("createdAt", "")?, n.string("updatedAt", "")?),
        "Database\\Row" | "Database\\Document" => {
            Row { document: class == "Database\\Document", table: n.resource("table")?, data: n.array("data")? }
                .resource(id(&n)?, n.strings("permissions")?)
        }
        "Domains\\Rule" => Resource::new(
            Kind::Rule(Rule {
                domain: n.required_string("domain")?,
                rule_type: n.required_string("type")?,
                trigger: n.required_string("trigger")?,
                redirect_url: n.string("redirectUrl", "")?,
                redirect_status_code: n.int("redirectStatusCode", 0)?,
                deployment_resource_type: n.string("deploymentResourceType", "")?,
                deployment_resource_id: n.string("deploymentResourceId", "")?,
                deployment_vcs_provider_branch: n.string("deploymentVcsProviderBranch", "")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Functions\\Deployment" => Deployment::new(
            n.resource("func")?,
            n.int("size", 0)?,
            n.required_string("entrypoint")?,
            Chunk { start: n.int("start", 0)?, end: n.int("end", 0)?, data: n.bytes("data")? },
            n.bool("activated", false)?,
        )
        .resource(id(&n)?),
        "Functions\\EnvVar" => {
            EnvVar { func: n.resource("func")?, key: n.required_string("key")?, value: n.required_string("value")? }
                .resource(id(&n)?)
        }
        "Functions\\Func" => Func {
            name: n.required_string("name")?,
            runtime: n.required_string("runtime")?,
            execute: n.array("execute")?,
            enabled: n.bool("enabled", true)?,
            events: n.array("events")?,
            schedule: n.string("schedule", "")?,
            timeout: n.int("timeout", 0)?,
            active_deployment: n.string("activeDeployment", "")?,
            entrypoint: n.string("entrypoint", "")?,
            commands: n.string("commands", "")?,
            logging: n.bool("logging", true)?,
            scopes: n.array("scopes")?,
            specification: n.string("specification", "")?,
            build_specification: n.string("buildSpecification", "")?,
        }
        .resource(id(&n)?),
        "Integrations\\ApiKey" => Resource::new(
            Kind::ApiKey(ApiKey {
                name: n.required_string("name")?,
                scopes: n.array("scopes")?,
                expire: n.string("expire", "")?,
                accessed_at: n.string("accessedAt", "")?,
                sdks: n.array("sdks")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Integrations\\Platform" => Resource::new(
            Kind::Platform(Platform {
                platform_type: n.required_string("type")?,
                name: n.required_string("name")?,
                key: n.string("key", "")?,
                store: n.string("store", "")?,
                hostname: n.string("hostname", "")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Messaging\\Message" => Resource::new(
            Kind::Message(Message {
                provider_type: n.required_string("providerType")?,
                topics: n.array("topics")?,
                users: n.array("users")?,
                targets: n.array("targets")?,
                data: n.array("data")?,
                message_status: n.string("messageStatus", "")?,
                scheduled_at: n.string("scheduledAt", "")?,
                delivered_at: n.string("deliveredAt", "")?,
                delivery_errors: n.array("deliveryErrors")?,
                delivered_total: n.int("deliveredTotal", 0)?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Messaging\\Provider" => Resource::new(
            Kind::Provider(Provider {
                name: n.required_string("name")?,
                provider: n.required_string("provider")?,
                provider_type: n.required_string("type")?,
                enabled: n.bool("enabled", true)?,
                credentials: n.array("credentials")?,
                options: n.array("options")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Messaging\\Subscriber" => Resource::new(
            Kind::Subscriber(Subscriber {
                topic_id: n.required_string("topicId")?,
                target_id: n.required_string("targetId")?,
                user_id: n.string("userId", "")?,
                user_name: n.string("userName", "")?,
                provider_type: n.string("providerType", "")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Messaging\\Topic" => Resource::new(
            Kind::Topic(Topic { name: n.required_string("name")?, subscribe: n.array("subscribe")? }),
            dated(&n, id(&n)?)?,
        ),
        "Settings\\Labels" => Resource::new(Kind::Labels(Labels { labels: n.array("labels")? }), dated(&n, id(&n)?)?),
        "Settings\\ProjectVariable" => Resource::new(
            Kind::ProjectVariable(ProjectVariable {
                key: n.required_string("key")?,
                value: n.string("value", "")?,
                secret: n.bool("secret", false)?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Settings\\Protocols" => Resource::new(
            Kind::Protocols(Protocols {
                rest: n.bool("rest", true)?,
                graphql: n.bool("graphql", true)?,
                websocket: n.bool("websocket", true)?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Settings\\Services" => {
            Resource::new(Kind::Services(Services { services: n.array("services")? }), dated(&n, id(&n)?)?)
        }
        "Settings\\SMTP" => Resource::new(
            Kind::Smtp(Smtp {
                enabled: n.bool("enabled", false)?,
                sender_name: n.string("senderName", "")?,
                sender_email: n.string("senderEmail", "")?,
                reply_to_name: n.string("replyToName", "")?,
                reply_to_email: n.string("replyToEmail", "")?,
                host: n.string("host", "")?,
                port: n.int("port", 0)?,
                username: n.string("username", "")?,
                secure: n.string("secure", "")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Settings\\Webhook" => Resource::new(
            Kind::Webhook(Webhook {
                name: n.required_string("name")?,
                url: n.required_string("url")?,
                events: n.array("events")?,
                security: n.bool("security", false)?,
                http_user: n.string("httpUser", "")?,
                http_pass: n.string("httpPass", "")?,
                enabled: n.bool("enabled", true)?,
            }),
            dated(&n, id(&n)?)?,
        ),
        "Sites\\Deployment" => SiteDeployment::new(
            n.resource("site")?,
            n.int("size", 0)?,
            Chunk { start: n.int("start", 0)?, end: n.int("end", 0)?, data: n.bytes("data")? },
            n.bool("activated", false)?,
        )
        .resource(id(&n)?),
        "Sites\\EnvVar" => {
            SiteEnvVar { site: n.resource("site")?, key: n.required_string("key")?, value: n.required_string("value")? }
                .resource(id(&n)?)
        }
        "Sites\\Site" => Site {
            name: n.required_string("name")?,
            framework: n.required_string("framework")?,
            build_runtime: n.required_string("buildRuntime")?,
            enabled: n.bool("enabled", true)?,
            logging: n.bool("logging", true)?,
            timeout: n.int("timeout", 600)?,
            install_command: n.string("installCommand", "")?,
            build_command: n.string("buildCommand", "")?,
            output_directory: n.string("outputDirectory", "")?,
            adapter: n.string("adapter", "static")?,
            fallback_file: n.string("fallbackFile", "")?,
            specification: n.string("specification", "")?,
            active_deployment: n.string("activeDeployment", "")?,
            build_specification: n.string("buildSpecification", "")?,
        }
        .resource(id(&n)?),
        "Storage\\Bucket" => Bucket {
            name: n.string("name", "")?,
            file_security: n.bool("fileSecurity", false)?,
            enabled: n.bool("enabled", false)?,
            max_file_size: n.opt_int("maxFileSize")?,
            allowed_file_extensions: n.array("allowedFileExtensions")?,
            compression: n.string("compression", "none")?,
            encryption: n.bool("encryption", false)?,
            anti_virus: n.bool("antiVirus", false)?,
            update_limits: n.bool("updateLimits", false)?,
            transformations: n.bool("transformations", false)?,
        }
        .resource(n.string("id", "")?, n.strings("permissions")?),
        "Storage\\File" => File::new(
            n.resource("bucket")?,
            n.string("name", "")?,
            n.string("signature", "")?,
            n.string("mimeType", "")?,
            n.int("size", 0)?,
            Chunk { start: n.int("start", 0)?, end: n.int("end", 0)?, data: n.bytes("data")? },
        )
        .resource(id(&n)?, n.strings("permissions")?),
        "Templates\\EmailTemplate" => Resource::new(
            Kind::EmailTemplate(EmailTemplate {
                template_id: n.required_string("templateId")?,
                locale: n.required_string("locale")?,
                subject: n.required_string("subject")?,
                body: n.required_string("body")?,
                sender_name: n.string("senderName", "")?,
                sender_email: n.string("senderEmail", "")?,
                reply_to_email: n.string("replyToEmail", "")?,
                reply_to_name: n.string("replyToName", "")?,
            }),
            dated(&n, id(&n)?)?,
        ),
        other => return Err(Fault::new(format!("unknown resource class `{other}`"))),
    })
}

fn construct_column(class: FieldClass, n: &Named<'_>) -> Result<ResourceRef, Fault> {
    let key = n.required_string("key")?;
    let table = n.resource("table")?;
    let field = match class {
        FieldClass::Text
        | FieldClass::Email
        | FieldClass::Ip
        | FieldClass::Url
        | FieldClass::RegularText
        | FieldClass::MediumText
        | FieldClass::LongText
        | FieldClass::Varchar => columns::text(
            class,
            &key,
            table,
            n.bool("required", false)?,
            n.opt_string("default", None)?,
            n.bool("array", false)?,
            n.int("size", columns::default_size(class))?,
            &n.string("format", "")?,
        ),
        FieldClass::Enum => columns::enumeration(
            &key,
            table,
            n.array("elements")?,
            n.bool("required", false)?,
            n.opt_string("default", None)?,
            n.bool("array", false)?,
            n.int("size", columns::default_size(class))?,
        ),
        FieldClass::Integer | FieldClass::BigInt => columns::integer(
            class == FieldClass::BigInt,
            &key,
            table,
            n.bool("required", false)?,
            n.opt_int("default")?,
            n.bool("array", false)?,
            n.opt_int("min")?,
            n.opt_int("max")?,
            n.bool("signed", true)?,
        ),
        FieldClass::Decimal => columns::decimal(
            &key,
            table,
            n.bool("required", false)?,
            n.opt_float("default")?,
            n.bool("array", false)?,
            n.opt_float("min")?,
            n.opt_float("max")?,
            n.bool("signed", true)?,
        ),
        FieldClass::Boolean => columns::boolean(
            &key,
            table,
            n.bool("required", false)?,
            match n.get("default") {
                None | Some(Json::Null) => None,
                Some(v) => v.as_bool(),
            },
            n.bool("array", false)?,
        ),
        FieldClass::DateTime => columns::datetime(
            &key,
            table,
            n.bool("required", false)?,
            n.opt_string("default", None)?,
            n.bool("array", false)?,
        ),
        FieldClass::Point | FieldClass::Line | FieldClass::Polygon | FieldClass::ObjectType => {
            columns::structured(class, &key, table, n.bool("required", false)?, n.opt_array("default")?)
        }
        FieldClass::Vector => {
            columns::vector(&key, table, n.bool("required", false)?, n.int("size", 0)?, n.opt_array("default")?)
        }
        FieldClass::Relationship => columns::relationship(
            &key,
            table,
            &n.required_string("relatedTable")?,
            &n.required_string("relationType")?,
            n.bool("twoWay", false)?,
            n.opt_string("twoWayKey", None)?,
            &n.string("onDelete", "restrict")?,
            &n.string("side", "parent")?,
        ),
        FieldClass::Attribute => return Err(Fault::new("Attribute is not a column class")),
    };
    Ok(field.resource(n.string("createdAt", "")?, n.string("updatedAt", "")?))
}

/// `<class>::fromArray($array)` (or `OAuth2Provider::fromArray($provider, $array)`).
pub fn from_array(class: &str, array: &Array, provider: Option<&str>) -> utopia_migration::Result<Option<ResourceRef>> {
    if let Some(p) = provider {
        return OAuth2Provider::from_array(p, array);
    }
    if let Some(c) = column_class(class) {
        return columns::from_array(c, array).map(Some);
    }
    let r = match class {
        "Auth\\AuthMethods" => AuthMethods::from_array(array),
        "Auth\\Hash" => Hash::from_array(array),
        "Auth\\Membership" => Membership::from_array(array),
        "Auth\\Policies" => Policies::from_array(array),
        "Auth\\Team" => Team::from_array(array),
        "Auth\\User" => User::from_array(array),
        "Backups\\Policy" => Policy::from_array(array),
        "Database\\Database" => Database::from_array(DatabaseFlavor::Database, array),
        "Database\\DocumentsDB" => Database::from_array(DatabaseFlavor::DocumentsDb, array),
        "Database\\VectorsDB" => Database::from_array(DatabaseFlavor::VectorsDb, array),
        "Database\\Table" => Table::from_array(array),
        "Database\\Collection" => Table::collection_from_array(array),
        "Database\\Index" => Index::from_array(array),
        "Database\\Row" => Row::from_array(false, array),
        "Database\\Document" => Row::from_array(true, array),
        "Domains\\Rule" => Rule::from_array(array),
        "Functions\\Deployment" => Deployment::from_array(array),
        "Functions\\EnvVar" => EnvVar::from_array(array),
        "Functions\\Func" => Func::from_array(array),
        "Integrations\\ApiKey" => ApiKey::from_array(array),
        "Integrations\\Platform" => Platform::from_array(array),
        "Messaging\\Message" => Message::from_array(array),
        "Messaging\\Provider" => Provider::from_array(array),
        "Messaging\\Subscriber" => Subscriber::from_array(array),
        "Messaging\\Topic" => Topic::from_array(array),
        "Settings\\Labels" => Labels::from_array(array),
        "Settings\\ProjectVariable" => ProjectVariable::from_array(array),
        "Settings\\Protocols" => Protocols::from_array(array),
        "Settings\\Services" => Services::from_array(array),
        "Settings\\SMTP" => Smtp::from_array(array),
        "Settings\\Webhook" => Webhook::from_array(array),
        "Sites\\Deployment" => SiteDeployment::from_array(array),
        "Sites\\EnvVar" => SiteEnvVar::from_array(array),
        "Sites\\Site" => Site::from_array(array),
        "Storage\\Bucket" => Bucket::from_array(array),
        "Storage\\File" => File::from_array(array),
        "Templates\\EmailTemplate" => EmailTemplate::from_array(array),
        other => {
            return Err(utopia_migration::Error::Other {
                class: "Error".into(),
                message: format!("Class \"Utopia\\Migration\\Resources\\{other}\" not found"),
                code: 0,
            });
        }
    };
    r.map(Some)
}

fn s(v: &str) -> Json {
    Json::String(v.to_owned())
}

fn arr(a: &Array) -> Json {
    super::codec::encode_array(a)
}

fn opt(v: &Option<String>) -> Json {
    v.as_deref().map_or(Json::Null, s)
}

fn res(r: &ResourceRef) -> Json {
    encode(&r.json())
}

fn ret_err(class: &str, method: &str, expected: &str, v: &Value) -> Json {
    serde_json::json!({ "$error": {
        "class": "TypeError",
        "message": format!("{class}::{method}(): Return value must be of type {expected}, {} returned", v.debug_type()),
    }})
}

/// Every getter of the resource, by name, after `class` (`Values::describe`).
pub fn describe(r: &ResourceRef) -> Json {
    let b = r.base_snapshot();
    let mut g: Vec<(&str, Json)> = vec![
        ("getCreatedAt", s(&b.created_at)),
        ("getGroup", s(r.group())),
        ("getId", s(&b.id)),
        ("getMessage", s(&b.message)),
        ("getName", s(r.name())),
        ("getOriginalId", s(&b.original_id)),
        ("getPermissions", Json::Array(b.permissions.iter().map(|p| s(p)).collect())),
        ("getSequence", s(&b.sequence)),
        ("getStatus", s(&b.status)),
        ("getUpdatedAt", s(&b.updated_at)),
    ];
    let bool = Json::Bool;
    match r.kind() {
        Kind::AuthMethods(m) => g.extend([
            ("getAnonymous", bool(m.anonymous)),
            ("getEmailOtp", bool(m.email_otp)),
            ("getEmailPassword", bool(m.email_password)),
            ("getInvites", bool(m.invites)),
            ("getJwt", bool(m.jwt)),
            ("getMagicURL", bool(m.magic_url)),
            ("getPhone", bool(m.phone)),
        ]),
        Kind::Hash(h) => g.extend([
            ("getAlgorithm", s(&h.algorithm)),
            ("getHash", s(&h.hash)),
            ("getPasswordCpu", Json::from(h.password_cpu)),
            ("getPasswordLength", Json::from(h.password_length)),
            ("getPasswordMemory", Json::from(h.password_memory)),
            ("getPasswordParallel", Json::from(h.password_parallel)),
            ("getSalt", s(&h.salt)),
            ("getSeparator", s(&h.separator)),
            ("getSigningKey", s(&h.signing_key)),
        ]),
        Kind::Membership(m) => g.extend([
            ("getActive", bool(m.active)),
            ("getRoles", arr(&m.roles)),
            ("getTeam", res(&m.team)),
            ("getUser", res(&m.user)),
        ]),
        Kind::OAuth2Provider(p) => g.extend([
            ("getDestinationAppId", opt(&p.destination_app_id())),
            ("getDestinationSecretFields", arr(&p.destination_secret_fields())),
            ("getEnabled", bool(p.enabled)),
            ("getProviderKey", s(&p.provider_key)),
            ("getSettings", arr(&p.settings)),
            ("isConfigured", bool(p.is_configured())),
        ]),
        Kind::Policies(p) => g.extend([
            ("getMembershipsUserEmail", bool(p.memberships_user_email)),
            ("getMembershipsUserId", bool(p.memberships_user_id)),
            ("getMembershipsUserMfa", bool(p.memberships_user_mfa)),
            ("getMembershipsUserName", bool(p.memberships_user_name)),
            ("getMembershipsUserPhone", bool(p.memberships_user_phone)),
            ("getPasswordDictionary", bool(p.password_dictionary)),
            ("getPasswordHistory", Json::from(p.password_history)),
            ("getPersonalDataCheck", bool(p.personal_data_check)),
            ("getSessionAlerts", bool(p.session_alerts)),
            ("getSessionDuration", Json::from(p.session_duration)),
            ("getSessionInvalidation", bool(p.session_invalidation)),
            ("getSessionsLimit", Json::from(p.sessions_limit)),
            ("getUserLimit", Json::from(p.user_limit)),
        ]),
        Kind::Team(t) => g.extend([
            ("getMembers", arr(&t.members)),
            ("getPreferences", arr(&t.preferences)),
            ("getTeamName", s(&t.name)),
        ]),
        Kind::User(u) => g.extend([
            ("getDisabled", bool(u.disabled)),
            ("getEmail", opt(&u.email)),
            ("getEmailVerified", bool(u.email_verified)),
            ("getLabels", arr(&u.labels)),
            ("getOAuthProvider", s(&u.oauth_provider)),
            ("getPasswordHash", u.password_hash.as_ref().map_or(Json::Null, res)),
            ("getPhone", opt(&u.phone)),
            ("getPhoneVerified", bool(u.phone_verified)),
            ("getPreferences", arr(&u.preferences)),
            ("getTargets", arr(&u.targets)),
            ("getUsername", opt(&u.username)),
        ]),
        Kind::BackupPolicy(p) => g.extend([
            ("getEnabled", bool(p.enabled)),
            ("getPolicyName", s(&p.name)),
            ("getResourceId", s(&p.resource_id)),
            ("getResourceType", s(&p.resource_type)),
            ("getRetention", Json::from(p.retention)),
            ("getSchedule", s(&p.schedule)),
            ("getServices", arr(&p.services)),
        ]),
        Kind::Database(d) => g.extend([
            ("getDatabase", opt(&d.database)),
            ("getDatabaseName", s(&d.name)),
            ("getDatabaseStatus", opt(&d.status)),
            ("getEnabled", bool(d.enabled)),
            ("getType", s(&d.database_type)),
        ]),
        Kind::Table(t) => {
            g.extend([
                ("getDatabase", res(&t.database)),
                ("getEnabled", bool(t.enabled)),
                ("getRowSecurity", bool(t.row_security)),
                ("getTableName", s(&t.name)),
            ]);
            if t.collection {
                g.push(("getDimension", t.dimension().map_or(Json::Null, Json::from)));
            }
        }
        Kind::Field(f) => {
            g.extend([
                ("getDefault", encode(&f.default)),
                ("getFilters", Json::Array(f.filters.iter().map(|x| s(x)).collect())),
                ("getFormat", s(&f.format)),
                ("getFormatOptions", arr(&f.format_options)),
                ("getKey", s(&f.key)),
                ("getOptions", arr(&f.options())),
                ("getSize", Json::from(f.size)),
                ("getTable", res(&f.table)),
                ("getType", s(f.field_type())),
                ("isArray", bool(f.array)),
                ("isRequired", bool(f.required)),
                ("isSigned", bool(f.signed)),
            ]);
            if !f.is_attribute() {
                g.push(("getAttribute", Field::attribute_of(r).map_or(Json::Null, |a| res(&a))));
            }
            let class = f.class.php_class();
            match f.class {
                FieldClass::Integer | FieldClass::BigInt => {
                    g.push(("getMax", Json::from(f.max_int())));
                    g.push(("getMin", Json::from(f.min_int())));
                }
                FieldClass::Decimal => {
                    g.push(("getMax", crate::adapter::float_value(f.max_float())));
                    g.push(("getMin", crate::adapter::float_value(f.min_float())));
                }
                FieldClass::Enum => g.push(("getElements", arr(&f.elements()))),
                FieldClass::Relationship => {
                    let as_string = |m: &str, v: Value| match v {
                        Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_) => s(&text(&v)),
                        other => ret_err(class, m, "string", &other),
                    };
                    let as_opt_string = |m: &str, v: Value| match v {
                        Value::Null => Json::Null,
                        Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_) => s(&text(&v)),
                        other => ret_err(class, m, "?string", &other),
                    };
                    g.push(("getOnDelete", as_string("getOnDelete", f.on_delete())));
                    g.push(("getRelatedTable", as_string("getRelatedTable", f.related_table())));
                    g.push(("getRelationType", as_string("getRelationType", f.relation_type())));
                    g.push(("getSide", as_string("getSide", f.side())));
                    g.push((
                        "getTwoWay",
                        match f.two_way() {
                            v @ (Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_)) => bool(v.truthy()),
                            other => ret_err(class, "getTwoWay", "bool", &other),
                        },
                    ));
                    g.push(("getTwoWayKey", as_opt_string("getTwoWayKey", f.two_way_key())));
                }
                _ => {}
            }
        }
        Kind::Index(i) => g.extend([
            ("getColumns", arr(&i.columns)),
            ("getKey", s(&i.key)),
            ("getLengths", arr(&i.lengths)),
            ("getOrders", arr(&i.orders)),
            ("getTable", res(&i.table)),
            ("getType", s(&i.index_type)),
        ]),
        Kind::Row(row) => g.extend([("getData", arr(&row.data)), ("getTable", res(&row.table))]),
        Kind::Rule(x) => g.extend([
            ("getDeploymentResourceId", s(&x.deployment_resource_id)),
            ("getDeploymentResourceType", s(&x.deployment_resource_type)),
            ("getDeploymentVcsProviderBranch", s(&x.deployment_vcs_provider_branch)),
            ("getDomain", s(&x.domain)),
            ("getRedirectStatusCode", Json::from(x.redirect_status_code)),
            ("getRedirectUrl", s(&x.redirect_url)),
            ("getTrigger", s(&x.trigger)),
            ("getType", s(&x.rule_type)),
        ]),
        Kind::Deployment(d) => {
            let c = d.chunk().clone();
            g.extend([
                ("getActivated", bool(d.activated)),
                ("getData", crate::adapter::bytes_value(&c.data)),
                ("getEnd", Json::from(c.end)),
                ("getEntrypoint", s(&d.entrypoint)),
                ("getFunction", res(&d.func)),
                ("getSize", Json::from(d.size)),
                ("getStart", Json::from(c.start)),
            ]);
        }
        Kind::EnvVar(v) => g.extend([("getFunc", res(&v.func)), ("getKey", s(&v.key)), ("getValue", s(&v.value))]),
        Kind::Function(f) => g.extend([
            ("getActiveDeployment", s(&f.active_deployment)),
            ("getBuildSpecification", s(f.effective_build_specification())),
            ("getCommands", s(&f.commands)),
            ("getEnabled", bool(f.enabled)),
            ("getEntrypoint", s(&f.entrypoint)),
            ("getEvents", arr(&f.events)),
            ("getExecute", arr(&f.execute)),
            ("getFunctionName", s(&f.name)),
            ("getLogging", bool(f.logging)),
            ("getRuntime", s(&f.runtime)),
            ("getRuntimeSpecification", s(&f.specification)),
            ("getSchedule", s(&f.schedule)),
            ("getScopes", arr(&f.scopes)),
            ("getSpecification", s(&f.specification)),
            ("getTimeout", Json::from(f.timeout)),
        ]),
        Kind::ApiKey(k) => g.extend([
            ("getAccessedAt", s(&k.accessed_at)),
            ("getApiKeyName", s(&k.name)),
            ("getExpire", s(&k.expire)),
            ("getScopes", arr(&k.scopes)),
            ("getSdks", arr(&k.sdks)),
        ]),
        Kind::Platform(p) => g.extend([
            ("getHostname", s(&p.hostname)),
            ("getKey", s(&p.key)),
            ("getPlatformName", s(&p.name)),
            ("getStore", s(&p.store)),
            ("getType", s(&p.platform_type)),
        ]),
        Kind::Message(m) => g.extend([
            ("getData", arr(&m.data)),
            ("getDeliveredAt", s(&m.delivered_at)),
            ("getDeliveredTotal", Json::from(m.delivered_total)),
            ("getDeliveryErrors", arr(&m.delivery_errors)),
            ("getMessageStatus", s(&m.message_status)),
            ("getProviderType", s(&m.provider_type)),
            ("getScheduledAt", s(&m.scheduled_at)),
            ("getTargets", arr(&m.targets)),
            ("getTopics", arr(&m.topics)),
            ("getUsers", arr(&m.users)),
        ]),
        Kind::Provider(p) => g.extend([
            ("getCredentials", arr(&p.credentials)),
            ("getEnabled", bool(p.enabled)),
            ("getOptions", arr(&p.options)),
            ("getProvider", s(&p.provider)),
            ("getProviderName", s(&p.name)),
            ("getType", s(&p.provider_type)),
        ]),
        Kind::Subscriber(x) => g.extend([
            ("getProviderType", s(&x.provider_type)),
            ("getTargetId", s(&x.target_id)),
            ("getTopicId", s(&x.topic_id)),
            ("getUserId", s(&x.user_id)),
            ("getUserName", s(&x.user_name)),
        ]),
        Kind::Topic(t) => g.extend([("getSubscribe", arr(&t.subscribe)), ("getTopicName", s(&t.name))]),
        Kind::Labels(l) => g.push(("getLabels", arr(&l.labels))),
        Kind::ProjectVariable(v) => {
            g.extend([("getKey", s(&v.key)), ("getValue", s(&v.value)), ("isSecret", bool(v.secret))])
        }
        Kind::Protocols(p) => {
            g.extend([("getGraphql", bool(p.graphql)), ("getRest", bool(p.rest)), ("getWebsocket", bool(p.websocket))])
        }
        Kind::Services(x) => g.push(("getServices", arr(&x.services))),
        Kind::Smtp(m) => g.extend([
            ("getEnabled", bool(m.enabled)),
            ("getHost", s(&m.host)),
            ("getPort", Json::from(m.port)),
            ("getReplyToEmail", s(&m.reply_to_email)),
            ("getReplyToName", s(&m.reply_to_name)),
            ("getSecure", s(&m.secure)),
            ("getSenderEmail", s(&m.sender_email)),
            ("getSenderName", s(&m.sender_name)),
            ("getUsername", s(&m.username)),
        ]),
        Kind::Webhook(w) => g.extend([
            ("getEvents", arr(&w.events)),
            ("getHttpPass", s(&w.http_pass)),
            ("getHttpUser", s(&w.http_user)),
            ("getSecurity", bool(w.security)),
            ("getUrl", s(&w.url)),
            ("getWebhookName", s(&w.name)),
            ("isEnabled", bool(w.enabled)),
        ]),
        Kind::SiteDeployment(d) => {
            let c = d.chunk().clone();
            g.extend([
                ("getActivated", bool(d.activated)),
                ("getData", crate::adapter::bytes_value(&c.data)),
                ("getEnd", Json::from(c.end)),
                ("getSite", res(&d.site)),
                ("getSize", Json::from(d.size)),
                ("getStart", Json::from(c.start)),
            ]);
        }
        Kind::SiteEnvVar(v) => g.extend([("getKey", s(&v.key)), ("getSite", res(&v.site)), ("getValue", s(&v.value))]),
        Kind::Site(x) => g.extend([
            ("getActiveDeployment", s(&x.active_deployment)),
            ("getAdapter", s(&x.adapter)),
            ("getBuildCommand", s(&x.build_command)),
            ("getBuildRuntime", s(&x.build_runtime)),
            ("getBuildSpecification", s(x.effective_build_specification())),
            ("getEnabled", bool(x.enabled)),
            ("getFallbackFile", s(&x.fallback_file)),
            ("getFramework", s(&x.framework)),
            ("getInstallCommand", s(&x.install_command)),
            ("getLogging", bool(x.logging)),
            ("getOutputDirectory", s(&x.output_directory)),
            ("getRuntimeSpecification", s(&x.specification)),
            ("getSiteName", s(&x.name)),
            ("getSpecification", s(&x.specification)),
            ("getTimeout", Json::from(x.timeout)),
        ]),
        Kind::Bucket(x) => g.extend([
            ("getAllowedFileExtensions", arr(&x.allowed_file_extensions)),
            ("getAntiVirus", bool(x.anti_virus)),
            ("getBucketName", s(&x.name)),
            ("getCompression", s(&x.compression)),
            ("getEnabled", bool(x.enabled)),
            ("getEncryption", bool(x.encryption)),
            ("getFileSecurity", bool(x.file_security)),
            ("getMaxFileSize", x.max_file_size.map_or(Json::Null, Json::from)),
            ("getTransformations", bool(x.transformations)),
        ]),
        Kind::File(f) => {
            let c = f.chunk().clone();
            g.extend([
                ("getBucket", res(&f.bucket)),
                ("getChunkSize", Json::from(c.end - c.start)),
                ("getData", crate::adapter::bytes_value(&c.data)),
                ("getEnd", Json::from(c.end)),
                ("getFileName", s(&f.name)),
                ("getMimeType", s(&f.mime_type)),
                ("getSignature", s(&f.signature)),
                ("getSize", Json::from(f.size)),
                ("getSizeInBytes", Json::from(c.data.len() as i64)),
                ("getStart", Json::from(c.start)),
            ]);
        }
        Kind::EmailTemplate(t) => g.extend([
            ("getBody", s(&t.body)),
            ("getLocale", s(&t.locale)),
            ("getReplyToEmail", s(&t.reply_to_email)),
            ("getReplyToName", s(&t.reply_to_name)),
            ("getSenderEmail", s(&t.sender_email)),
            ("getSenderName", s(&t.sender_name)),
            ("getSubject", s(&t.subject)),
            ("getTemplateId", s(&t.template_id)),
        ]),
        _ => {}
    }
    g.sort_by(|a, b| a.0.cmp(b.0));
    let mut out = Map::new();
    out.insert("class".into(), s(r.class()));
    for (k, v) in g {
        out.insert(k.into(), v);
    }
    Json::Object(out)
}

/// A setter (`setId`, `setStatus`, ...): `{"$self": true}` as PHP's fluent `$this`.
pub fn set(r: &ResourceRef, method: &str, args: &Named<'_>) -> Result<Json, Fault> {
    let first = |n: &Named<'_>| -> Result<Value, Fault> {
        n.map.values().next().map(decode).transpose().map(|v| v.unwrap_or(Value::Null))
    };
    match method {
        "setId" => r.set_id(text(&first(args)?)),
        "setOriginalId" => r.set_original_id(text(&first(args)?)),
        "setSequence" => r.set_sequence(text(&first(args)?)),
        "setStatus" => r.set_status(args.required_string("status")?, args.string("message", "")?),
        "setMessage" => r.set_message(text(&first(args)?)),
        "setPermissions" => r.set_permissions(match first(args)? {
            Value::Array(a) => a.values().map(text).collect(),
            _ => Vec::new(),
        }),
        "setCreatedAt" => r.set_created_at(text(&first(args)?)),
        "setUpdatedAt" => r.set_updated_at(text(&first(args)?)),
        "setStart" | "setEnd" | "setData" => {
            let v = first(args)?;
            let apply = |c: &mut Chunk| match method {
                "setStart" => c.start = php_std::format::str_to_int(text(&v).as_bytes()),
                "setEnd" => c.end = php_std::format::str_to_int(text(&v).as_bytes()),
                _ => {
                    c.data = match &v {
                        Value::Str(s) => s.to_bytes(),
                        other => Bytes::from(text(other)),
                    }
                }
            };
            match r.kind() {
                Kind::File(f) => apply(&mut f.chunk_mut()),
                Kind::Deployment(d) => apply(&mut d.chunk_mut()),
                Kind::SiteDeployment(d) => apply(&mut d.chunk_mut()),
                _ => return Err(Fault::new(format!("{method} on {}", r.class()))),
            }
        }
        "setDimension" => match r.kind() {
            Kind::Table(t) if t.collection => t.set_dimension(match first(args)? {
                Value::Int(i) => Some(i),
                _ => None,
            }),
            _ => return Err(Fault::new(format!("{method} on {}", r.class()))),
        },
        other => return Err(Fault::new(format!("unknown setter `{other}`"))),
    }
    Ok(serde_json::json!({ "$self": true }))
}
