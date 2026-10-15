//! `Validator\*` (but `Validator\Authorization`): the validators of
//! documents, attributes, indexes, keys, permissions, query lists and single
//! queries, built with PHP's named constructor parameters and driven by
//! their PHP method names (`isValid`, `getDescription`, `getType`,
//! `isArray`, the setters the query list validators call, the `check*`
//! steps), plus the statics of `Structure` (formats), `BigInt`, `Spatial`
//! and `Queries\Narrow`.
//!
//! Every validator is held behind an `Arc` ([`ValidatorObj`]): PHP objects
//! are references, and the Rust validators keep their state (the message
//! of the last failure, joins, aggregates) behind locks, so a stored
//! validator and the query list validator it was handed to share it.

use std::future::Future;
use std::sync::Arc;
use std::task::{Context, Poll, Waker};

use serde_json::{Value as Json, json};
use utopia_database::adapter::Profile;
use utopia_database::attribute::Attribute;
use utopia_database::document::Document;
use utopia_database::index::Index;
use utopia_database::operator::OperatorType;
use utopia_database::permission::PermissionType;
use utopia_database::schema::Column;
use utopia_database::validator::attribute_definition::{AttributeDefinition, Measure, NameFilter};
use utopia_database::validator::big_int::{BigInt, Integer, Operand};
use utopia_database::validator::byte_length::ByteLength;
use utopia_database::validator::datetime::{Datetime, Precision, default_max, default_min};
use utopia_database::validator::index_definition::IndexDefinition;
use utopia_database::validator::index_dependency::IndexDependency;
use utopia_database::validator::key::Key;
use utopia_database::validator::label::Label;
use utopia_database::validator::object_path::ObjectPath;
use utopia_database::validator::object_value::ObjectValue;
use utopia_database::validator::operator::Operator;
use utopia_database::validator::partial_structure::PartialStructure;
use utopia_database::validator::permissions::{PERMISSIONS, Permissions};
use utopia_database::validator::queries::base::Queries;
use utopia_database::validator::queries::document::Document as DocumentQueries;
use utopia_database::validator::queries::documents::Documents;
use utopia_database::validator::queries::indexed::Indexed;
use utopia_database::validator::queries::narrow::Narrow;
use utopia_database::validator::query::aggregate::Aggregate;
use utopia_database::validator::query::base::QueryValidator;
use utopia_database::validator::query::cursor::Cursor;
use utopia_database::validator::query::distinct::Distinct;
use utopia_database::validator::query::relationship::Relationship as RelationshipValidator;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::group_by::GroupBy;
use utopia_database::validator::query::having::Having;
use utopia_database::validator::query::join::Join;
use utopia_database::validator::query::limit::Limit;
use utopia_database::validator::query::offset::Offset;
use utopia_database::validator::query::order::Order;
use utopia_database::validator::query::select::Select;
use utopia_database::validator::roles::{ROLES, Roles};
use utopia_database::validator::sequence::Sequence;
use utopia_database::validator::spatial::Spatial;
use utopia_database::validator::structure::{Structure, formats};
use utopia_database::validator::uid::Uid;
use utopia_database::validator::vector::Vector;
use utopia_database::value::{self, Str, Value};
use utopia_database::{Error, Kind};
use utopia_query::schema::ColumnType;

use crate::libs::database::args::{self, Args};
use crate::libs::database::calls::{Calls, Cb};
use crate::libs::database::codec;
use crate::libs::database::convert::{IntoObj, ok};
use crate::libs::database::ctx::{Fail, R, fault};
use crate::libs::database::obj::{Obj, PhpObject};
use crate::libs::database::values;

/// A validator of this module, as a case holds it.
#[derive(Clone)]
pub enum V {
    /// `Validator\Structure`
    Structure(Arc<Structure>),
    /// `Validator\PartialStructure`
    PartialStructure(Arc<PartialStructure>),
    /// `Validator\AttributeDefinition`
    AttributeDefinition(Arc<AttributeDefinition>),
    /// `Validator\IndexDefinition`
    IndexDefinition(Arc<IndexDefinition>),
    /// `Validator\IndexDependency`
    IndexDependency(Arc<IndexDependency>),
    /// `Validator\Operator`
    Operator(Arc<Operator>),
    /// `Validator\Key`
    Key(Arc<Key>),
    /// `Validator\Label`
    Label(Arc<Label>),
    /// `Validator\UID`
    Uid(Arc<Uid>),
    /// `Validator\Roles`
    Roles(Arc<Roles>),
    /// `Validator\Permissions`
    Permissions(Arc<Permissions>),
    /// `Validator\Datetime`
    Datetime(Arc<Datetime>),
    /// `Validator\BigInt`
    BigInt(Arc<BigInt>),
    /// `Validator\ByteLength`
    ByteLength(Arc<ByteLength>),
    /// `Validator\Sequence`
    Sequence(Arc<Sequence>),
    /// `Validator\Spatial`
    Spatial(Arc<Spatial>),
    /// `Validator\Vector`
    Vector(Arc<Vector>),
    /// `Validator\ObjectValue`
    ObjectValue(Arc<ObjectValue>),
    /// `Validator\ObjectPath`
    ObjectPath(Arc<ObjectPath>),
    /// `Validator\Queries\Base`
    Queries(Arc<Queries>),
    /// `Validator\Queries\Document`
    DocumentQueries(Arc<DocumentQueries>),
    /// `Validator\Queries\Documents`
    Documents(Arc<Documents>),
    /// `Validator\Queries\Indexed`
    Indexed(Arc<Indexed>),
    /// `Validator\Queries\Narrow`
    Narrow(Arc<Narrow>),
    /// `Validator\Query\Filter`
    Filter(Arc<Filter>),
    /// `Validator\Query\Order`
    Order(Arc<Order>),
    /// `Validator\Query\Limit`
    Limit(Arc<Limit>),
    /// `Validator\Query\Offset`
    Offset(Arc<Offset>),
    /// `Validator\Query\Cursor`
    Cursor(Arc<Cursor>),
    /// `Validator\Query\Select`
    Select(Arc<Select>),
    /// `Validator\Query\Join`
    Join(Arc<Join>),
    /// `Validator\Query\Aggregate`
    Aggregate(Arc<Aggregate>),
    /// `Validator\Query\GroupBy`
    GroupBy(Arc<GroupBy>),
    /// `Validator\Query\Having`
    Having(Arc<Having>),
    /// `Validator\Query\Distinct`
    Distinct(Arc<Distinct>),
    /// `Validator\Query\Relationship`
    Relationship(Arc<RelationshipValidator>),
}

/// The same expression for every validator (`$x` is the `&Arc<T>`).
macro_rules! each {
    ($v:expr, $x:ident => $body:expr) => {
        match $v {
            V::Structure($x) => $body,
            V::PartialStructure($x) => $body,
            V::AttributeDefinition($x) => $body,
            V::IndexDefinition($x) => $body,
            V::IndexDependency($x) => $body,
            V::Operator($x) => $body,
            V::Key($x) => $body,
            V::Label($x) => $body,
            V::Uid($x) => $body,
            V::Roles($x) => $body,
            V::Permissions($x) => $body,
            V::Datetime($x) => $body,
            V::BigInt($x) => $body,
            V::ByteLength($x) => $body,
            V::Sequence($x) => $body,
            V::Spatial($x) => $body,
            V::Vector($x) => $body,
            V::ObjectValue($x) => $body,
            V::ObjectPath($x) => $body,
            V::Queries($x) => $body,
            V::DocumentQueries($x) => $body,
            V::Documents($x) => $body,
            V::Indexed($x) => $body,
            V::Narrow($x) => $body,
            V::Filter($x) => $body,
            V::Order($x) => $body,
            V::Limit($x) => $body,
            V::Offset($x) => $body,
            V::Cursor($x) => $body,
            V::Select($x) => $body,
            V::Join($x) => $body,
            V::Aggregate($x) => $body,
            V::GroupBy($x) => $body,
            V::Having($x) => $body,
            V::Distinct($x) => $body,
            V::Relationship($x) => $body,
        }
    };
}

impl V {
    /// The PHP class, relative to `Utopia\Database`.
    pub fn class(&self) -> &'static str {
        match self {
            V::Structure(_) => "Validator\\Structure",
            V::PartialStructure(_) => "Validator\\PartialStructure",
            V::AttributeDefinition(_) => "Validator\\AttributeDefinition",
            V::IndexDefinition(_) => "Validator\\IndexDefinition",
            V::IndexDependency(_) => "Validator\\IndexDependency",
            V::Operator(_) => "Validator\\Operator",
            V::Key(_) => "Validator\\Key",
            V::Label(_) => "Validator\\Label",
            V::Uid(_) => "Validator\\UID",
            V::Roles(_) => "Validator\\Roles",
            V::Permissions(_) => "Validator\\Permissions",
            V::Datetime(_) => "Validator\\Datetime",
            V::BigInt(_) => "Validator\\BigInt",
            V::ByteLength(_) => "Validator\\ByteLength",
            V::Sequence(_) => "Validator\\Sequence",
            V::Spatial(_) => "Validator\\Spatial",
            V::Vector(_) => "Validator\\Vector",
            V::ObjectValue(_) => "Validator\\ObjectValue",
            V::ObjectPath(_) => "Validator\\ObjectPath",
            V::Queries(_) => "Validator\\Queries\\Base",
            V::DocumentQueries(_) => "Validator\\Queries\\Document",
            V::Documents(_) => "Validator\\Queries\\Documents",
            V::Indexed(_) => "Validator\\Queries\\Indexed",
            V::Narrow(_) => "Validator\\Queries\\Narrow",
            V::Filter(_) => "Validator\\Query\\Filter",
            V::Order(_) => "Validator\\Query\\Order",
            V::Limit(_) => "Validator\\Query\\Limit",
            V::Offset(_) => "Validator\\Query\\Offset",
            V::Cursor(_) => "Validator\\Query\\Cursor",
            V::Select(_) => "Validator\\Query\\Select",
            V::Join(_) => "Validator\\Query\\Join",
            V::Aggregate(_) => "Validator\\Query\\Aggregate",
            V::GroupBy(_) => "Validator\\Query\\GroupBy",
            V::Having(_) => "Validator\\Query\\Having",
            V::Distinct(_) => "Validator\\Query\\Distinct",
            V::Relationship(_) => "Validator\\Query\\Relationship",
        }
    }

    /// The validator as a validator of one kind of query (a
    /// `Validator\Query\Base`), if it is one.
    pub fn query_validator(&self) -> Option<Arc<dyn QueryValidator>> {
        Some(match self {
            V::Filter(v) => v.clone(),
            V::Order(v) => v.clone(),
            V::Limit(v) => v.clone(),
            V::Offset(v) => v.clone(),
            V::Cursor(v) => v.clone(),
            V::Select(v) => v.clone(),
            V::Join(v) => v.clone(),
            V::Aggregate(v) => v.clone(),
            V::GroupBy(v) => v.clone(),
            V::Having(v) => v.clone(),
            V::Distinct(v) => v.clone(),
            V::Relationship(v) => v.clone(),
            _ => return None,
        })
    }

    /// The query list validator under a `Validator\Queries\*`, if it is one.
    fn queries(&self) -> Option<&Queries> {
        Some(match self {
            V::Queries(v) => v,
            V::DocumentQueries(v) => v,
            V::Documents(v) => v,
            V::Indexed(v) => v,
            V::Narrow(v) => v,
            _ => return None,
        })
    }
}

/// A validator object (`{"$value": "Validator\\...", "fields": {}}`: no
/// validator has public properties).
#[derive(Clone)]
pub struct ValidatorObj(pub V);

impl PhpObject for ValidatorObj {
    fn class(&self) -> &str {
        self.0.class()
    }

    fn encode(&self) -> Json {
        values::object(self.0.class(), Vec::new())
    }

    fn clone_box(&self) -> Box<dyn PhpObject> {
        Box::new(self.clone())
    }

    fn as_any(&self) -> &dyn std::any::Any {
        self
    }

    fn as_any_mut(&mut self) -> &mut dyn std::any::Any {
        self
    }
}

/// A PHP `Closure` (`{"$closure": true}`), as `Structure::getFormat()`
/// returns a format's callback.
#[derive(Clone)]
struct ClosureObj;

impl PhpObject for ClosureObj {
    fn class(&self) -> &str {
        "Closure"
    }

    fn encode(&self) -> Json {
        json!({"$closure": true})
    }

    fn clone_box(&self) -> Box<dyn PhpObject> {
        Box::new(self.clone())
    }

    fn as_any(&self) -> &dyn std::any::Any {
        self
    }

    fn as_any_mut(&mut self) -> &mut dyn std::any::Any {
        self
    }
}

fn obj(v: V) -> Obj {
    Obj::object(ValidatorObj(v))
}

// ----- Arguments -----

/// The items of a list argument, each decoded as an object (empty when
/// absent or `null`).
fn items(a: &Args, key: &str) -> R<Vec<Obj>> {
    match a.raw(key) {
        None | Some(Json::Null) => Ok(Vec::new()),
        Some(j) => {
            if let Some(stored) = args::stored_obj(&a.cx, j)? {
                return Ok(match stored.into_result() {
                    Obj::List(list) => list,
                    Obj::Value(Value::Array(array)) => array.into_iter().map(|(_, v)| Obj::Value(v)).collect(),
                    other => vec![other],
                });
            }
            codec::list(j)?.into_iter().map(|item| args::decode_obj(&a.cx, item)).collect()
        }
    }
}

/// An `Attribute|Document` as an attribute (`Attribute::fromDocument`).
fn attribute_of(o: Obj) -> R<Attribute> {
    if let Some(attribute) = o.downcast_ref::<Attribute>() {
        return Ok(attribute.clone());
    }
    Ok(Attribute::from_document(&args::obj_document(o)?)?)
}

/// An `Index|Document` as an index (`Index::fromDocument`).
fn index_of(o: Obj) -> R<Index> {
    if let Some(index) = o.downcast_ref::<Index>() {
        return Ok(index.clone());
    }
    Ok(Index::from_document(&args::obj_document(o)?)?)
}

/// An `array<Attribute|Document>` argument.
fn attributes(a: &Args, key: &str) -> R<Vec<Attribute>> {
    items(a, key)?.into_iter().map(attribute_of).collect()
}

/// An `array<Index|Document>` argument.
fn indexes(a: &Args, key: &str) -> R<Vec<Index>> {
    items(a, key)?.into_iter().map(index_of).collect()
}

/// A required `Attribute` argument.
fn attribute(a: &Args, key: &str) -> R<Attribute> {
    attribute_of(a.req_obj(key)?)
}

/// A required `Index` argument.
fn index(a: &Args, key: &str) -> R<Index> {
    index_of(a.req_obj(key)?)
}

/// An `array<Query\Base>` argument: stored query validators.
fn query_validators(a: &Args, key: &str) -> R<Vec<Arc<dyn QueryValidator>>> {
    items(a, key)?
        .into_iter()
        .map(|o| match o.downcast_ref::<ValidatorObj>().and_then(|v| v.0.query_validator()) {
            Some(v) => Ok(v),
            None => fault(format!("`{key}` holds a {o:?}, not a query validator")),
        })
        .collect()
}

/// The value `isValid($value)` is given: a PHP value (stored model objects
/// are handled by the callers that take them).
fn value_arg(a: &Args) -> R<Value> {
    match a.obj("value")? {
        None => Ok(Value::Null),
        Some(o) => match o.into_result() {
            Obj::Value(v) => Ok(v),
            Obj::List(items) => Ok(Value::Array(
                items
                    .into_iter()
                    .map(|o| match o {
                        Obj::Value(v) => Ok(v),
                        other => fault(format!("cannot pass a {other:?} as a value")),
                    })
                    .collect::<R<_>>()?,
            )),
            other => fault(format!("cannot pass a {other:?} as a value")),
        },
    }
}

/// A `Profile` argument.
fn profile(a: &Args) -> R<Profile> {
    a.req::<Profile>("profile")
}

/// The `int|float|string` operand a value passes (a `TypeError` where PHP
/// throws one).
fn operand<'v>(value: &'v Value, function: &str, position: usize, name: &str) -> R<Operand<'v>> {
    match Operand::from_value(value) {
        Some(o) => Ok(o),
        None => Err(Fail::php(
            "TypeError",
            format!(
                "Utopia\\Database\\Validator\\BigInt::{function}(): Argument #{position} (${name}) must be of type string|int|float, {} given",
                value.debug_type()
            ),
        )),
    }
}

/// An exact integer as PHP's `int|string`.
fn integer(r: utopia_database::Result<Integer>) -> R<Obj> {
    Ok(Value::from(r?).into_obj())
}

/// Runs a callback's future to completion on this thread: the callbacks the
/// validators take are synchronous in Rust, and the steps a case gives
/// them (`$return`) do not wait on I/O.
fn block_on<F: Future>(future: F) -> Option<F::Output> {
    let mut future = std::pin::pin!(future);
    let mut cx = Context::from_waker(Waker::noop());
    for _ in 0..100_000 {
        if let Poll::Ready(output) = future.as_mut().poll(&mut cx) {
            return Some(output);
        }
        std::thread::yield_now();
    }
    None
}

/// A callback's value, synchronously.
fn call_value(cb: &Cb, args: Vec<Json>) -> utopia_database::Result<Value> {
    block_on(cb.value(args))
        .unwrap_or_else(|| Err(Error::new(Kind::Exception, "compat: a validator callback did not complete")))
}

/// A `Closure(Document): int` measure.
fn measure(cb: Cb) -> Measure {
    Box::new(move |document: &Document| Ok(value::to_long(&call_value(&cb, vec![codec::encode_document(document)])?)))
}

/// A `Closure(string): string` name filter.
fn name_filter(cb: Cb) -> NameFilter {
    Box::new(move |name: &str| match call_value(&cb, vec![Json::String(name.to_owned())]) {
        Ok(v) => value::to_text(&v),
        Err(_) => name.to_owned(),
    })
}

// ----- Constructors -----

/// `new <class>(...)` for the classes of this module (`None`: not one).
pub async fn construct(class: &str, a: &Args) -> R<Option<Obj>> {
    let Some(name) = class.strip_prefix("Validator\\") else { return Ok(None) };
    let v = match name {
        "Structure" | "PartialStructure" => {
            let collection = a.document("collection")?;
            let profile = profile(a)?;
            let current = a.opt_document("currentDocument")?;
            let stored = a.strings("storedAttributes")?;
            if name == "Structure" {
                V::Structure(Arc::new(
                    Structure::new(collection, profile).current_document(current).stored_attributes(stored),
                ))
            } else {
                V::PartialStructure(Arc::new(
                    PartialStructure::new(collection, profile).current_document(current).stored_attributes(stored),
                ))
            }
        }
        "AttributeDefinition" => return attribute_definition(a).map(Some),
        "IndexDefinition" => V::IndexDefinition(Arc::new(IndexDefinition::new(
            attributes(a, "attributes")?,
            indexes(a, "indexes")?,
            profile(a)?,
        ))),
        "IndexDependency" => {
            V::IndexDependency(Arc::new(IndexDependency::new(indexes(a, "indexes")?, a.bool("castIndexSupport")?)))
        }
        "Operator" => V::Operator(Arc::new(Operator::from_document(
            &a.document("collection")?,
            a.opt_document("currentDocument")?,
            a.bool_or("supportUnsignedBigInt", true)?,
        )?)),
        "Key" => {
            let default = Key::default();
            V::Key(Arc::new(Key::new(a.bool_or("allowInternal", false)?, a.int_or("maxLength", default.max_length())?)))
        }
        "Label" => match a.opt_int("maxLength")? {
            Some(max) => V::Label(Arc::new(Label::new(a.bool_or("allowInternal", false)?, max))),
            None if a.given("allowInternal") => {
                V::Label(Arc::new(Label::new(a.bool("allowInternal")?, Key::default().max_length())))
            }
            None => V::Label(Arc::new(Label::default())),
        },
        "UID" => match a.opt_int("maxLength")? {
            Some(max) => V::Uid(Arc::new(Uid::new(max))),
            None => V::Uid(Arc::new(Uid::default())),
        },
        "Roles" => {
            let allowed = match a.opt_strings("allowed")? {
                Some(list) => list,
                None => ROLES.iter().map(|r| (*r).to_owned()).collect(),
            };
            V::Roles(Arc::new(Roles::new(a.int_or("length", 0)?, allowed)))
        }
        "Permissions" => {
            let allowed: Vec<PermissionType> = match a.raw("allowed") {
                None | Some(Json::Null) => PERMISSIONS.to_vec(),
                Some(_) => items(a, "allowed")?
                    .into_iter()
                    .map(|o| match values::enum_obj::<PermissionType>(&o) {
                        Some(p) => Ok(p),
                        None => match o {
                            Obj::Value(Value::Str(s)) => {
                                values::enum_of::<PermissionType>(&Json::String(value::to_text(&Value::Str(s))))
                            }
                            other => fault(format!("{other:?} is not a PermissionType")),
                        },
                    })
                    .collect::<R<_>>()?,
            };
            V::Permissions(Arc::new(Permissions::new(a.int_or("length", 0)?, &allowed)))
        }
        "Datetime" => V::Datetime(Arc::new(Datetime::new(
            a.datetime("min")?.unwrap_or_else(default_min),
            a.datetime("max")?.unwrap_or_else(default_max),
            a.bool_or("requireDateInFuture", false)?,
            Precision::from_name(&a.string_or("precision", "any")?),
            a.int_or("offset", 0)?,
        )?)),
        "BigInt" => V::BigInt(Arc::new(BigInt::new(a.bool("signed")?, a.bool_or("supportUnsigned64Bit", true)?))),
        "ByteLength" => V::ByteLength(Arc::new(ByteLength::new(a.int("max")?))),
        "Sequence" => V::Sequence(Arc::new(Sequence::new(a.string("idAttributeType")?, a.bool("primary")?))),
        "Spatial" => V::Spatial(Arc::new(Spatial::new(a.string("spatialType")?))),
        "Vector" => V::Vector(Arc::new(Vector::new(a.int("size")?))),
        "ObjectValue" => V::ObjectValue(Arc::new(ObjectValue)),
        "ObjectPath" => V::ObjectPath(Arc::new(ObjectPath)),
        "Queries\\Base" => V::Queries(Arc::new(Queries::new(
            query_validators(a, "validators")?,
            a.int_or("length", 0)?.max(0) as usize,
        ))),
        "Queries\\Document" => V::DocumentQueries(Arc::new(DocumentQueries::new(
            &attributes(a, "attributes")?,
            &profile(a)?,
            a.int_or("maxValuesCount", 5000)?,
        ))),
        "Queries\\Documents" => V::Documents(Arc::new(Documents::new(
            &attributes(a, "attributes")?,
            &indexes(a, "indexes")?,
            &profile(a)?,
            a.int_or("maxValuesCount", 5000)?,
        ))),
        "Queries\\Indexed" => V::Indexed(Arc::new(Indexed::new(
            &attributes(a, "attributes")?,
            &indexes(a, "indexes")?,
            query_validators(a, "validators")?,
        ))),
        "Queries\\Narrow" => return fault("Validator\\Queries\\Narrow's constructor is private: use Narrow::of"),
        "Query\\Filter" => {
            let defaults = FilterOptions::default();
            let options = FilterOptions {
                max_values_count: a.int_or("maxValuesCount", defaults.max_values_count)?,
                min_allowed_date: a.datetime("minAllowedDate")?.unwrap_or(defaults.min_allowed_date),
                max_allowed_date: a.datetime("maxAllowedDate")?.unwrap_or(defaults.max_allowed_date),
                support_for_attributes: a.bool_or("supportForAttributes", defaults.support_for_attributes)?,
                support_unsigned_big_int: a.bool_or("supportUnsignedBigInt", defaults.support_unsigned_big_int)?,
            };
            V::Filter(Arc::new(Filter::with_options(
                &attributes(a, "attributes")?,
                a.string("idAttributeType")?,
                options,
            )))
        }
        "Query\\Order" => V::Order(Arc::new(Order::new(
            &attributes(a, "attributes")?,
            a.bool_or("supportForAttributes", true)?,
            a.bool_or("supportForOrderRandom", true)?,
        ))),
        "Query\\Limit" => V::Limit(Arc::new(match a.opt_int("maxLimit")? {
            Some(max) => Limit::new(max),
            None => Limit::default(),
        })),
        "Query\\Offset" => V::Offset(Arc::new(match a.opt_int("maxOffset")? {
            Some(max) => Offset::new(max),
            None => Offset::default(),
        })),
        "Query\\Cursor" => V::Cursor(Arc::new(match a.opt_int("maxLength")? {
            Some(max) => Cursor::new(max),
            None => Cursor::default(),
        })),
        "Query\\Select" => V::Select(Arc::new(Select::new(
            &attributes(a, "attributes")?,
            a.bool_or("supportForAttributes", true)?,
            a.bool_or("sharedTables", false)?,
        ))),
        "Query\\Join" => {
            let attributes = if a.given("attributes") { Some(attributes(a, "attributes")?) } else { None };
            V::Join(Arc::new(Join::new(attributes.as_deref(), a.bool_or("supportForAttributes", true)?)))
        }
        "Query\\Aggregate" => V::Aggregate(Arc::new(Aggregate::new(
            &attributes(a, "attributes")?,
            a.bool_or("supportForAttributes", true)?,
            a.bool_or("sharedTables", false)?,
        ))),
        "Query\\GroupBy" => V::GroupBy(Arc::new(GroupBy::new(
            &attributes(a, "attributes")?,
            a.bool_or("supportForAttributes", true)?,
            a.bool_or("sharedTables", false)?,
        ))),
        "Query\\Having" => V::Having(Arc::new(Having::default())),
        "Query\\Distinct" => V::Distinct(Arc::new(Distinct::default())),
        "Query\\Relationship" => V::Relationship(Arc::new(RelationshipValidator::new(
            &attributes(a, "attributes")?,
            a.opt_int("maxUIDLength")?.unwrap_or(utopia_database::database::MAX_UID_DEFAULT_LENGTH),
            a.bool_or("supportForAttributes", true)?,
        ))),
        _ => return Ok(None),
    };
    Ok(Some(obj(v)))
}

/// `new AttributeDefinition($attributes, $profile, $schemaAttributes,
/// $attributeCount, $attributeWidth, $filter)`: with callbacks, the result
/// carries the calls they received while it was built (none). The
/// callbacks the validator keeps record their later calls apart (`finish`
/// closes the record the result reports).
fn attribute_definition(a: &Args) -> R<Obj> {
    let calls = Calls::new(&a.cx);
    let kept = Calls::new(&a.cx);
    let callback = |name: &str| -> R<Option<Cb>> {
        calls.callback(name, a.raw(name))?;
        kept.callback(name, a.raw(name))
    };
    let count = callback("attributeCount")?;
    let width = callback("attributeWidth")?;
    let filter = callback("filter")?;
    let mut validator = AttributeDefinition::new(attributes(a, "attributes")?, profile(a)?)
        .schema_attributes(&a.list::<Column>("schemaAttributes")?);
    if count.is_some() || width.is_some() {
        validator = validator.measures(count.map(measure), width.map(measure));
    }
    if let Some(filter) = filter {
        validator = validator.filter(name_filter(filter));
    }
    calls.finish(Ok(obj(V::AttributeDefinition(Arc::new(validator)))))
}

// ----- Statics -----

/// `<class>::<method>(...)` for the classes of this module.
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Option<Obj>> {
    let Some(name) = class.strip_prefix("Validator\\") else { return Ok(None) };
    let result = match (name, method) {
        ("Structure" | "PartialStructure", _) => structure_static(method, a)?,
        ("BigInt", _) => big_int_static(method, a)?,
        ("Spatial", "isWktString") => Spatial::is_wkt_string(a.string("value")?.as_bytes()).into_obj(),
        ("ObjectPath", "accepts") => ObjectPath::accepts(a.string("path")?.as_bytes()).into_obj(),
        ("ObjectPath", "isKey") => ObjectPath::is_key(a.string("key")?.as_bytes()).into_obj(),
        ("Queries\\Narrow", "accepts") => Narrow::accepts(&a.queries("queries")?).into_obj(),
        ("Queries\\Narrow", "of") => {
            match Narrow::of(
                &a.queries("queries")?,
                &attributes(a, "attributes")?,
                &profile(a)?,
                a.int("maxValuesCount")?,
            ) {
                Some(narrow) => obj(V::Narrow(Arc::new(narrow))),
                None => Obj::null(),
            }
        }
        ("Query\\Join", "describeInvalidAlias") => Join::describe_invalid_alias(&a.string("alias")?).into_obj(),
        _ => return Ok(None),
    };
    Ok(Some(result))
}

/// `Structure::addFormat` (a format fixture by name), `hasFormat`,
/// `getFormat`, `removeFormat`.
fn structure_static(method: &str, a: &Args) -> R<Obj> {
    Ok(match method {
        "addFormat" => {
            let name = a.string("name")?;
            crate::libs::database::fixtures::add_format(&name)?;
            super::note_format(&name);
            Obj::null()
        }
        "hasFormat" => formats::has(&a.string("name")?, a.req_enum::<ColumnType>("type")?).into_obj(),
        "getFormat" => {
            let format = formats::get(&a.string("name")?, a.req_enum::<ColumnType>("type")?)?;
            Obj::Map(vec![
                ("callback".to_owned(), Obj::object(ClosureObj)),
                ("type".to_owned(), format.kind.as_str().into_obj()),
            ])
        }
        "removeFormat" => {
            formats::remove(&a.string("name")?);
            Obj::null()
        }
        _ => return fault(format!("Validator\\Structure has no static method `{method}`")),
    })
}

/// The statics of `BigInt`.
fn big_int_static(method: &str, a: &Args) -> R<Obj> {
    let text = |k: &str| a.string(k);
    let pair = |f: fn(Operand<'_>, Operand<'_>) -> utopia_database::Result<Integer>| -> R<Obj> {
        let (x, y) = (a.value("a")?, a.value("b")?);
        integer(f(operand(&x, method, 1, "a")?, operand(&y, method, 2, "b")?))
    };
    Ok(match method {
        "isIntegerString" => {
            BigInt::is_integer_string(text("value")?.as_bytes(), a.bool_or("signed", true)?).into_obj()
        }
        "fitsPhpInt" => BigInt::fits_php_int(text("value")?.as_bytes(), a.bool_or("signed", true)?).into_obj(),
        "fitsBigIntRange" => BigInt::fits_big_int_range(
            text("value")?.as_bytes(),
            a.bool("signed")?,
            a.bool_or("supportUnsigned64Bit", true)?,
        )
        .into_obj(),
        "normalizeUnsignedString" => {
            Value::Str(Str::from(BigInt::normalize_unsigned_string(text("value")?.as_bytes()))).into_obj()
        }
        "compareUnsignedStrings" => {
            BigInt::compare_unsigned_strings(text("a")?.as_bytes(), text("b")?.as_bytes()).into_obj()
        }
        "formatIntegerString" => BigInt::format_integer_string(&text("value")?).into_obj(),
        "normalizeInteger" => ok(BigInt::normalize_integer(&a.value("value")?))?,
        "toNative" => {
            let v = a.value("value")?;
            match Operand::from_value(&v) {
                Some(o) => integer(BigInt::to_native(o))?,
                None => ok(BigInt::normalize_integer(&v))?,
            }
        }
        "isIntegerValue" => BigInt::is_integer_value(&a.value("value")?).into_obj(),
        "integralValue" => {
            let v = a.value("value")?;
            BigInt::integral_value(operand(&v, method, 1, "value")?).map(Value::from).into_obj()
        }
        "compare" => {
            let (x, y) = (a.value("a")?, a.value("b")?);
            let ordering = BigInt::compare(operand(&x, method, 1, "a")?, operand(&y, method, 2, "b")?)?;
            (ordering as i64).into_obj()
        }
        "add" => pair(|x, y| BigInt::add(x, y))?,
        "subtract" => pair(|x, y| BigInt::subtract(x, y))?,
        "multiply" => pair(|x, y| BigInt::multiply(x, y))?,
        "divide" => pair(|x, y| BigInt::divide(x, y))?,
        "modulo" => pair(|x, y| BigInt::modulo(x, y))?,
        "power" => {
            let (x, y) = (a.value("base")?, a.value("exponent")?);
            integer(BigInt::power(operand(&x, method, 1, "base")?, operand(&y, method, 2, "exponent")?))?
        }
        "negate" => {
            let v = a.value("value")?;
            integer(BigInt::negate(operand(&v, method, 1, "value")?))?
        }
        "calculate" => {
            let (x, y) = (a.value("current")?, a.value("operand")?);
            integer(BigInt::calculate(
                a.req_enum::<OperatorType>("method")?,
                operand(&x, method, 2, "current")?,
                operand(&y, method, 3, "operand")?,
            ))?
        }
        "calculateOutsideNative" => BigInt::calculate_outside_native(
            a.req_enum::<OperatorType>("method")?,
            &a.value("current")?,
            &a.value("operand")?,
        )
        .map(Value::from)
        .into_obj(),
        _ => return fault(format!("Validator\\BigInt has no static method `{method}`")),
    })
}

// ----- Methods -----

/// What an `isValid` returns: a verdict, or the error PHP throws.
trait Verdict {
    fn verdict(self) -> R<Obj>;
}

impl Verdict for bool {
    fn verdict(self) -> R<Obj> {
        Ok(self.into_obj())
    }
}

impl Verdict for utopia_database::Result<bool> {
    fn verdict(self) -> R<Obj> {
        ok(self)
    }
}

/// `$this-><method>(...)` for the objects of this module.
pub async fn invoke(this: &mut Obj, method: &str, a: &Args) -> R<Option<Obj>> {
    let Some(v) = this.downcast_ref::<ValidatorObj>().map(|o| o.0.clone()) else { return Ok(None) };
    let result = match method {
        "getDescription" => each!(&v, x => x.description()).into_obj(),
        "getType" => each!(&v, x => x.type_name()).into_obj(),
        "isArray" => each!(&v, x => utopia_validators::Validator::is_array(&**x)).into_obj(),
        "isValid" => is_valid(&v, a)?,
        _ => match specific(&v, method, a)? {
            Some(result) => result,
            None => return fault(format!("object.call: `{}->{method}` is not supported", v.class())),
        },
    };
    Ok(Some(result))
}

/// `isValid($value)`.
fn is_valid(v: &V, a: &Args) -> R<Obj> {
    match v {
        V::Structure(_) | V::PartialStructure(_) => return structure_is_valid(v, a),
        V::AttributeDefinition(x) => {
            if let Some(attribute) = model::<Attribute>(a)? {
                return ok(x.check(&attribute));
            }
        }
        V::IndexDependency(x) => {
            if let Some(attribute) = model::<Attribute>(a)? {
                return Ok(x.check(&attribute).into_obj());
            }
        }
        V::IndexDefinition(x) => {
            if let Some(index) = model::<Index>(a)? {
                return ok(x.check(&index));
            }
        }
        _ => {}
    }
    let value = value_arg(a)?;
    each!(v, x => x.is_valid(&value).verdict())
}

/// The `value` argument as a model object of type `T`, if it is one.
fn model<T: Clone + 'static>(a: &Args) -> R<Option<T>> {
    Ok(a.obj("value")?.and_then(|o| o.into_result().downcast_ref::<T>().cloned()))
}

/// `Structure::isValid($document)`: PHP's validation changes the document
/// (a big integer string that fits becomes an integer, operators learn
/// their attribute), so a stored document is validated in place.
fn structure_is_valid(v: &V, a: &Args) -> R<Obj> {
    let stored = a
        .raw("value")
        .and_then(|j| j.get("$obj"))
        .and_then(Json::as_str)
        .filter(|_| a.raw("value").and_then(Json::as_object).is_some_and(|o| o.len() == 1));
    if let Some(name) = stored
        && let Obj::Value(Value::Ext(utopia_database::value::Ext::Document(document))) =
            a.cx.stored(name)?.into_result()
    {
        let mut document = *document;
        let result = match v {
            V::Structure(x) => x.validate(&mut document),
            V::PartialStructure(x) => x.validate(&mut document),
            _ => return fault("not a structure validator"),
        };
        a.cx.store(name, Obj::Value(Value::from(document)));
        return ok(result);
    }
    let value = value_arg(a)?;
    match v {
        V::Structure(x) => ok(x.is_valid(&value)),
        V::PartialStructure(x) => ok(x.is_valid(&value)),
        _ => fault("not a structure validator"),
    }
}

/// The methods only some validators have.
fn specific(v: &V, method: &str, a: &Args) -> R<Option<Obj>> {
    // The query list validators.
    if let Some(queries) = v.queries()
        && method == "setJoinedCollections"
    {
        return ok(queries.set_joined_collections(&a.documents("collections")?)).map(Some);
    }

    let aliases = || a.strings("aliases");
    let aggregations = || a.queries("aggregations");
    let group_by =
        || -> R<Vec<utopia_query::Value>> { Ok(a.array("attributes")?.values().map(value::to_query).collect()) };
    let null = Obj::null;

    let result = match (v, method) {
        // ----- Query validators -----
        (_, "getMethodType") if v.query_validator().is_some() => {
            v.query_validator().map(|q| q.method_type().as_str()).into_obj()
        }
        (V::Filter(x), "allowJoinAliases") => {
            x.allow_join_aliases(&aliases()?);
            null()
        }
        (V::Order(x), "allowJoinAliases") => {
            x.allow_join_aliases(&aliases()?);
            null()
        }
        (V::Select(x), "allowJoinAliases") => {
            x.allow_join_aliases(&aliases()?);
            null()
        }
        (V::Aggregate(x), "allowJoinAliases") => {
            x.allow_join_aliases(&aliases()?);
            null()
        }
        (V::GroupBy(x), "allowJoinAliases") => {
            x.allow_join_aliases(&aliases()?);
            null()
        }
        (V::Filter(x), "resetJoinAliases") => {
            x.reset_join_aliases();
            null()
        }
        (V::Order(x), "resetJoinAliases") => {
            x.reset_join_aliases();
            null()
        }
        (V::Select(x), "resetJoinAliases") => {
            x.reset_join_aliases();
            null()
        }
        (V::Aggregate(x), "resetJoinAliases") => {
            x.reset_join_aliases();
            null()
        }
        (V::GroupBy(x), "resetJoinAliases") => {
            x.reset_join_aliases();
            null()
        }
        (V::Join(x), "resetJoinAliases") => {
            x.reset_join_aliases();
            null()
        }
        (V::Join(x), "isValidCount") => x.is_valid_count(a.int("count")?.max(0) as usize).into_obj(),
        (V::Filter(x), "getMaxValuesCount") => x.max_values_count().into_obj(),
        (V::Order(x), "addAggregationAliases") => {
            x.add_aggregation_aliases(&aliases()?);
            null()
        }
        (V::Order(x), "resetAggregationAliases") => {
            x.reset_aggregation_aliases();
            null()
        }
        (V::Order(x), "setAggregations") => {
            let list = aggregations()?;
            x.set_aggregations(&list.iter().map(|q| &**q).collect::<Vec<_>>());
            null()
        }
        (V::Select(x), "setAggregations") => {
            let list = aggregations()?;
            x.set_aggregations(&list.iter().map(|q| &**q).collect::<Vec<_>>());
            null()
        }
        (V::Aggregate(x), "setAggregations") => {
            let list = aggregations()?;
            x.set_aggregations(&list.iter().map(|q| &**q).collect::<Vec<_>>());
            null()
        }
        (V::Having(x), "setAggregations") => {
            let list = aggregations()?;
            x.set_aggregations(&list.iter().map(|q| &**q).collect::<Vec<_>>());
            null()
        }
        (V::Order(x), "setGroupBy") => {
            x.set_group_by(&group_by()?);
            null()
        }
        (V::Select(x), "setGroupBy") => {
            x.set_group_by(&group_by()?);
            null()
        }
        (V::Aggregate(x), "setGroupBy") => {
            x.set_group_by(&group_by()?);
            null()
        }
        (V::Having(x), "setGroupBy") => {
            x.set_group_by(&group_by()?);
            null()
        }
        (V::Having(x), "setFilter") => {
            let filter = match a.obj("filter")? {
                None => None,
                Some(o) => o.downcast_ref::<ValidatorObj>().and_then(|v| v.0.query_validator()),
            };
            x.set_filter(filter);
            null()
        }

        // ----- Others -----
        (V::Spatial(x), "getSpatialType") => x.spatial_type().into_obj(),
        (V::AttributeDefinition(x), _) => return attribute_definition_method(x, method, a),
        (V::IndexDefinition(x), _) => return index_definition_method(x, method, a),
        (V::IndexDependency(x), "check") => x.check(&attribute(a, "attribute")?).into_obj(),
        _ => return Ok(None),
    };
    Ok(Some(result))
}

/// The public `check*` steps of `AttributeDefinition`.
fn attribute_definition_method(x: &AttributeDefinition, method: &str, a: &Args) -> R<Option<Obj>> {
    let attr = || attribute(a, "attribute");
    let result = match method {
        "checkDuplicateId" => x.check_duplicate_id(&attr()?),
        "checkDuplicateInSchema" => x.check_duplicate_in_schema(&attr()?),
        "checkRequiredFilters" => {
            let filters = a.opt_strings("filters")?;
            x.check_required_filters(&attr()?, filters.as_deref())
        }
        "checkFormat" => x.check_format(&attr()?),
        "checkAttributeLimits" => x.check_attribute_limits(&attr()?),
        "checkType" => x.check_type(&attr()?, a.opt_int("size")?, a.opt_bool("array")?),
        "checkDefaultValue" => x.check_default_value(&attr()?),
        _ => return Ok(None),
    };
    ok(result).map(Some)
}

/// The public `check*` steps of `IndexDefinition`.
fn index_definition_method(x: &IndexDefinition, method: &str, a: &Args) -> R<Option<Obj>> {
    let i = index(a, "index");
    let result = match method {
        "checkValidIndex" => x.check_valid_index(&i?),
        "checkValidAttributes" => x.check_valid_attributes(&i?),
        "checkEmptyIndexAttributes" => x.check_empty_index_attributes(&i?),
        "checkDuplicatedAttributes" => x.check_duplicated_attributes(&i?),
        "checkFulltextIndexNonString" => x.check_fulltext_index_non_string(&i?),
        "checkArrayIndexes" => x.check_array_indexes(&i?),
        "checkIndexLengths" => return ok(x.check_index_lengths(&i?)).map(Some),
        "checkReservedNames" => x.check_reserved_names(&i?),
        "checkSpatialIndexes" => x.check_spatial_indexes(&i?),
        "checkNonSpatialIndexOnSpatialAttributes" => x.check_non_spatial_index_on_spatial_attributes(&i?),
        "checkVectorIndexes" => x.check_vector_indexes(&i?),
        "checkTrigramIndexes" => x.check_trigram_indexes(&i?),
        "checkKeyUniqueFulltextSupport" => x.check_key_unique_fulltext_support(&i?),
        "checkMultipleFulltextIndexes" => x.check_multiple_fulltext_indexes(&i?),
        "checkIdenticalIndexes" => x.check_identical_indexes(&i?),
        "checkObjectIndexes" => x.check_object_indexes(&i?),
        "checkTtlIndexes" => x.check_ttl_indexes(&i?),
        _ => return Ok(None),
    };
    Ok(Some(result.into_obj()))
}
