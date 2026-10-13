//! [`Metric`]: one row read back, and the schema rows are written with.

use std::sync::LazyLock;

use php_std::{ArrayKey, Str};
use utopia_query::{Array, Value};

use crate::error::{Error, Result};
use crate::php;

/// Which table a metric lives in: additive events or point-in-time gauges.
///
/// PHP: the `Usage::TYPE_EVENT` / `Usage::TYPE_GAUGE` strings.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// `event`: additive (bandwidth, requests), summed.
    Event,
    /// `gauge`: a snapshot (storage, users), read as the latest sample.
    Gauge,
}

impl Kind {
    /// `event` or `gauge`.
    pub const fn as_str(self) -> &'static str {
        match self {
            Kind::Event => "event",
            Kind::Gauge => "gauge",
        }
    }

    /// The kind named `name`; anything else is refused as `Usage::sum()`
    /// refuses it (`InvalidArgumentException`).
    pub fn parse(name: &str) -> Result<Kind> {
        match name {
            "event" => Ok(Kind::Event),
            "gauge" => Ok(Kind::Gauge),
            other => Err(Error::invalid_argument(format!("Invalid type '{other}'. Allowed: event, gauge"))),
        }
    }

    /// The dimension columns rows of this kind carry.
    pub fn columns(self) -> &'static [&'static str] {
        match self {
            Kind::Event => EVENT_COLUMNS,
            Kind::Gauge => GAUGE_COLUMNS,
        }
    }

    /// The attribute schema of this kind's table.
    pub fn schema(self) -> &'static [Attribute] {
        match self {
            Kind::Event => &EVENT_SCHEMA,
            Kind::Gauge => &GAUGE_SCHEMA,
        }
    }

    /// The index definitions of this kind's table.
    pub fn indexes(self) -> &'static [Index] {
        match self {
            Kind::Event => &EVENT_INDEXES,
            Kind::Gauge => &GAUGE_INDEXES,
        }
    }

    /// Whether `id` is an attribute of this kind's schema.
    pub fn has_attribute(self, id: &str) -> bool {
        self.schema().iter().any(|a| a.id == id)
    }
}

/// A PHP number: an integer or a float (aggregates may be either).
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Number {
    /// An `int`.
    Int(i64),
    /// A `float`.
    Float(f64),
}

impl Number {
    /// The number as a query value.
    pub fn to_value(self) -> Value {
        match self {
            Number::Int(i) => Value::Int(i),
            Number::Float(f) => Value::Float(f),
        }
    }

    /// `(int)` of the number.
    pub fn to_int(self) -> i64 {
        match self {
            Number::Int(i) => i,
            Number::Float(f) => php_std::format::dval_to_lval(f),
        }
    }
}

/// Event dimension columns, extracted from tags into dedicated columns.
///
/// PHP: `Metric::EVENT_COLUMNS`.
pub const EVENT_COLUMNS: &[&str] = &[
    "path",
    "method",
    "status",
    "service",
    "resourceType",
    "resourceId",
    "resourceInternalId",
    "teamId",
    "teamInternalId",
    "country",
    "region",
    "hostname",
    "ip",
    "protocol",
    "accept",
    "acceptLanguage",
    "queryKeys",
    "ipReputation",
    "city",
    "continentCode",
    "subdivisions",
    "postalCode",
    "latitude",
    "longitude",
    "timeZone",
    "weatherCode",
    "isp",
    "autonomousSystemNumber",
    "autonomousSystemOrganization",
    "connectionType",
    "connectionUsageType",
    "connectionOrganization",
    "osCode",
    "osName",
    "osVersion",
    "clientType",
    "clientCode",
    "clientName",
    "clientVersion",
    "clientEngine",
    "clientEngineVersion",
    "sdk",
    "sdkVersion",
    "deviceName",
    "deviceBrand",
    "deviceModel",
];

/// Gauge dimension columns.
///
/// PHP: `Metric::GAUGE_COLUMNS`.
pub const GAUGE_COLUMNS: &[&str] =
    &["service", "resourceType", "teamId", "teamInternalId", "resourceId", "resourceInternalId", "ordinal"];

/// One attribute of a table's schema.
///
/// PHP: the arrays of `Metric::getEventSchema()` / `getGaugeSchema()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Attribute {
    /// `$id`.
    pub id: &'static str,
    /// `type`: `string`, `integer` or `datetime`.
    pub kind: &'static str,
    /// `size` (0 for unbounded).
    pub size: i64,
    /// `required`.
    pub required: bool,
    /// `signed`.
    pub signed: bool,
    /// `array`.
    pub array: bool,
    /// `format` (datetimes only).
    pub format: Option<&'static str>,
    /// `filters`.
    pub filters: &'static [&'static str],
}

impl Attribute {
    const fn string(id: &'static str, size: i64) -> Attribute {
        Attribute { id, kind: "string", size, required: false, signed: true, array: false, format: None, filters: &[] }
    }

    /// The PHP array, keys in PHP's order.
    pub fn to_array(&self) -> Array {
        let mut a = Array::default();
        let mut set = |k: &str, v: Value| {
            a.set(ArrayKey::from(k), v);
        };
        set("$id", Value::from(self.id));
        set("type", Value::from(self.kind));
        if let Some(format) = self.format {
            set("format", Value::from(format));
            set("size", Value::Int(self.size));
            set("signed", Value::Bool(self.signed));
            set("required", Value::Bool(self.required));
        } else {
            set("size", Value::Int(self.size));
            set("required", Value::Bool(self.required));
            set("signed", Value::Bool(self.signed));
        }
        set("array", Value::Bool(self.array));
        set("filters", Value::Array(Array::from_list(self.filters.iter().map(|f| Value::from(*f)).collect())));
        a
    }
}

/// One index of a table's schema.
///
/// PHP: the arrays of `Metric::getEventIndexes()` / `getGaugeIndexes()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Index {
    /// `$id`: `index-<column>`.
    pub id: String,
    /// `type`: `key`.
    pub kind: &'static str,
    /// `attributes`.
    pub attributes: Vec<&'static str>,
    /// `indexType`: ClickHouse's skip index (`set(0)` or `bloom_filter`).
    pub index_type: Option<&'static str>,
    /// `lengths`: the key prefix SQL adapters index long columns on.
    pub lengths: Option<Vec<i64>>,
}

impl Index {
    /// The PHP array, keys in PHP's order.
    pub fn to_array(&self) -> Array {
        let mut a = Array::default();
        a.set(ArrayKey::from("$id"), Value::from(self.id.as_str()));
        a.set(ArrayKey::from("type"), Value::from(self.kind));
        a.set(
            ArrayKey::from("attributes"),
            Value::Array(Array::from_list(self.attributes.iter().map(|c| Value::from(*c)).collect())),
        );
        if let Some(index_type) = self.index_type {
            a.set(ArrayKey::from("indexType"), Value::from(index_type));
        }
        if let Some(lengths) = &self.lengths {
            a.set(
                ArrayKey::from("lengths"),
                Value::Array(Array::from_list(lengths.iter().map(|l| Value::Int(*l)).collect())),
            );
        }
        a
    }
}

const CORE: [Attribute; 3] = [
    Attribute {
        id: "metric",
        kind: "string",
        size: 255,
        required: true,
        signed: true,
        array: false,
        format: None,
        filters: &[],
    },
    Attribute {
        id: "value",
        kind: "integer",
        size: 0,
        required: true,
        signed: true,
        array: false,
        format: None,
        filters: &[],
    },
    Attribute {
        id: "time",
        kind: "datetime",
        size: 0,
        required: false,
        signed: true,
        array: false,
        format: Some(""),
        filters: &["datetime"],
    },
];

static EVENT_SCHEMA: LazyLock<Vec<Attribute>> = LazyLock::new(|| {
    let mut schema = CORE.to_vec();
    schema.extend(
        [
            ("path", 1024),
            ("method", 16),
            ("status", 16),
            ("service", 256),
            ("resourceType", 256),
            ("resourceId", 255),
            ("resourceInternalId", 255),
            ("teamId", 255),
            ("teamInternalId", 255),
            ("country", 2),
            ("region", 64),
            ("hostname", 255),
            ("ip", 45),
            ("protocol", 16),
            ("accept", 1024),
            ("acceptLanguage", 256),
            ("queryKeys", 1024),
            ("ipReputation", 32),
            ("city", 256),
            ("continentCode", 2),
            ("subdivisions", 256),
            ("postalCode", 32),
            ("latitude", 32),
            ("longitude", 32),
            ("timeZone", 64),
            ("weatherCode", 16),
            ("isp", 256),
            ("autonomousSystemNumber", 255),
            ("autonomousSystemOrganization", 256),
            ("connectionType", 256),
            ("connectionUsageType", 256),
            ("connectionOrganization", 256),
            ("osCode", 256),
            ("osName", 256),
            ("osVersion", 255),
            ("clientType", 256),
            ("clientCode", 256),
            ("clientName", 256),
            ("clientVersion", 255),
            ("clientEngine", 256),
            ("clientEngineVersion", 255),
            ("sdk", 256),
            ("sdkVersion", 255),
            ("deviceName", 256),
            ("deviceBrand", 256),
            ("deviceModel", 255),
        ]
        .into_iter()
        .map(|(id, size)| Attribute::string(id, size)),
    );
    schema
});

static GAUGE_SCHEMA: LazyLock<Vec<Attribute>> = LazyLock::new(|| {
    let mut schema = CORE.to_vec();
    schema.extend(
        [
            ("service", 256),
            ("resourceType", 256),
            ("teamId", 255),
            ("teamInternalId", 255),
            ("resourceId", 255),
            ("resourceInternalId", 255),
            ("ordinal", 255),
        ]
        .into_iter()
        .map(|(id, size)| Attribute::string(id, size)),
    );
    schema
});

static EVENT_INDEXES: LazyLock<Vec<Index>> = LazyLock::new(|| {
    const INDEXED: &[&str] = &[
        "path",
        "method",
        "status",
        "service",
        "resourceType",
        "resourceId",
        "resourceInternalId",
        "teamId",
        "teamInternalId",
        "country",
        "region",
        "hostname",
        "ip",
        "osName",
        "clientType",
        "clientName",
        "deviceName",
        "protocol",
        "accept",
        "acceptLanguage",
        "queryKeys",
        "ipReputation",
        "postalCode",
        "timeZone",
        "weatherCode",
    ];
    const SET: &[&str] = &[
        "status",
        "method",
        "country",
        "service",
        "clientType",
        "osName",
        "protocol",
        "ipReputation",
        "timeZone",
        "weatherCode",
    ];
    const PREFIXED: &[(&str, i64)] = &[("path", 255), ("accept", 255), ("queryKeys", 255)];
    INDEXED
        .iter()
        .map(|col| Index {
            id: format!("index-{col}"),
            kind: "key",
            attributes: vec![*col],
            index_type: Some(if SET.contains(col) { "set(0)" } else { "bloom_filter" }),
            lengths: PREFIXED.iter().find(|(c, _)| c == col).map(|(_, l)| vec![*l]),
        })
        .collect()
});

static GAUGE_INDEXES: LazyLock<Vec<Index>> = LazyLock::new(|| {
    const INDEXED: &[&str] =
        &["service", "resourceType", "resourceId", "resourceInternalId", "teamId", "teamInternalId", "ordinal"];
    const SET: &[&str] = &["service", "resourceType", "ordinal"];
    INDEXED
        .iter()
        .map(|col| Index {
            id: format!("index-{col}"),
            kind: "key",
            attributes: vec![*col],
            index_type: Some(if SET.contains(col) { "set(0)" } else { "bloom_filter" }),
            lengths: None,
        })
        .collect()
});

/// The dimension columns of a row, as extracted from its tags: each of the
/// kind's columns in order, `None` when absent or empty.
pub type Columns = Vec<(&'static str, Option<Str>)>;

/// One usage metric read back: its attributes as the adapter returned
/// them (`$id`, `metric`, `value`, `time`, `type`, `tenant`, dimension
/// columns, grouped dimensions), with typed getters for the common ones.
///
/// PHP: `Utopia\Usage\Metric` (an `ArrayObject`).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Metric {
    attributes: Array,
}

impl Metric {
    /// A metric of `attributes`.
    ///
    /// PHP: `new Metric($input)`.
    pub fn new(attributes: Array) -> Metric {
        Metric { attributes }
    }

    /// `isset($this[$name]) ? $this[$name] : null`.
    fn isset(&self, name: &str) -> Option<&Value> {
        self.attributes.get(&ArrayKey::from(name)).filter(|v| !matches!(v, Value::Null))
    }

    fn string(&self, name: &str) -> Option<&Str> {
        match self.isset(name) {
            Some(Value::Str(s)) => Some(s),
            _ => None,
        }
    }

    /// The id, or `""`.
    ///
    /// PHP: `getId()`.
    pub fn id(&self) -> &[u8] {
        self.string("$id").map_or(b"", |s| s.as_bytes())
    }

    /// The metric name, or `""`.
    ///
    /// PHP: `getMetric()`.
    pub fn metric(&self) -> &[u8] {
        self.string("metric").map_or(b"", |s| s.as_bytes())
    }

    /// The value when it is a number; `default` otherwise (an unset value
    /// with no default is `0`).
    ///
    /// PHP: `getValue($default)`.
    pub fn value(&self, default: Option<Number>) -> Option<Number> {
        match self.isset("value") {
            Some(Value::Int(i)) => Some(Number::Int(*i)),
            Some(Value::Float(f)) => Some(Number::Float(*f)),
            Some(_) => default,
            None => Some(default.unwrap_or(Number::Int(0))),
        }
    }

    /// The type (`event` unless it says otherwise).
    ///
    /// PHP: `getType()`.
    pub fn kind(&self) -> &[u8] {
        self.string("type").map_or(b"event", |s| s.as_bytes())
    }

    /// A string attribute, `None` when unset or not a string. The typed
    /// getters of PHP (`getTime()`, `getPath()`, `getMethod()`, ...) are
    /// this with their attribute: `time`, `path`, `method`, `status`,
    /// `resourceType`, `resourceId`, `country`, `service`,
    /// `resourceInternalId`, `ordinal`, `teamId`, `teamInternalId`,
    /// `region`, `hostname`, `ip`, `osCode`, `osName`, `osVersion`,
    /// `clientType`, `clientCode`, `clientName`, `clientVersion`,
    /// `clientEngine`, `clientEngineVersion`, `deviceName`, `deviceBrand`,
    /// `deviceModel`.
    ///
    /// PHP: `getTime()`, `getPath()`, ...
    pub fn text(&self, name: &str) -> Option<&[u8]> {
        self.string(name).map(|s| s.as_bytes())
    }

    /// The tenant: a string, or a number as a string; `None` otherwise.
    ///
    /// PHP: `getTenant()`.
    pub fn tenant(&self) -> Option<Str> {
        match self.isset("tenant")? {
            Value::Str(s) => Some(s.clone()),
            v if php::is_numeric(v) => v.to_php_string().ok(),
            _ => None,
        }
    }

    /// Every attribute.
    ///
    /// PHP: `getAttributes()`, `toArray()`.
    pub fn attributes(&self) -> &Array {
        &self.attributes
    }

    /// The attributes, owned.
    pub fn into_attributes(self) -> Array {
        self.attributes
    }

    /// An attribute, or `default` when unset (or null).
    ///
    /// PHP: `getAttribute($name, $default)`.
    pub fn attribute<'a>(&'a self, name: &str, default: &'a Value) -> &'a Value {
        self.isset(name).unwrap_or(default)
    }

    /// Sets an attribute.
    ///
    /// PHP: `setAttribute($key, $value)`.
    pub fn set_attribute(&mut self, key: &str, value: Value) -> &mut Self {
        self.attributes.set(ArrayKey::from(key), value);
        self
    }

    /// Whether an attribute is set to something other than null.
    ///
    /// PHP: `hasAttribute($name)` (`isset`).
    pub fn has_attribute(&self, name: &str) -> bool {
        self.isset(name).is_some()
    }

    /// Removes an attribute.
    ///
    /// PHP: `removeAttribute($name)`.
    pub fn remove_attribute(&mut self, name: &str) -> &mut Self {
        self.attributes.remove(&ArrayKey::from(name));
        self
    }

    /// Whether the id is empty (`""` or `"0"`, as `empty()` reads it).
    ///
    /// PHP: `isEmpty()`.
    pub fn is_empty(&self) -> bool {
        matches!(self.id(), b"" | b"0")
    }

    /// The event table's schema.
    ///
    /// PHP: `Metric::getEventSchema()` (and `getSchema()`).
    pub fn event_schema() -> &'static [Attribute] {
        &EVENT_SCHEMA
    }

    /// The gauge table's schema.
    ///
    /// PHP: `Metric::getGaugeSchema()`.
    pub fn gauge_schema() -> &'static [Attribute] {
        &GAUGE_SCHEMA
    }

    /// The event table's indexes.
    ///
    /// PHP: `Metric::getEventIndexes()` (and `getIndexes()`).
    pub fn event_indexes() -> &'static [Index] {
        &EVENT_INDEXES
    }

    /// The gauge table's indexes.
    ///
    /// PHP: `Metric::getGaugeIndexes()`.
    pub fn gauge_indexes() -> &'static [Index] {
        &GAUGE_INDEXES
    }

    /// Pulls the dimension columns of `kind` out of `tags`: scalars as
    /// strings (`""` as `None`), `country` and `region` lower-cased; a key
    /// that is no column of the kind is refused (`type_name` names the kind
    /// in the message, as the caller spelled it).
    ///
    /// PHP: `Metric::extractColumns($tags, $type)`.
    pub fn extract_columns(tags: &Array, kind: Kind) -> Result<Columns> {
        let allowed = kind.columns();
        let mut columns = Vec::with_capacity(allowed.len());
        let mut used = 0;
        for col in allowed {
            let raw = tags.get(&ArrayKey::from(*col));
            if raw.is_some() {
                used += 1;
            }
            let mut val = match raw {
                Some(Value::Str(s)) if s.is_empty() => None,
                Some(Value::Str(s)) => Some(s.clone()),
                Some(v) => php::scalar_string(v),
                None => None,
            };
            if (*col == "country" || *col == "region")
                && let Some(s) = &val
            {
                val = Some(Str::from(php_std::string::strtolower(s.as_bytes()).into_owned()));
            }
            columns.push((*col, val));
        }
        if used < tags.len()
            && let Some((key, _)) = tags.iter().find(|(k, _)| match k {
                php_std::types::KeyRef::Str(s) => !std::str::from_utf8(s).is_ok_and(|s| allowed.contains(&s)),
                php_std::types::KeyRef::Int(_) => true,
            })
        {
            let mut message = b"Unknown column '".to_vec();
            match key {
                php_std::types::KeyRef::Str(s) => message.extend_from_slice(s),
                php_std::types::KeyRef::Int(i) => message.extend_from_slice(i.to_string().as_bytes()),
            }
            message.extend_from_slice(format!("' for {}", kind.as_str()).as_bytes());
            return Err(Error::exception(message));
        }
        Ok(columns)
    }

    /// Checks `data` against the kind's schema: required attributes
    /// present, strings within their size, `value` an integer, `time` a
    /// parseable date.
    ///
    /// PHP: `Metric::validate($data, $type)`.
    pub fn validate(data: &Array, kind: Kind) -> Result<()> {
        for attribute in kind.schema() {
            let value = data.get(&ArrayKey::from(attribute.id)).filter(|v| !matches!(v, Value::Null));
            let Some(value) = value else {
                if attribute.required {
                    return Err(Error::exception(format!("Required attribute '{}' is missing", attribute.id)));
                }
                continue;
            };
            match attribute.kind {
                "string" => {
                    let Value::Str(s) = value else {
                        return Err(Error::exception(format!(
                            "Attribute '{}' must be a string, got {}",
                            attribute.id,
                            value.type_name()
                        )));
                    };
                    if attribute.size > 0 && s.len() as i64 > attribute.size {
                        return Err(Error::exception(format!(
                            "Attribute '{}' exceeds maximum size of {} characters",
                            attribute.id, attribute.size
                        )));
                    }
                }
                "integer" => {
                    if !matches!(value, Value::Int(_)) {
                        return Err(Error::exception(format!(
                            "Attribute '{}' must be an integer, got {}",
                            attribute.id,
                            value.type_name()
                        )));
                    }
                }
                "datetime" => {
                    let Value::Str(s) = value else {
                        return Err(Error::exception(format!(
                            "Attribute '{}' must be a DateTime object or string, got {}",
                            attribute.id,
                            value.type_name()
                        )));
                    };
                    if let Err(e) = php::datetime(s.as_bytes()) {
                        let mut message =
                            format!("Attribute '{}' is not a valid datetime string: ", attribute.id).into_bytes();
                        message.extend_from_slice(e.message_bytes());
                        return Err(Error::exception(message));
                    }
                }
                _ => {}
            }
        }
        Ok(())
    }
}

impl From<Array> for Metric {
    fn from(attributes: Array) -> Self {
        Metric::new(attributes)
    }
}
