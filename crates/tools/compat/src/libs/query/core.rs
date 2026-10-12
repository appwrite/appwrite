//! The core area of the query adapter: `Query`, `Method` and the other
//! enums, exceptions, `Statement`, `Condition`, hooks and identifier quoting.

use std::sync::Arc;

use php_std::ArrayKey;
use serde_json::Value as Json;
use utopia_query::builder::{Condition, Executor, JoinType, Statement};
use utopia_query::hook::{self, Hook, JoinCondition, Map, Placement, Tenant};
use utopia_query::{
    Array, CursorDirection, Error, Method, NullsPosition, OrderDirection, ParsedQuery, Query, QueryOrJson, Type, Value,
};

use super::engine::{Args, Callback, Failure, Object, Outcome, Ret, fault, foreign};
use crate::query_object_boilerplate;

/// `new Class(...)`.
pub(crate) fn construct(class: &str, a: &mut Args) -> Option<Outcome> {
    const CLASSES: &[&str] = &[
        "Query",
        "Exception",
        "Exception\\ValidationException",
        "Exception\\UnsupportedException",
        "Builder\\Statement",
        "Builder\\Condition",
        "Hook\\Filter\\Tenant",
        "Hook\\Attribute\\Map",
        "Hook\\Join\\Condition",
        "Tests\\Fixture\\QuotesIdentifiersHarness",
    ];
    if !CLASSES.contains(&class) {
        return None;
    }
    Some((|| -> Outcome {
        match class {
            "Query" => {
                let method = method_arg(a, 0, "method")?;
                Ok(Ret::obj(Query::new(
                    method,
                    a.str_or(1, "attribute", "")?,
                    a.array_or_empty(2, "values")?,
                    a.str_or(3, "alias", "")?,
                )))
            }
            "Exception" | "Exception\\ValidationException" | "Exception\\UnsupportedException" => {
                let message = a.str(0, "message")?;
                let code = match a.raw(1, "code") {
                    None => utopia_query::Code(0),
                    Some(Json::String(s)) => utopia_query::Code::from(s.as_str()),
                    Some(_) => utopia_query::Code(a.int(1, "code")?),
                };
                let previous = a.raw(2, "previous").and_then(foreign);
                let error = match (class, previous) {
                    ("Exception", Some(p)) => Error::with_previous(message, code, Arc::new(p)),
                    ("Exception", None) => Error::new(message, code),
                    ("Exception\\ValidationException", _) => Error::Validation(message),
                    _ => Error::Unsupported(message),
                };
                Ok(Ret::obj(super::engine::ErrorObject {
                    class: error.php_class().to_owned(),
                    message: error.to_string(),
                    code: code.0,
                    previous: error
                        .previous()
                        .and_then(|p| p.downcast_ref::<super::engine::Foreign>())
                        .map(|f| (f.class.clone(), f.message.clone(), f.code)),
                }))
            }
            "Builder\\Statement" => Ok(Ret::obj(Statement::with_parts(
                a.str(0, "query")?,
                list_values(&a.array(1, "bindings")?),
                a.bool_or(2, "readOnly", false)?,
                executor_arg(a, 3, "executor")?,
                a.opt_array(4, "namedBindings")?,
            ))),
            "Builder\\Condition" => {
                Ok(Ret::obj(Condition::new(a.str(0, "expression")?, list_values(&a.array_or_empty(1, "bindings")?))))
            }
            "Hook\\Filter\\Tenant" => {
                Ok(Ret::obj(Tenant::new(a.array(0, "tenantIds")?, a.str_or(1, "column", "tenant_id")?)?))
            }
            "Hook\\Attribute\\Map" => {
                let mut map = Vec::new();
                for (k, v) in a.array(0, "map")?.iter() {
                    let key = match k {
                        php_std::types::KeyRef::Int(i) => i.to_string(),
                        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    map.push((
                        key,
                        String::from_utf8_lossy(v.to_php_string().unwrap_or_default().as_bytes()).into_owned(),
                    ));
                }
                Ok(Ret::obj(MapObject(Map::new(map))))
            }
            "Hook\\Join\\Condition" => {
                let condition = a.object::<Condition>(0, "condition")?;
                let placement = match a.enum_case(1, "placement")? {
                    Some((_, case)) if case == "On" => Placement::On,
                    _ => Placement::Where,
                };
                Ok(Ret::obj(JoinCondition::new(condition, placement)))
            }
            "Tests\\Fixture\\QuotesIdentifiersHarness" => Ok(Ret::obj(QuotesHarness)),
            _ => Err(fault(format!("no constructor for {class}"))),
        }
    })())
}

fn list_values(array: &Array) -> Vec<Value> {
    array.values().cloned().collect()
}

fn method_arg(a: &Args, index: usize, name: &str) -> Result<Method, Failure> {
    match a.enum_case(index, name) {
        Ok(Some((class, case))) if class == "Method" => {
            Method::from_name(&case).ok_or_else(|| fault(format!("no Method::{case}")))
        }
        _ => Ok(Method::from_value(&a.str(index, name)?)?),
    }
}

/// An executor closure argument.
pub(crate) fn executor_arg(a: &Args, index: usize, name: &str) -> Result<Option<Executor>, Failure> {
    match a.raw(index, name) {
        None | Some(Json::Null) => Ok(None),
        Some(_) => Ok(Some(executor(a.closure(index, name)?))),
    }
}

/// A test closure as an executor.
pub(crate) fn executor(callback: Callback) -> Executor {
    Arc::new(move |statement: &Statement| {
        let ret = callback
            .invoke(vec![Ret::obj(statement.clone())])
            .map_err(|_| Error::new("executor callback failed", 0))?;
        callback.cx().decoded(&ret).value(0, "ret").map_err(|_| Error::new("executor returned no value", 0))
    })
}

/// A hook argument: a library hook or a test object implementing hook interfaces.
pub(crate) fn hook_arg(a: &Args, index: usize, name: &str) -> Result<Hook, Failure> {
    if let Some((k, interfaces)) = a.test_object(index, name)? {
        let cx = a.cx().clone();
        let mut hook = Hook::default();
        for interface in &interfaces {
            match interface.as_str() {
                "Hook\\Filter" => hook.filter = Some(Arc::new(TestFilter(Callback::method(&cx, k, "filter")))),
                "Hook\\Attribute" => {
                    hook.attribute = Some(Arc::new(TestAttribute(Callback::method(&cx, k, "resolve"))))
                }
                "Hook\\Join\\Filter" => {
                    hook.join_filter = Some(Arc::new(TestJoinFilter(Callback::method(&cx, k, "filterJoin"))))
                }
                _ => {}
            }
        }
        return Ok(hook);
    }
    a.with(index, name, |o| {
        if let Some(t) = o.as_any().downcast_ref::<Tenant>() {
            Ok(Hook::from(t.clone()))
        } else if let Some(m) = o.as_any().downcast_ref::<MapObject>() {
            Ok(Hook::from(m.0.clone()))
        } else {
            Err(fault(format!("{} is not a hook", o.class())))
        }
    })?
}

fn decode_err(f: Failure) -> Error {
    match f {
        Failure::Lib(e) => e,
        Failure::Engine(_, m) | Failure::Fault(m) => Error::new(m, 0),
    }
}

struct TestFilter(Callback);

impl hook::Filter for TestFilter {
    fn filter(&self, table: &str) -> utopia_query::Result<Condition> {
        let ret = self.0.invoke(vec![Ret::str(table)]).map_err(decode_err)?;
        self.0.cx().decoded(&ret).object::<Condition>(0, "ret").map_err(decode_err)
    }
}

struct TestAttribute(Callback);

impl hook::Attribute for TestAttribute {
    fn resolve(&self, attribute: &str) -> utopia_query::Result<String> {
        let ret = self.0.invoke(vec![Ret::str(attribute)]).map_err(decode_err)?;
        self.0.cx().decoded(&ret).str(0, "ret").map_err(decode_err)
    }
}

struct TestJoinFilter(Callback);

impl hook::JoinFilter for TestJoinFilter {
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        let ret = self
            .0
            .invoke(vec![Ret::str(table), Ret::Enum("Builder\\JoinType".into(), join_type.name())])
            .map_err(decode_err)?;
        if ret.is_null() {
            return Ok(None);
        }
        self.0.cx().decoded(&ret).object::<JoinCondition>(0, "ret").map(Some).map_err(decode_err)
    }
}

/// `Class::method(...)`.
pub(crate) fn call_static(class: &str, method: &str, a: &mut Args) -> Option<Outcome> {
    match class {
        "Query" => query_static(method, a),
        "Method" => match method {
            "cases" => {
                Some(Ok(Ret::List(Method::CASES.iter().map(|m| Ret::Enum("Method".into(), m.name())).collect())))
            }
            "from" => Some((|| Ok(Ret::Enum("Method".into(), Method::from_value(&a.str(0, "value")?)?.name())))()),
            "tryFrom" => Some((|| {
                Ok(match Method::try_from_value(&a.str(0, "value")?) {
                    Some(m) => Ret::Enum("Method".into(), m.name()),
                    None => Ret::null(),
                })
            })()),
            _ => None,
        },
        _ => None,
    }
}

fn queries_ret(queries: Vec<Query>) -> Ret {
    Ret::List(queries.into_iter().map(Ret::obj).collect())
}

fn query_static(method: &str, a: &mut Args) -> Option<Outcome> {
    let attr = |a: &Args| a.str(0, "attribute");
    Some((|| -> Outcome {
        Ok(Ret::obj(match method {
            "equal" => Query::equal(attr(a)?, a.array(1, "values")?),
            "notEqual" => Query::not_equal(attr(a)?, a.value(1, "value")?),
            "lessThan" => Query::less_than(attr(a)?, a.value(1, "value")?),
            "lessThanEqual" => Query::less_than_equal(attr(a)?, a.value(1, "value")?),
            "greaterThan" => Query::greater_than(attr(a)?, a.value(1, "value")?),
            "greaterThanEqual" => Query::greater_than_equal(attr(a)?, a.value(1, "value")?),
            "contains" => Query::contains(attr(a)?, a.array(1, "values")?),
            "containsString" => Query::contains_string(attr(a)?, a.array(1, "values")?),
            "containsAny" => Query::contains_any(attr(a)?, a.array(1, "values")?),
            "notContains" => Query::not_contains(attr(a)?, a.array(1, "values")?),
            "containsAll" => Query::contains_all(attr(a)?, a.array(1, "values")?),
            "between" => Query::between(attr(a)?, a.value(1, "start")?, a.value(2, "end")?),
            "notBetween" => Query::not_between(attr(a)?, a.value(1, "start")?, a.value(2, "end")?),
            "search" => Query::search(attr(a)?, a.value(1, "value")?),
            "notSearch" => Query::not_search(attr(a)?, a.value(1, "value")?),
            "select" => Query::select_values(a.array(0, "attributes")?),
            "orderDesc" => Query::order_desc(a.str_or(0, "attribute", "")?, nulls_arg(a, 1, "nulls")?),
            "orderAsc" => Query::order_asc(a.str_or(0, "attribute", "")?, nulls_arg(a, 1, "nulls")?),
            "orderRandom" => Query::order_random(),
            "limit" => Query::limit(a.int(0, "value")?),
            "offset" => Query::offset(a.int(0, "value")?),
            "cursorAfter" => Query::cursor_after(cursor_arg(a, "cursorAfter")?),
            "cursorBefore" => Query::cursor_before(cursor_arg(a, "cursorBefore")?),
            "isNull" => Query::is_null(attr(a)?),
            "isNotNull" => Query::is_not_null(attr(a)?),
            "startsWith" => Query::starts_with(attr(a)?, a.value(1, "value")?),
            "notStartsWith" => Query::not_starts_with(attr(a)?, a.value(1, "value")?),
            "endsWith" => Query::ends_with(attr(a)?, a.value(1, "value")?),
            "notEndsWith" => Query::not_ends_with(attr(a)?, a.value(1, "value")?),
            "createdBefore" => Query::created_before(a.value(0, "value")?),
            "createdAfter" => Query::created_after(a.value(0, "value")?),
            "updatedBefore" => Query::updated_before(a.value(0, "value")?),
            "updatedAfter" => Query::updated_after(a.value(0, "value")?),
            "createdBetween" => Query::created_between(a.value(0, "start")?, a.value(1, "end")?),
            "updatedBetween" => Query::updated_between(a.value(0, "start")?, a.value(1, "end")?),
            "or" => Query::logical(Method::Or, a.array(0, "queries")?),
            "and" => Query::logical(Method::And, a.array(0, "queries")?),
            "elemMatch" => Query::new(Method::ElemMatch, attr(a)?, a.array(1, "queries")?, ""),
            "having" => Query::logical(Method::Having, a.array(0, "queries")?),
            "union" => Query::logical(Method::Union, a.array(0, "queries")?),
            "unionAll" => Query::logical(Method::UnionAll, a.array(0, "queries")?),
            "distanceEqual" => Query::distance_equal(
                attr(a)?,
                a.array(1, "values")?,
                a.value(2, "distance")?,
                a.bool_or(3, "meters", false)?,
            ),
            "distanceNotEqual" => Query::distance_not_equal(
                attr(a)?,
                a.array(1, "values")?,
                a.value(2, "distance")?,
                a.bool_or(3, "meters", false)?,
            ),
            "distanceGreaterThan" => Query::distance_greater_than(
                attr(a)?,
                a.array(1, "values")?,
                a.value(2, "distance")?,
                a.bool_or(3, "meters", false)?,
            ),
            "distanceLessThan" => Query::distance_less_than(
                attr(a)?,
                a.array(1, "values")?,
                a.value(2, "distance")?,
                a.bool_or(3, "meters", false)?,
            ),
            "intersects" => Query::intersects(attr(a)?, a.array(1, "values")?),
            "notIntersects" => Query::not_intersects(attr(a)?, a.array(1, "values")?),
            "crosses" => Query::crosses(attr(a)?, a.array(1, "values")?),
            "notCrosses" => Query::not_crosses(attr(a)?, a.array(1, "values")?),
            "overlaps" => Query::overlaps(attr(a)?, a.array(1, "values")?),
            "notOverlaps" => Query::not_overlaps(attr(a)?, a.array(1, "values")?),
            "touches" => Query::touches(attr(a)?, a.array(1, "values")?),
            "notTouches" => Query::not_touches(attr(a)?, a.array(1, "values")?),
            "covers" => Query::covers(attr(a)?, a.array(1, "values")?),
            "notCovers" => Query::not_covers(attr(a)?, a.array(1, "values")?),
            "spatialEquals" => Query::spatial_equals(attr(a)?, a.array(1, "values")?),
            "notSpatialEquals" => Query::not_spatial_equals(attr(a)?, a.array(1, "values")?),
            "vectorDot" => Query::vector_dot(attr(a)?, a.array(1, "vector")?),
            "vectorCosine" => Query::vector_cosine(attr(a)?, a.array(1, "vector")?),
            "vectorEuclidean" => Query::vector_euclidean(attr(a)?, a.array(1, "vector")?),
            "regex" => Query::regex(attr(a)?, a.value(1, "pattern")?),
            "exists" => Query::exists(a.array(0, "attributes")?),
            "notExists" => Query::not_exists(a.value(0, "attribute")?),
            "count" => Query::count(a.str_or(0, "attribute", "*")?, a.str_or(1, "alias", "")?),
            "countDistinct" => Query::count_distinct(attr(a)?, a.str_or(1, "alias", "")?),
            "sum" => Query::sum(attr(a)?, a.str_or(1, "alias", "")?),
            "avg" => Query::avg(attr(a)?, a.str_or(1, "alias", "")?),
            "min" => Query::min(attr(a)?, a.str_or(1, "alias", "")?),
            "max" => Query::max(attr(a)?, a.str_or(1, "alias", "")?),
            "stddev" => Query::stddev(attr(a)?, a.str_or(1, "alias", "")?),
            "stddevPop" => Query::stddev_pop(attr(a)?, a.str_or(1, "alias", "")?),
            "stddevSamp" => Query::stddev_samp(attr(a)?, a.str_or(1, "alias", "")?),
            "variance" => Query::variance(attr(a)?, a.str_or(1, "alias", "")?),
            "varPop" => Query::var_pop(attr(a)?, a.str_or(1, "alias", "")?),
            "varSamp" => Query::var_samp(attr(a)?, a.str_or(1, "alias", "")?),
            "bitAnd" => Query::bit_and(attr(a)?, a.str_or(1, "alias", "")?),
            "bitOr" => Query::bit_or(attr(a)?, a.str_or(1, "alias", "")?),
            "bitXor" => Query::bit_xor(attr(a)?, a.str_or(1, "alias", "")?),
            "groupBy" => Query::new(Method::GroupBy, "", a.array(0, "attributes")?, ""),
            "groupByTimeBucket" => Query::group_by_time_bucket(attr(a)?, &a.str(1, "interval")?)?,
            "distinct" => Query::distinct(),
            "on" => Query::on(a.str(0, "left")?, a.str(1, "right")?, a.str_or(2, "operator", "=")?),
            "join" | "leftJoin" | "rightJoin" | "fullOuterJoin" => {
                let m = Method::from_value(method)?;
                Query::join_values(m, a.str(0, "collection")?, a.str(1, "alias")?, a.array(2, "on")?)?
            }
            "crossJoin" => Query::cross_join(a.str(0, "collection")?, a.str(1, "alias")?)?,
            "naturalJoin" => Query::natural_join(a.str(0, "collection")?, a.str(1, "alias")?)?,
            "jsonContains" => Query::json_contains(attr(a)?, a.value(1, "value")?),
            "jsonNotContains" => Query::json_not_contains(attr(a)?, a.value(1, "value")?),
            "jsonOverlaps" => Query::json_overlaps(attr(a)?, a.array(1, "values")?),
            "jsonPath" => Query::json_path(attr(a)?, a.str(1, "path")?, a.str(2, "operator")?, a.value(3, "value")?),
            "raw" => Query::raw(a.str(0, "sql")?, a.array_or_empty(1, "bindings")?),
            "parse" => Query::parse_bytes(&raw_bytes(a, 0, "query")?, a.bool_or(1, "allowRaw", false)?)?,
            "parseQuery" => Query::parse_query(&a.array(0, "query")?, a.bool_or(1, "allowRaw", false)?)?,
            _ => {
                return Ok(match method {
                    "isMethod" => Ret::bool(Query::is_method(&a.str(0, "value")?)),
                    "parseQueries" => {
                        let items = a.strings(0, "queries")?;
                        queries_ret(Query::parse_queries(&items, a.bool_or(1, "allowRaw", false)?)?)
                    }
                    "fingerprint" => {
                        let mut owned_queries = Vec::new();
                        let mut owned_json = Vec::new();
                        let items = a.items(0, "queries")?;
                        for item in &items {
                            match item {
                                Json::String(s) => owned_json.push(s.clone()),
                                _ => owned_queries.push(a.query_of(item)?),
                            }
                        }
                        let (mut qi, mut ji) = (0, 0);
                        let mut list = Vec::with_capacity(items.len());
                        for item in &items {
                            if item.is_string() {
                                list.push(QueryOrJson::Json(&owned_json[ji]));
                                ji += 1;
                            } else {
                                list.push(QueryOrJson::Query(&owned_queries[qi]));
                                qi += 1;
                            }
                        }
                        Ret::str(Query::fingerprint(&list)?)
                    }
                    "getByType" => {
                        let queries = a.queries(0, "queries")?;
                        let methods = methods_arg(a, 1, "types")?;
                        queries_ret(Query::by_type(&queries, &methods).into_iter().cloned().collect())
                    }
                    "getCursorQueries" => {
                        let queries = a.queries(0, "queries")?;
                        queries_ret(Query::cursor_queries(&queries).into_iter().cloned().collect())
                    }
                    "groupByType" => {
                        let mut queries = Vec::new();
                        for item in a.items(0, "queries")? {
                            if let Ok(q) = a.query_of(item) {
                                queries.push(q);
                            }
                        }
                        Ret::obj(Query::group_by_type(&queries))
                    }
                    "page" => {
                        let [limit, offset] = Query::page(a.int(0, "page")?, a.int_or(1, "perPage", 25)?)?;
                        Ret::List(vec![Ret::obj(limit), Ret::obj(offset)])
                    }
                    "merge" => queries_ret(Query::merge(&a.queries(0, "queriesA")?, &a.queries(1, "queriesB")?)),
                    "diff" => queries_ret(Query::diff(&a.queries(0, "queriesA")?, &a.queries(1, "queriesB")?)),
                    "validate" => {
                        let allowed = a.strings(1, "allowedAttributes")?;
                        Ret::strings(Query::validate(&a.queries(0, "queries")?, &allowed))
                    }
                    _ => return Err(fault(format!("no static method Query::{method}"))),
                });
            }
        }))
    })())
}

fn raw_bytes(a: &Args, index: usize, name: &str) -> Result<Vec<u8>, Failure> {
    let raw = a.raw(index, name).ok_or_else(|| fault(format!("missing ${name}")))?;
    crate::adapter::bytes(raw).ok_or_else(|| Failure::Engine("TypeError".into(), format!("${name} must be a string")))
}

fn methods_arg(a: &Args, index: usize, name: &str) -> Result<Vec<Method>, Failure> {
    let mut out = Vec::new();
    for item in a.items(index, name)? {
        let case = item.get("$enum").and_then(Json::as_str).ok_or_else(|| fault("expected a Method"))?;
        let name = case.rsplit("::").next().unwrap_or_default();
        out.push(Method::from_name(name).ok_or_else(|| fault(format!("no Method::{name}")))?);
    }
    Ok(out)
}

fn nulls_arg(a: &Args, index: usize, name: &str) -> Result<Option<NullsPosition>, Failure> {
    Ok(a.enum_case(index, name)?.and_then(|(_, case)| NullsPosition::from_name(&case)))
}

fn cursor_arg(a: &Args, method: &str) -> Result<Value, Failure> {
    let value = a.value(0, "value")?;
    match value {
        Value::Array(_) | Value::Object(_) | Value::Ext(_) => Ok(value),
        other => Err(Failure::Engine(
            "TypeError".into(),
            format!(
                "Utopia\\Query\\Query::{method}(): Argument #1 ($value) must be of type object|array, {} given, called in /usr/src/code/tests/compat/query/Replay.php on line 121",
                other.debug_type()
            ),
        )),
    }
}

/// `Class::CONSTANT`.
pub(crate) fn constant(class: &str, name: &str) -> Option<Ret> {
    match (class, name) {
        ("Query", "DEFAULT_ALIAS") => Some(Ret::str(utopia_query::DEFAULT_ALIAS)),
        ("Query", "GROUP_BY_TIME_BUCKET_INTERVALS") => Some(Ret::strings(utopia_query::GROUP_BY_TIME_BUCKET_INTERVALS)),
        _ => None,
    }
}

/// `Class::Case->method(...)`.
pub(crate) fn enum_call(class: &str, case: &str, method: &str, _a: &mut Args) -> Option<Outcome> {
    macro_rules! backed {
        ($t:ty) => {{
            let value = <$t>::from_name(case)?;
            match method {
                "->value" => return Some(Ok(Ret::str(value.as_str()))),
                _ => value,
            }
        }};
    }
    match class {
        "Method" => {
            let m = backed!(Method);
            Some(Ok(match method {
                "isFilter" => Ret::bool(m.is_filter()),
                "isSpatial" => Ret::bool(m.is_spatial()),
                "isVector" => Ret::bool(m.is_vector()),
                "isJson" => Ret::bool(m.is_json()),
                "isNested" => Ret::bool(m.is_nested()),
                "isAggregate" => Ret::bool(m.is_aggregate()),
                "isJoinCondition" => Ret::bool(m.is_join_condition()),
                "isJoin" => Ret::bool(m.is_join()),
                "sqlFunction" => Ret::opt_str(m.sql_function()),
                _ => return None,
            }))
        }
        "NullsPosition" => {
            backed!(NullsPosition);
            None
        }
        "OrderDirection" => {
            backed!(OrderDirection);
            None
        }
        "CursorDirection" => {
            backed!(CursorDirection);
            None
        }
        "Type" => {
            backed!(Type);
            None
        }
        "Builder\\JoinType" => {
            backed!(JoinType);
            None
        }
        _ => None,
    }
}

fn method_ret(m: Method) -> Ret {
    Ret::Enum("Method".into(), m.name())
}

impl Object for Query {
    fn class(&self) -> String {
        "Query".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("method", method_ret(self.method())),
            ("attribute", Ret::str(self.attribute())),
            ("values", Ret::Value(Value::Array(self.values().clone()))),
            ("alias", Ret::str(self.alias())),
            ("onArray", Ret::bool(self.on_array())),
            ("attributeType", Ret::str(self.attribute_type())),
        ]
    }
    fn get(&self, _property: &str) -> Option<Ret> {
        None
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            Ok(match method {
                "getMethod" => method_ret(self.method()),
                "getAttribute" => Ret::str(self.attribute()),
                "getValues" => Ret::Value(Value::Array(self.values().clone())),
                "getValue" => match self.value() {
                    Some(v) => Ret::Value(v.clone()),
                    None => Ret::Value(a.value(0, "default")?),
                },
                "getAlias" => Ret::str(self.alias()),
                "isNestedJoin" => Ret::bool(self.is_nested_join()),
                "getJoinOnQueries" => queries_ret(self.join_on_queries().into_iter().cloned().collect()),
                "setMethod" => {
                    let m = method_arg(a, 0, "method")?;
                    self.set_method(m);
                    Ret::This
                }
                "setAttribute" => {
                    self.set_attribute(a.str(0, "attribute")?);
                    Ret::This
                }
                "setValues" => {
                    self.set_values(a.array(0, "values")?);
                    Ret::This
                }
                "setValue" => {
                    self.set_value(a.value(0, "value")?);
                    Ret::This
                }
                "isSpatialQuery" => Ret::bool(self.is_spatial_query()),
                "isNested" => Ret::bool(self.is_nested()),
                "onArray" => Ret::bool(self.on_array()),
                "setOnArray" => {
                    self.set_on_array(a.bool(0, "bool")?);
                    Ret::null()
                }
                "setAttributeType" => {
                    self.set_attribute_type(a.str(0, "type")?);
                    Ret::null()
                }
                "getAttributeType" => Ret::str(self.attribute_type()),
                "shape" => Ret::str(self.shape()),
                "toArray" => Ret::Value(Value::Array(self.to_array())),
                "toString" => Ret::str(self.to_string()?),
                "compile" => {
                    let query = self.clone();
                    let id = a.object_id(0, "compiler")?;
                    return a.with_id(id, |o| super::builder::compile_with(o, &query))?;
                }
                _ => return Err(fault(format!("Query has no method {method}"))),
            })
        })())
    }
    query_object_boilerplate!();
}

impl Object for Statement {
    fn class(&self) -> String {
        "Builder\\Statement".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("query", Ret::str(&self.query)),
            ("bindings", Ret::values(&self.bindings)),
            ("readOnly", Ret::bool(self.read_only)),
            (
                "namedBindings",
                match &self.named_bindings {
                    Some(a) => Ret::Value(Value::Array(a.clone())),
                    None => Ret::null(),
                },
            ),
        ]
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            Ok(match method {
                "execute" => Ret::Value(self.execute()?),
                "withExecutor" => Ret::obj(self.with_executor(executor(a.closure(0, "executor")?))),
                _ => return Err(fault(format!("Statement has no method {method}"))),
            })
        })())
    }
    query_object_boilerplate!();
}

impl Object for Condition {
    fn class(&self) -> String {
        "Builder\\Condition".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("expression", Ret::str(&self.expression)), ("bindings", Ret::values(&self.bindings))]
    }
    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for JoinCondition {
    fn class(&self) -> String {
        "Hook\\Join\\Condition".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("condition", Ret::obj(self.condition.clone())),
            ("placement", Ret::Enum("Hook\\Join\\Placement".into(), self.placement.name())),
        ]
    }
    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for Tenant {
    fn class(&self) -> String {
        "Hook\\Filter\\Tenant".into()
    }
    fn interfaces(&self) -> &'static [&'static str] {
        &["Hook", "Hook\\Filter", "Hook\\Join\\Filter"]
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            Ok(match method {
                "filter" => Ret::obj(hook::Filter::filter(self, &a.str(0, "table")?)?),
                "filterJoin" => {
                    let join_type = match a.enum_case(1, "joinType")? {
                        Some((_, case)) => JoinType::from_name(&case).ok_or_else(|| fault("bad JoinType"))?,
                        None => return Err(fault("missing joinType")),
                    };
                    Ret::opt_obj(hook::JoinFilter::filter_join(self, &a.str(0, "table")?, join_type)?)
                }
                _ => return Err(fault(format!("Tenant has no method {method}"))),
            })
        })())
    }
    query_object_boilerplate!();
}

/// `Hook\Attribute\Map` (a readonly class: a value with no public properties).
#[derive(Clone)]
pub(crate) struct MapObject(pub(crate) Map);

impl Object for MapObject {
    fn class(&self) -> String {
        "Hook\\Attribute\\Map".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn interfaces(&self) -> &'static [&'static str] {
        &["Hook", "Hook\\Attribute"]
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            Ok(match method {
                "resolve" => Ret::str(hook::Attribute::resolve(&self.0, &a.str(0, "attribute")?)?),
                _ => return Err(fault(format!("Map has no method {method}"))),
            })
        })())
    }
    query_object_boilerplate!();
}

impl Object for ParsedQuery {
    fn class(&self) -> String {
        "Builder\\ParsedQuery".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        let queries = |list: &Vec<Query>| queries_ret(list.clone());
        let opt_int = |i: Option<i64>| i.map(Ret::int).unwrap_or_else(Ret::null);
        vec![
            ("filters", queries(&self.filters)),
            ("selections", queries(&self.selections)),
            ("aggregations", queries(&self.aggregations)),
            ("groupBy", Ret::strings(&self.group_by)),
            ("having", queries(&self.having)),
            ("distinct", Ret::bool(self.distinct)),
            ("joins", queries(&self.joins)),
            ("unions", queries(&self.unions)),
            ("limit", opt_int(self.limit)),
            ("offset", opt_int(self.offset)),
            ("cursor", self.cursor.clone().map(Ret::Value).unwrap_or_else(Ret::null)),
            (
                "cursorDirection",
                self.cursor_direction.map(|d| Ret::Enum("CursorDirection".into(), d.name())).unwrap_or_else(Ret::null),
            ),
            (
                "timeBuckets",
                Ret::List(
                    self.time_buckets
                        .iter()
                        .map(|b| {
                            Ret::Map(vec![
                                (ArrayKey::from("attribute"), Ret::str(&b.attribute)),
                                (ArrayKey::from("interval"), Ret::str(&b.interval)),
                            ])
                        })
                        .collect(),
                ),
            ),
        ]
    }
    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

/// The test fixture exposing `QuotesIdentifiers` (MySQL's backtick).
#[derive(Clone)]
struct QuotesHarness;

impl Object for QuotesHarness {
    fn class(&self) -> String {
        "Tests\\Fixture\\QuotesIdentifiersHarness".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            Ok(match method {
                "quote" => Ret::str(utopia_query::quote(&a.str(0, "identifier")?, '`')?),
                "quoteLiteral" => Ret::str(utopia_query::quote_literal(&a.str(0, "identifier")?, '`')?),
                _ => return Err(fault(format!("no method {method}"))),
            })
        })())
    }
    query_object_boilerplate!();
}
