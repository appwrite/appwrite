//! `Validator\Authorization` and its `Input`, `Profiler`, `Cache\*`, `Filter\*`, `Hook\*`, `State\*`, `Builder\*`, `Query\Builder\*`, `Adapter\SQL\Hook\*`, `Adapter\Limits`, `Adapter\Profile`, `Event\DispatcherHook`.
//!
//! The services a case builds or a database hands out, by class: each is
//! a Rust type of the crate held as an object ([`crate::php_object!`]), its
//! methods dispatched by PHP name in [`invoke`].

use std::sync::{Arc, LazyLock, Mutex, RwLock, Weak};

use futures_util::FutureExt as _;
use serde_json::{Map, Value as Json, json};
use utopia_database::adapter::sql::hook::{column, join, permission, tenant};
use utopia_database::adapter::{Feature, Limits, Profile};
use utopia_database::cache::{self, Entry, Epoch, Invalidator, Owners, Region, Registration, Scope};
use utopia_database::capability::Capability;
use utopia_database::database::Hook;
use utopia_database::event::{DispatcherHook, Domain, Event};
use utopia_database::filter::Registry;
use utopia_database::hook::mongo::Read as _;
use utopia_database::hook::relationships::Relationships;
use utopia_database::hook::{RowMetadata, Selective as _, SqlBuilder, Tenancy};
use utopia_database::permission::PermissionType;
use utopia_database::profiler::Log;
use utopia_database::state::{Scoped, Snapshot};
use utopia_database::validator::authorization::{Authorization, Input};
use utopia_database::value::{self, Array, ArrayKey, Str, Value};
use utopia_database::{Database, Profiler, builder, each_builder};
use utopia_query::builder::{Condition, JoinBuilder, JoinOn, JoinType, Statement};
use utopia_query::hook::{Filter as FilterHook, JoinCondition, JoinFilter, Placement};

use crate::libs::database::args::{self, Args};
use crate::libs::database::calls::{Calls, Cb};
use crate::libs::database::codec;
use crate::libs::database::convert::{IntoObj, object, objects, strings};
use crate::libs::database::ctx::{Ctx, Fail, R, fault, lock};
use crate::libs::database::database::{
    self as dbmod, AuthorizationObj, BuilderObj, ProfilerObj, QueryCacheObj, RegistryObj, SnapshotObj,
};
use crate::libs::database::enums;
use crate::libs::database::fixtures::{CodecObj, HookObj};
use crate::libs::database::obj::Obj;
use crate::libs::database::values::{self, Fields, LimitsObj};
use crate::php_object;

// ----- Objects -----

/// A `Validator\Authorization\Input` (no public properties).
#[derive(Clone)]
pub struct InputObj(pub Input);
php_object!(InputObj, "Validator\\Authorization\\Input", |_i| values::object(
    "Validator\\Authorization\\Input",
    vec![]
));

/// A `Cache\Owners` (no public properties).
#[derive(Clone)]
pub struct OwnersObj(pub Arc<Owners>);
php_object!(OwnersObj, "Cache\\Owners", |_o| values::object("Cache\\Owners", vec![]));

/// A `State\Value`: a value scoped to the future that overrides it.
#[derive(Clone)]
pub struct StateValueObj(pub Arc<Scoped<Value>>);
php_object!(StateValueObj, "State\\Value", |_v| values::object("State\\Value", vec![]));

/// A raw statement, with the database its builder ran on (PHP's executor).
#[derive(Clone)]
pub struct StatementObj {
    /// The statement.
    pub statement: Statement,
    /// The database that runs it, when a database's builder built it.
    pub db: Option<Database>,
}
php_object!(StatementObj, "Query\\Builder\\Statement", |s| statement_json(&s.statement));

/// A `Query\Builder\Condition`.
#[derive(Clone)]
pub struct ConditionObj(pub Condition);
php_object!(ConditionObj, "Query\\Builder\\Condition", |c| condition_json(&c.0));

/// A `Query\Hook\Join\Condition`.
#[derive(Clone)]
pub struct JoinConditionObj(pub JoinCondition);
php_object!(JoinConditionObj, "Query\\Hook\\Join\\Condition", |c| join_condition_json(&c.0));

/// A `Query\Builder\JoinBuilder`.
#[derive(Clone)]
pub struct JoinBuilderObj(pub JoinBuilder);
php_object!(JoinBuilderObj, "Query\\Builder\\JoinBuilder", |j| join_builder_json(&j.0));

/// A `Query\Builder\JoinOn`.
#[derive(Clone)]
pub struct JoinOnObj(pub JoinOn);
php_object!(JoinOnObj, "Query\\Builder\\JoinOn", |j| join_on_json(&j.0));

/// An `Adapter\SQL\Hook\Join\Chain` (no public properties).
#[derive(Clone)]
pub struct ChainObj(pub join::Chain);
php_object!(ChainObj, "Adapter\\SQL\\Hook\\Join\\Chain", |_c| values::object(
    "Adapter\\SQL\\Hook\\Join\\Chain",
    vec![]
));

/// The SQL adapters' builder hooks (`Adapter\SQL\Hook\*`).
#[derive(Clone)]
pub enum SqlHook {
    /// `Column\AllowNull`.
    AllowNull(Arc<column::AllowNull>),
    /// `Join\OuterChain`.
    OuterChain(Arc<join::OuterChain>),
    /// `Permission\Filter`.
    PermissionFilter(Arc<permission::Filter>),
    /// `Permission\Join`.
    PermissionJoin(Arc<permission::Join>),
    /// `Permission\OuterJoin`.
    PermissionOuterJoin(Arc<permission::OuterJoin>),
    /// `Tenant\Filter`.
    TenantFilter(Arc<tenant::Filter>),
    /// `Tenant\OuterJoin`.
    TenantOuterJoin(Arc<tenant::OuterJoin>),
    /// `Tenant\Raw`.
    Raw(Arc<tenant::Raw>),
    /// `Tenant\RawOuterJoin`.
    RawOuterJoin(Arc<tenant::RawOuterJoin>),
}

/// A SQL builder hook held as an object (`{"$hook": "<short class>"}`).
#[derive(Clone)]
pub struct SqlHookObj(pub SqlHook);
php_object!(SqlHookObj, "Adapter\\SQL\\Hook", |h| json!({"$hook": h.short()}));

impl SqlHookObj {
    /// The short class `{"$hook": ...}` reports.
    fn short(&self) -> &'static str {
        match &self.0 {
            SqlHook::AllowNull(_) => "AllowNull",
            SqlHook::OuterChain(_) => "OuterChain",
            SqlHook::PermissionFilter(_) | SqlHook::TenantFilter(_) => "Filter",
            SqlHook::PermissionJoin(_) => "Join",
            SqlHook::PermissionOuterJoin(_) | SqlHook::TenantOuterJoin(_) => "OuterJoin",
            SqlHook::Raw(_) => "Raw",
            SqlHook::RawOuterJoin(_) => "RawOuterJoin",
        }
    }

    /// The hook as a `Filter`, when it is one.
    fn filter(&self) -> Option<Arc<dyn FilterHook>> {
        Some(match &self.0 {
            SqlHook::AllowNull(h) => h.clone(),
            SqlHook::PermissionFilter(h) => h.clone(),
            SqlHook::TenantFilter(h) => h.clone(),
            SqlHook::Raw(h) => h.clone(),
            _ => return None,
        })
    }

    /// The hook as a `JoinFilter`, when it is one.
    fn join_filter(&self) -> Option<Arc<dyn JoinFilter>> {
        Some(match &self.0 {
            SqlHook::OuterChain(h) => h.clone(),
            SqlHook::PermissionFilter(h) => h.clone(),
            SqlHook::PermissionJoin(h) => h.clone(),
            SqlHook::PermissionOuterJoin(h) => h.clone(),
            SqlHook::TenantFilter(h) => h.clone(),
            SqlHook::TenantOuterJoin(h) => h.clone(),
            SqlHook::Raw(h) => h.clone(),
            SqlHook::RawOuterJoin(h) => h.clone(),
            SqlHook::AllowNull(_) => return None,
        })
    }
}

/// A `Hook\Mongo\Permission` (`{"$hook": "Permission"}`).
#[derive(Clone)]
pub struct MongoPermissionObj(pub Arc<utopia_database::hook::mongo::Permission>);
php_object!(MongoPermissionObj, "Hook\\Mongo\\Permission", |_h| json!({"$hook": "Permission"}));

php_object!(Scope, "Cache\\Scope", |s| scope_json(s));
php_object!(Entry, "Cache\\Entry", |e| entry_json(e));
php_object!(Epoch, "Cache\\Epoch", |e| values::object(
    "Cache\\Epoch",
    vec![
        ("value", e.value.as_ref().map(|v| Json::String(v.clone())).unwrap_or(Json::Null)),
        ("blockedAt", e.blocked_at.map(Json::from).unwrap_or(Json::Null)),
    ]
));
php_object!(Region, "Cache\\Region", |r| values::object(
    "Cache\\Region",
    vec![("ttl", Json::from(r.ttl)), ("enabled", Json::Bool(r.enabled))]
));
php_object!(Registration, "Cache\\Registration", |r| values::object(
    "Cache\\Registration",
    vec![("key", Json::String(r.key.clone())), ("field", Json::String(r.field.clone()))]
));
php_object!(RowMetadata, "Hook\\RowMetadata", |m| values::object(
    "Hook\\RowMetadata",
    vec![("tenant", codec::encode(&m.tenant))]
));

/// The `DispatcherHook`s cases built, to find the one a [`HookObj`] holds
/// (its `on()` needs the concrete hook; `addHook()` takes the `HookObj`).
static DISPATCHERS: LazyLock<Mutex<Vec<Weak<DispatcherHook>>>> = LazyLock::new(|| Mutex::new(Vec::new()));

/// The `DispatcherHook` a lifecycle hook is, if a case built it.
fn dispatcher_of(hook: &HookObj) -> Option<Arc<DispatcherHook>> {
    let Hook::Lifecycle(lifecycle, _) = &hook.hook else {
        return None;
    };
    let address = Arc::as_ptr(lifecycle) as *const ();
    let mut list = lock(&DISPATCHERS);
    list.retain(|w| w.strong_count() > 0);
    list.iter().filter_map(Weak::upgrade).find(|d| Arc::as_ptr(d) as *const () == address)
}

// ----- Wire forms -----

fn query_values(values: &[utopia_query::Value]) -> Json {
    Json::Array(values.iter().map(|v| codec::encode(&value::from_query(v))).collect())
}

fn statement_json(s: &Statement) -> Json {
    values::object(
        "Query\\Builder\\Statement",
        vec![
            ("query", Json::String(s.query.clone())),
            ("bindings", query_values(&s.bindings)),
            ("readOnly", Json::Bool(s.read_only)),
            (
                "namedBindings",
                s.named_bindings
                    .as_ref()
                    .map(|b| codec::encode_array(&value::from_query_array(b)))
                    .unwrap_or(Json::Null),
            ),
        ],
    )
}

fn condition_json(c: &Condition) -> Json {
    values::object(
        "Query\\Builder\\Condition",
        vec![("expression", Json::String(c.expression.clone())), ("bindings", query_values(&c.bindings))],
    )
}

fn join_condition_json(c: &JoinCondition) -> Json {
    values::object(
        "Query\\Hook\\Join\\Condition",
        vec![("condition", condition_json(&c.condition)), ("placement", enums::encode(c.placement))],
    )
}

fn join_on_json(o: &JoinOn) -> Json {
    values::object(
        "Query\\Builder\\JoinOn",
        vec![
            ("left", Json::String(o.left.clone())),
            ("operator", Json::String(o.operator.clone())),
            ("right", Json::String(o.right.clone())),
        ],
    )
}

fn join_builder_json(j: &JoinBuilder) -> Json {
    values::object(
        "Query\\Builder\\JoinBuilder",
        vec![
            ("ons", Json::Array(j.ons().iter().map(join_on_json).collect())),
            ("wheres", Json::Array(j.wheres().iter().map(condition_json).collect())),
        ],
    )
}

fn scope_json(s: &Scope) -> Json {
    values::object(
        "Cache\\Scope",
        vec![
            ("hostname", Json::String(s.hostname.clone())),
            ("database", Json::String(s.database.clone())),
            ("namespace", Json::String(s.namespace.clone())),
            ("tenant", codec::encode(&s.tenant)),
            ("name", Json::String(s.name.clone())),
            ("writerTimeout", Json::from(s.writer_timeout)),
        ],
    )
}

fn entry_json(e: &Entry) -> Json {
    values::object(
        "Cache\\Entry",
        vec![
            ("key", Json::String(e.key.clone())),
            ("collection", Json::String(e.collection.clone())),
            ("field", Json::String(e.field.clone())),
            ("epoch", Json::String(e.epoch.clone())),
            ("slot", Json::String(e.slot.clone())),
        ],
    )
}

/// A `State\Snapshot` on the wire.
pub fn snapshot(s: &Snapshot) -> Json {
    let listeners = if s.silenced_listeners.is_empty() {
        Json::Array(Vec::new())
    } else {
        Json::Object(s.silenced_listeners.iter().map(|n| (n.clone(), Json::Bool(true))).collect())
    };
    let disabled = match &s.disabled_filters {
        None => Json::Null,
        Some(list) if list.is_empty() => Json::Array(Vec::new()),
        Some(list) => Json::Object(list.iter().map(|(n, b)| (n.clone(), Json::Bool(*b))).collect()),
    };
    let timestamp = match &s.request_timestamp {
        None => Json::Null,
        Some(text) => match args::parse_datetime(text) {
            Ok(d) => values::datetime(&d),
            Err(_) => Json::String(text.clone()),
        },
    };
    values::object(
        "State\\Snapshot",
        vec![
            ("authorization", Json::Bool(s.authorization)),
            ("roles", Json::Array(s.roles.iter().map(|r| Json::String(r.clone())).collect())),
            ("relationships", Json::Bool(s.relationships)),
            ("existCheck", Json::Bool(s.exist_check)),
            ("population", Json::Bool(s.population)),
            ("silenced", Json::Bool(s.silenced)),
            ("silencedListeners", listeners),
            ("tenant", codec::encode(&s.tenant)),
            ("filters", Json::Bool(s.filters)),
            ("disabledFilters", disabled),
            ("validation", Json::Bool(s.validation)),
            ("preserveDates", Json::Bool(s.preserve_dates)),
            ("preserveSequence", Json::Bool(s.preserve_sequence)),
            ("ignoreDuplicates", Json::Bool(s.ignore_duplicates)),
            ("requestTimestamp", timestamp),
            ("checkGrants", Json::Bool(s.check_grants)),
            ("rewriting", Json::Bool(s.rewriting)),
        ],
    )
}

// ----- Decoding -----

/// The names of a PHP `array<string, mixed>` given as an object (or `[]`).
fn keys_of(json: Option<&Json>) -> Vec<String> {
    match json {
        Some(Json::Object(o)) => o.keys().cloned().collect(),
        Some(Json::Array(items)) => items.iter().filter_map(Json::as_str).map(str::to_owned).collect(),
        _ => Vec::new(),
    }
}

/// A snapshot from its constructor's arguments (or its fields).
fn snapshot_of(cx: Option<&Ctx>, j: &Map<String, Json>) -> R<Snapshot> {
    let bool_of = |k: &str, default: bool| j.get(k).and_then(Json::as_bool).unwrap_or(default);
    let disabled = match j.get("disabledFilters") {
        None | Some(Json::Null) => None,
        Some(Json::Object(o)) => Some(o.iter().map(|(k, v)| (k.clone(), v.as_bool().unwrap_or(true))).collect()),
        Some(_) => Some(Vec::new()),
    };
    let timestamp = match j.get("requestTimestamp") {
        None | Some(Json::Null) => None,
        Some(Json::Object(o)) if o.contains_key("$datetime") => {
            let text = o.get("$datetime").and_then(Json::as_str).unwrap_or_default();
            Some(utopia_database::datetime::format(&args::parse_datetime(text)?))
        }
        Some(Json::String(text)) => Some(utopia_database::datetime::format(&args::parse_datetime(text)?)),
        Some(other) => return fault(format!("requestTimestamp: {other}")),
    };
    Ok(Snapshot {
        authorization: bool_of("authorization", true),
        roles: codec::list(j.get("roles").unwrap_or(&Json::Null))?
            .into_iter()
            .map(|r| Ok(value::to_text(&codec::decode(r)?)))
            .collect::<R<Vec<_>>>()?,
        relationships: bool_of("relationships", true),
        exist_check: bool_of("existCheck", true),
        population: bool_of("population", false),
        silenced: bool_of("silenced", false),
        silenced_listeners: keys_of(j.get("silencedListeners")),
        tenant: decode_with(cx, j.get("tenant").unwrap_or(&Json::Null))?,
        filters: bool_of("filters", true),
        disabled_filters: disabled,
        validation: bool_of("validation", true),
        preserve_dates: bool_of("preserveDates", false),
        preserve_sequence: bool_of("preserveSequence", false),
        ignore_duplicates: bool_of("ignoreDuplicates", false),
        request_timestamp: timestamp,
        check_grants: bool_of("checkGrants", false),
        rewriting: bool_of("rewriting", true),
    })
}

/// Query values from a JSON list (`$obj` resolved).
fn query_list(cx: Option<&Ctx>, json: Option<&Json>) -> R<Vec<utopia_query::Value>> {
    match json {
        None | Some(Json::Null) => Ok(Vec::new()),
        Some(j) => match decode_with(cx, j)? {
            Value::Array(a) => Ok(a.values().map(value::to_query).collect()),
            Value::Object(_) => Ok(Vec::new()),
            v => Ok(vec![value::to_query(&v)]),
        },
    }
}

fn condition_of(cx: Option<&Ctx>, j: &Map<String, Json>) -> R<Condition> {
    let expression = j.get("expression").and_then(Json::as_str).unwrap_or_default().to_owned();
    Ok(Condition::new(expression, query_list(cx, j.get("bindings"))?))
}

fn join_builder_of(cx: Option<&Ctx>, j: &Map<String, Json>) -> R<JoinBuilder> {
    let mut builder = JoinBuilder::new();
    for on in codec::list(j.get("ons").unwrap_or(&Json::Null))? {
        let f = on.get("fields").unwrap_or(on);
        let s = |k: &str| f.get(k).and_then(Json::as_str).unwrap_or_default().to_owned();
        builder.on(&s("left"), &s("right"), &s("operator"))?;
    }
    for w in codec::list(j.get("wheres").unwrap_or(&Json::Null))? {
        let f = w.get("fields").unwrap_or(w);
        let condition = condition_of(cx, f.as_object().unwrap_or(&Map::new()))?;
        builder.where_raw(condition.expression, condition.bindings);
    }
    Ok(builder)
}

/// A value from JSON: `$obj` resolved when a session is at hand.
fn decode_with(cx: Option<&Ctx>, json: &Json) -> R<Value> {
    match cx {
        Some(cx) => args::decode_value(cx, json),
        None => Ok(codec::decode(json)?),
    }
}

fn object_of(fields: &Json) -> Map<String, Json> {
    fields.as_object().cloned().unwrap_or_default()
}

fn limits_of(f: &Fields<'_>) -> R<Limits> {
    let date = |k: &str| -> R<php_std::datetime::DateTime> {
        match f.raw(k) {
            Some(Json::Object(o)) => args::parse_datetime(o.get("$datetime").and_then(Json::as_str).unwrap_or("now")),
            Some(Json::String(s)) => args::parse_datetime(s),
            _ => args::parse_datetime("now"),
        }
    };
    Ok(Limits {
        string: f.int("string")?,
        varchar: f.int("varchar")?,
        integer: f.int("integer")?,
        big_integer: f.int("bigInteger")?,
        attributes: f.int("attributes")?,
        indexes: f.int("indexes")?,
        default_attributes: f.int("defaultAttributes")?,
        default_indexes: f.int("defaultIndexes")?,
        index_length: f.int("indexLength")?,
        uid_length: f.int("uidLength")?,
        document_size: f.int("documentSize")?,
        min_date_time: date("minDateTime")?,
        max_date_time: date("maxDateTime")?,
        id_type: match f.raw("idType") {
            Some(j) => values::enum_of(j)?,
            None => utopia_query::schema::ColumnType::Integer,
        },
        keywords: f.strings("keywords").unwrap_or_default(),
        internal_index_keys: f.strings("internalIndexKeys").unwrap_or_default(),
    })
}

fn feature_of(name: &str) -> R<Feature> {
    match Feature::ALL.iter().copied().find(|f| f.php_class() == name || f.php_class().ends_with(&format!("\\{name}")))
    {
        Some(f) => Ok(f),
        None => fault(format!("no adapter feature `{name}`")),
    }
}

fn profile_of(cx: Option<&Ctx>, j: &Map<String, Json>) -> R<Profile> {
    let limits = match j.get("limits") {
        Some(l) => match cx.map(|cx| args::stored_obj(cx, l)).transpose()?.flatten() {
            Some(obj) => args::downcast::<LimitsObj>(&obj)?.0,
            None => {
                let fields = l.get("fields").unwrap_or(l);
                Arc::new(limits_of(&Fields::of(fields)?)?)
            }
        },
        None => return fault("Adapter\\Profile needs its limits"),
    };
    let capabilities = codec::list(j.get("capabilities").unwrap_or(&Json::Null))?
        .into_iter()
        .map(values::enum_of::<Capability>)
        .collect::<R<Vec<_>>>()?;
    let features = codec::list(j.get("features").unwrap_or(&Json::Null))?
        .into_iter()
        .map(|f| feature_of(f.as_str().unwrap_or_default()))
        .collect::<R<Vec<_>>>()?;
    Ok(Profile::new(
        limits,
        capabilities.into_iter().collect(),
        features.into_iter().collect(),
        j.get("sharedTables").and_then(Json::as_bool).unwrap_or(false),
        j.get("migrating").and_then(Json::as_bool).unwrap_or(false),
        None,
    ))
}

fn scope_of(cx: Option<&Ctx>, j: &Map<String, Json>) -> R<Scope> {
    let s = |k: &str, d: &str| j.get(k).and_then(Json::as_str).unwrap_or(d).to_owned();
    Ok(Scope {
        hostname: s("hostname", ""),
        database: s("database", ""),
        namespace: s("namespace", ""),
        tenant: decode_with(cx, j.get("tenant").unwrap_or(&Json::Null))?,
        name: s("name", Scope::NAME),
        writer_timeout: j.get("writerTimeout").and_then(Json::as_i64).unwrap_or(Scope::WRITER_TIMEOUT),
    })
}

fn entry_of(j: &Map<String, Json>) -> Entry {
    let s = |k: &str| j.get(k).and_then(Json::as_str).unwrap_or_default().to_owned();
    Entry { key: s("key"), collection: s("collection"), field: s("field"), epoch: s("epoch"), slot: s("slot") }
}

/// A `$value` tag of a class of this module.
pub fn decode_value(class: &str, fields: &Json) -> R<Option<Obj>> {
    let cx: Option<&Ctx> = None;
    let j = object_of(fields);
    Ok(Some(match class {
        "Query\\Builder\\Statement" => object(StatementObj { statement: statement_fields(cx, fields)?, db: None }),
        "Query\\Builder\\Condition" => object(ConditionObj(condition_of(cx, &j)?)),
        "Query\\Builder\\JoinBuilder" => object(JoinBuilderObj(join_builder_of(cx, &j)?)),
        "Query\\Builder\\JoinOn" => {
            let s = |k: &str| j.get(k).and_then(Json::as_str).unwrap_or_default().to_owned();
            object(JoinOnObj(JoinOn { left: s("left"), operator: s("operator"), right: s("right") }))
        }
        "Query\\Hook\\Join\\Condition" => {
            let condition = match j.get("condition") {
                Some(c) => condition_of(cx, &object_of(c.get("fields").unwrap_or(c)))?,
                None => Condition::new("", Vec::new()),
            };
            let placement = match j.get("placement") {
                Some(p) => values::enum_of::<Placement>(p)?,
                None => Placement::Where,
            };
            object(JoinConditionObj(JoinCondition::new(condition, placement)))
        }
        "Adapter\\Limits" => object(LimitsObj(Arc::new(limits_of(&Fields::of(fields)?)?))),
        "Adapter\\Profile" => object(profile_of(cx, &j)?),
        "Profiler\\Log" => object(log_of(cx, &j)?),
        "Cache\\Scope" => object(scope_of(cx, &j)?),
        "Cache\\Entry" => object(entry_of(&j)),
        "Cache\\Epoch" => object(Epoch {
            value: j.get("value").and_then(Json::as_str).map(str::to_owned),
            blocked_at: j.get("blockedAt").and_then(Json::as_i64),
        }),
        "Cache\\Region" => object(Region {
            ttl: j.get("ttl").and_then(Json::as_i64).unwrap_or(3600),
            enabled: j.get("enabled").and_then(Json::as_bool).unwrap_or(true),
        }),
        "Cache\\Registration" => object(Registration {
            key: j.get("key").and_then(Json::as_str).unwrap_or_default().to_owned(),
            field: j.get("field").and_then(Json::as_str).unwrap_or_default().to_owned(),
        }),
        "Hook\\RowMetadata" => object(RowMetadata { tenant: decode_with(cx, j.get("tenant").unwrap_or(&Json::Null))? }),
        "State\\Snapshot" => object(SnapshotObj(snapshot_of(cx, &j)?)),
        "Validator\\Authorization\\Input" => object(InputObj(Input::new("", Vec::new()))),
        "Adapter\\SQL\\Hook\\Join\\Chain" => object(ChainObj(join::Chain::default())),
        _ => return Ok(None),
    }))
}

fn log_of(cx: Option<&Ctx>, j: &Map<String, Json>) -> R<Log> {
    let s = |k: &str| j.get(k).and_then(Json::as_str).unwrap_or_default().to_owned();
    let bindings = match decode_with(cx, j.get("bindings").unwrap_or(&Json::Null))? {
        Value::Array(a) => a,
        _ => Array::new(),
    };
    Ok(Log {
        query: s("query"),
        bindings,
        duration_ms: j.get("durationMs").and_then(Json::as_f64).unwrap_or(0.0),
        collection: s("collection"),
        operation: s("operation"),
        backtrace: match j.get("backtrace") {
            Some(Json::Array(items)) => Some(items.iter().filter_map(Json::as_str).map(str::to_owned).collect()),
            _ => None,
        },
    })
}

/// A raw statement from a stored statement or builder.
pub fn statement_of(obj: &Obj) -> R<Statement> {
    if let Some(s) = obj.downcast_ref::<StatementObj>() {
        return Ok(s.statement.clone());
    }
    if let Some(b) = obj.downcast_ref::<BuilderObj>() {
        return lock(&b.0).build().map_err(query_fail);
    }
    fault(format!("not a statement: {obj:?}"))
}

/// A `Query\Builder\Statement` from its fields.
pub fn decode_statement(cx: &Ctx, fields: &Json) -> R<Statement> {
    statement_fields(Some(cx), fields)
}

fn statement_fields(cx: Option<&Ctx>, fields: &Json) -> R<Statement> {
    let j = object_of(fields);
    let named = match j.get("namedBindings") {
        None | Some(Json::Null) => None,
        Some(n) => match decode_with(cx, n)? {
            Value::Array(a) => Some(value::to_query_array(&a)),
            _ => Some(utopia_query::Array::new()),
        },
    };
    Ok(Statement::with_parts(
        j.get("query").and_then(Json::as_str).unwrap_or_default(),
        query_list(cx, j.get("bindings"))?,
        j.get("readOnly").and_then(Json::as_bool).unwrap_or(false),
        None,
        named,
    ))
}

/// A `utopia-query` error as PHP reports it: a database error a hook
/// raised inside the builder unchanged, else the query library's.
fn query_fail(e: utopia_query::Error) -> Fail {
    if let Some(error) = e.previous().and_then(|p| p.downcast_ref::<utopia_database::Error>()) {
        return Fail::Error(error.clone());
    }
    Fail::from(e)
}

// ----- Helpers -----

/// What a callback returned, as the scope method returns it.
fn returned(r: Result<Option<Obj>, utopia_database::Error>) -> R<Obj> {
    match r {
        Ok(v) => Ok(v.unwrap_or_else(Obj::null)),
        Err(e) => Err(Fail::Error(e)),
    }
}

/// Runs a callback that cannot await (the library calls it synchronously):
/// its steps must complete at once.
fn now(cb: &Cb, args: Vec<Json>) -> Result<Option<Obj>, utopia_database::Error> {
    match cb.invoke(args).now_or_never() {
        Some(r) => r,
        None => Ok(None),
    }
}

/// A callback the object keeps and the library calls after the operation
/// returned: reported with the operation (with no calls, as PHP reports
/// it), and run on a record of its own that outlives the operation's.
fn deferred(calls: &Calls, a: &Args, name: &str) -> R<Cb> {
    calls.required(name, a.raw(name))?;
    Calls::new(&a.cx).required(name, a.raw(name))
}

/// A `PermissionType|string` action.
fn action(a: &Args, key: &str) -> R<String> {
    // The harness decodes a string for a `PermissionType|string` parameter
    // as the enum (`Core::argument`), so only its backing values pass.
    match a.raw(key) {
        Some(Json::String(s)) => match <PermissionType as enums::PhpEnum>::from_backing(s) {
            Some(p) => Ok(p.as_str().to_owned()),
            None => Err(Fail::php(
                "ValueError",
                format!("\"{s}\" is not a valid backing value for enum Utopia\\Database\\PermissionType"),
            )),
        },
        _ => Ok(a.req_enum::<PermissionType>(key)?.as_str().to_owned()),
    }
}

/// The query of a database query.
fn inner_queries(a: &Args, key: &str) -> R<Vec<utopia_query::Query>> {
    Ok(a.queries(key)?.into_iter().map(|q| (*q).clone()).collect())
}

/// A stored SQL hook.
fn sql_hook(a: &Args, key: &str) -> R<SqlHookObj> {
    a.req::<SqlHookObj>(key)
}

/// `array<string, Condition>` parameters.
fn conditions(a: &Args, key: &str) -> R<Vec<(String, Condition)>> {
    match a.raw(key) {
        Some(Json::Object(o)) => o
            .iter()
            .map(|(k, v)| {
                let obj = args::decode_obj(&a.cx, v)?.into_result();
                Ok((k.clone(), args::downcast::<ConditionObj>(&obj)?.0))
            })
            .collect(),
        _ => Ok(Vec::new()),
    }
}

/// The `Event` whose typed event class is `class`.
fn event_of_class(class: &str) -> R<Event> {
    let class = class.trim_start_matches('\\');
    match Event::CASES.iter().copied().find(|e| e.domain() == Some(class)) {
        Some(e) => Ok(e),
        None => fault(format!("no event class `{class}`")),
    }
}

// ----- new -----

/// `new <class>(...)` for the classes of this module (`None`: not one).
pub async fn construct(class: &str, a: &Args) -> R<Option<Obj>> {
    let calls = Calls::new(&a.cx);
    let result = build(class, a, &calls).await;
    match result {
        Ok(Some(obj)) => calls.finish(Ok(obj)).map(Some),
        Ok(None) => Ok(None),
        Err(e) => calls.finish(Err(e)).map(Some),
    }
}

async fn build(class: &str, a: &Args, calls: &Calls) -> R<Option<Obj>> {
    let s = |k: &str| a.string(k);
    Ok(Some(match class {
        "Validator\\Authorization" => {
            object(AuthorizationObj(Arc::new(Authorization::new(a.bool_or("defaultStatus", true)?))))
        }
        "Validator\\Authorization\\Input" => {
            object(InputObj(Input::new(action(a, "action")?, a.strings("permissions")?)))
        }
        "Profiler" => object(ProfilerObj(Arc::new(Profiler::new()))),
        "Profiler\\Log" => object(log_of(Some(&a.cx), a.json())?),
        "Cache\\Query" => {
            let cache = dbmod::cache(a, "cache").await?;
            object(QueryCacheObj(Arc::new(cache::Query::new(cache, a.int_or("slots", cache::Query::SLOTS)?)?)))
        }
        "Cache\\Owners" => object(OwnersObj(Arc::new(Owners::new(dbmod::cache(a, "cache").await?)))),
        "Cache\\Scope" => object(scope_of(Some(&a.cx), a.json())?),
        "Cache\\Entry" => object(entry_of(a.json())),
        "Cache\\Epoch" => object(Epoch { value: a.opt_string("value")?, blocked_at: a.opt_int("blockedAt")? }),
        "Cache\\Region" => object(Region { ttl: a.int_or("ttl", 3600)?, enabled: a.bool_or("enabled", true)? }),
        "Cache\\Registration" => object(Registration { key: s("key")?, field: a.string_or("field", "")? }),
        "Cache\\Invalidator" => {
            let query_cache = dbmod::query_cache(a, "queryCache").await?;
            let Some(query_cache) = query_cache else {
                return fault("Cache\\Invalidator needs a query cache");
            };
            let scope = a.typed::<Scope>("scope")?.unwrap_or_default();
            object(HookObj {
                hook: Hook::Invalidator(Arc::new(Invalidator::with_scope(query_cache, scope))),
                class: "Invalidator",
            })
        }
        "Filter\\Registry" => object(RegistryObj(Arc::new(RwLock::new(Registry::new())))),
        "Filter\\Callback" => {
            let encode = deferred(calls, a, "encode")?;
            let decode = deferred(calls, a, "decode")?;
            let run = |cb: Cb| {
                move |v: Value| -> utopia_database::Result<Value> {
                    match now(&cb, vec![codec::encode(&v)])? {
                        None => Ok(Value::Null),
                        Some(Obj::Value(v)) => Ok(v),
                        Some(other) => Ok(codec::decode(&other.encode()).unwrap_or(Value::Null)),
                    }
                }
            };
            object(CodecObj(Arc::new(utopia_database::filter::Callback::new(s("name")?, run(encode), run(decode)))))
        }
        "Hook\\Permissions" => object(HookObj {
            hook: Hook::write(Arc::new(utopia_database::hook::Permissions::new())),
            class: "Permissions",
        }),
        "Hook\\Relationships" => object(HookObj {
            hook: Hook::Relationships(Arc::new(Relationships::new(a.bool_or("prepare", true)?))),
            class: "Relationships",
        }),
        "Hook\\Tenancy" => object(HookObj {
            hook: Hook::write(Arc::new(match a.opt_string("column")? {
                Some(c) => Tenancy::new(c),
                None => Tenancy::default(),
            })),
            class: "Tenancy",
        }),
        "Hook\\RowMetadata" => object(RowMetadata { tenant: a.value("tenant")? }),
        "Hook\\Mongo\\Permission" => object(MongoPermissionObj(Arc::new(
            utopia_database::hook::mongo::Permission::new(dbmod::authorization(a, "authorization")?),
        ))),
        "Event\\DispatcherHook" => {
            let hook = Arc::new(DispatcherHook::new(None));
            lock(&DISPATCHERS).push(Arc::downgrade(&hook));
            object(HookObj { hook: Hook::lifecycle(hook), class: "DispatcherHook" })
        }
        "State\\Value" => object(StateValueObj(Arc::new(Scoped::new(a.value("value")?)))),
        "State\\Snapshot" => object(SnapshotObj(snapshot_of(Some(&a.cx), a.json())?)),
        "Builder\\MySQL" => builder_obj(SqlBuilder::MySql(builder::mysql())),
        "Builder\\MariaDB" => builder_obj(SqlBuilder::MariaDb(builder::mariadb())),
        "Builder\\Postgres" => builder_obj(SqlBuilder::PostgreSql(builder::postgres())),
        "Builder\\SQLite" => builder_obj(SqlBuilder::Sqlite(builder::sqlite())),
        "Query\\Builder\\Statement" => {
            let named = match a.raw("namedBindings") {
                None | Some(Json::Null) => None,
                Some(_) => Some(value::to_query_array(&a.array("namedBindings")?)),
            };
            object(StatementObj {
                statement: Statement::with_parts(
                    s("query")?,
                    query_list(Some(&a.cx), a.raw("bindings"))?,
                    a.bool_or("readOnly", false)?,
                    None,
                    named,
                ),
                db: None,
            })
        }
        "Query\\Builder\\Condition" => object(ConditionObj(condition_of(Some(&a.cx), a.json())?)),
        "Query\\Builder\\JoinBuilder" => object(JoinBuilderObj(JoinBuilder::new())),
        "Query\\Builder\\JoinOn" => {
            object(JoinOnObj(JoinOn { left: s("left")?, operator: a.string_or("operator", "=")?, right: s("right")? }))
        }
        "Query\\Hook\\Join\\Condition" => {
            let condition = a.req::<ConditionObj>("condition")?.0;
            let placement = a.enum_::<Placement>("placement")?.unwrap_or(Placement::Where);
            object(JoinConditionObj(JoinCondition::new(condition, placement)))
        }
        "Adapter\\Limits" => object(LimitsObj(Arc::new(limits_of(&Fields::of(&Json::Object(a.json().clone()))?)?))),
        "Adapter\\Profile" => object(profile_of(Some(&a.cx), a.json())?),
        "Adapter\\SQL\\Hook\\Join\\Chain" => {
            let joins = match a.raw("joins") {
                Some(Json::Object(o)) => {
                    o.iter().map(|(k, v)| Ok((k.clone(), values::enum_of::<JoinType>(v)?))).collect::<R<Vec<_>>>()?
                }
                _ => Vec::new(),
            };
            object(ChainObj(join::Chain::new(joins)))
        }
        "Adapter\\SQL\\Hook\\Join\\OuterChain" => {
            let chain = a.req::<ChainObj>("chain")?.0;
            let quote = a.string_or("quoteCharacter", "`")?;
            sql_obj(SqlHook::OuterChain(Arc::new(join::OuterChain::new(chain, conditions(a, "conditions")?, quote))))
        }
        "Adapter\\SQL\\Hook\\Column\\AllowNull" => {
            let Some(filter) = sql_hook(a, "filter")?.filter() else {
                return fault("AllowNull wraps a filter");
            };
            sql_obj(SqlHook::AllowNull(Arc::new(column::AllowNull::new(
                filter,
                s("column")?,
                a.string_or("quoteCharacter", "`")?,
            )?)))
        }
        "Adapter\\SQL\\Hook\\Permission\\Filter" => {
            let table = deferred(calls, a, "permissionsTable")?;
            let options = permission::Options {
                r#type: match a.raw("type") {
                    None | Some(Json::Null) => PermissionType::Read.as_str().to_owned(),
                    Some(_) => s("type")?,
                },
                columns: a.opt_strings("columns")?,
                document_column: a.string_or("documentColumn", "id")?,
                permission_document_column: a.string_or("permissionDocumentColumn", "document_id")?,
                permission_role_column: a.string_or("permissionRoleColumn", "role")?,
                permission_type_column: a.string_or("permissionTypeColumn", "type")?,
                scope_column: a.string_or("scopeColumn", "column")?,
                subquery_filter: match a.raw("subqueryFilter") {
                    None | Some(Json::Null) => None,
                    Some(_) => sql_hook(a, "subqueryFilter")?.filter(),
                },
                quote: a.string_or("quoteCharacter", "`")?,
                semi_join: a.bool_or("semiJoin", true)?,
            };
            let permissions_table = move |base: &str| -> String {
                match now(&table, vec![Json::String(base.to_owned())]) {
                    Ok(Some(Obj::Value(v))) => value::to_text(&v),
                    _ => String::new(),
                }
            };
            sql_obj(SqlHook::PermissionFilter(Arc::new(permission::Filter::with_options(
                a.strings("roles")?,
                permissions_table,
                options,
            )?)))
        }
        "Adapter\\SQL\\Hook\\Permission\\Join" => {
            let SqlHook::PermissionFilter(filter) = sql_hook(a, "filter")?.0 else {
                return fault("Permission\\Join takes a Permission\\Filter");
            };
            sql_obj(SqlHook::PermissionJoin(Arc::new(permission::Join::new(
                (*filter).clone(),
                s("alias")?,
                a.string_or("quoteCharacter", "`")?,
                a.bool_or("preservingOuterJoin", false)?,
            ))))
        }
        "Adapter\\SQL\\Hook\\Permission\\OuterJoin" => sql_obj(SqlHook::PermissionOuterJoin(Arc::new(
            permission::OuterJoin::new(s("source")?, conditions(a, "conditions")?, a.string_or("quoteCharacter", "`")?),
        ))),
        "Adapter\\SQL\\Hook\\Tenant\\Filter" => {
            let tenants: Vec<utopia_query::Value> = match a.value("tenant")? {
                Value::Array(list) => list.values().map(value::to_query).collect(),
                v => vec![value::to_query(&v)],
            };
            let filter = tenant::Filter::new(tenants)
                .with_metadata_collection(a.string_or("metadataCollection", "")?)
                .with_collection(a.string_or("collection", "")?)
                .with_allow_null_column(a.string_or("allowNullColumn", "")?)
                .with_quote(a.string_or("quoteCharacter", "`")?)
                .with_unindexed(a.opt_strings("unindexed")?.unwrap_or_default());
            sql_obj(SqlHook::TenantFilter(Arc::new(filter)))
        }
        "Adapter\\SQL\\Hook\\Tenant\\OuterJoin" => {
            let SqlHook::TenantFilter(filter) = sql_hook(a, "filter")?.0 else {
                return fault("Tenant\\OuterJoin takes a Tenant\\Filter");
            };
            sql_obj(SqlHook::TenantOuterJoin(Arc::new(tenant::OuterJoin::new((*filter).clone(), s("source")?))))
        }
        "Adapter\\SQL\\Hook\\Tenant\\Raw" => sql_obj(SqlHook::Raw(Arc::new(tenant::Raw::new(
            value::to_query(&a.value("tenant")?),
            s("table")?,
            a.bool("metadata")?,
            s("quoteCharacter")?,
        )))),
        "Adapter\\SQL\\Hook\\Tenant\\RawOuterJoin" => {
            let SqlHook::Raw(filter) = sql_hook(a, "filter")?.0 else {
                return fault("Tenant\\RawOuterJoin takes a Tenant\\Raw");
            };
            sql_obj(SqlHook::RawOuterJoin(Arc::new(tenant::RawOuterJoin::new(filter))))
        }
        _ => return Ok(None),
    }))
}

fn builder_obj(b: SqlBuilder) -> Obj {
    object(BuilderObj(Arc::new(Mutex::new(b)), None))
}

fn sql_obj(h: SqlHook) -> Obj {
    object(SqlHookObj(h))
}

// ----- static -----

/// `<class>::<method>(...)` for the classes of this module.
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Option<Obj>> {
    Ok(Some(match (class, method) {
        ("Adapter\\SQL\\Hook\\Join\\Chain", "fromQueries") => {
            object(ChainObj(join::Chain::from_queries(&a.queries("queries")?)))
        }
        ("Adapter\\SQL\\Hook\\Column\\AllowNull", "wrap") => object(ConditionObj(column::AllowNull::wrap(
            a.req::<ConditionObj>("condition")?.0,
            &a.string("column")?,
            &a.string_or("quoteCharacter", "`")?,
        )?)),
        ("Adapter\\SQL\\Hook\\Column\\AllowNull", "quote") => {
            column::AllowNull::quote(&a.string("identifier")?, &a.string_or("quoteCharacter", "`")?).into_obj()
        }
        _ => return Ok(None),
    }))
}

// ----- $this->method() -----

/// `$this-><method>(...)` for the objects of this module.
pub async fn invoke(this: &mut Obj, method: &str, a: &Args) -> R<Option<Obj>> {
    let calls = Calls::new(&a.cx);
    let result = dispatch(this, method, a, &calls).await;
    match result {
        Ok(Some(obj)) => calls.finish(Ok(obj)).map(Some),
        Ok(None) => Ok(None),
        Err(e) => calls.finish(Err(e)).map(Some),
    }
}

async fn dispatch(this: &mut Obj, method: &str, a: &Args, calls: &Calls) -> R<Option<Obj>> {
    // `Validator\Authorization`'s methods are core's (`core::authorization`).
    if this.downcast_ref::<AuthorizationObj>().is_some() {
        return Ok(None);
    }
    if let Some(input) = this.downcast_mut::<InputObj>() {
        return Ok(Some(match method {
            "getAction" => input.0.action.clone().into_obj(),
            "getPermissions" => strings(&input.0.permissions),
            "setAction" => {
                input.0.set_action(action(a, "action")?);
                object(input.clone())
            }
            "setPermissions" => {
                input.0.set_permissions(a.strings("permissions")?);
                object(input.clone())
            }
            _ => return fault(format!("Validator\\Authorization\\Input has no method `{method}`")),
        }));
    }
    if let Some(profiler) = this.downcast_ref::<ProfilerObj>() {
        let profiler = profiler.0.clone();
        return profile(&profiler, this, method, a, calls).map(Some);
    }
    if let Some(q) = this.downcast_ref::<QueryCacheObj>() {
        let q = q.0.clone();
        return query_cache(&q, this, method, a).await.map(Some);
    }
    if let Some(o) = this.downcast_ref::<OwnersObj>() {
        let o = o.0.clone();
        return Ok(Some(match method {
            "register" => o.register(&a.string("key")?, &a.string("token")?).await?.into_obj(),
            "find" => object(o.find(&a.string("key")?, &a.string("token")?).await?),
            _ => return fault(format!("Cache\\Owners has no method `{method}`")),
        }));
    }
    if let Some(scope) = this.downcast_ref::<Scope>() {
        return Ok(Some(match method {
            "withTenant" => object(scope.with_tenant(a.value("tenant")?)),
            _ => return fault(format!("Cache\\Scope has no method `{method}`")),
        }));
    }
    if let Some(r) = this.downcast_ref::<RegistryObj>() {
        let r = r.0.clone();
        return registry(&r, this, method, a).map(Some);
    }
    if let Some(c) = this.downcast_ref::<CodecObj>() {
        let c = c.0.clone();
        return Ok(Some(match method {
            "name" => c.name().into_obj(),
            "encode" => c.encode(a.value("value")?)?.into_obj(),
            "decode" => c.decode(a.value("value")?)?.into_obj(),
            "signature" => c.signature().into_obj(),
            _ => return fault(format!("Filter\\Codec has no method `{method}`")),
        }));
    }
    if let Some(h) = this.downcast_ref::<HookObj>() {
        let h = h.clone();
        return hook(&h, this, method, a, calls).await.map(Some);
    }
    if let Some(m) = this.downcast_ref::<MongoPermissionObj>() {
        let m = m.0.clone();
        return Ok(Some(match method {
            "applyFilters" => m
                .apply_filters(
                    a.array("filters")?,
                    &a.string("collection")?,
                    a.enum_::<PermissionType>("forPermission")?.unwrap_or(PermissionType::Read),
                )
                .into_obj(),
            _ => return fault(format!("Hook\\Mongo\\Permission has no method `{method}`")),
        }));
    }
    if let Some(v) = this.downcast_ref::<StateValueObj>() {
        let v = v.0.clone();
        return Ok(Some(match method {
            "get" => v.get().into_obj(),
            "set" => {
                v.set(a.value("value")?);
                Obj::null()
            }
            "with" => {
                let cb = calls.required("callback", a.raw("callback"))?;
                returned(v.with(a.value("value")?, cb.invoke(Vec::new())).await)?
            }
            _ => return fault(format!("State\\Value has no method `{method}`")),
        }));
    }
    if let Some(b) = this.downcast_ref::<BuilderObj>() {
        let b = b.clone();
        let out = query_builder(&b, this, method, a, calls).await;
        // PHP throws a bound builder's refusal from the call that made it.
        if let Some(e) = lock(&b.0).take_refusal() {
            return Err(query_fail(e));
        }
        return out.map(Some);
    }
    if let Some(s) = this.downcast_ref::<StatementObj>() {
        let s = s.clone();
        return Ok(Some(match method {
            "execute" => execute(&s.statement, s.db.as_ref()).await?,
            _ => return fault(format!("Query\\Builder\\Statement has no method `{method}`")),
        }));
    }
    if let Some(j) = this.downcast_mut::<JoinBuilderObj>() {
        let operator = a.string_or("operator", "=")?;
        match method {
            "on" => {
                j.0.on(&a.string("left")?, &a.string("right")?, &operator)?;
            }
            "onRaw" => {
                j.0.on_raw(a.string("expression")?, query_list(Some(&a.cx), a.raw("bindings"))?);
            }
            "where" => {
                j.0.where_(&a.string("column")?, &operator, value::to_query(&a.value("value")?))?;
            }
            "whereRaw" => {
                j.0.where_raw(a.string("expression")?, query_list(Some(&a.cx), a.raw("bindings"))?);
            }
            _ => return fault(format!("Query\\Builder\\JoinBuilder has no method `{method}`")),
        }
        return Ok(Some(object(j.clone())));
    }
    if let Some(c) = this.downcast_ref::<ChainObj>() {
        let c = &c.0;
        return Ok(Some(match method {
            "type" => enums::obj(c.join_type(&a.string("alias")?)),
            "has" => c.has(a.req_enum::<JoinType>("type")?).into_obj(),
            "hasPreservingOuterJoin" => c.has_preserving_outer_join().into_obj(),
            "preceding" => strings(&c.preceding(&a.string("alias")?)),
            _ => return fault(format!("Adapter\\SQL\\Hook\\Join\\Chain has no method `{method}`")),
        }));
    }
    if let Some(h) = this.downcast_ref::<SqlHookObj>() {
        let h = h.clone();
        return sql_hook_call(&h, method, a).map(Some);
    }
    if let Some(p) = this.downcast_ref::<Profile>() {
        return Ok(Some(match method {
            "supports" => p.supports(a.req_enum::<Capability>("capability")?).into_obj(),
            "hasFeature" => p.has_feature(feature_of(&a.string("feature")?)?).into_obj(),
            _ => return fault(format!("Adapter\\Profile has no method `{method}`")),
        }));
    }
    Ok(None)
}

fn profile(p: &Arc<Profiler>, this: &Obj, method: &str, a: &Args, calls: &Calls) -> R<Obj> {
    let fluent = || this.clone();
    Ok(match method {
        "enable" => {
            p.enable();
            fluent()
        }
        "disable" => {
            p.disable();
            fluent()
        }
        "isEnabled" => p.is_enabled().into_obj(),
        "setSlowThreshold" => {
            p.set_slow_threshold(a.opt_float("milliseconds")?.unwrap_or(0.0));
            fluent()
        }
        "setCapacity" => {
            p.set_capacity(a.int("capacity")?)?;
            fluent()
        }
        "getCapacity" => p.capacity().into_obj(),
        "enableBacktrace" => {
            p.enable_backtrace(a.bool_or("enabled", true)?);
            fluent()
        }
        "onSlowQuery" => {
            let cb = deferred(calls, a, "callback")?;
            p.on_slow_query(move |log: &Log| {
                let _ = now(&cb, vec![values::log(log)]);
            });
            fluent()
        }
        "log" => {
            let bindings = match a.value("bindings")? {
                Value::Array(b) => b,
                _ => Array::new(),
            };
            p.log(
                &a.string("query")?,
                bindings,
                a.opt_float("durationMs")?.unwrap_or(0.0),
                &a.string_or("collection", "")?,
                &a.string_or("operation", "")?,
            );
            Obj::null()
        }
        "getLogs" => objects(p.logs()),
        "getSlowQueries" => {
            let slow = p.slow_queries();
            if slow.iter().enumerate().all(|(i, (k, _))| i == *k) {
                objects(slow.into_iter().map(|(_, l)| l).collect())
            } else {
                Obj::Map(slow.into_iter().map(|(k, l)| (k.to_string(), object(l))).collect())
            }
        }
        "getQueryCount" => p.query_count().into_obj(),
        "getTotalTime" => p.total_time().into_obj(),
        "detectNPlusOne" => {
            let mut out = Array::new();
            for (pattern, count) in p.detect_n_plus_one(a.int_or("threshold", 5)?) {
                out.set(ArrayKey::normalize(Str::from(pattern.as_str())), Value::Int(count));
            }
            out.into_obj()
        }
        "reset" => {
            p.reset();
            Obj::null()
        }
        _ => return fault(format!("Profiler has no method `{method}`")),
    })
}

async fn query_cache(q: &Arc<cache::Query>, this: &Obj, method: &str, a: &Args) -> R<Obj> {
    let scope = || a.req::<Scope>("scope");
    // An entry `getEntry()` did not find is `null`: PHP refuses it as the
    // `Entry` parameter.
    let entry = || match a.raw("entry") {
        Some(j) if a.decode_value(j).is_ok_and(|v| matches!(v, Value::Null)) => Err(Fail::php(
            "TypeError",
            format!(
                "Utopia\\Database\\Cache\\Query::{method}(): Argument #1 ($entry) must be of type Utopia\\Database\\Cache\\Entry, null given, called in /usr/src/code/tests/compat/database/ops/core.php on line 609"
            ),
        )),
        _ => a.req::<Entry>("entry"),
    };
    Ok(match method {
        "setRegion" => {
            q.set_region(&a.string("collection")?, a.req::<Region>("region")?);
            this.clone()
        }
        "getRegion" => object(q.region(&a.string("collection")?)),
        "getCollectionKey" => q.collection_key(&scope()?, &a.string("collection")?).into_obj(),
        "getEntry" => {
            let queries = a.value("queries")?;
            q.entry(&scope()?, &a.string("collection")?, &queries, &a.string_or("context", "")?)
                .await?
                .map(object)
                .into_obj()
        }
        "get" => q.get(&entry()?).await?.into_obj(),
        "getGeneration" => q.generation(&entry()?).await?.into_obj(),
        "set" => q.set(&entry()?, &a.documents("results")?, &a.string("generation")?).await?.into_obj(),
        "invalidateCollection" => {
            q.invalidate_collection(&scope()?, &a.string("collection")?).await?;
            Obj::null()
        }
        "createToken" => q.create_token().into_obj(),
        "blockCollection" => {
            q.block_collection(&a.string("key")?, &a.string("token")?).await?;
            Obj::null()
        }
        "activateCollection" => {
            q.activate_collection(&a.string("key")?, &a.string("token")?, a.int("writerTimeout")?).await?;
            Obj::null()
        }
        "flush" => {
            q.flush().await?;
            Obj::null()
        }
        _ => return fault(format!("Cache\\Query has no method `{method}`")),
    })
}

fn registry(r: &Arc<RwLock<Registry>>, this: &Obj, method: &str, a: &Args) -> R<Obj> {
    Ok(match method {
        "register" => {
            let codec = dbmod::codec_param(a, "codec")?;
            r.write().unwrap_or_else(|e| e.into_inner()).register(codec.0)?;
            this.clone()
        }
        "get" => {
            let found = r.read().unwrap_or_else(|e| e.into_inner()).get(&a.string("name")?).cloned();
            found.map(|c| object(CodecObj(c))).into_obj()
        }
        "has" => r.read().unwrap_or_else(|e| e.into_inner()).has(&a.string("name")?).into_obj(),
        "signatures" => {
            let mut out = Array::new();
            for (name, signature) in r.read().unwrap_or_else(|e| e.into_inner()).signatures() {
                out.set(ArrayKey::normalize(Str::from(name)), Value::Str(Str::from(signature)));
            }
            out.into_obj()
        }
        _ => return fault(format!("Filter\\Registry has no method `{method}`")),
    })
}

async fn hook(h: &HookObj, this: &Obj, method: &str, a: &Args, calls: &Calls) -> R<Obj> {
    let missing = || fault(format!("{} has no method `{method}`", h.class));
    match &h.hook {
        Hook::Relationships(r) => Ok(match method {
            "isEnabled" => r.is_enabled().into_obj(),
            "setEnabled" => {
                r.set_enabled(a.bool("enabled")?);
                Obj::null()
            }
            "withEnabled" => {
                let cb = calls.required("callback", a.raw("callback"))?;
                returned(r.with_enabled(a.bool("enabled")?, cb.invoke(Vec::new())).await)?
            }
            "shouldCheckExist" => r.should_check_exist().into_obj(),
            "withCheckExist" => {
                let cb = calls.required("callback", a.raw("callback"))?;
                returned(r.with_check_exist(a.bool("check")?, cb.invoke(Vec::new())).await)?
            }
            "getWriteStackCount" => r.write_stack_count().into_obj(),
            "getFetchDepth" => r.fetch_depth().into_obj(),
            "isInBatchPopulation" => r.is_in_batch_population().into_obj(),
            "withSnapshot" => {
                let cb = calls.required("callback", a.raw("callback"))?;
                let snapshot = a.req::<SnapshotObj>("snapshot")?;
                returned(r.with_snapshot(&snapshot.0, cb.invoke(Vec::new())).await)?
            }
            "attach" => {
                let db = a.req::<dbmod::DatabaseObj>("database")?;
                r.attach(&db.0)?;
                Obj::null()
            }
            _ => return missing(),
        }),
        Hook::Write(w, _) => Ok(match method {
            "decorateRow" => {
                let metadata = a.req::<RowMetadata>("metadata")?;
                w.decorate_row(a.array("row")?, &metadata).into_obj()
            }
            _ => return missing(),
        }),
        Hook::Invalidator(i) => Ok(match method {
            "isMutation" => i.is_mutation(a.req_enum::<Event>("event")?).into_obj(),
            _ => return missing(),
        }),
        Hook::Lifecycle(lifecycle, _) => {
            let Some(dispatcher) = dispatcher_of(h) else {
                return missing();
            };
            Ok(match method {
                "on" => {
                    let event = event_of_class(&a.string("eventClass")?)?;
                    let cb = deferred(calls, a, "listener")?;
                    dispatcher.on(event, move |domain: &Domain| now(&cb, vec![values::event(domain)]).map(|_| ()));
                    this.clone()
                }
                "handles" => dispatcher.handles(a.req_enum::<Event>("event")?).into_obj(),
                "handle" => {
                    let event = a.req::<Domain>("event")?;
                    lifecycle.handle(&event).await?;
                    Obj::null()
                }
                _ => return missing(),
            })
        }
        _ => missing(),
    }
}

/// Runs a statement through its database (PHP's executor: a read-only
/// statement is a `query()`, any other a `mutate()`).
async fn execute(statement: &Statement, db: Option<&Database>) -> R<Obj> {
    let Some(db) = db else {
        return Err(query_fail(utopia_query::Error::NoExecutor("No executor configured on this plan".into())));
    };
    Ok(if statement.read_only { db.query(statement).await?.into_obj() } else { db.mutate(statement).await?.into_obj() })
}

async fn query_builder(b: &BuilderObj, this: &Obj, method: &str, a: &Args, calls: &Calls) -> R<Obj> {
    let fluent = || this.clone();
    let s = |k: &str| a.string(k);
    let alias = || a.string_or("alias", "");
    let operator = || a.string_or("operator", "=");
    // Statements: built under the lock, run after it.
    let built = |r: utopia_query::Result<Statement>| -> R<Obj> {
        Ok(object(StatementObj { statement: r.map_err(query_fail)?, db: b.1.clone() }))
    };
    match method {
        "build" => return built(lock(&b.0).build()),
        "insert" => return built(lock(&b.0).insert()),
        "update" => return built(lock(&b.0).update()),
        "delete" => return built(lock(&b.0).delete()),
        "insertDefaultValues" => return built(lock(&b.0).insert_default_values()),
        "execute" => {
            let statement = lock(&b.0).build().map_err(query_fail)?;
            return execute(&statement, b.1.as_ref()).await;
        }
        "joinWhere" => {
            let cb = calls.required("callback", a.raw("callback"))?;
            let join_type = a.enum_::<JoinType>("type")?.unwrap_or(JoinType::Inner);
            let table = s("table")?;
            let alias = alias()?;
            // PHP's replay calls the steps on a copy of the join builder it
            // encoded, so the builder's own join keeps no conditions.
            let mut guard = lock(&b.0);
            each_builder!(&mut *guard, x => x.join_where(table.as_str(), |join: &mut JoinBuilder| {
                now(&cb, vec![join_builder_json(join)]).map(|_| ()).map_err(builder::query_error)
            }, join_type, alias.as_str()).map(|_| ()))
            .map_err(query_fail)?;
            return Ok(fluent());
        }
        _ => {}
    }
    let mut guard = lock(&b.0);
    let x = &mut *guard;
    Ok(match method {
        "from" => {
            x.from(&s("table")?, &alias()?);
            fluent()
        }
        "fromNone" => {
            x.from_none();
            fluent()
        }
        "fromTable" => {
            x.from_table(&s("table")?, &alias()?).map_err(query_fail)?;
            fluent()
        }
        "into" => {
            x.into(&s("table")?);
            fluent()
        }
        "insertAs" => {
            x.insert_as(&alias()?);
            fluent()
        }
        "select" => {
            x.select(a.strings("columns")?);
            fluent()
        }
        "selectRaw" => {
            x.select_raw(&s("expression")?, query_list(Some(&a.cx), a.raw("bindings"))?);
            fluent()
        }
        "filter" => {
            x.filter(inner_queries(a, "queries")?);
            fluent()
        }
        "queries" => {
            x.queries(inner_queries(a, "queries")?);
            fluent()
        }
        "having" => {
            x.having(inner_queries(a, "queries")?);
            fluent()
        }
        "distinct" => {
            x.distinct();
            fluent()
        }
        "limit" => {
            x.limit(a.int("value")?);
            fluent()
        }
        "offset" => {
            x.offset(a.int("value")?);
            fluent()
        }
        "sortAsc" => {
            x.sort_asc(&s("attribute")?);
            fluent()
        }
        "sortDesc" => {
            x.sort_desc(&s("attribute")?);
            fluent()
        }
        "sortRandom" => {
            x.sort_random();
            fluent()
        }
        "groupBy" => {
            x.group_by(a.strings("columns")?);
            fluent()
        }
        "onConflict" => {
            x.on_conflict(a.strings("keys")?, a.strings("updateColumns")?);
            fluent()
        }
        "reset" => {
            x.reset();
            fluent()
        }
        "set" => {
            x.set(value::to_query_array(&a.array("row")?)).map_err(query_fail)?;
            fluent()
        }
        "isScoped" => x.is_scoped().into_obj(),
        "getBindings" => Obj::Value(Value::Array(x.bindings().iter().map(value::from_query).collect())),
        "compileFilters" => object(ConditionObj(x.compile_filters(&a.queries("filters")?).map_err(query_fail)?)),
        "join" | "leftJoin" | "rightJoin" => {
            let (table, left, right, op, alias) = (s("table")?, s("left")?, s("right")?, operator()?, alias()?);
            match method {
                "join" => each_builder!(x, y => { y.join(table, &left, &right, &op, alias); }),
                "leftJoin" => each_builder!(x, y => { y.left_join(table, &left, &right, &op, alias); }),
                _ => each_builder!(x, y => { y.right_join(table, &left, &right, &op, alias); }),
            }
            fluent()
        }
        "fullOuterJoin" => {
            let (table, left, right, op, alias) = (s("table")?, s("left")?, s("right")?, operator()?, alias()?);
            let SqlBuilder::PostgreSql(y) = x else {
                return fault("fullOuterJoin is PostgreSQL's");
            };
            y.full_outer_join(table, &left, &right, &op, alias);
            fluent()
        }
        "crossJoin" => {
            let (table, alias) = (s("table")?, alias()?);
            each_builder!(x, y => { y.cross_join(table.as_str(), alias.as_str()); });
            fluent()
        }
        "filterJsonContains" | "filterJsonNotContains" | "filterJsonOverlaps" => {
            let SqlBuilder::Sqlite(y) = x else {
                return fault(format!("{method} is SQLite's"));
            };
            let attribute = s("attribute")?;
            match method {
                "filterJsonContains" => {
                    y.filter_json_contains(attribute, value::to_query(&a.value("value")?));
                }
                "filterJsonNotContains" => {
                    y.filter_json_not_contains(attribute, value::to_query(&a.value("value")?));
                }
                _ => {
                    y.filter_json_overlaps(attribute, query_list(Some(&a.cx), a.raw("values"))?);
                }
            }
            fluent()
        }
        _ => return fault(format!("Query\\Builder has no method `{method}`")),
    })
}

fn sql_hook_call(h: &SqlHookObj, method: &str, a: &Args) -> R<Obj> {
    let join_type = || a.req_enum::<JoinType>("joinType");
    Ok(match (method, &h.0) {
        ("filter", _) => match h.filter() {
            Some(f) => object(ConditionObj(f.filter(&a.string("table")?).map_err(query_fail)?)),
            None => return fault(format!("{} is not a filter", h.short())),
        },
        ("filterJoin", _) => match h.join_filter() {
            Some(f) => f
                .filter_join(&a.string("table")?, join_type()?)
                .map_err(query_fail)?
                .map(|c| object(JoinConditionObj(c)))
                .into_obj(),
            None => return fault(format!("{} is not a join filter", h.short())),
        },
        ("joined", SqlHook::TenantFilter(f)) => object(ConditionObj(f.joined(&a.string("table")?))),
        ("reset", SqlHook::Raw(r)) => {
            r.reset();
            Obj::null()
        }
        ("outerJoin", SqlHook::Raw(r)) => object(ConditionObj(r.outer_join(&a.string("table")?, join_type()?)?)),
        ("collate", SqlHook::PermissionFilter(f)) => {
            sql_obj(SqlHook::PermissionFilter(Arc::new(f.collate(&a.string("collation")?)?)))
        }
        ("withoutSemiJoin", SqlHook::PermissionFilter(f)) => {
            sql_obj(SqlHook::PermissionFilter(Arc::new(f.without_semi_join())))
        }
        _ => return fault(format!("Adapter\\SQL\\Hook {} has no method `{method}`", h.short())),
    })
}
