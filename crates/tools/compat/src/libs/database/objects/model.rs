//! The model classes: `Attribute`, `Index`, `Relationship`, `Collection`, the update models, `Format`, `Change`, `Permission`, `Role`, `Id`, `DateTime`, `Storage`, `Connection`, the exceptions, the events, the enums.
//!
//! Each class is driven as PHP's reflection drives it: statics by name with
//! the parameters by their PHP names (a factory's optional parameters are
//! the Rust builder's refining methods), constructors with `object.new`,
//! instance methods on a stored object. `Role` and `Permission` have no
//! public properties (`{"$value": "Role", "fields": {}}` on the wire) but
//! keep their state for later calls; exceptions encode as `$error`.

use serde_json::Value as Json;
use utopia_database::attribute::{self, Attribute, Format, IntegerWidth, NumericBounds, Patch};
use utopia_database::capability::Capability;
use utopia_database::change::Change;
use utopia_database::collection::{self, Collection};
use utopia_database::document::{Document, SetType};
use utopia_database::event::{Domain, Event};
use utopia_database::filter::{Filter, FilterName};
use utopia_database::index::Index;
use utopia_database::operator::OperatorType;
use utopia_database::permission::PermissionType;
use utopia_database::relationship::{self, Relationship, RelationshipDeleteAction, RelationshipSide, RelationshipType};
use utopia_database::role::Role;
use utopia_database::value::{self, Array, ArrayKey, Ext, Str, Value};
use utopia_database::{Error, Kind, connection, datetime, id, schema, storage};
use utopia_query::OrderDirection;
use utopia_query::schema::{ColumnType, ForeignKeyAction, IndexType, Order};

use crate::libs::database::args::{self, Args};
use crate::libs::database::codec;
use crate::libs::database::convert::{IntoObj, objects, ok};
use crate::libs::database::ctx::{Fail, R, fault};
use crate::libs::database::enums::{self, PhpEnum};
use crate::libs::database::obj::{EnumCase, Obj};
use crate::libs::database::values;
use crate::php_object;

// ----- Objects of this module -----

/// A `Role`: no public properties on the wire, its parts kept for the
/// calls that read them (`toString()`, `getRole()`, ...).
#[derive(Clone)]
pub struct RoleObj(pub Role);
php_object!(RoleObj, "Role", |_r| values::object("Role", vec![]));

/// A `Permission`: its permission as given (PHP's constructor takes any
/// string) and its role.
#[derive(Clone)]
pub struct PermissionObj {
    /// The permission (`read`, `write`, ...).
    pub permission: String,
    /// The role it is granted to.
    pub role: Role,
}
php_object!(PermissionObj, "Permission", |_p| values::object("Permission", vec![]));

impl PermissionObj {
    /// `toString()`: `<permission>("<role>")`.
    fn text(&self) -> String {
        format!("{}(\"{}\")", self.permission, self.role)
    }
}

/// An exception of the library a case built (`new Exception\Duplicate(...)`).
#[derive(Clone)]
pub struct ErrorObj(pub Error);
php_object!(ErrorObj, "Exception", |e| codec::error(&e.0));

// ----- Helpers -----

/// A string as a value.
fn text(s: impl AsRef<str>) -> Value {
    Value::Str(Str::from(s.as_ref()))
}

/// A library error as a failure.
fn err(e: Error) -> Fail {
    Fail::Error(e)
}

/// The `filters` parameter (`list<Filter|string>`): names, or `Filter`
/// cases.
fn filter_names(a: &Args, key: &str) -> R<Option<Vec<FilterName>>> {
    let Some(raw) = a.raw(key) else { return Ok(None) };
    let json = a.resolve(raw)?;
    match &json {
        Json::Null => Ok(None),
        Json::Array(items) => items
            .iter()
            .map(|j| match j {
                Json::String(s) => Ok(FilterName::from(s.as_str())),
                other => Ok(FilterName::Builtin(values::enum_of::<Filter>(other)?)),
            })
            .collect::<R<Vec<_>>>()
            .map(Some),
        Json::Object(o) if o.is_empty() => Ok(Some(Vec::new())),
        other => fault(format!("`{key}` is a list of filters, not {other}")),
    }
}

/// A `list<?int>` parameter (an index's lengths): each an integer or
/// `null` (`Index::lengths()`).
fn lengths(a: &Args, key: &str) -> R<Vec<Option<i64>>> {
    a.array(key)?
        .values()
        .map(|v| match v {
            Value::Null => Ok(None),
            Value::Int(i) => Ok(Some(*i)),
            _ => Err(err(Error::index("Index lengths must be integers or null"))),
        })
        .collect()
}

/// An index's `list<string>` attributes (`Index::attributes()`).
fn index_attributes(a: &Args, key: &str) -> R<Vec<String>> {
    a.array(key)?
        .values()
        .map(|v| match v {
            Value::Str(s) => Ok(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => Err(err(Error::index("Index attributes must be strings"))),
        })
        .collect()
}

/// A `list<?OrderDirection>` parameter (orders): `$enum` tags, backing
/// values or `null`s.
fn orders(a: &Args, key: &str) -> R<Vec<Option<OrderDirection>>> {
    let Some(raw) = a.raw(key) else { return Ok(Vec::new()) };
    let json = a.resolve(raw)?;
    match &json {
        Json::Null => Ok(Vec::new()),
        Json::Array(items) => items
            .iter()
            .map(|j| match j {
                Json::Null => Ok(None),
                j if j
                    .get("$enum")
                    .and_then(Json::as_str)
                    .is_some_and(|t| t.starts_with("Query\\OrderDirection::")) =>
                {
                    values::enum_of::<OrderDirection>(j).map(Some)
                }
                _ => Err(err(Error::index("Index orders must be OrderDirection cases or null"))),
            })
            .collect(),
        Json::Object(o) if o.is_empty() => Ok(Vec::new()),
        other => fault(format!("`{key}` is a list of orders, not {other}")),
    }
}

/// The backing value of a backed enum's `$enum` tag of the classes a
/// model's hydration reads (`RelationshipType`, `Query\Schema\ColumnType`,
/// ...): hydration takes the case or its value alike.
fn backing_of(tag: &str) -> Option<&'static str> {
    let case = enums::parse_tag(tag)?;
    fn of<E: PhpEnum>(case: &EnumCase) -> Option<&'static str> {
        enums::from_case::<E>(case).and_then(E::backing)
    }
    of::<RelationshipType>(&case)
        .or_else(|| of::<RelationshipSide>(&case))
        .or_else(|| of::<RelationshipDeleteAction>(&case))
        .or_else(|| of::<ForeignKeyAction>(&case))
        .or_else(|| of::<ColumnType>(&case))
        .or_else(|| of::<IndexType>(&case))
        .or_else(|| of::<OrderDirection>(&case))
        .or_else(|| of::<Order>(&case))
}

/// Whether hydration reads a case of the enum `tag` names, under the key
/// `key`, as its value: `relationType` a `RelationshipType`, `onDelete` a
/// `RelationshipDeleteAction` or `ForeignKeyAction`, `side` a
/// `RelationshipSide`, `type` a `ColumnType` or `IndexType`, `orders` any
/// backed case (`Index::storedOrder()`).
fn takes_case(key: Option<&str>, tag: &str) -> bool {
    let class = tag.rsplit_once("::").map_or(tag, |(c, _)| c);
    match key {
        Some("relationType") => class == "RelationshipType",
        Some("onDelete") => class == "RelationshipDeleteAction" || class == "Query\\Schema\\ForeignKeyAction",
        Some("side") => class == "RelationshipSide",
        Some("type") => class == "Query\\Schema\\ColumnType" || class == "Query\\Schema\\IndexType",
        Some("orders") => true,
        _ => false,
    }
}

/// An array argument for a model's hydration (`fromArray()`): stored
/// objects resolved, the enum cases hydration reads as their values (any
/// other case as its class, which is what hydration's messages name),
/// attributes and indexes as the documents they store.
fn model_json(json: &Json) -> R<Json> {
    keyed_model_json(json, None)
}

/// [`model_json`] of a value under `key`.
fn keyed_model_json(json: &Json, key: Option<&str>) -> R<Json> {
    Ok(match json {
        Json::Array(items) => Json::Array(items.iter().map(|j| keyed_model_json(j, key)).collect::<R<_>>()?),
        Json::Object(o) => {
            if o.len() == 1
                && let Some(tag) = o.get("$enum").and_then(Json::as_str)
            {
                if takes_case(key, tag)
                    && let Some(backing) = backing_of(tag)
                {
                    return Ok(Json::String(backing.to_owned()));
                }
                let class = tag.rsplit_once("::").map_or(tag, |(c, _)| c);
                return Ok(Json::String(codec::full_class(class)));
            }
            if o.len() == 2
                && let (Some(Json::String(class)), Some(fields)) = (o.get("$value"), o.get("fields"))
            {
                match values::decode(class, fields)? {
                    obj if obj.downcast_ref::<Attribute>().is_some() => {
                        let attribute = args::downcast::<Attribute>(&obj)?;
                        return Ok(codec::encode_document(&attribute.to_document().map_err(err)?));
                    }
                    obj if obj.downcast_ref::<Index>().is_some() => {
                        return Ok(codec::encode_document(&args::downcast::<Index>(&obj)?.to_document()));
                    }
                    _ => return Ok(json.clone()),
                }
            }
            Json::Object(o.iter().map(|(k, v)| Ok((k.clone(), keyed_model_json(v, Some(k))?))).collect::<R<_>>()?)
        }
        other => other.clone(),
    })
}

/// A `Document $document` parameter of a model's `fromDocument()`: the
/// enum cases it holds read as [`model_json`] reads them.
fn model_document(a: &Args, key: &str) -> R<Document> {
    let document = a.document(key)?;
    let attributes = model_json(&codec::encode_array(document.as_array()))?;
    match codec::decode(&attributes)? {
        Value::Array(data) | Value::Object(data) => Ok(Document::from_array_unchecked(data)),
        _ => Ok(document),
    }
}

/// An `array $data` parameter of a model's `fromArray()`.
fn model_array(a: &Args, key: &str) -> R<Array> {
    let json = match a.raw(key) {
        None | Some(Json::Null) => return Ok(Array::new()),
        Some(raw) => model_json(&a.resolve(raw)?)?,
    };
    match codec::decode(&json)? {
        Value::Array(a) | Value::Object(a) => Ok(a),
        other => fault(format!("`{key}` is an array, not {}", other.type_name())),
    }
}

/// An exception from `{"$throwable": {"class", "message", "code"?,
/// "errorInfo"?, "previous"?}}` (`Core::throwable`).
fn throwable(json: &Json) -> R<Error> {
    let Some(spec) = json.get("$throwable") else {
        return fault("an exception is {\"$throwable\": {...}}");
    };
    let class = spec.get("class").and_then(Json::as_str).unwrap_or("Exception");
    let kind = Kind::from_php_class(class).unwrap_or(Kind::Exception);
    let message =
        spec.get("message").map(|m| value::to_text(&codec::decode(m).unwrap_or(Value::Null))).unwrap_or_default();
    let mut error = Error::new(kind, message);
    if let Some(Json::Array(info)) = spec.get("errorInfo") {
        if let Some(Json::String(state)) = info.first() {
            error = error.with_string_code(state.as_bytes());
        }
        if let Some(code) = info.get(1).and_then(Json::as_i64) {
            error = error.with_driver_code(code);
        }
    }
    match spec.get("code") {
        Some(Json::String(code)) => error = error.with_string_code(code.as_bytes()),
        Some(code) => {
            if let Some(code) = code.as_i64() {
                error = error.with_code(code);
            }
        }
        None => {}
    }
    if let Some(previous) = spec.get("previous").filter(|p| !p.is_null()) {
        let previous = if previous.get("$throwable").is_some() {
            throwable(previous)?
        } else {
            throwable(&serde_json::json!({"$throwable": previous}))?
        };
        error = error.with_previous(previous);
    }
    Ok(error)
}

// ----- Constructors -----

/// `new <class>(...)` for the classes of this module (`None`: not one).
pub async fn construct(class: &str, a: &Args) -> R<Option<Obj>> {
    Ok(Some(match class {
        "Role" => Obj::object(RoleObj(Role::named(
            &a.string("role")?,
            a.string_or("identifier", "")?,
            a.string_or("dimension", "")?,
        ))),
        "Permission" => Obj::object(PermissionObj {
            permission: a.string("permission")?,
            role: Role::named(&a.string("role")?, a.string_or("identifier", "")?, a.string_or("dimension", "")?),
        }),
        "Format" => Obj::object(Format::new(a.string("name")?, a.array("options")?).map_err(err)?),
        "NumericBounds" => Obj::object(NumericBounds { min: a.value("min")?, max: a.value("max")? }),
        "Change" => Obj::object(Change::new(a.document("old")?, a.document("new")?)),
        "Attribute\\Update" => Obj::object(attribute_update(a)?),
        "Relationship\\Update" => Obj::object(relationship::Update {
            key: a.opt_string("key")?,
            two_way_key: a.opt_string("twoWayKey")?,
            two_way: a.opt_bool("twoWay")?,
            on_delete: a.enum_("onDelete")?,
        }),
        "Collection\\Update" => Obj::object(collection::Update {
            permissions: a.opt_strings("permissions")?,
            document_security: a.opt_bool("documentSecurity")?,
        }),
        "Schema\\Column" => Obj::object(schema::Column {
            name: a.string("name")?,
            kind: a.string("type")?,
            length: a.opt_int("length")?,
            nullable: a.bool("nullable")?,
        }),
        "Schema\\Index" => Obj::object(schema::Index {
            name: a.string("name")?,
            kind: a.req_enum("type")?,
            columns: a.strings("columns")?,
            lengths: lengths(a, "lengths")?,
        }),
        c if c == "Exception" || c.starts_with("Exception\\") => Obj::object(ErrorObj(exception(c, a)?)),
        c if c.starts_with("Event\\") && c != "Event\\DispatcherHook" && c != "Event\\Domain" => match event(c, a)? {
            Some(e) => Obj::object(e),
            None => return Ok(None),
        },
        _ => return Ok(None),
    }))
}

/// `new Attribute\Update(...)`: `default` and `format` left out are
/// `Unchanged::Value`.
fn attribute_update(a: &Args) -> R<attribute::Update> {
    let unchanged = |key: &str| match a.raw(key) {
        None => true,
        Some(j) => j.get("$enum").and_then(Json::as_str) == Some("Unchanged::Value"),
    };
    Ok(attribute::Update {
        kind: a.enum_("type")?,
        size: a.opt_int("size")?,
        required: a.opt_bool("required")?,
        default: if unchanged("default") { Patch::Unchanged } else { Patch::Set(a.value("default")?) },
        signed: a.opt_bool("signed")?,
        array: a.opt_bool("array")?,
        format: if unchanged("format") { Patch::Unchanged } else { Patch::Set(a.typed::<Format>("format")?) },
        filters: filter_names(a, "filters")?,
        key: a.opt_string("key")?,
    })
}

/// `new Exception\<Class>($message, $code, $previous)` (`Exception\Order`:
/// `$message, $attribute, $code, $previous`).
fn exception(class: &str, a: &Args) -> R<Error> {
    let Some(kind) = Kind::from_php_class(&codec::full_class(class)) else {
        return fault(format!("no exception class `{class}`"));
    };
    let message = a.string_or("message", "")?;
    let mut error =
        if kind == Kind::Order { Error::order(message, a.opt_string("attribute")?) } else { Error::new(kind, message) };
    match a.value("code")? {
        Value::Null => {}
        Value::Str(s) => error = error.with_string_code(s.as_bytes()),
        v => error = error.with_code(value::to_long(&v)),
    }
    if let Some(previous) = a.raw("previous").filter(|p| !p.is_null()) {
        let previous = match args::stored_obj(&a.cx, previous)? {
            Some(obj) => match obj.downcast_ref::<ErrorObj>() {
                Some(e) => e.0.clone(),
                None => return fault("`previous` is an exception"),
            },
            None => throwable(previous)?,
        };
        error = error.with_previous(previous);
    }
    Ok(error)
}

/// `new Event\<Group>\<Name>(...)`.
fn event(class: &str, a: &Args) -> R<Option<Domain>> {
    let s = |k: &str| a.string(k);
    let c = || a.string("collection");
    Ok(Some(match class {
        "Event\\Database\\Listed" => Domain::DatabaseListed { databases: a.documents("databases")? },
        "Event\\Database\\Created" => Domain::DatabaseCreated { database: s("database")? },
        "Event\\Database\\Updated" => Domain::DatabaseUpdated { database: s("database")?, new: s("new")? },
        "Event\\Database\\Deleted" => Domain::DatabaseDeleted { database: s("database")? },
        "Event\\Collection\\Listed" => Domain::CollectionListed {
            collections: match a.raw("collections") {
                None | Some(Json::Null) => Vec::new(),
                Some(j) => {
                    let j = a.resolve(j)?;
                    codec::list(&j)?.into_iter().map(|c| args::collection_of(&a.cx, c)).collect::<R<_>>()?
                }
            },
        },
        "Event\\Collection\\Created" => {
            Domain::CollectionCreated { collection: c()?, definition: a.collection("definition")? }
        }
        "Event\\Collection\\Read" => {
            Domain::CollectionRead { collection: c()?, definition: a.collection("definition")? }
        }
        "Event\\Collection\\Updated" => {
            Domain::CollectionUpdated { collection: c()?, definition: a.collection("definition")? }
        }
        "Event\\Collection\\Deleted" => {
            Domain::CollectionDeleted { collection: c()?, definition: a.collection("definition")? }
        }
        "Event\\Attribute\\Created" => Domain::AttributeCreated { collection: c()?, attribute: a.req("attribute")? },
        "Event\\Attribute\\BatchCreated" => {
            Domain::AttributeBatchCreated { collection: c()?, attributes: a.list("attributes")? }
        }
        "Event\\Attribute\\Updated" => Domain::AttributeUpdated { collection: c()?, attribute: a.req("attribute")? },
        "Event\\Attribute\\Renamed" => {
            Domain::AttributeRenamed { collection: c()?, old: s("old")?, attribute: a.req("attribute")? }
        }
        "Event\\Attribute\\Deleted" => Domain::AttributeDeleted { collection: c()?, attribute: a.req("attribute")? },
        "Event\\Index\\Created" => Domain::IndexCreated { collection: c()?, index: a.req("index")? },
        "Event\\Index\\BatchCreated" => Domain::IndexBatchCreated { collection: c()?, indexes: a.list("indexes")? },
        "Event\\Index\\Renamed" => Domain::IndexRenamed { collection: c()?, old: s("old")?, index: a.req("index")? },
        "Event\\Index\\Deleted" => Domain::IndexDeleted { collection: c()?, index: a.req("index")? },
        "Event\\Document\\Read" => Domain::DocumentRead { collection: c()?, document: a.document("document")? },
        "Event\\Document\\Found" => Domain::DocumentFound { collection: c()?, documents: a.documents("documents")? },
        "Event\\Document\\Aggregated" => Domain::DocumentAggregated {
            collection: c()?,
            rows: a
                .array("rows")?
                .values()
                .map(|r| match r {
                    Value::Array(r) | Value::Object(r) => Ok(r.clone()),
                    other => fault(format!("a row is an array, not {}", other.type_name())),
                })
                .collect::<R<_>>()?,
        },
        "Event\\Document\\Created" => Domain::DocumentCreated { collection: c()?, document: a.document("document")? },
        "Event\\Document\\BatchCreated" => Domain::DocumentBatchCreated { collection: c()?, count: a.int("count")? },
        "Event\\Document\\Updated" => Domain::DocumentUpdated { collection: c()?, document: a.document("document")? },
        "Event\\Document\\BatchUpdated" => Domain::DocumentBatchUpdated { collection: c()?, count: a.int("count")? },
        "Event\\Document\\Upserted" => Domain::DocumentUpserted {
            collection: c()?,
            document: a.document("document")?,
            created: a.bool("created")?,
        },
        "Event\\Document\\BatchUpserted" => Domain::document_batch_upserted(c()?, a.int("created")?, a.int("updated")?),
        "Event\\Document\\Deleted" => Domain::DocumentDeleted { collection: c()?, document: a.document("document")? },
        "Event\\Document\\BatchDeleted" => Domain::DocumentBatchDeleted { collection: c()?, count: a.int("count")? },
        "Event\\Document\\Increased" => Domain::DocumentIncreased {
            collection: c()?,
            document: a.document("document")?,
            attribute: s("attribute")?,
        },
        "Event\\Document\\Decreased" => Domain::DocumentDecreased {
            collection: c()?,
            document: a.document("document")?,
            attribute: s("attribute")?,
        },
        "Event\\Document\\Counted" => Domain::DocumentCounted { collection: c()?, count: a.int("count")? },
        "Event\\Document\\Summed" => {
            Domain::DocumentSummed { collection: c()?, attribute: s("attribute")?, sum: a.value("sum")? }
        }
        "Event\\Document\\Purged" => Domain::DocumentPurged { collection: c()?, id: s("id")? },
        _ => return Ok(None),
    }))
}

// ----- Statics -----

/// `<class>::<method>(...)` for the classes of this module.
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Option<Obj>> {
    match class {
        "Attribute" => attribute_static(method, a),
        "Index" => index_static(method, a),
        "Relationship" => relationship_static(method, a),
        "Collection" => collection_static(method, a),
        "Role" => role_static(method, a),
        "Permission" => permission_static(method, a),
        "Id" => Ok(Some(match method {
            "unique" => ok(id::unique_padded(a.int_or("padding", id::PADDING)?))?,
            "custom" => id::custom(a.string("id")?).into_obj(),
            _ => return Ok(None),
        })),
        "DateTime" => datetime_static(method, a),
        "Storage" => Ok(Some(match method {
            "column" => storage::column(&a.string("attribute")?).into_obj(),
            "attribute" => storage::attribute(&a.string("column")?).into_obj(),
            "attributeMap" => map(storage::ATTRIBUTE_MAP.iter().map(|(k, v)| (*k, *v))),
            "columnMap" => map(storage::ATTRIBUTE_MAP.iter().map(|(k, v)| (*v, *k))),
            "permissionsTable" => storage::permissions_table(&a.string("collection")?).into_obj(),
            _ => return Ok(None),
        })),
        "Connection" => Ok(Some(match method {
            "hasError" => {
                let error = match a.raw("e") {
                    Some(j) => match args::stored_obj(&a.cx, j)? {
                        Some(obj) => match obj.downcast_ref::<ErrorObj>() {
                            Some(e) => e.0.clone(),
                            None => return fault("`e` is an exception"),
                        },
                        None => throwable(j)?,
                    },
                    None => return fault("missing argument `e`"),
                };
                connection::is_lost(&error).into_obj()
            }
            _ => return Ok(None),
        })),
        "IntegerWidth" => match method {
            "fromSize" => Ok(Some(enums::obj(IntegerWidth::from_size(a.opt_int("size")?)))),
            _ => enum_static(&[IntegerWidth::Bits32, IntegerWidth::Bits64], method, a),
        },
        "Capability" => enum_static(&Capability::CASES, method, a),
        "RelationshipType" => enum_static(RelationshipType::CASES, method, a),
        "RelationshipSide" => enum_static(RelationshipSide::CASES, method, a),
        "RelationshipDeleteAction" => enum_static(RelationshipDeleteAction::CASES, method, a),
        "Event" => enum_static(Event::CASES, method, a),
        "Filter" => match method {
            "names" => Ok(Some(
                Filter::names(filter_names(a, "filters")?.unwrap_or_default())
                    .into_iter()
                    .map(text)
                    .collect::<Vec<_>>()
                    .into_obj(),
            )),
            _ => enum_static(Filter::CASES, method, a),
        },
        "OperatorType" => enum_static(OperatorType::CASES, method, a),
        "PermissionType" => enum_static(
            &[
                PermissionType::Create,
                PermissionType::Read,
                PermissionType::Update,
                PermissionType::Delete,
                PermissionType::Write,
            ],
            method,
            a,
        ),
        // The query library's enums the models hold.
        "Query\\Schema\\ColumnType" => enum_static(ColumnType::CASES, method, a),
        "Query\\Schema\\IndexType" => enum_static(IndexType::CASES, method, a),
        "Query\\Schema\\ForeignKeyAction" => enum_static(ForeignKeyAction::CASES, method, a),
        "Query\\Schema\\Order" => enum_static(Order::CASES, method, a),
        "Query\\OrderDirection" => enum_static(OrderDirection::CASES, method, a),
        "SetType" => enum_static(&[SetType::Assign, SetType::Prepend, SetType::Append], method, a),
        "Unchanged" => Ok(match method {
            "cases" => Some(Obj::List(vec![args::enum_case("Unchanged", "Value")])),
            _ => None,
        }),
        _ => Ok(None),
    }
}

/// An associative array of strings.
fn map<'s>(pairs: impl Iterator<Item = (&'s str, &'s str)>) -> Obj {
    Obj::Value(Value::Array(pairs.map(|(k, v)| (ArrayKey::Str(Str::from(k)), text(v))).collect()))
}

/// `cases()`, `from()` and `tryFrom()` of an enum.
fn enum_static<E: PhpEnum>(cases: &[E], method: &str, a: &Args) -> R<Option<Obj>> {
    Ok(Some(match method {
        "cases" => Obj::List(cases.iter().map(|c| enums::obj(*c)).collect()),
        "from" => match a.value("value")? {
            Value::Str(s) => {
                enums::obj(values::enum_of::<E>(&Json::String(String::from_utf8_lossy(s.as_bytes()).into_owned()))?)
            }
            Value::Int(i) => {
                return Err(Fail::Error(Error::new(
                    Kind::ValueError,
                    format!("{i} is not a valid backing value for enum {}", codec::full_class(E::CLASS)),
                )));
            }
            other => return fault(format!("{}::from() takes a string, not {}", E::CLASS, other.type_name())),
        },
        "tryFrom" => match a.value("value")? {
            Value::Str(s) => {
                E::from_backing(&String::from_utf8_lossy(s.as_bytes())).map(enums::obj).unwrap_or_else(Obj::null)
            }
            _ => Obj::null(),
        },
        _ => return Ok(None),
    }))
}

/// The optional parameters of an `Attribute` factory, as the builder's
/// refining methods: only those the PHP factory takes (`keys`).
fn refine(mut attribute: Attribute, a: &Args, keys: &[&str]) -> R<Attribute> {
    for key in keys {
        if !a.has(key) {
            continue;
        }
        attribute = match *key {
            "required" => attribute.required(a.bool_or("required", false)?),
            "default" => attribute.default(a.value("default")?),
            "signed" => attribute.signed(a.bool_or("signed", true)?),
            "array" => attribute.array(a.bool_or("array", false)?),
            "format" => match a.typed::<Format>("format")? {
                Some(f) => attribute.format(f),
                None => attribute,
            },
            "filters" => attribute.filters(filter_names(a, "filters")?.unwrap_or_default()),
            "size" => attribute.size(a.opt_int("size")?),
            "width" => attribute.width(a.enum_::<IntegerWidth>("width")?.unwrap_or_default()),
            _ => attribute,
        };
    }
    Ok(attribute)
}

/// `Attribute::<method>(...)`.
fn attribute_static(method: &str, a: &Args) -> R<Option<Obj>> {
    const TEXT: &[&str] = &["required", "default", "array", "format", "filters"];
    const SIZED: &[&str] = &["size", "required", "default", "array", "format", "filters"];
    const NUMBER: &[&str] = &["required", "default", "signed", "array", "format", "filters"];
    const INTEGER: &[&str] = &["required", "default", "signed", "array", "width", "format", "filters"];
    const SHAPE: &[&str] = &["required", "default"];
    let key = || a.string("key");
    let attribute = match method {
        "string" => {
            refine(Attribute::string(key()?, a.int_or("size", utopia_database::database::LENGTH_KEY)?), a, TEXT)?
        }
        "varchar" => {
            refine(Attribute::varchar(key()?, a.int_or("size", utopia_database::database::LENGTH_KEY)?), a, TEXT)?
        }
        "text" => refine(Attribute::text(key()?), a, SIZED)?,
        "mediumText" => refine(Attribute::medium_text(key()?), a, SIZED)?,
        "longText" => refine(Attribute::long_text(key()?), a, SIZED)?,
        "integer" => refine(Attribute::integer(key()?), a, INTEGER)?,
        "bigInteger" => refine(Attribute::big_integer(key()?), a, NUMBER)?,
        "float" => refine(Attribute::float(key()?), a, NUMBER)?,
        "double" => refine(Attribute::double(key()?), a, NUMBER)?,
        "boolean" => refine(Attribute::boolean(key()?), a, &["required", "default", "array", "filters"])?,
        "datetime" => refine(Attribute::datetime(key()?), a, &["required", "default", "array"])?,
        "point" => refine(Attribute::point(key()?), a, SHAPE)?,
        "lineString" => refine(Attribute::line_string(key()?), a, SHAPE)?,
        "polygon" => refine(Attribute::polygon(key()?), a, SHAPE)?,
        "vector" => refine(Attribute::vector(key()?, a.int("dimensions")?), a, SHAPE)?,
        "object" => refine(Attribute::object(key()?), a, SHAPE)?,
        "id" => refine(Attribute::id(key()?), a, &["required", "default", "array"])?,
        "relationship" => {
            Attribute::relationship(key()?, a.req::<Relationship>("relationship")?, a.req_enum("side")?).map_err(err)?
        }
        "fromDocument" => Attribute::from_document(&model_document(a, "document")?).map_err(err)?,
        "fromArray" => Attribute::from_array(&model_array(a, "data")?).map_err(err)?,
        "isRelationship" => {
            return Ok(Some(Attribute::is_relationship_document(&model_document(a, "attribute")?).into_obj()));
        }
        "typeFromStored" => return Ok(Some(enums::obj(Attribute::type_from_stored(&a.string("type")?).map_err(err)?))),
        "storedType" => return Ok(Some(Attribute::stored_type(a.req_enum("type")?).into_obj())),
        "availableTypes" => {
            let profile = a.req::<utopia_database::adapter::Profile>("profile")?;
            return Ok(Some(Obj::List(Attribute::available_types(&profile).into_iter().map(enums::obj).collect())));
        }
        _ => return Ok(None),
    };
    Ok(Some(Obj::object(attribute)))
}

/// `Index::<method>(...)`.
fn index_static(method: &str, a: &Args) -> R<Option<Obj>> {
    let key = || a.string("key");
    let attribute = || a.string("attribute");
    let index = match method {
        "key" => Index::key(key()?, index_attributes(a, "attributes")?, lengths(a, "lengths")?, orders(a, "orders")?)
            .map_err(err)?,
        "unique" => {
            Index::unique(key()?, index_attributes(a, "attributes")?, lengths(a, "lengths")?, orders(a, "orders")?)
                .map_err(err)?
        }
        "fulltext" => Index::fulltext(key()?, index_attributes(a, "attributes")?),
        "trigram" => Index::trigram(key()?, index_attributes(a, "attributes")?),
        "spatial" => Index::spatial(key()?, attribute()?, a.enum_("order")?).map_err(err)?,
        "object" => Index::object(key()?, attribute()?),
        "hnswEuclidean" => Index::hnsw_euclidean(key()?, attribute()?),
        "hnswCosine" => Index::hnsw_cosine(key()?, attribute()?),
        "hnswDot" => Index::hnsw_dot(key()?, attribute()?),
        "ttl" => Index::ttl(key()?, attribute()?, a.int("ttl")?).map_err(err)?,
        "fromDocument" => Index::from_document(&model_document(a, "document")?).map_err(err)?,
        "fromArray" => Index::from_array(&model_array(a, "data")?).map_err(err)?,
        "unknownTypeMessage" => return Ok(Some(Index::unknown_type_message(&a.string("type")?).into_obj())),
        _ => return Ok(None),
    };
    Ok(Some(Obj::object(index)))
}

/// `Relationship::<method>(...)`.
fn relationship_static(method: &str, a: &Args) -> R<Option<Obj>> {
    let kind = match method {
        "oneToOne" => RelationshipType::OneToOne,
        "oneToMany" => RelationshipType::OneToMany,
        "manyToOne" => RelationshipType::ManyToOne,
        "manyToMany" => RelationshipType::ManyToMany,
        "fromDocument" => {
            return Ok(Some(Obj::object(Relationship::from_document(&model_document(a, "document")?).map_err(err)?)));
        }
        "fromArray" => return Ok(Some(Obj::object(Relationship::from_array(&model_array(a, "data")?).map_err(err)?))),
        _ => return Ok(None),
    };
    let mut relationship = Relationship::new(a.string("relatedCollection")?, kind);
    if let Some(key) = a.opt_string("key")? {
        relationship = relationship.key(key);
    }
    relationship = relationship.two_way(a.bool_or("twoWay", false)?);
    if let Some(key) = a.opt_string("twoWayKey")? {
        relationship = relationship.two_way_key(key);
    }
    if let Some(action) = a.enum_::<RelationshipDeleteAction>("onDelete")? {
        relationship = relationship.on_delete(action);
    }
    Ok(Some(Obj::object(relationship)))
}

/// `Collection::<method>(...)`.
fn collection_static(method: &str, a: &Args) -> R<Option<Obj>> {
    let collection = match method {
        "create" => {
            let metadata = match a.raw("metadata") {
                None | Some(Json::Null) => Array::new(),
                Some(_) => a.array("metadata")?,
            };
            Collection::create(
                a.string("id")?,
                a.string_or("name", "")?,
                a.list::<Attribute>("attributes")?,
                a.list::<Index>("indexes")?,
                a.opt_strings("permissions")?,
                a.bool_or("documentSecurity", true)?,
                metadata,
            )
            .map_err(err)?
        }
        "fromArray" => Collection::from_array(model_array(a, "data")?).map_err(err)?,
        "fromDocument" => {
            let document = a.document("document")?;
            if document.class() == collection::CLASS {
                return Ok(Some(Obj::Value(Value::from(document))));
            }
            Collection::from_document(&document).map_err(err)?
        }
        _ => return Ok(None),
    };
    Ok(Some(collection.into_obj()))
}

/// `Role::<method>(...)`.
fn role_static(method: &str, a: &Args) -> R<Option<Obj>> {
    let role = match method {
        "any" => Role::any(),
        "guests" => Role::guests(),
        "users" => Role::users(a.string_or("status", "")?),
        "user" => Role::user(a.string("identifier")?, a.string_or("status", "")?),
        "team" => Role::team(a.string("identifier")?, a.string_or("dimension", "")?),
        "label" => Role::label(a.string("identifier")?),
        "member" => Role::member(a.string("identifier")?),
        "parse" => Role::parse(&a.string("role")?).map_err(err)?,
        _ => return Ok(None),
    };
    Ok(Some(Obj::object(RoleObj(role))))
}

/// The `Role` a parameter names.
fn role_arg(a: &Args, key: &str) -> R<Role> {
    Ok(a.req::<RoleObj>(key)?.0)
}

/// `Permission::<method>(...)`.
fn permission_static(method: &str, a: &Args) -> R<Option<Obj>> {
    let grant = |kind: PermissionType| -> R<Obj> {
        Ok(PermissionObj { permission: kind.as_str().to_owned(), role: role_arg(a, "role")? }.text().into_obj())
    };
    Ok(Some(match method {
        "read" => grant(PermissionType::Read)?,
        "create" => grant(PermissionType::Create)?,
        "update" => grant(PermissionType::Update)?,
        "delete" => grant(PermissionType::Delete)?,
        "write" => grant(PermissionType::Write)?,
        "parse" => {
            let p = utopia_database::Permission::parse(&a.string("permission")?).map_err(err)?;
            Obj::object(PermissionObj { permission: p.permission().as_str().to_owned(), role: p.role().clone() })
        }
        "aggregate" => {
            let Some(permissions) = a.opt_strings("permissions")? else { return Ok(Some(Obj::null())) };
            let allowed: Vec<PermissionType> = match a.raw("allowed") {
                None | Some(Json::Null) => PermissionType::ALL.to_vec(),
                Some(j) => {
                    let j = a.resolve(j)?;
                    codec::list(&j)?.into_iter().map(values::enum_of::<PermissionType>).collect::<R<_>>()?
                }
            };
            ok(utopia_database::Permission::aggregate(&permissions, &allowed))?
        }
        _ => return Ok(None),
    }))
}

/// `DateTime::<method>(...)`.
fn datetime_static(method: &str, a: &Args) -> R<Option<Obj>> {
    Ok(Some(match method {
        "now" => datetime::now().into_obj(),
        "nowAfter" => ok(datetime::now_after(a.opt_string("previous")?.as_deref()))?,
        "format" => match a.datetime("date")? {
            Some(d) => datetime::format(&d).into_obj(),
            None => return fault("missing argument `date`"),
        },
        "addSeconds" => {
            let Some(mut date) = a.datetime("date")? else { return fault("missing argument `date`") };
            let result = datetime::add_seconds(&mut date, a.int("seconds")?).map_err(err)?;
            // PHP's DateTime is changed in place: a stored one with it.
            if let Some(name) = a.raw("date").and_then(|j| j.get("$obj")).and_then(Json::as_str) {
                a.cx.store(name, Obj::object(date));
            }
            result.into_obj()
        }
        "setTimezone" => match a.value("datetime")? {
            Value::Str(s) => ok(datetime::set_timezone(s.as_bytes()))?,
            v => ok(datetime::set_timezone(value::to_text(&v)))?,
        },
        "formatTz" => match a.value("dbFormat")? {
            Value::Null => Obj::null(),
            // Bytes that are not UTF-8 are no date: PHP returns them as given.
            Value::Str(s) if s.as_str().is_none() => Obj::Value(Value::Str(s)),
            v => datetime::format_tz(Some(&value::to_text(&v))).into_obj(),
        },
        _ => return Ok(None),
    }))
}

// ----- Instance methods -----

/// `$this-><method>(...)` for the objects of this module.
pub async fn invoke(this: &mut Obj, method: &str, a: &Args) -> R<Option<Obj>> {
    if let Some(attribute) = this.downcast_ref::<Attribute>() {
        return attribute_method(attribute, method, a);
    }
    if let Some(index) = this.downcast_ref::<Index>() {
        return Ok(Some(match method {
            "toDocument" => index.to_document().into_obj(),
            "withKey" => Obj::object(index.with_key(a.string("key")?)),
            "withLengths" => Obj::object(index.with_lengths(lengths(a, "lengths")?).map_err(err)?),
            "withOrders" => Obj::object(index.with_orders(orders(a, "orders")?).map_err(err)?),
            _ => return Ok(None),
        }));
    }
    if let Some(relationship) = this.downcast_ref::<Relationship>() {
        return Ok(Some(match method {
            "toDocument" => relationship.to_document().into_obj(),
            "toOptions" => relationship.to_options(a.req_enum("side")?).into_obj(),
            "inverse" => Obj::object(relationship.inverse(a.string("collection")?)),
            "apply" => Obj::object(relationship.apply(&a.req::<relationship::Update>("update")?)),
            _ => return Ok(None),
        }));
    }
    if let Some(update) = this.downcast_ref::<attribute::Update>() {
        return Ok(Some(match method {
            "isEmpty" => update.is_empty().into_obj(),
            "changesDefault" => update.changes_default().into_obj(),
            "changesFormat" => update.changes_format().into_obj(),
            _ => return Ok(None),
        }));
    }
    if let Some(role) = this.downcast_ref::<RoleObj>() {
        let role = &role.0;
        return Ok(Some(match method {
            "toString" => role.to_string().into_obj(),
            "getRole" => role.name().as_str().into_obj(),
            "getIdentifier" => role.identifier().into_obj(),
            "getDimension" => role.dimension().into_obj(),
            _ => return Ok(None),
        }));
    }
    if let Some(permission) = this.downcast_ref::<PermissionObj>() {
        return Ok(Some(match method {
            "toString" => permission.text().into_obj(),
            "getPermission" => permission.permission.as_str().into_obj(),
            "getRole" => permission.role.name().as_str().into_obj(),
            "getIdentifier" => permission.role.identifier().into_obj(),
            "getDimension" => permission.role.dimension().into_obj(),
            _ => return Ok(None),
        }));
    }
    if let Some(error) = this.downcast_ref::<ErrorObj>() {
        let error = &error.0;
        return Ok(Some(match method {
            "getMessage" => Obj::Value(Value::Str(Str::from(error.message_bytes().to_vec()))),
            "getCode" => error.code().into_obj(),
            "getAttribute" if error.kind() == Kind::Order => error.attribute().map(str::to_owned).into_obj(),
            "getPrevious" => match error.previous() {
                Some(p) => Obj::object(ErrorObj(p.clone())),
                None => Obj::null(),
            },
            _ => return Ok(None),
        }));
    }
    if let Obj::Enum(case) = this {
        return enum_method(&case.clone(), method, a);
    }
    if let Obj::Value(Value::Ext(Ext::Document(document))) = this
        && document.class() == collection::CLASS
    {
        return collection_method(document, method, a);
    }
    Ok(None)
}

/// `$attribute-><method>(...)`.
fn attribute_method(attribute: &Attribute, method: &str, a: &Args) -> R<Option<Obj>> {
    Ok(Some(match method {
        "toDocument" => ok(attribute.to_document())?,
        "apply" => Obj::object(attribute.apply(&a.req::<attribute::Update>("update")?).map_err(err)?),
        "withFilters" => Obj::object(attribute.with_filters(filter_names(a, "filters")?.unwrap_or_default())),
        "width" => attribute.integer_width().map(enums::obj).unwrap_or_else(Obj::null),
        "resolvedSize" => attribute.resolved_size().into_obj(),
        "isSpatial" => attribute.is_spatial().into_obj(),
        "isNumeric" => attribute.is_numeric().into_obj(),
        "isInteger" => attribute.is_integer().into_obj(),
        "bounds" => attribute.bounds().map(Obj::object).unwrap_or_else(Obj::null),
        _ => return Ok(None),
    }))
}

/// `$collection-><method>(...)`: the methods only a `Collection` has.
fn collection_method(document: &Document, method: &str, a: &Args) -> R<Option<Obj>> {
    if !matches!(
        method,
        "attributes"
            | "attributesWith"
            | "indexes"
            | "fingerprint"
            | "name"
            | "documentSecurity"
            | "declaredPermissions"
            | "toDocument"
    ) {
        return Ok(None);
    }
    // The collection as stored, not normalised again (`fromArray()` ran
    // when it was built; later writes are read as they are).
    let mut collection = Collection::from_array(Array::new()).map_err(err)?;
    collection.exchange_array(document.as_array().clone());
    // A model written straight into the stored list (held here as its
    // `$value` array) is neither a document nor an array in PHP.
    let holds_model = |key: &str| {
        match document.get(key) {
        Some(Value::Array(items)) => items.values().any(|v| {
            matches!(v, Value::Array(fields) if fields.get_str("$value").is_some() && fields.get_str("fields").is_some())
        }),
        _ => false,
    }
    };
    let reads_attributes = matches!(method, "attributes" | "attributesWith" | "fingerprint");
    let reads_indexes = matches!(method, "indexes" | "fingerprint");
    if reads_attributes && holds_model(collection::ATTRIBUTES) {
        return Err(err(Error::structure("Collection attributes must be attribute documents")));
    }
    if reads_indexes && holds_model(collection::INDEXES) {
        return Err(err(Error::index("Collection indexes must be index documents")));
    }
    Ok(Some(match method {
        "attributes" => objects(collection.attributes().map_err(err)?.to_vec()),
        "attributesWith" => objects(collection.attributes_with(&a.list::<Attribute>("internal")?).map_err(err)?),
        "indexes" => objects(collection.indexes().map_err(err)?.to_vec()),
        "fingerprint" => ok(collection.fingerprint())?,
        "name" => collection.name().into_obj(),
        "documentSecurity" => collection.document_security().into_obj(),
        "declaredPermissions" => ok(collection.declared_permissions())?,
        _ => Document::from_array_unchecked(document.as_array().clone()).into_obj(),
    }))
}

/// A method of an enum case of this module.
fn enum_method(case: &EnumCase, method: &str, a: &Args) -> R<Option<Obj>> {
    let _ = a;
    if let Some(width) = enums::from_case::<IntegerWidth>(case) {
        return Ok(match method {
            "size" => Some(width.size().into_obj()),
            _ => None,
        });
    }
    if let Some(action) = enums::from_case::<RelationshipDeleteAction>(case) {
        return Ok(match method {
            "toForeignKeyAction" => Some(enums::obj(action.to_foreign_key_action())),
            _ => None,
        });
    }
    if let Some(event) = enums::from_case::<Event>(case) {
        return Ok(match method {
            "domain" => Some(event.domain().into_obj()),
            _ => None,
        });
    }
    if let Some(kind) = enums::from_case::<OperatorType>(case) {
        return Ok(Some(match method {
            "isNumeric" => kind.is_numeric().into_obj(),
            "isArray" => kind.is_array().into_obj(),
            "isString" => kind.is_string().into_obj(),
            "isBoolean" => kind.is_boolean().into_obj(),
            "isDate" => kind.is_date().into_obj(),
            _ => return Ok(None),
        }));
    }
    Ok(None)
}

// ----- Properties -----

/// A public property of an object of this module (`object.get`), for
/// objects whose wire form does not list it.
pub fn property(this: &Obj, property: &str) -> R<Option<Obj>> {
    if let Some(error) = this.downcast_ref::<ErrorObj>() {
        return Ok(match property {
            "state" => Some(error.0.state().map(str::to_owned).into_obj()),
            _ => None,
        });
    }
    if let Obj::Enum(case) = this {
        return Ok(match property {
            "name" => Some(case.case.as_str().into_obj()),
            "value" => backing_of(&format!("{}::{}", case.class, case.case))
                .or_else(|| enum_backing(case))
                .map(IntoObj::into_obj),
            _ => None,
        });
    }
    Ok(None)
}

/// The backing value of a database enum's case.
fn enum_backing(case: &EnumCase) -> Option<&'static str> {
    fn of<E: PhpEnum>(case: &EnumCase) -> Option<&'static str> {
        enums::from_case::<E>(case).and_then(E::backing)
    }
    of::<Event>(case)
        .or_else(|| of::<Filter>(case))
        .or_else(|| of::<OperatorType>(case))
        .or_else(|| of::<PermissionType>(case))
        .or_else(|| of::<SetType>(case))
}

// ----- Value tags -----

/// A `$value` tag of a class of this module.
pub fn decode_value(class: &str, fields: &serde_json::Value) -> R<Option<Obj>> {
    let f = values::Fields::of(fields)?;
    Ok(Some(match class {
        "Schema\\Index" => Obj::object(schema::Index {
            name: f.string("name")?,
            kind: match f.enum_("type")? {
                Some(k) => k,
                None => return fault("a Schema\\Index has a type"),
            },
            columns: f.strings("columns")?,
            lengths: match f.value("lengths")? {
                Value::Array(a) => {
                    a.values().map(|v| if matches!(v, Value::Null) { None } else { Some(value::to_long(v)) }).collect()
                }
                _ => Vec::new(),
            },
        }),
        "Role" | "Permission" => {
            return fault(format!("a {class} keeps its state: pass the stored object ({{\"$obj\": <name>}})"));
        }
        _ => return Ok(None),
    }))
}
