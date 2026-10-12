//! query.*, operator.*: Query and Operator (twin of `ops/values.php`).

use serde_json::{Map, Value as Json, json};
use utopia_database::operator::{Number, Operator, OperatorMethod};
use utopia_database::query::{self, Method, Query};
use utopia_database::value::{Array, Ext, Value};

use super::codec;
use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

/// Operations of this area (must match `spec.d/values.json` and `ops/values.php`).
pub const OPS: &[&str] = &[
    "query.new",
    "query.inspect",
    "query.set",
    "query.attribute_type",
    "query.is_method",
    "query.constants",
    "query.parse",
    "query.parse_query",
    "query.parse_queries",
    "query.to_array",
    "query.to_string",
    "query.shape",
    "query.fingerprint",
    "query.get_by_type",
    "query.get_cursor_queries",
    "query.group_by_type",
    "query.equal",
    "query.not_equal",
    "query.less_than",
    "query.less_than_equal",
    "query.greater_than",
    "query.greater_than_equal",
    "query.contains",
    "query.contains_any",
    "query.not_contains",
    "query.contains_all",
    "query.between",
    "query.not_between",
    "query.search",
    "query.not_search",
    "query.starts_with",
    "query.not_starts_with",
    "query.ends_with",
    "query.not_ends_with",
    "query.regex",
    "query.select",
    "query.order_desc",
    "query.order_asc",
    "query.order_random",
    "query.limit",
    "query.offset",
    "query.cursor_after",
    "query.cursor_before",
    "query.is_null",
    "query.is_not_null",
    "query.created_before",
    "query.created_after",
    "query.updated_before",
    "query.updated_after",
    "query.created_between",
    "query.updated_between",
    "query.or",
    "query.and",
    "query.elem_match",
    "query.distance_equal",
    "query.distance_not_equal",
    "query.distance_greater_than",
    "query.distance_less_than",
    "query.intersects",
    "query.not_intersects",
    "query.crosses",
    "query.not_crosses",
    "query.overlaps",
    "query.not_overlaps",
    "query.touches",
    "query.not_touches",
    "query.vector_dot",
    "query.vector_cosine",
    "query.vector_euclidean",
    "query.exists",
    "query.not_exists",
    "operator.new",
    "operator.inspect",
    "operator.set",
    "operator.is_method",
    "operator.is_operator",
    "operator.constants",
    "operator.extract_operators",
    "operator.parse",
    "operator.parse_operator",
    "operator.parse_operators",
    "operator.to_array",
    "operator.to_string",
    "operator.increment",
    "operator.decrement",
    "operator.multiply",
    "operator.divide",
    "operator.modulo",
    "operator.power",
    "operator.array_append",
    "operator.array_prepend",
    "operator.array_insert",
    "operator.array_remove",
    "operator.array_unique",
    "operator.array_intersect",
    "operator.array_diff",
    "operator.array_filter",
    "operator.string_concat",
    "operator.string_replace",
    "operator.toggle",
    "operator.date_add_days",
    "operator.date_sub_days",
    "operator.date_set_now",
];

const NULL: Json = Json::Null;

/// A query argument (`$query` tag, array or JSON string); a parse error is
/// the operation's error.
fn query(args: &Json, key: &str) -> Result<utopia_database::Result<Query>, Fault> {
    let item = args.get(key).cloned().unwrap_or(Json::Null);
    Ok(codec::queries(&Json::Array(vec![item]))?.map(|mut v| v.remove(0)))
}

/// An operator argument (`$operator` tag or array form).
fn operator(args: &Json, key: &str) -> Result<utopia_database::Result<Operator>, Fault> {
    match value(args, key)? {
        Value::Ext(Ext::Operator(o)) => Ok(Ok(*o)),
        Value::Array(a) => Ok(Operator::parse_operator(&a)),
        Value::Object(a) if a.is_empty() => Ok(Operator::parse_operator(&Array::new())),
        other => Err(Fault::new(format!("expected an operator, got {}", other.type_name()))),
    }
}

fn value(args: &Json, key: &str) -> Result<Value, Fault> {
    codec::decode(args.get(key).unwrap_or(&NULL))
}

/// An array argument (missing: empty), keys kept.
fn array(args: &Json, key: &str) -> Result<Array, Fault> {
    codec::decode_array(args.get(key).unwrap_or(&Json::Array(Vec::new())))
}

/// A list argument's values (PHP passes the array itself; these are lists).
fn list(args: &Json, key: &str) -> Result<Vec<Value>, Fault> {
    Ok(array(args, key)?.into_iter().map(|(_, v)| v).collect())
}

fn number(json: &Json) -> Result<Number, Fault> {
    match codec::decode(json)? {
        Value::Int(i) => Ok(Number::Int(i)),
        Value::Float(f) => Ok(Number::Float(f)),
        other => Err(Fault::new(format!("expected a number, got {}", other.type_name()))),
    }
}

fn opt_number(args: &Json, key: &str) -> Result<Option<Number>, Fault> {
    Args(args).opt(key).map(number).transpose()
}

fn queries(args: &Json, key: &str) -> Result<utopia_database::Result<Vec<Query>>, Fault> {
    codec::queries(args.get(key).unwrap_or(&NULL))
}

fn strings(args: &Json, key: &str) -> Result<Vec<Vec<u8>>, Fault> {
    let items = codec::list(args.get(key).unwrap_or(&NULL))?;
    items
        .into_iter()
        .map(|j| crate::adapter::bytes(j).ok_or_else(|| Fault::new(format!("`{key}` must hold strings"))))
        .collect()
}

fn q(query: Query) -> Outcome {
    Outcome::Ok(codec::encode(&Value::from(query)))
}

fn o(operator: Operator) -> Outcome {
    Outcome::Ok(codec::encode(&Value::from(operator)))
}

fn object(fields: Vec<(&str, Json)>) -> Json {
    Json::Object(fields.into_iter().map(|(k, v)| (k.to_owned(), v)).collect::<Map<_, _>>())
}

fn names<'a>(methods: impl IntoIterator<Item = &'a str>) -> Json {
    Json::Array(methods.into_iter().map(|m| Json::String(m.to_owned())).collect())
}

/// Runs `f` on a decoded argument, reporting the library's error as PHP does.
macro_rules! lib {
    ($e:expr) => {
        match $e {
            Ok(v) => v,
            Err(e) => return Ok(codec::error(&e)),
        }
    };
}

pub async fn call(op: &str, args: &Json, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        // Query: construction, accessors and setters.
        "query.new" => q(Query::from_array(Method::from_name(a.str("method")?), attr(&a)?, array(args, "values")?)),
        "query.inspect" => {
            let x = lib!(query(args, "query")?);
            Outcome::Ok(object(vec![
                ("method", json!(x.method().as_str())),
                ("attribute", json!(x.attribute())),
                ("values", codec::encode_array(&x.values_array())),
                ("value", x.value().map_or(Json::Null, codec::encode)),
                ("valueOr", x.value().map_or(json!("default"), codec::encode)),
                ("isNested", json!(x.is_nested())),
                ("isSpatialQuery", json!(x.is_spatial_query())),
                ("onArray", json!(x.on_array())),
                ("attributeType", json!(x.attribute_type())),
                ("isSpatialAttribute", json!(x.is_spatial_attribute())),
                ("isObjectAttribute", json!(x.is_object_attribute())),
            ]))
        }
        "query.set" => {
            let mut x = lib!(query(args, "query")?);
            if args.get("method").is_some() {
                x.set_method(Method::from_name(a.str("method")?));
            }
            if args.get("attribute").is_some() {
                x.set_attribute(a.str("attribute")?);
            }
            if args.get("values").is_some() {
                x.set_values_array(array(args, "values")?);
            }
            if args.get("value").is_some() {
                x.set_value(value(args, "value")?);
            }
            q(x)
        }
        "query.attribute_type" => {
            let mut x = lib!(query(args, "query")?);
            x.set_attribute_type(a.str("type")?);
            x.set_on_array(a.opt_bool("onArray")?.unwrap_or(false));
            Outcome::Ok(object(vec![
                ("attributeType", json!(x.attribute_type())),
                ("isSpatialAttribute", json!(x.is_spatial_attribute())),
                ("isObjectAttribute", json!(x.is_object_attribute())),
                ("onArray", json!(x.on_array())),
            ]))
        }
        "query.is_method" => Outcome::ok(Query::is_method(a.str("value")?)),
        "query.constants" => Outcome::Ok(object(vec![
            ("types", names(query::TYPES.iter().map(Method::as_str))),
            ("vectorTypes", names(query::VECTOR_TYPES.iter().map(Method::as_str))),
            ("defaultAlias", json!(query::DEFAULT_ALIAS)),
        ])),

        // Query: parsing and serialization.
        "query.parse" => q(lib!(Query::parse(a.bytes("query")?))),
        "query.parse_query" => q(lib!(Query::parse_query(&array(args, "query")?))),
        "query.parse_queries" => {
            let parsed = lib!(Query::parse_queries(&strings(args, "queries")?));
            Outcome::Ok(Json::Array(parsed.into_iter().map(|x| codec::encode(&Value::from(x))).collect()))
        }
        "query.to_array" => Outcome::Ok(codec::encode_array(&lib!(lib!(query(args, "query")?).to_array()))),
        "query.to_string" => Outcome::ok(lib!(lib!(query(args, "query")?).to_string())),
        "query.shape" => Outcome::ok(lib!(query(args, "query")?).shape()),
        "query.fingerprint" => Outcome::ok(lib!(Query::fingerprint(&list(args, "queries")?))),

        // Query: grouping.
        "query.get_by_type" => {
            let types: Vec<Method> = codec::list(args.get("types").unwrap_or(&NULL))?
                .into_iter()
                .map(method_of)
                .collect::<Result<_, _>>()?;
            encode_queries(Query::get_by_type(&lib!(queries(args, "queries")?), &types))
        }
        "query.get_cursor_queries" => encode_queries(Query::get_cursor_queries(&lib!(queries(args, "queries")?))),
        "query.group_by_type" => {
            // PHP skips values that are not queries.
            let all: Vec<Query> = list(args, "queries")?
                .into_iter()
                .filter_map(|v| match v {
                    Value::Ext(Ext::Query(q)) => Some(*q),
                    _ => None,
                })
                .collect();
            let g = Query::group_by_type(&all);
            let opt = |v: &Option<Value>| v.as_ref().map_or(Json::Null, codec::encode);
            let list = |qs: Vec<Query>| Json::Array(qs.into_iter().map(|x| codec::encode(&Value::from(x))).collect());
            Outcome::Ok(object(vec![
                ("filters", list(g.filters)),
                ("selections", list(g.selections)),
                ("limit", opt(&g.limit)),
                ("offset", opt(&g.offset)),
                ("orderAttributes", json!(g.order_attributes)),
                ("orderTypes", json!(g.order_types)),
                ("cursor", opt(&g.cursor)),
                ("cursorDirection", json!(g.cursor_direction)),
            ]))
        }

        // Query: static constructors.
        "query.equal" => q(Query::equal(a.str("attribute")?, list(args, "values")?)),
        "query.not_equal" => q(Query::not_equal(a.str("attribute")?, value(args, "value")?)),
        "query.less_than" => q(Query::less_than(a.str("attribute")?, value(args, "value")?)),
        "query.less_than_equal" => q(Query::less_than_equal(a.str("attribute")?, value(args, "value")?)),
        "query.greater_than" => q(Query::greater_than(a.str("attribute")?, value(args, "value")?)),
        "query.greater_than_equal" => q(Query::greater_than_equal(a.str("attribute")?, value(args, "value")?)),
        "query.contains" => q(Query::contains(a.str("attribute")?, list(args, "values")?)),
        "query.contains_any" => q(Query::contains_any(a.str("attribute")?, list(args, "values")?)),
        "query.not_contains" => q(Query::not_contains(a.str("attribute")?, list(args, "values")?)),
        "query.contains_all" => q(Query::contains_all(a.str("attribute")?, list(args, "values")?)),
        "query.between" => q(Query::between(a.str("attribute")?, value(args, "start")?, value(args, "end")?)),
        "query.not_between" => q(Query::not_between(a.str("attribute")?, value(args, "start")?, value(args, "end")?)),
        "query.search" => q(Query::search(a.str("attribute")?, a.str("value")?)),
        "query.not_search" => q(Query::not_search(a.str("attribute")?, a.str("value")?)),
        "query.starts_with" => q(Query::starts_with(a.str("attribute")?, a.str("value")?)),
        "query.not_starts_with" => q(Query::not_starts_with(a.str("attribute")?, a.str("value")?)),
        "query.ends_with" => q(Query::ends_with(a.str("attribute")?, a.str("value")?)),
        "query.not_ends_with" => q(Query::not_ends_with(a.str("attribute")?, a.str("value")?)),
        "query.regex" => q(Query::regex(a.str("attribute")?, a.str("pattern")?)),
        "query.select" => q(Query::select(list(args, "attributes")?)),
        "query.order_desc" => q(Query::order_desc(a.opt_str("attribute")?.unwrap_or(""))),
        "query.order_asc" => q(Query::order_asc(a.opt_str("attribute")?.unwrap_or(""))),
        "query.order_random" => q(Query::order_random()),
        "query.limit" => q(Query::limit(a.i64("value")?)),
        "query.offset" => q(Query::offset(a.i64("value")?)),
        "query.cursor_after" => q(Query::cursor_after(codec::document(args.get("document").unwrap_or(&json!([])))?)),
        "query.cursor_before" => q(Query::cursor_before(codec::document(args.get("document").unwrap_or(&json!([])))?)),
        "query.is_null" => q(Query::is_null(a.str("attribute")?)),
        "query.is_not_null" => q(Query::is_not_null(a.str("attribute")?)),
        "query.created_before" => q(Query::created_before(a.str("value")?)),
        "query.created_after" => q(Query::created_after(a.str("value")?)),
        "query.updated_before" => q(Query::updated_before(a.str("value")?)),
        "query.updated_after" => q(Query::updated_after(a.str("value")?)),
        "query.created_between" => q(Query::created_between(a.str("start")?, a.str("end")?)),
        "query.updated_between" => q(Query::updated_between(a.str("start")?, a.str("end")?)),
        "query.or" => q(Query::or(lib!(queries(args, "queries")?))),
        "query.and" => q(Query::and(lib!(queries(args, "queries")?))),
        "query.elem_match" => q(Query::elem_match(a.str("attribute")?, lib!(queries(args, "queries")?))),
        "query.distance_equal"
        | "query.distance_not_equal"
        | "query.distance_greater_than"
        | "query.distance_less_than" => {
            let ctor = match op {
                "query.distance_equal" => Query::distance_equal::<Value>,
                "query.distance_not_equal" => Query::distance_not_equal::<Value>,
                "query.distance_greater_than" => Query::distance_greater_than::<Value>,
                _ => Query::distance_less_than::<Value>,
            };
            let distance = number(a.value("distance")?)?;
            q(ctor(a.str("attribute")?, list(args, "values")?, distance, a.opt_bool("meters")?.unwrap_or(false)))
        }
        "query.intersects" => q(Query::intersects(a.str("attribute")?, list(args, "values")?)),
        "query.not_intersects" => q(Query::not_intersects(a.str("attribute")?, list(args, "values")?)),
        "query.crosses" => q(Query::crosses(a.str("attribute")?, list(args, "values")?)),
        "query.not_crosses" => q(Query::not_crosses(a.str("attribute")?, list(args, "values")?)),
        "query.overlaps" => q(Query::overlaps(a.str("attribute")?, list(args, "values")?)),
        "query.not_overlaps" => q(Query::not_overlaps(a.str("attribute")?, list(args, "values")?)),
        "query.touches" => q(Query::touches(a.str("attribute")?, list(args, "values")?)),
        "query.not_touches" => q(Query::not_touches(a.str("attribute")?, list(args, "values")?)),
        "query.vector_dot" => q(Query::vector_dot(a.str("attribute")?, list(args, "vector")?)),
        "query.vector_cosine" => q(Query::vector_cosine(a.str("attribute")?, list(args, "vector")?)),
        "query.vector_euclidean" => q(Query::vector_euclidean(a.str("attribute")?, list(args, "vector")?)),
        "query.exists" => q(Query::exists(list(args, "attributes")?)),
        "query.not_exists" => q(Query::not_exists(value(args, "attribute")?)),

        // Operator: construction, accessors and setters.
        "operator.new" => {
            o(Operator::from_array(OperatorMethod::from_name(a.str("method")?), attr(&a)?, array(args, "values")?))
        }
        "operator.inspect" => {
            let x = lib!(operator(args, "operator")?);
            Outcome::Ok(object(vec![
                ("method", json!(x.method().as_str())),
                ("attribute", json!(x.attribute())),
                ("values", codec::encode_array(&x.values_array())),
                ("value", x.value().map_or(Json::Null, codec::encode)),
                ("valueOr", x.value().map_or(json!("default"), codec::encode)),
                ("isNumeric", json!(x.is_numeric_operation())),
                ("isArray", json!(x.is_array_operation())),
                ("isString", json!(x.is_string_operation())),
                ("isBoolean", json!(x.is_boolean_operation())),
                ("isDate", json!(x.is_date_operation())),
            ]))
        }
        "operator.set" => {
            let mut x = lib!(operator(args, "operator")?);
            if args.get("method").is_some() {
                x.set_method(OperatorMethod::from_name(a.str("method")?));
            }
            if args.get("attribute").is_some() {
                x.set_attribute(a.str("attribute")?);
            }
            if args.get("values").is_some() {
                x.set_values_array(array(args, "values")?);
            }
            if args.get("value").is_some() {
                x.set_value(value(args, "value")?);
            }
            o(x)
        }
        "operator.is_method" => Outcome::ok(Operator::is_method(a.str("value")?)),
        "operator.is_operator" => Outcome::ok(Operator::is_operator(&value(args, "value")?)),
        "operator.constants" => Outcome::Ok(object(vec![
            ("types", names(utopia_database::operator::TYPES.iter().map(OperatorMethod::as_str))),
            ("maxArrayOperatorSize", json!(utopia_database::operator::MAX_ARRAY_OPERATOR_SIZE)),
            ("arrayFilterConditions", names(utopia_database::operator::ARRAY_FILTER_CONDITIONS)),
        ])),
        "operator.extract_operators" => {
            let extracted = Operator::extract_operators(array(args, "data")?);
            let operators: Array = extracted.operators.into_iter().map(|(k, op)| (k, Value::from(op))).collect();
            Outcome::Ok(object(vec![
                ("operators", codec::encode_array(&operators)),
                ("updates", codec::encode_array(&extracted.updates)),
            ]))
        }

        // Operator: parsing and serialization.
        "operator.parse" => o(lib!(Operator::parse(a.bytes("operator")?))),
        "operator.parse_operator" => o(lib!(Operator::parse_operator(&array(args, "operator")?))),
        "operator.parse_operators" => {
            let parsed = lib!(Operator::parse_operators(&strings(args, "operators")?));
            Outcome::Ok(Json::Array(parsed.into_iter().map(|x| codec::encode(&Value::from(x))).collect()))
        }
        "operator.to_array" => Outcome::Ok(codec::encode_array(&lib!(operator(args, "operator")?).to_array())),
        "operator.to_string" => Outcome::ok(lib!(lib!(operator(args, "operator")?).to_string())),

        // Operator: static constructors.
        "operator.increment" => {
            o(Operator::increment(opt_number(args, "value")?.unwrap_or(Number::Int(1)), opt_number(args, "max")?))
        }
        "operator.decrement" => {
            o(Operator::decrement(opt_number(args, "value")?.unwrap_or(Number::Int(1)), opt_number(args, "min")?))
        }
        "operator.multiply" => o(Operator::multiply(number(a.value("factor")?)?, opt_number(args, "max")?)),
        "operator.divide" => o(lib!(Operator::divide(number(a.value("divisor")?)?, opt_number(args, "min")?))),
        "operator.modulo" => o(lib!(Operator::modulo(number(a.value("divisor")?)?))),
        "operator.power" => o(Operator::power(number(a.value("exponent")?)?, opt_number(args, "max")?)),
        "operator.array_append" => o(Operator::array_append(list(args, "values")?)),
        "operator.array_prepend" => o(Operator::array_prepend(list(args, "values")?)),
        "operator.array_insert" => o(Operator::array_insert(a.i64("index")?, value(args, "value")?)),
        "operator.array_remove" => o(Operator::array_remove(value(args, "value")?)),
        "operator.array_unique" => o(Operator::array_unique()),
        "operator.array_intersect" => o(Operator::array_intersect(list(args, "values")?)),
        "operator.array_diff" => o(Operator::array_diff(list(args, "values")?)),
        "operator.array_filter" => o(Operator::array_filter(a.str("condition")?, value(args, "value")?)),
        "operator.string_concat" => o(Operator::string_concat(value(args, "value")?)),
        "operator.string_replace" => o(Operator::string_replace(a.str("search")?, a.str("replace")?)),
        "operator.toggle" => o(Operator::toggle()),
        "operator.date_add_days" => o(Operator::date_add_days(a.i64("days")?)),
        "operator.date_sub_days" => o(Operator::date_sub_days(a.i64("days")?)),
        "operator.date_set_now" => o(Operator::date_set_now()),

        _ => return Err(Fault::new(format!("database: unknown operation `{op}`"))),
    })
}

fn attr(a: &Args<'_>) -> Result<String, Fault> {
    Ok(a.opt_str("attribute")?.unwrap_or("").to_owned())
}

fn method_of(json: &Json) -> Result<Method, Fault> {
    json.as_str().map(Method::from_name).ok_or_else(|| Fault::new("`types` must hold strings"))
}

fn encode_queries(queries: Vec<Query>) -> Outcome {
    Outcome::Ok(Json::Array(queries.into_iter().map(|x| codec::encode(&Value::from(x))).collect()))
}
