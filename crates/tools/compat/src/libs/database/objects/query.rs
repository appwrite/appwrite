//! `Query`, `Operator`, `ParsedQuery`, and the `Query\Method` and
//! `OperatorType` enums: the twin of `Utopia\Database\Query` (on
//! `Utopia\Query\Query`), `Utopia\Database\Operator` and
//! `Utopia\Database\ParsedQuery` driven by `object.*`.
//!
//! A query is a value (`Value::Ext(Ext::Query)`, a base query whose
//! database behaviour is [`utopia_database::query::Query`]'s), an operator
//! too (`Value::Ext(Ext::Operator)`); a `ParsedQuery` is an object
//! ([`ParsedQueryObj`]). Parameters are decoded as `Core::argument` decodes
//! them for their PHP types: an `array $queries` with `Values::queries`, an
//! enum (`Method|string`) with `Method::from()`, any other array with
//! `Values::decode`.

use serde_json::{Value as Json, json};
use utopia_database::operator::{Number, Operator, OperatorType};
use utopia_database::query::{ParsedQuery, Query};
use utopia_database::value::{self, Array, ArrayKey, Ext, Str, Value};
use utopia_database::{Error, Kind};
use utopia_query::{Method, NullsPosition, Value as QValue};

use crate::libs::database::args::{self, Args};
use crate::libs::database::codec;
use crate::libs::database::convert::IntoObj;
use crate::libs::database::ctx::{Fail, R, fault};
use crate::libs::database::enums::{self, PhpEnum};
use crate::libs::database::obj::{EnumCase, Obj};
use crate::libs::database::values;
use crate::php_object;

/// A `ParsedQuery` (`Query::groupByType()`'s result).
#[derive(Clone)]
pub struct ParsedQueryObj(pub ParsedQuery);

php_object!(ParsedQueryObj, "ParsedQuery", |this| encode_parsed(&this.0));

/// A `ParsedQuery` on the wire: its public properties, in declaration order.
fn encode_parsed(p: &ParsedQuery) -> Json {
    let queries = |qs: &[Query]| Json::Array(qs.iter().map(|q| codec::encode_query(q)).collect());
    json!({
        "$value": "ParsedQuery",
        "fields": {
            "filters": queries(&p.filters),
            "selections": queries(&p.selections),
            "aggregations": queries(&p.aggregations),
            "groupBy": Json::Array(p.group_by.iter().map(codec::encode_query_value).collect()),
            "having": queries(&p.having),
            "distinct": p.distinct,
            "joins": queries(&p.joins),
            "unions": queries(&p.unions),
            "limit": p.limit,
            "offset": p.offset,
            "cursor": p.cursor.as_ref().map(codec::encode_document).unwrap_or(Json::Null),
            "cursorDirection": enums::encode_opt(p.cursor_direction),
            "timeBuckets": Json::Array(p.time_buckets.iter().map(|b| json!({
                "attribute": b.attribute,
                "interval": codec::encode_query_value(&b.interval),
            })).collect()),
            "orderAttributes": p.order_attributes,
            "orderTypes": Json::Array(p.order_types.iter().map(|t| enums::encode(*t)).collect()),
            "relationships": queries(&p.relationships),
        }
    })
}

/// `new <class>(...)` for the classes of this module (`None`: not one).
pub async fn construct(class: &str, a: &Args) -> R<Option<Obj>> {
    Ok(Some(match class {
        // The database's constructor: an order without an attribute
        // orders by `$sequence`.
        "Query" => Query::new(
            a.req_enum::<Method>("method")?,
            a.string_or("attribute", "")?,
            qarray(a, "values")?,
            a.string_or("alias", "")?,
        )
        .into_obj(),
        "Operator" => operator_obj(Operator::from_array(
            a.req_enum::<OperatorType>("method")?,
            a.string_or("attribute", "")?,
            a.array("values")?,
        )),
        _ => return Ok(None),
    }))
}

/// `<class>::<method>(...)` for the classes of this module.
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Option<Obj>> {
    match class {
        "Query" => query_static(method, a),
        "Operator" => operator_static(method, a),
        "Query\\Method" => enum_static::<Method>(method, a, Method::CASES),
        "OperatorType" => enum_static::<OperatorType>(method, a, OperatorType::CASES),
        _ => Ok(None),
    }
}

/// `$this-><method>(...)` for the objects of this module.
pub async fn invoke(this: &mut Obj, method: &str, a: &Args) -> R<Option<Obj>> {
    match this {
        Obj::Value(Value::Ext(Ext::Query(q))) => {
            let result = query_method(q, method, a)?;
            Ok(result.map(|r| match r {
                Ret::This => Obj::Value(Value::Ext(Ext::Query(q.clone()))),
                Ret::Obj(o) => o,
            }))
        }
        Obj::Value(Value::Ext(Ext::Operator(op))) => {
            let result = operator_method(op, method, a)?;
            Ok(result.map(|r| match r {
                Ret::This => Obj::Value(Value::Ext(Ext::Operator(op.clone()))),
                Ret::Obj(o) => o,
            }))
        }
        Obj::Enum(e) => enum_method(e, method),
        Obj::Object(o) => match o.downcast_ref::<ParsedQueryObj>() {
            Some(p) => Ok(match method {
                "isAggregation" => Some(p.0.is_aggregation().into_obj()),
                _ => None,
            }),
            None => Ok(None),
        },
        _ => Ok(None),
    }
}

/// What an instance method returns: `$this` (fluent), or a result.
enum Ret {
    This,
    Obj(Obj),
}

impl<T: IntoObj> From<T> for Ret {
    fn from(t: T) -> Self {
        Ret::Obj(t.into_obj())
    }
}

/// An `array` parameter as query values (`Values::decode`).
fn qarray(a: &Args, key: &str) -> R<utopia_query::Array> {
    Ok(value::to_query_array(&a.array(key)?))
}

/// An `array` parameter of a static method: anything else is PHP's
/// `TypeError` (raised at `Core::invoke`'s call).
fn array_param(a: &Args, key: &str, function: &str) -> R<Array> {
    match a.value(key)? {
        Value::Array(array) => Ok(array),
        other => Err(Fail::Error(Error::new(
            Kind::TypeError,
            format!(
                "{function}: Argument #1 (${key}) must be of type array, {} given, called in /usr/src/code/tests/compat/database/ops/core.php on line 610",
                other.debug_type()
            ),
        ))),
    }
}

/// A `mixed` parameter as a query value.
fn qvalue(a: &Args, key: &str) -> R<QValue> {
    Ok(value::to_query(&a.value(key)?))
}

/// A base query as a database query (`new static(...)`: the database's
/// constructor), as an object.
fn query(q: utopia_query::Query) -> Obj {
    Query::from(q).into_obj()
}

/// A base query that may fail: the base library's exception, as PHP raises
/// it (the database does not override these factories).
fn query_r(q: utopia_query::Result<utopia_query::Query>) -> R<Obj> {
    Ok(query(q?))
}

/// The queries of an `array $queries` parameter as values (`Values::queries`).
fn queries_values(a: &Args, key: &str) -> R<utopia_query::Array> {
    Ok(utopia_query::Array::from_list(
        a.queries(key)?.into_iter().map(|q| QValue::from(q.into_inner())).collect::<Vec<_>>(),
    ))
}

/// An `int|float|string` parameter of the operator helpers.
fn number(v: Value, method: &str, param: &str) -> R<Number> {
    Ok(match v {
        Value::Int(i) => Number::Int(i),
        Value::Float(f) => Number::Float(f),
        Value::Str(s) => Number::Str(s),
        Value::Bool(b) => Number::Int(i64::from(b)),
        other => {
            return Err(Fail::Error(Error::new(
                Kind::TypeError,
                format!(
                    "Utopia\\Database\\Operator::{method}(): Argument ${param} must be of type string|int|float, {} given",
                    other.type_name()
                ),
            )));
        }
    })
}

/// An optional `int|float|string|null` parameter.
fn opt_number(a: &Args, key: &str, method: &str) -> R<Option<Number>> {
    match a.value(key)? {
        Value::Null => Ok(None),
        v => number(v, method, key).map(Some),
    }
}

/// A number parameter with a default.
fn number_or(a: &Args, key: &str, default: i64, method: &str) -> R<Number> {
    match a.opt_value(key)? {
        None => Ok(Number::Int(default)),
        Some(v) => number(v, method, key),
    }
}

/// An operator as an object.
fn operator_obj(op: Operator) -> Obj {
    Obj::Value(Value::from(op))
}

/// A `string` parameter as bytes (binary strings stay as they are).
fn bytes(a: &Args, key: &str) -> R<Vec<u8>> {
    match a.value(key)? {
        Value::Str(s) => Ok(s.as_bytes().to_vec()),
        Value::Null => fault(format!("missing argument `{key}`")),
        other => Ok(value::to_text(&other).into_bytes()),
    }
}

fn query_static(method: &str, a: &Args) -> R<Option<Obj>> {
    use utopia_query::Query as B;
    let attr = || a.string("attribute");
    let alias = || a.string_or("alias", "");
    Ok(Some(match method {
        "equal" => query(B::equal(attr()?, qarray(a, "values")?)),
        "notEqual" => query(B::not_equal(attr()?, qvalue(a, "value")?)),
        "lessThan" => query(B::less_than(attr()?, qvalue(a, "value")?)),
        "lessThanEqual" => query(B::less_than_equal(attr()?, qvalue(a, "value")?)),
        "greaterThan" => query(B::greater_than(attr()?, qvalue(a, "value")?)),
        "greaterThanEqual" => query(B::greater_than_equal(attr()?, qvalue(a, "value")?)),
        "contains" => query(B::contains(attr()?, qarray(a, "values")?)),
        "containsString" => query(B::contains_string(attr()?, qarray(a, "values")?)),
        "containsAny" => query(B::contains_any(attr()?, qarray(a, "values")?)),
        "containsAll" => query(B::contains_all(attr()?, qarray(a, "values")?)),
        "notContains" => query(B::not_contains(attr()?, qarray(a, "values")?)),
        "between" => query(B::between(attr()?, qvalue(a, "start")?, qvalue(a, "end")?)),
        "notBetween" => query(B::not_between(attr()?, qvalue(a, "start")?, qvalue(a, "end")?)),
        "search" => query(B::search(attr()?, qvalue(a, "value")?)),
        "notSearch" => query(B::not_search(attr()?, qvalue(a, "value")?)),
        "select" => query(B::select_values(qarray(a, "attributes")?)),
        "orderDesc" => query(B::order_desc(a.string_or("attribute", "")?, a.enum_::<NullsPosition>("nulls")?)),
        "orderAsc" => query(B::order_asc(a.string_or("attribute", "")?, a.enum_::<NullsPosition>("nulls")?)),
        "orderRandom" => query(B::order_random()),
        "limit" => query(B::limit(a.int("value")?)),
        "offset" => query(B::offset(a.int("value")?)),
        "cursorAfter" => Query::cursor_after_value(cursor(a, "cursorAfter")?).into_obj(),
        "cursorBefore" => Query::cursor_before_value(cursor(a, "cursorBefore")?).into_obj(),
        "isNull" => query(B::is_null(attr()?)),
        "isNotNull" => query(B::is_not_null(attr()?)),
        "startsWith" => query(B::starts_with(attr()?, qvalue(a, "value")?)),
        "notStartsWith" => query(B::not_starts_with(attr()?, qvalue(a, "value")?)),
        "endsWith" => query(B::ends_with(attr()?, qvalue(a, "value")?)),
        "notEndsWith" => query(B::not_ends_with(attr()?, qvalue(a, "value")?)),
        "createdBefore" => query(B::created_before(qvalue(a, "value")?)),
        "createdAfter" => query(B::created_after(qvalue(a, "value")?)),
        "updatedBefore" => query(B::updated_before(qvalue(a, "value")?)),
        "updatedAfter" => query(B::updated_after(qvalue(a, "value")?)),
        "createdBetween" => query(B::created_between(qvalue(a, "start")?, qvalue(a, "end")?)),
        "updatedBetween" => query(B::updated_between(qvalue(a, "start")?, qvalue(a, "end")?)),
        "or" => query(B::logical(Method::Or, queries_values(a, "queries")?)),
        "and" => query(B::logical(Method::And, queries_values(a, "queries")?)),
        "elemMatch" => query(B::new(Method::ElemMatch, attr()?, queries_values(a, "queries")?, "")),
        "relationship" => query(B::new(Method::Relationship, attr()?, queries_values(a, "queries")?, "")),
        "having" => query(B::logical(Method::Having, queries_values(a, "queries")?)),
        "union" => query(B::logical(Method::Union, queries_values(a, "queries")?)),
        "unionAll" => query(B::logical(Method::UnionAll, queries_values(a, "queries")?)),
        "distanceEqual" => {
            query(B::distance_equal(attr()?, qarray(a, "values")?, qvalue(a, "distance")?, a.bool_or("meters", false)?))
        }
        "distanceNotEqual" => query(B::distance_not_equal(
            attr()?,
            qarray(a, "values")?,
            qvalue(a, "distance")?,
            a.bool_or("meters", false)?,
        )),
        "distanceGreaterThan" => query(B::distance_greater_than(
            attr()?,
            qarray(a, "values")?,
            qvalue(a, "distance")?,
            a.bool_or("meters", false)?,
        )),
        "distanceLessThan" => query(B::distance_less_than(
            attr()?,
            qarray(a, "values")?,
            qvalue(a, "distance")?,
            a.bool_or("meters", false)?,
        )),
        "intersects" => query(B::intersects(attr()?, qarray(a, "values")?)),
        "notIntersects" => query(B::not_intersects(attr()?, qarray(a, "values")?)),
        "crosses" => query(B::crosses(attr()?, qarray(a, "values")?)),
        "notCrosses" => query(B::not_crosses(attr()?, qarray(a, "values")?)),
        "overlaps" => query(B::overlaps(attr()?, qarray(a, "values")?)),
        "notOverlaps" => query(B::not_overlaps(attr()?, qarray(a, "values")?)),
        "touches" => query(B::touches(attr()?, qarray(a, "values")?)),
        "notTouches" => query(B::not_touches(attr()?, qarray(a, "values")?)),
        "covers" => query(B::covers(attr()?, qarray(a, "values")?)),
        "notCovers" => query(B::not_covers(attr()?, qarray(a, "values")?)),
        "spatialEquals" => query(B::spatial_equals(attr()?, qarray(a, "values")?)),
        "notSpatialEquals" => query(B::not_spatial_equals(attr()?, qarray(a, "values")?)),
        "vectorDot" => query(B::vector_dot(attr()?, qarray(a, "vector")?)),
        "vectorCosine" => query(B::vector_cosine(attr()?, qarray(a, "vector")?)),
        "vectorEuclidean" => query(B::vector_euclidean(attr()?, qarray(a, "vector")?)),
        "regex" => query(B::regex(attr()?, qvalue(a, "pattern")?)),
        "exists" => query(B::exists(qarray(a, "attributes")?)),
        "notExists" => query(B::not_exists(qvalue(a, "attribute")?)),
        "count" => query(B::count(a.string_or("attribute", "*")?, alias()?)),
        "countDistinct" => query(B::count_distinct(attr()?, alias()?)),
        "sum" => query(B::sum(attr()?, alias()?)),
        "avg" => query(B::avg(attr()?, alias()?)),
        "min" => query(B::min(attr()?, alias()?)),
        "max" => query(B::max(attr()?, alias()?)),
        "stddev" => query(B::stddev(attr()?, alias()?)),
        "stddevPop" => query(B::stddev_pop(attr()?, alias()?)),
        "stddevSamp" => query(B::stddev_samp(attr()?, alias()?)),
        "variance" => query(B::variance(attr()?, alias()?)),
        "varPop" => query(B::var_pop(attr()?, alias()?)),
        "varSamp" => query(B::var_samp(attr()?, alias()?)),
        "bitAnd" => query(B::bit_and(attr()?, alias()?)),
        "bitOr" => query(B::bit_or(attr()?, alias()?)),
        "bitXor" => query(B::bit_xor(attr()?, alias()?)),
        "groupBy" => query(B::new(Method::GroupBy, "", qarray(a, "attributes")?, "")),
        "groupByTimeBucket" => query_r(B::group_by_time_bucket(attr()?, &a.string("interval")?))?,
        "distinct" => query(B::distinct()),
        "on" => query(B::on(a.string("left")?, a.string("right")?, a.string_or("operator", "=")?)),
        "join" | "leftJoin" | "rightJoin" | "fullOuterJoin" => {
            let join = match method {
                "join" => Method::Join,
                "leftJoin" => Method::LeftJoin,
                "rightJoin" => Method::RightJoin,
                _ => Method::FullOuterJoin,
            };
            query_r(B::join_values(join, a.string("collection")?, a.string("alias")?, qarray(a, "on")?))?
        }
        "crossJoin" => query_r(B::cross_join(a.string("collection")?, a.string("alias")?))?,
        "naturalJoin" => query_r(B::natural_join(a.string("collection")?, a.string("alias")?))?,
        "jsonContains" => query(B::json_contains(attr()?, qvalue(a, "value")?)),
        "jsonNotContains" => query(B::json_not_contains(attr()?, qvalue(a, "value")?)),
        "jsonOverlaps" => query(B::json_overlaps(attr()?, qarray(a, "values")?)),
        "jsonPath" => query(B::json_path(attr()?, a.string("path")?, a.string("operator")?, qvalue(a, "value")?)),
        "raw" => query(B::raw(a.string("sql")?, qarray(a, "bindings")?)),
        "page" => {
            let [limit, offset] = B::page(a.int("page")?, a.int_or("perPage", 25)?)?;
            vec![Query::from(limit), Query::from(offset)].into_obj()
        }
        "merge" => {
            let merged = B::merge(&base_queries(a, "queriesA")?, &base_queries(a, "queriesB")?);
            merged.into_iter().map(Query::from).collect::<Vec<_>>().into_obj()
        }
        "diff" => {
            let diff = B::diff(&base_queries(a, "queriesA")?, &base_queries(a, "queriesB")?);
            diff.into_iter().map(Query::from).collect::<Vec<_>>().into_obj()
        }
        // `Method|string`: a string goes through `Method::from()` (a
        // `ValueError` when it names no method), and a case is a method.
        "isMethod" => {
            a.req_enum::<Method>("value")?;
            true.into_obj()
        }
        "parse" => Query::parse_bytes(&bytes(a, "query")?, a.bool_or("allowRaw", false)?)?.into_obj(),
        "parseQuery" => {
            let raw = array_param(a, "query", "Utopia\\Database\\Query::parseQuery()")?;
            Query::parse_query(&value::to_query_array(&raw), a.bool_or("allowRaw", false)?)?.into_obj()
        }
        "parseQueries" => {
            // `Values::queries` has parsed the strings already, so PHP's
            // `parse(string)` is handed a query.
            if !a.queries("queries")?.is_empty() {
                return Err(Fail::Error(Error::new(
                    Kind::TypeError,
                    "Utopia\\Database\\Query::parse(): Argument #1 ($query) must be of type string, Utopia\\Database\\Query given, called in /usr/src/code/packages/query/src/Query.php on line 331",
                )));
            }
            Vec::<Query>::new().into_obj()
        }
        "fingerprint" => Query::fingerprint_queries(&a.queries("queries")?)?.into_obj(),
        "groupByType" => Obj::object(ParsedQueryObj(Query::group_by_type(&a.queries("queries")?)?)),
        "getByType" => {
            let queries = a.queries("queries")?;
            let types = methods(a, "types")?;
            Query::by_type(&queries, &types).into_iter().cloned().collect::<Vec<_>>().into_obj()
        }
        "getCursorQueries" => {
            let queries = a.queries("queries")?;
            Query::cursor_queries(&queries).into_iter().cloned().collect::<Vec<_>>().into_obj()
        }
        _ => return Ok(None),
    }))
}

/// Base queries of an `array` parameter of queries.
fn base_queries(a: &Args, key: &str) -> R<Vec<utopia_query::Query>> {
    Ok(Query::into_inners(a.queries(key)?))
}

/// The cursor of `cursorAfter` / `cursorBefore` (`array|object $value`).
fn cursor(a: &Args, method: &str) -> R<QValue> {
    match a.value("value")? {
        v @ (Value::Array(_) | Value::Object(_) | Value::Ext(_)) => Ok(value::to_query(&v)),
        other => Err(Fail::Error(Error::new(
            Kind::TypeError,
            format!(
                "Utopia\\Database\\Query::{method}(): Argument #1 ($value) must be of type object|array, {} given, called in /usr/src/code/tests/compat/database/ops/core.php on line 610",
                other.debug_type()
            ),
        ))),
    }
}

/// An `array<Method>` parameter: its `$enum` cases (anything else matches
/// no method).
fn methods(a: &Args, key: &str) -> R<Vec<Method>> {
    let mut out = Vec::new();
    let Some(raw) = a.raw(key) else { return Ok(out) };
    let items = match args::stored_obj(&a.cx, raw)? {
        Some(Obj::List(items)) => items,
        Some(Obj::Enum(e)) => vec![Obj::Enum(e)],
        _ => codec::list(raw)?.into_iter().map(|j| args::decode_obj(&a.cx, j)).collect::<R<Vec<_>>>()?,
    };
    for item in items {
        if let Some(m) = values::enum_obj::<Method>(&item) {
            out.push(m);
        }
    }
    Ok(out)
}

fn query_method(q: &mut utopia_query::Query, method: &str, a: &Args) -> R<Option<Ret>> {
    Ok(Some(match method {
        "getMethod" => enums::obj(q.method()).into(),
        "getAttribute" => q.attribute().into(),
        "getValues" => value::from_query_array(q.values()).into(),
        "getValue" => match q.value() {
            Some(v) => value::from_query(v).into(),
            None => a.value("default")?.into(),
        },
        "getAlias" => q.alias().into(),
        "isNestedJoin" => q.is_nested_join().into(),
        "getJoinOnQueries" => q.join_on_queries().into_iter().cloned().map(Query::from).collect::<Vec<_>>().into(),
        "setMethod" => {
            q.set_method(a.req_enum::<Method>("method")?);
            Ret::This
        }
        "setAttribute" => {
            q.set_attribute(a.string("attribute")?);
            Ret::This
        }
        "setValues" => {
            q.set_values(qarray(a, "values")?);
            Ret::This
        }
        "setValue" => {
            q.set_value(qvalue(a, "value")?);
            Ret::This
        }
        "isSpatialQuery" => q.is_spatial_query().into(),
        "isNested" => q.is_nested().into(),
        "onArray" => q.on_array().into(),
        "setOnArray" => {
            q.set_on_array(a.bool("bool")?);
            ().into()
        }
        "setAttributeType" => {
            q.set_attribute_type(a.string("type")?);
            ().into()
        }
        "getAttributeType" => q.attribute_type().into(),
        "isSpatialAttribute" => database_query(q).is_spatial_attribute().into(),
        "isObjectAttribute" => database_query(q).is_object_attribute().into(),
        "shape" => database_query(q).shape()?.into(),
        "toArray" => value::from_query_array(&database_query(q).to_array()?).into(),
        "toString" => {
            let db = database_query(q);
            // `toArray()` throws the database's exception; `json_encode`'s
            // failure is the base library's (`Utopia\Query\Query::toString`).
            db.to_array()?;
            match db.to_json() {
                Ok(json) => json.into(),
                Err(e) => return Err(Fail::php("Utopia\\Query\\Exception", e.message_bytes().to_vec())),
            }
        }
        _ => return Ok(None),
    }))
}

/// A query as a database query (its class: `Utopia\Database\Query`).
fn database_query(q: &utopia_query::Query) -> Query {
    // Not `Query::from`: the constructor already ran, and a query's
    // attribute set to '' afterwards stays ''.
    let mut db = Query::from(utopia_query::Query::distinct());
    *db = q.clone();
    db
}

fn operator_static(method: &str, a: &Args) -> R<Option<Obj>> {
    Ok(Some(match method {
        "increment" => {
            operator_obj(Operator::increment(number_or(a, "value", 1, method)?, opt_number(a, "max", method)?))
        }
        "decrement" => {
            operator_obj(Operator::decrement(number_or(a, "value", 1, method)?, opt_number(a, "min", method)?))
        }
        "multiply" => operator_obj(Operator::multiply(
            number(a.value("factor")?, method, "factor")?,
            opt_number(a, "max", method)?,
        )),
        "divide" => operator_obj(Operator::divide(
            number(a.value("divisor")?, method, "divisor")?,
            opt_number(a, "min", method)?,
        )?),
        "modulo" => operator_obj(Operator::modulo(number(a.value("divisor")?, method, "divisor")?)?),
        "power" => operator_obj(Operator::power(
            number(a.value("exponent")?, method, "exponent")?,
            opt_number(a, "max", method)?,
        )),
        "arrayAppend" => operator_obj(Operator::from_array(OperatorType::ArrayAppend, "", a.array("values")?)),
        "arrayPrepend" => operator_obj(Operator::from_array(OperatorType::ArrayPrepend, "", a.array("values")?)),
        "arrayIntersect" => operator_obj(Operator::from_array(OperatorType::ArrayIntersect, "", a.array("values")?)),
        "arrayDiff" => operator_obj(Operator::from_array(OperatorType::ArrayDiff, "", a.array("values")?)),
        "arrayInsert" => operator_obj(Operator::array_insert(a.int("index")?, a.value("value")?)),
        "arrayRemove" => operator_obj(Operator::array_remove(a.value("value")?)),
        "arrayUnique" => operator_obj(Operator::array_unique()),
        "arrayFilter" => operator_obj(Operator::array_filter(&a.string("condition")?, a.value("value")?)),
        "stringConcat" => operator_obj(Operator::string_concat(a.value("value")?)),
        "stringReplace" => operator_obj(Operator::from_array(
            OperatorType::StringReplace,
            "",
            Array::from_list(vec![
                Value::Str(Str::from(bytes(a, "search")?)),
                Value::Str(Str::from(bytes(a, "replace")?)),
            ]),
        )),
        "toggle" => operator_obj(Operator::toggle()),
        "dateAddDays" => operator_obj(Operator::date_add_days(a.int("days")?)),
        "dateSubDays" => operator_obj(Operator::date_sub_days(a.int("days")?)),
        "dateSetNow" => operator_obj(Operator::date_set_now()),
        // `OperatorType|string`: a string goes through `OperatorType::from()`.
        "isMethod" => {
            a.req_enum::<OperatorType>("value")?;
            true.into_obj()
        }
        "isOperator" => Operator::is_operator(&a.value("value")?).into_obj(),
        "parse" => operator_obj(Operator::parse(bytes(a, "operator")?)?),
        "parseOperator" => operator_obj(Operator::parse_operator(&array_param(
            a,
            "operator",
            "Utopia\\Database\\Operator::parseOperator()",
        )?)?),
        "parseOperators" => {
            let mut out = Vec::new();
            for item in a.array("operators")?.values() {
                let text = match item {
                    Value::Str(s) => s.as_bytes().to_vec(),
                    other => value::to_text(other).into_bytes(),
                };
                out.push(Value::from(Operator::parse(text)?));
            }
            Obj::Value(Value::Array(Array::from_list(out)))
        }
        "extractOperators" => extract_operators(a)?,
        _ => return Ok(None),
    }))
}

/// `Operator::extractOperators($data)`: PHP sets the attribute on the
/// operator objects the data holds, so a stored operator passed in is
/// changed too.
fn extract_operators(a: &Args) -> R<Obj> {
    let data = a.array("data")?;
    let extracted = Operator::extract_operators(data);
    let raw = a.raw("data").and_then(Json::as_object);
    let mut operators = Array::new();
    for (key, op) in extracted.operators {
        let name = match key.as_ref() {
            value::KeyRef::Int(i) => i.to_string(),
            value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        if let Some(stored) = raw.and_then(|o| o.get(&name)).and_then(|j| j.get("$obj")).and_then(Json::as_str)
            && a.cx.has_stored(stored)
        {
            a.cx.store(stored, operator_obj(op.clone()));
        }
        operators.set(key, Value::from(op));
    }
    let mut out = Array::new();
    out.set(ArrayKey::from("operators"), Value::Array(operators));
    out.set(ArrayKey::from("updates"), Value::Array(extracted.updates));
    Ok(Obj::Value(Value::Array(out)))
}

fn operator_method(op: &mut Operator, method: &str, a: &Args) -> R<Option<Ret>> {
    Ok(Some(match method {
        "getMethod" => enums::obj(op.method()).into(),
        "getAttribute" => op.attribute().into(),
        "getValues" => op.values().into_owned().into(),
        "getValue" => match op.value() {
            Some(v) => v.into(),
            None => a.value("default")?.into(),
        },
        "setMethod" => {
            op.set_method(a.req_enum::<OperatorType>("method")?);
            Ret::This
        }
        "setAttribute" => {
            op.set_attribute(a.string("attribute")?);
            Ret::This
        }
        "setValues" => {
            op.set_values_array(a.array("values")?);
            Ret::This
        }
        "setValue" => {
            op.set_value(a.value("value")?);
            Ret::This
        }
        "isNumericOperation" => op.is_numeric_operation().into(),
        "isArrayOperation" => op.is_array_operation().into(),
        "isStringOperation" => op.is_string_operation().into(),
        "isBooleanOperation" => op.is_boolean_operation().into(),
        "isDateOperation" => op.is_date_operation().into(),
        "toArray" => op.to_array().into(),
        "toString" => op.to_string()?.into(),
        _ => return Ok(None),
    }))
}

/// `cases()`, `from()`, `tryFrom()` of a backed enum.
fn enum_static<E: PhpEnum>(method: &str, a: &Args, cases: &[E]) -> R<Option<Obj>> {
    Ok(Some(match method {
        "cases" => Obj::List(cases.iter().map(|c| enums::obj(*c)).collect()),
        "from" => enums::obj(a.req_enum::<E>("value")?),
        "tryFrom" => match a.opt_string("value")?.and_then(|v| E::from_backing(&v)) {
            Some(c) => enums::obj(c),
            None => Obj::null(),
        },
        _ => return Ok(None),
    }))
}

/// The methods of a `Query\Method` or `OperatorType` case.
fn enum_method(e: &EnumCase, method: &str) -> R<Option<Obj>> {
    if let Some(m) = enums::from_case::<Method>(e) {
        return Ok(Some(match method {
            "isFilter" => m.is_filter().into_obj(),
            "isSpatial" => m.is_spatial().into_obj(),
            "isVector" => m.is_vector().into_obj(),
            "isJson" => m.is_json().into_obj(),
            "isNested" => m.is_nested().into_obj(),
            "isAggregate" => m.is_aggregate().into_obj(),
            "isJoinCondition" => m.is_join_condition().into_obj(),
            "isJoin" => m.is_join().into_obj(),
            _ => return Ok(None),
        }));
    }
    if let Some(t) = enums::from_case::<OperatorType>(e) {
        return Ok(Some(match method {
            "isNumeric" => t.is_numeric().into_obj(),
            "isArray" => t.is_array().into_obj(),
            "isString" => t.is_string().into_obj(),
            "isBoolean" => t.is_boolean().into_obj(),
            "isDate" => t.is_date().into_obj(),
            _ => return Ok(None),
        }));
    }
    Ok(None)
}
