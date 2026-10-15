//! The library's value objects on the wire: `{"$value": "<class>",
//! "fields": {...}}`, their public properties in declaration order
//! (`Values::encodeObject`), and back (`Values::object`).
//!
//! Each class a case sends or receives has an encoder here, through
//! [`PhpObject`], and, when cases send it, a decoder in [`decode`].

use std::sync::Arc;

use php_std::datetime::DateTime;
use serde_json::{Map, Value as Json, json};
use utopia_database::adapter::{Limits, Profile};
use utopia_database::attribute::{self, Attribute, Format, NumericBounds, Patch};
use utopia_database::change::Change;
use utopia_database::collection;
use utopia_database::event::Domain;
use utopia_database::filter::{Filter, FilterName};
use utopia_database::index::Index;
use utopia_database::profiler::Log;
use utopia_database::relationship::{self, Relationship};
use utopia_database::schema;
use utopia_database::value::{self, Array, Value};

use super::codec;
use super::ctx::{R, fault};
use super::enums::{self, PhpEnum};
use super::obj::{EnumCase, Obj};
use crate::php_object;

/// `{"$value": class, "fields": {...}}`.
pub fn object(class: &str, fields: Vec<(&str, Json)>) -> Json {
    let fields: Map<String, Json> = fields.into_iter().map(|(k, v)| (k.to_owned(), v)).collect();
    json!({"$value": class, "fields": if fields.is_empty() { Json::Object(Map::new()) } else { Json::Object(fields) }})
}

/// A `DateTime` on the wire.
pub fn datetime(d: &DateTime) -> Json {
    json!({"$datetime": String::from_utf8_lossy(&d.format(b"Y-m-d\\TH:i:s.uP"))})
}

fn opt_int(v: Option<i64>) -> Json {
    v.map(Json::from).unwrap_or(Json::Null)
}

fn opt_str(v: &Option<String>) -> Json {
    v.as_ref().map(|s| Json::String(s.clone())).unwrap_or(Json::Null)
}

fn strings(v: &[String]) -> Json {
    Json::Array(v.iter().map(|s| Json::String(s.clone())).collect())
}

/// A `Format` on the wire.
pub fn format(f: &Format) -> Json {
    object("Format", vec![("name", Json::String(f.name.clone())), ("options", codec::encode_array(&f.options))])
}

/// A `Relationship` on the wire.
pub fn relationship(r: &Relationship) -> Json {
    object(
        "Relationship",
        vec![
            ("relatedCollection", Json::String(r.related_collection.clone())),
            ("type", enums::encode(r.kind)),
            ("twoWay", Json::Bool(r.two_way)),
            ("key", opt_str(&r.key)),
            ("twoWayKey", opt_str(&r.two_way_key)),
            ("onDelete", enums::encode(r.on_delete)),
        ],
    )
}

/// An `Attribute` on the wire.
pub fn attribute(a: &Attribute) -> Json {
    object(
        "Attribute",
        vec![
            ("key", Json::String(a.key.clone())),
            ("type", enums::encode(a.kind)),
            ("size", opt_int(a.size)),
            ("required", Json::Bool(a.required)),
            ("default", codec::encode(&a.default)),
            ("signed", Json::Bool(a.signed)),
            ("array", Json::Bool(a.array)),
            ("format", a.format.as_ref().map(format).unwrap_or(Json::Null)),
            ("filters", strings(&a.filters)),
            ("relationship", a.relationship.as_ref().map(relationship).unwrap_or(Json::Null)),
            ("side", enums::encode_opt(a.side)),
        ],
    )
}

/// An `Index` on the wire.
pub fn index(i: &Index) -> Json {
    object(
        "Index",
        vec![
            ("key", Json::String(i.key.clone())),
            ("type", enums::encode(i.kind)),
            ("attributes", strings(&i.attributes)),
            ("lengths", Json::Array(i.lengths.iter().map(|l| opt_int(*l)).collect())),
            ("orders", Json::Array(i.orders.iter().map(|o| enums::encode_opt(*o)).collect())),
            ("ttl", opt_int(i.ttl)),
        ],
    )
}

fn filter_name(f: &FilterName) -> Json {
    match f {
        FilterName::Builtin(b) => enums::encode(*b),
        FilterName::Named(n) => Json::String(n.clone()),
    }
}

fn unchanged() -> Json {
    json!({"$enum": "Unchanged::Value"})
}

/// An `Attribute\Update` on the wire.
pub fn attribute_update(u: &attribute::Update) -> Json {
    object(
        "Attribute\\Update",
        vec![
            ("type", enums::encode_opt(u.kind)),
            ("size", opt_int(u.size)),
            ("required", u.required.map(Json::Bool).unwrap_or(Json::Null)),
            (
                "default",
                match &u.default {
                    Patch::Unchanged => unchanged(),
                    Patch::Set(v) => codec::encode(v),
                },
            ),
            ("signed", u.signed.map(Json::Bool).unwrap_or(Json::Null)),
            ("array", u.array.map(Json::Bool).unwrap_or(Json::Null)),
            (
                "format",
                match &u.format {
                    Patch::Unchanged => unchanged(),
                    Patch::Set(f) => f.as_ref().map(format).unwrap_or(Json::Null),
                },
            ),
            (
                "filters",
                u.filters.as_ref().map(|f| Json::Array(f.iter().map(filter_name).collect())).unwrap_or(Json::Null),
            ),
            ("key", opt_str(&u.key)),
        ],
    )
}

/// A `Relationship\Update` on the wire.
pub fn relationship_update(u: &relationship::Update) -> Json {
    object(
        "Relationship\\Update",
        vec![
            ("key", opt_str(&u.key)),
            ("twoWayKey", opt_str(&u.two_way_key)),
            ("twoWay", u.two_way.map(Json::Bool).unwrap_or(Json::Null)),
            ("onDelete", enums::encode_opt(u.on_delete)),
        ],
    )
}

/// A `Collection\Update` on the wire.
pub fn collection_update(u: &collection::Update) -> Json {
    object(
        "Collection\\Update",
        vec![
            ("permissions", u.permissions.as_ref().map(|p| strings(p)).unwrap_or(Json::Null)),
            ("documentSecurity", u.document_security.map(Json::Bool).unwrap_or(Json::Null)),
        ],
    )
}

/// `Adapter\Limits` on the wire.
pub fn limits(l: &Limits) -> Json {
    object(
        "Adapter\\Limits",
        vec![
            ("string", Json::from(l.string)),
            ("varchar", Json::from(l.varchar)),
            ("integer", Json::from(l.integer)),
            ("bigInteger", Json::from(l.big_integer)),
            ("attributes", Json::from(l.attributes)),
            ("indexes", Json::from(l.indexes)),
            ("defaultAttributes", Json::from(l.default_attributes)),
            ("defaultIndexes", Json::from(l.default_indexes)),
            ("indexLength", Json::from(l.index_length)),
            ("uidLength", Json::from(l.uid_length)),
            ("documentSize", Json::from(l.document_size)),
            ("minDateTime", datetime(&l.min_date_time)),
            ("maxDateTime", datetime(&l.max_date_time)),
            ("idType", enums::encode(l.id_type)),
            ("keywords", strings(&l.keywords)),
            ("internalIndexKeys", strings(&l.internal_index_keys)),
        ],
    )
}

/// An `Adapter\Profile` on the wire.
pub fn profile(p: &Profile) -> Json {
    object(
        "Adapter\\Profile",
        vec![
            ("limits", limits(&p.limits)),
            ("capabilities", Json::Array(p.capabilities.iter().map(enums::encode).collect())),
            ("features", Json::Array(p.features.iter().map(|f| Json::String(f.php_class().to_owned())).collect())),
            ("sharedTables", Json::Bool(p.shared_tables)),
            ("migrating", Json::Bool(p.migrating)),
        ],
    )
}

/// A `Schema\Column` on the wire.
pub fn column(c: &schema::Column) -> Json {
    object(
        "Schema\\Column",
        vec![
            ("name", Json::String(c.name.clone())),
            ("type", Json::String(c.kind.clone())),
            ("length", opt_int(c.length)),
            ("nullable", Json::Bool(c.nullable)),
        ],
    )
}

/// A `Schema\Index` on the wire.
pub fn schema_index(i: &schema::Index) -> Json {
    object(
        "Schema\\Index",
        vec![
            ("name", Json::String(i.name.clone())),
            ("type", enums::encode(i.kind)),
            ("columns", strings(&i.columns)),
            ("lengths", Json::Array(i.lengths.iter().map(|l| opt_int(*l)).collect())),
        ],
    )
}

/// A `Change` on the wire.
pub fn change(c: &Change) -> Json {
    object("Change", vec![("old", codec::encode_document(&c.old)), ("new", codec::encode_document(&c.new))])
}

/// A `Profiler\Log` on the wire.
pub fn log(l: &Log) -> Json {
    object(
        "Profiler\\Log",
        vec![
            ("query", Json::String(l.query.clone())),
            ("bindings", codec::encode_array(&l.bindings)),
            ("durationMs", crate::adapter::float_value(l.duration_ms)),
            ("collection", Json::String(l.collection.clone())),
            ("operation", Json::String(l.operation.clone())),
            ("backtrace", l.backtrace.as_ref().map(|b| strings(b)).unwrap_or(Json::Null)),
        ],
    )
}

/// A `NumericBounds` on the wire.
pub fn numeric_bounds(b: &NumericBounds) -> Json {
    object("NumericBounds", vec![("min", codec::encode(&b.min)), ("max", codec::encode(&b.max))])
}

fn collection_doc(c: &utopia_database::Collection) -> Json {
    codec::encode_document(&c.to_document())
}

/// A typed event (`Event\Domain` and its subclasses) on the wire.
pub fn event(e: &Domain) -> Json {
    let class = codec::short_class(e.php_class()).unwrap_or("Event\\Domain").to_owned();
    let s = |v: &String| Json::String(v.clone());
    let mut fields = vec![("event", enums::encode(e.event()))];
    match e {
        Domain::DatabaseListed { databases } => fields.push(("databases", codec::encode_documents(databases))),
        Domain::DatabaseCreated { database } | Domain::DatabaseDeleted { database } => {
            fields.push(("database", s(database)))
        }
        Domain::DatabaseUpdated { database, new } => {
            fields.push(("database", s(database)));
            fields.push(("new", s(new)));
        }
        Domain::CollectionListed { collections } => {
            fields.push(("collections", Json::Array(collections.iter().map(collection_doc).collect())))
        }
        Domain::CollectionCreated { collection, definition }
        | Domain::CollectionRead { collection, definition }
        | Domain::CollectionUpdated { collection, definition }
        | Domain::CollectionDeleted { collection, definition } => {
            fields.push(("collection", s(collection)));
            fields.push(("definition", collection_doc(definition)));
        }
        Domain::AttributeCreated { collection, attribute: a }
        | Domain::AttributeUpdated { collection, attribute: a }
        | Domain::AttributeDeleted { collection, attribute: a } => {
            fields.push(("collection", s(collection)));
            fields.push(("attribute", attribute(a)));
        }
        Domain::AttributeBatchCreated { collection, attributes } => {
            fields.push(("collection", s(collection)));
            fields.push(("attributes", Json::Array(attributes.iter().map(attribute).collect())));
        }
        Domain::AttributeRenamed { collection, old, attribute: a } => {
            fields.push(("collection", s(collection)));
            fields.push(("old", s(old)));
            fields.push(("attribute", attribute(a)));
        }
        Domain::IndexCreated { collection, index: i } | Domain::IndexDeleted { collection, index: i } => {
            fields.push(("collection", s(collection)));
            fields.push(("index", index(i)));
        }
        Domain::IndexBatchCreated { collection, indexes } => {
            fields.push(("collection", s(collection)));
            fields.push(("indexes", Json::Array(indexes.iter().map(index).collect())));
        }
        Domain::IndexRenamed { collection, old, index: i } => {
            fields.push(("collection", s(collection)));
            fields.push(("old", s(old)));
            fields.push(("index", index(i)));
        }
        Domain::DocumentRead { collection, document }
        | Domain::DocumentCreated { collection, document }
        | Domain::DocumentUpdated { collection, document }
        | Domain::DocumentDeleted { collection, document } => {
            fields.push(("collection", s(collection)));
            fields.push(("document", codec::encode_document(document)));
        }
        Domain::DocumentFound { collection, documents } => {
            fields.push(("collection", s(collection)));
            fields.push(("documents", codec::encode_documents(documents)));
        }
        Domain::DocumentAggregated { collection, rows } => {
            fields.push(("collection", s(collection)));
            fields.push(("rows", Json::Array(rows.iter().map(codec::encode_array).collect())));
        }
        Domain::DocumentBatchCreated { collection, count }
        | Domain::DocumentBatchUpdated { collection, count }
        | Domain::DocumentBatchDeleted { collection, count }
        | Domain::DocumentCounted { collection, count } => {
            fields.push(("collection", s(collection)));
            fields.push(("count", Json::from(*count)));
        }
        Domain::DocumentUpserted { collection, document, created } => {
            fields.push(("collection", s(collection)));
            fields.push(("document", codec::encode_document(document)));
            fields.push(("created", Json::Bool(*created)));
        }
        Domain::DocumentBatchUpserted { collection, created, updated, count } => {
            fields.push(("count", Json::from(*count)));
            fields.push(("collection", s(collection)));
            fields.push(("created", Json::from(*created)));
            fields.push(("updated", Json::from(*updated)));
        }
        Domain::DocumentIncreased { collection, document, attribute: a }
        | Domain::DocumentDecreased { collection, document, attribute: a } => {
            fields.push(("collection", s(collection)));
            fields.push(("document", codec::encode_document(document)));
            fields.push(("attribute", s(a)));
        }
        Domain::DocumentSummed { collection, attribute: a, sum } => {
            fields.push(("collection", s(collection)));
            fields.push(("attribute", s(a)));
            fields.push(("sum", codec::encode(sum)));
        }
        Domain::DocumentPurged { collection, id } => {
            fields.push(("collection", s(collection)));
            fields.push(("id", s(id)));
        }
    }
    object(&class, fields)
}

php_object!(Attribute, "Attribute", |a| attribute(a));
php_object!(Index, "Index", |i| index(i));
php_object!(Relationship, "Relationship", |r| relationship(r));
php_object!(Format, "Format", |f| format(f));
php_object!(attribute::Update, "Attribute\\Update", |u| attribute_update(u));
php_object!(relationship::Update, "Relationship\\Update", |u| relationship_update(u));
php_object!(collection::Update, "Collection\\Update", |u| collection_update(u));
php_object!(Profile, "Adapter\\Profile", |p| profile(p));
php_object!(schema::Column, "Schema\\Column", |c| column(c));
php_object!(schema::Index, "Schema\\Index", |i| schema_index(i));
php_object!(Change, "Change", |c| change(c));
php_object!(Log, "Profiler\\Log", |l| log(l));
php_object!(NumericBounds, "NumericBounds", |b| numeric_bounds(b));
php_object!(Domain, "Event\\Domain", |e| event(e));
php_object!(DateTime, "DateTime", |d| datetime(d));

/// `Adapter\Limits`, held as the adapter shares it.
#[derive(Clone)]
pub struct LimitsObj(pub Arc<Limits>);
php_object!(LimitsObj, "Adapter\\Limits", |l| limits(&l.0));

// ----- Decoding (Values::object) -----

/// The fields of a `$value` tag.
pub struct Fields<'a>(&'a Map<String, Json>);

impl<'a> Fields<'a> {
    /// The fields of a tag's `fields`.
    pub fn of(json: &'a Json) -> R<Fields<'a>> {
        static EMPTY: std::sync::LazyLock<Map<String, Json>> = std::sync::LazyLock::new(Map::new);
        match json {
            Json::Object(o) => Ok(Fields(o)),
            Json::Array(a) if a.is_empty() => Ok(Fields(&EMPTY)),
            _ => fault("`fields` is an object"),
        }
    }

    /// A field as sent (`None`: absent).
    pub fn raw(&self, key: &str) -> Option<&'a Json> {
        self.0.get(key)
    }

    /// A field as a value.
    pub fn value(&self, key: &str) -> R<Value> {
        match self.0.get(key) {
            None => Ok(Value::Null),
            Some(j) => Ok(codec::decode(j)?),
        }
    }

    /// A string field.
    pub fn string(&self, key: &str) -> R<String> {
        Ok(value::to_text(&self.value(key)?))
    }

    /// An optional string field.
    pub fn opt_string(&self, key: &str) -> R<Option<String>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            v => Some(value::to_text(&v)),
        })
    }

    /// An optional integer field.
    pub fn opt_int(&self, key: &str) -> R<Option<i64>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            v => Some(value::to_long(&v)),
        })
    }

    /// An integer field.
    pub fn int(&self, key: &str) -> R<i64> {
        Ok(self.opt_int(key)?.unwrap_or(0))
    }

    /// A boolean field.
    pub fn bool(&self, key: &str) -> R<bool> {
        Ok(self.value(key)?.truthy())
    }

    /// An optional boolean field.
    pub fn opt_bool(&self, key: &str) -> R<Option<bool>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            v => Some(v.truthy()),
        })
    }

    /// A list of strings.
    pub fn strings(&self, key: &str) -> R<Vec<String>> {
        Ok(match self.value(key)? {
            Value::Array(a) => a.values().map(value::to_text).collect(),
            _ => Vec::new(),
        })
    }

    /// An enum field (`$enum` tag, or a backed value).
    pub fn enum_<E: PhpEnum>(&self, key: &str) -> R<Option<E>> {
        match self.0.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(j) => enum_of::<E>(j).map(Some),
        }
    }

    /// Whether a field is `Unchanged::Value`.
    pub fn unchanged(&self, key: &str) -> bool {
        match self.0.get(key) {
            None => true,
            Some(j) => j.get("$enum").and_then(Json::as_str) == Some("Unchanged::Value"),
        }
    }
}

/// An enum from its tag or backing value.
pub fn enum_of<E: PhpEnum>(json: &Json) -> R<E> {
    if let Some(tag) = json.get("$enum").and_then(Json::as_str) {
        return match enums::parse_tag(tag).as_ref().and_then(enums::from_case::<E>) {
            Some(e) => Ok(e),
            None => fault(format!("`{tag}` is not a case of {}", E::CLASS)),
        };
    }
    match json {
        Json::String(s) => match E::from_backing(s) {
            Some(e) => Ok(e),
            None => Err(super::ctx::Fail::Error(utopia_database::Error::new(
                utopia_database::Kind::ValueError,
                format!("\"{s}\" is not a valid backing value for enum {}", codec::full_class(E::CLASS)),
            ))),
        },
        other => fault(format!("expected a case of {}, got {other}", E::CLASS)),
    }
}

/// A `Format` from its tag's fields.
pub fn decode_format(f: &Fields<'_>) -> R<Format> {
    Ok(Format { name: f.string("name")?, options: codec::decode_array(f.raw("options").unwrap_or(&Json::Null))? })
}

fn opt_format(json: Option<&Json>) -> R<Option<Format>> {
    match json {
        None | Some(Json::Null) => Ok(None),
        Some(j) => match (j.get("$value").and_then(Json::as_str), j.get("fields")) {
            (Some("Format"), Some(fields)) => Ok(Some(decode_format(&Fields::of(fields)?)?)),
            _ => fault("a format is a Format value"),
        },
    }
}

fn filter_names(json: Option<&Json>) -> R<Option<Vec<FilterName>>> {
    let Some(Json::Array(items)) = json else {
        return Ok(None);
    };
    items
        .iter()
        .map(|j| match j {
            Json::String(s) => Ok(match Filter::try_from_value(s) {
                Some(f) => FilterName::Builtin(f),
                None => FilterName::Named(s.clone()),
            }),
            other => Ok(FilterName::Builtin(enum_of::<Filter>(other)?)),
        })
        .collect::<R<Vec<_>>>()
        .map(Some)
}

/// A `Relationship` from its tag's fields.
pub fn decode_relationship(f: &Fields<'_>) -> R<Relationship> {
    Ok(Relationship {
        related_collection: f.string("relatedCollection")?,
        kind: f.enum_("type")?.ok_or_else(|| super::ctx::Fail::Fault(crate::adapter::Fault::new("type")))?,
        two_way: f.bool("twoWay")?,
        key: f.opt_string("key")?,
        two_way_key: f.opt_string("twoWayKey")?,
        on_delete: f
            .enum_("onDelete")?
            .unwrap_or(utopia_database::relationship::RelationshipDeleteAction::Restrict),
    })
}

fn opt_relationship(json: Option<&Json>) -> R<Option<Relationship>> {
    match json {
        None | Some(Json::Null) => Ok(None),
        Some(j) => match j.get("fields") {
            Some(fields) => Ok(Some(decode_relationship(&Fields::of(fields)?)?)),
            None => fault("a relationship is a Relationship value"),
        },
    }
}

/// An `Attribute` from its tag's fields.
pub fn decode_attribute(f: &Fields<'_>) -> R<Attribute> {
    Ok(Attribute {
        key: f.string("key")?,
        kind: f.enum_("type")?.ok_or_else(|| super::ctx::Fail::Fault(crate::adapter::Fault::new("type")))?,
        size: f.opt_int("size")?,
        required: f.bool("required")?,
        default: f.value("default")?,
        signed: f.bool("signed")?,
        array: f.bool("array")?,
        format: opt_format(f.raw("format"))?,
        filters: filter_names(f.raw("filters"))?.unwrap_or_default().iter().map(|n| n.as_str().to_owned()).collect(),
        relationship: opt_relationship(f.raw("relationship"))?,
        side: f.enum_("side")?,
    })
}

/// An `Index` from its tag's fields.
pub fn decode_index(f: &Fields<'_>) -> R<Index> {
    let lengths = match f.value("lengths")? {
        Value::Array(a) => a.values().map(|v| if matches!(v, Value::Null) { None } else { Some(value::to_long(v)) }).collect(),
        _ => Vec::new(),
    };
    let orders = match f.raw("orders") {
        Some(Json::Array(items)) => items
            .iter()
            .map(|j| if j.is_null() { Ok(None) } else { enum_of(j).map(Some) })
            .collect::<R<Vec<_>>>()?,
        _ => Vec::new(),
    };
    Ok(Index {
        key: f.string("key")?,
        kind: f.enum_("type")?.ok_or_else(|| super::ctx::Fail::Fault(crate::adapter::Fault::new("type")))?,
        attributes: f.strings("attributes")?,
        lengths,
        orders,
        ttl: f.opt_int("ttl")?,
    })
}

/// An `Attribute\Update` from its tag's fields.
pub fn decode_attribute_update(f: &Fields<'_>) -> R<attribute::Update> {
    Ok(attribute::Update {
        kind: f.enum_("type")?,
        size: f.opt_int("size")?,
        required: f.opt_bool("required")?,
        default: if f.unchanged("default") { Patch::Unchanged } else { Patch::Set(f.value("default")?) },
        signed: f.opt_bool("signed")?,
        array: f.opt_bool("array")?,
        format: if f.unchanged("format") { Patch::Unchanged } else { Patch::Set(opt_format(f.raw("format"))?) },
        filters: filter_names(f.raw("filters"))?,
        key: f.opt_string("key")?,
    })
}

/// A `Relationship\Update` from its tag's fields.
pub fn decode_relationship_update(f: &Fields<'_>) -> R<relationship::Update> {
    Ok(relationship::Update {
        key: f.opt_string("key")?,
        two_way_key: f.opt_string("twoWayKey")?,
        two_way: f.opt_bool("twoWay")?,
        on_delete: f.enum_("onDelete")?,
    })
}

/// A `Collection\Update` from its tag's fields.
pub fn decode_collection_update(f: &Fields<'_>) -> R<collection::Update> {
    Ok(collection::Update {
        permissions: match f.value("permissions")? {
            Value::Null => None,
            Value::Array(a) => Some(a.values().map(value::to_text).collect()),
            _ => None,
        },
        document_security: f.opt_bool("documentSecurity")?,
    })
}

/// A value object from a `$value` tag (`Values::object`). Classes this
/// does not know are a fault: add them here.
pub fn decode(class: &str, fields: &Json) -> R<Obj> {
    let f = Fields::of(fields)?;
    Ok(match class {
        "Attribute" => Obj::object(decode_attribute(&f)?),
        "Index" => Obj::object(decode_index(&f)?),
        "Relationship" => Obj::object(decode_relationship(&f)?),
        "Format" => Obj::object(decode_format(&f)?),
        "Attribute\\Update" => Obj::object(decode_attribute_update(&f)?),
        "Relationship\\Update" => Obj::object(decode_relationship_update(&f)?),
        "Collection\\Update" => Obj::object(decode_collection_update(&f)?),
        "NumericBounds" => Obj::object(NumericBounds { min: f.value("min")?, max: f.value("max")? }),
        "Change" => Obj::object(Change {
            old: codec::document(f.raw("old").unwrap_or(&Json::Null))?,
            new: codec::document(f.raw("new").unwrap_or(&Json::Null))?,
        }),
        "Schema\\Column" => Obj::object(schema::Column {
            name: f.string("name")?,
            kind: f.string("type")?,
            length: f.opt_int("length")?,
            nullable: f.bool("nullable")?,
        }),
        other => return super::objects::decode_value(other, fields),
    })
}

/// An enum case object of `E`, if `obj` is one.
pub fn enum_obj<E: PhpEnum>(obj: &Obj) -> Option<E> {
    match obj {
        Obj::Enum(EnumCase { .. }) => match obj {
            Obj::Enum(e) => enums::from_case(e),
            _ => None,
        },
        _ => None,
    }
}
