//! `isMethod()`, `parse()`, `parseQuery()`, `parseQueries()` and the joins they build.
//!
//! The JSON is decoded by `php-std` (as `utopia_query::Query::parse()` decodes it) into PHP
//! arrays; validation and construction follow `Query.php` line by line, every `static::` call
//! going to the called class's override when it has one.

use ext_php_rs::ffi::{HashTable, zval};
use ext_php_rs::types::{ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use php_std::ArrayKey;
use php_std::json::{self, Flags};
use utopia_query::Method;

use super::zend::{self, Dispatch};
use super::{concat, fail, get, invalid, new_static, overridden, query_object};
use crate::classes::engine::{self, has_exception};

unsafe extern "C" {
    fn zend_hash_str_update(
        ht: *mut HashTable,
        key: *const std::ffi::c_char,
        len: usize,
        value: *mut zval,
    ) -> *mut zval;
    fn zend_hash_index_update(ht: *mut HashTable, h: i64, value: *mut zval) -> *mut zval;
}

/// A decoded JSON value as PHP holds it (`json_decode($json, true)`).
fn to_zval(value: php_std::Value) -> Zval {
    let mut zval = Zval::new();
    match value {
        php_std::Value::Null => zval.set_null(),
        php_std::Value::Bool(b) => zval.set_bool(b),
        php_std::Value::Int(i) => zval.set_long(i),
        php_std::Value::Float(f) => zval.set_double(f),
        php_std::Value::Str(s) => return engine::string(s.as_bytes()),
        php_std::Value::Array(array) | php_std::Value::Object(array) => {
            let mut table = ZendHashTable::with_capacity(u32::try_from(array.len()).unwrap_or(u32::MAX));
            if array.is_list() {
                for value in array {
                    let _ = table.push(to_zval(value.1));
                }
            } else {
                for (key, value) in array {
                    let mut value = to_zval(value);
                    // SAFETY: a live table the value moves into (the table owns it after).
                    unsafe {
                        let ht = std::ptr::from_mut(&mut *table);
                        match key {
                            ArrayKey::Int(i) => zend_hash_index_update(ht, i, std::ptr::from_mut(&mut value).cast()),
                            ArrayKey::Str(s) => {
                                let s = s.as_bytes();
                                zend_hash_str_update(
                                    ht,
                                    s.as_ptr().cast(),
                                    s.len(),
                                    std::ptr::from_mut(&mut value).cast(),
                                )
                            }
                        };
                    }
                    std::mem::forget(value);
                }
            }
            zval.set_hashtable(table);
        }
        php_std::Value::Ext(never) => match never {},
    }
    zval
}

/// `static::isMethod($method)` for the called class `scope`.
fn is_method_in(scope: &ClassEntry, method: &Zval) -> Option<bool> {
    match overridden(scope, Dispatch::IsMethod) {
        Some(f) => zend::call(f, None, scope, &mut [method.shallow_clone()]).map(|v| engine::truthy(&v)),
        None => Some(method.zend_str().and_then(|s| s.as_str().ok()).and_then(Method::try_from_value).is_some()),
    }
}

fn boolean(b: bool) -> Zval {
    let mut zval = Zval::new();
    zval.set_bool(b);
    zval
}

/// `static::parse($query, $allowRaw)` (`count` arguments) for the called class `scope`.
pub fn parse_in(scope: &ClassEntry, query: &Zval, allow_raw: bool, count: usize) -> Option<Zval> {
    let query = query.dereference();
    if let Some(f) = overridden(scope, Dispatch::Parse) {
        let mut args = [query.shallow_clone(), boolean(allow_raw)];
        return zend::call(f, None, scope, &mut args[..count]);
    }
    match query.zend_str() {
        Some(json) => parse_native(scope, json.as_bytes(), allow_raw),
        // Not a string: the native method itself, for PHP's coercion or `TypeError`.
        None => {
            let f = zend::function(scope, "parse");
            let mut args = [query.shallow_clone(), boolean(allow_raw)];
            zend::call(f, None, scope, &mut args[..count])
        }
    }
}

/// `static::parseQuery($query, $allowRaw)` for the called class `scope`.
pub fn parse_query_in(scope: &ClassEntry, query: &Zval, allow_raw: bool) -> Option<Zval> {
    let query = query.dereference();
    if let Some(f) = overridden(scope, Dispatch::ParseQuery) {
        return zend::call(f, None, scope, &mut [query.shallow_clone(), boolean(allow_raw)]);
    }
    if query.is_array() {
        return parse_query_native(scope, query, allow_raw);
    }
    let f = zend::function(scope, "parsequery");
    zend::call(f, None, scope, &mut [query.shallow_clone(), boolean(allow_raw)])
}

/// `parse()`'s body.
fn parse_native(scope: &ClassEntry, json: &[u8], allow_raw: bool) -> Option<Zval> {
    let decoded = match json::decode(json, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR) {
        Ok(decoded) => decoded,
        Err(error) => {
            fail(concat(&[b"Invalid query: ", error.message().as_bytes()]));
            return None;
        }
    };
    let query = to_zval(decoded);
    if !query.is_array() {
        fail(format!("Invalid query. Must be an array, got {}", zend::gettype(&query)));
        return None;
    }
    parse_query_in(scope, &query, allow_raw)
}

/// `$query[$key] ?? $default`.
fn key_or(query: &Zval, key: &str, default: impl FnOnce() -> Zval) -> Zval {
    query
        .array()
        .and_then(|a| a.get(key))
        .map(Zval::dereference)
        .filter(|v| !v.is_null())
        .map_or_else(default, Zval::shallow_clone)
}

/// `parseQuery()`'s body.
fn parse_query_native(scope: &ClassEntry, query: &Zval, allow_raw: bool) -> Option<Zval> {
    let method = key_or(query, "method", || engine::string(b""));
    let attribute = key_or(query, "attribute", || engine::string(b""));
    let values = key_or(query, "values", engine::empty_array);
    let mut alias = key_or(query, "alias", || engine::string(b""));

    let Some(name) = method.zend_str() else {
        fail(format!("Invalid query method. Must be a string, got {}", zend::gettype(&method)));
        return None;
    };
    if !is_method_in(scope, &method)? {
        fail(concat(&[b"Invalid query method: ", name.as_bytes()]));
        return None;
    }
    if !attribute.is_string() {
        fail(format!("Invalid query attribute. Must be a string, got {}", zend::gettype(&attribute)));
        return None;
    }
    if !values.is_array() {
        fail(format!("Invalid query values. Must be an array, got {}", zend::gettype(&values)));
        return None;
    }
    if !alias.is_string() {
        fail(format!("Invalid query alias. Must be a string, got {}", zend::gettype(&alias)));
        return None;
    }
    // `Method::from($method)`: a `ValueError` when an `isMethod()` override let it through.
    let method = match name.as_str().ok().and_then(Method::try_from_value) {
        Some(method) => method,
        None => {
            zend::method_from(name)?;
            return None;
        }
    };
    if method == Method::Raw && !allow_raw {
        invalid("Raw queries cannot be parsed from untrusted input; construct via Query::raw() in code");
        return None;
    }
    let empty = |v: &Zval| v.array().is_some_and(|a| a.is_empty());
    if method == Method::Distinct && (!attribute.zend_str().is_some_and(|a| a.as_bytes().is_empty()) || !empty(&values))
    {
        fail(
            "Invalid distinct query: distinct takes no attribute or values, it deduplicates the attributes a select names",
        );
        return None;
    }
    if method.is_join() {
        return parse_join(scope, method, attribute, &values, alias, allow_raw);
    }
    if method.is_aggregate() {
        let legacy = values.array().and_then(|a| a.get_index(0)).map(Zval::dereference).filter(|v| !v.is_null());
        if alias.zend_str().is_some_and(|a| a.as_bytes().is_empty())
            && let Some(legacy) = legacy.filter(|v| v.is_string())
        {
            alias = legacy.shallow_clone();
        }
        return new_static(scope, method, attribute, engine::empty_array(), alias, 4);
    }
    let values = if method.is_nested() {
        let (parsed, done) = engine::map_values(&values, |value| {
            let value = value.dereference();
            if value.is_string() {
                return parse_in(scope, value, allow_raw, 2).map(Some);
            }
            if !value.is_array() {
                fail(format!("Invalid nested query. Must be an array or string, got {}", zend::gettype(value)));
                return None;
            }
            parse_query_in(scope, value, allow_raw).map(Some)
        });
        if !done || has_exception() {
            return None;
        }
        parsed
    } else {
        values
    };
    new_static(scope, method, attribute, values, alias, 4)
}

/// `parseJoin()`: a join, in the 0.6 layouts as well.
fn parse_join(
    scope: &ClassEntry,
    method: Method,
    collection: Zval,
    values: &Zval,
    alias: Zval,
    allow_raw: bool,
) -> Option<Zval> {
    let mut on = Vec::new();
    let mut names: Vec<Zval> = Vec::new();
    if let Some(values) = values.array() {
        for value in engine::values(values) {
            let value = value.dereference();
            if value.is_array() {
                on.push(parse_query_in(scope, value, allow_raw)?);
                continue;
            }
            if !value.is_string() {
                fail(format!("Invalid join value. Must be a query or a string, got {}", zend::gettype(value)));
                return None;
            }
            names.push(value.shallow_clone());
        }
    }
    let column = on.is_empty() && names.len() >= 3;
    if column {
        on.push(on_in(scope, &names[0], &names[2], Some(&names[1]))?);
    }
    let legacy =
        if column { names.get(3) } else { names.first() }.map_or_else(|| engine::string(b""), Zval::shallow_clone);
    if names.len() > if column { 4 } else { 1 } {
        let collection = collection.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
        fail(concat(&[b"Invalid join values for ", method.as_str().as_bytes(), b" ", &collection]));
        return None;
    }
    let alias = if alias.zend_str().is_some_and(|a| !a.as_bytes().is_empty()) { alias } else { legacy };
    create_join(scope, method, collection, alias, &zend::list(on))
}

/// `static::on($left, $right, $operator)` (`$operator` omitted: its default).
pub fn on_in(scope: &ClassEntry, left: &Zval, right: &Zval, operator: Option<&Zval>) -> Option<Zval> {
    if let Some(f) = overridden(scope, Dispatch::On) {
        let mut args = vec![left.shallow_clone(), right.shallow_clone()];
        args.extend(operator.map(Zval::shallow_clone));
        return zend::call(f, None, scope, &mut args);
    }
    let operator = operator.map_or_else(|| engine::string(b"="), Zval::shallow_clone);
    let values = zend::list(vec![left.shallow_clone(), operator, right.shallow_clone()]);
    new_static(scope, Method::On, engine::string(b""), values, engine::string(b""), 3)
}

/// `createJoin()`: a join of `collection` under `alias` on the conditions `on`.
pub fn create_join(scope: &ClassEntry, method: Method, collection: Zval, alias: Zval, on: &Zval) -> Option<Zval> {
    let bytes = |v: &Zval| v.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
    let alias = if bytes(&alias).is_empty() { collection.shallow_clone() } else { alias };
    if bytes(&alias).is_empty() {
        invalid(concat(&[b"Join alias is required: ", method.as_str().as_bytes(), b" ", &bytes(&collection)]));
        return None;
    }
    let unconditioned = matches!(method, Method::CrossJoin | Method::NaturalJoin);
    let empty = on.array().is_none_or(|a| a.is_empty());
    if unconditioned && !empty {
        invalid(concat(&[b"Join ON conditions are not allowed in ", method.as_str().as_bytes()]));
        return None;
    }
    if !unconditioned && empty {
        invalid("Join ON requires at least one condition");
        return None;
    }
    let mut conditions = Vec::new();
    if let Some(on) = on.array() {
        for condition in engine::values(on) {
            let condition = condition.dereference();
            let Some(object) = query_object(condition) else {
                invalid("Join ON conditions must be Query objects");
                return None;
            };
            assert_join_condition(object)?;
            conditions.push(condition.shallow_clone());
        }
    }
    new_static(scope, method, collection, zend::list(conditions), alias, 4)
}

/// `assertJoinCondition()`.
fn assert_join_condition(condition: &mut ZendObject) -> Option<()> {
    let (_, method) = super::method(condition)?;
    if !method.is_join_condition() {
        invalid(concat(&[b"Unsupported join ON condition: ", method.as_str().as_bytes()]));
        return None;
    }
    if method != Method::And && method != Method::Or {
        return Some(());
    }
    let values = get(condition, &super::VALUES)?;
    if let Some(values) = values.array() {
        for child in engine::values(values) {
            let Some(child) = query_object(child) else {
                invalid("Join ON conditions must be Query objects");
                return None;
            };
            assert_join_condition(child)?;
        }
    }
    Some(())
}

// Handlers -------------------------------------------------------------------------------------

pub extern "C" fn is_method(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let Some(value) = args.get(0) else { return };
    let found = value.zend_str().and_then(|s| s.as_str().ok()).and_then(Method::try_from_value).is_some();
    retval.set_bool(found);
}

pub extern "C" fn parse(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let Some(json) = args.get(0).and_then(|v| v.zend_str()) else { return };
    if let Some(query) = parse_native(scope, json.as_bytes(), args.bool_or(1, false)) {
        *retval = query;
    }
}

pub extern "C" fn parse_query(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let Some(query) = args.get(0) else { return };
    if let Some(query) = parse_query_native(scope, query.dereference(), args.bool_or(1, false)) {
        *retval = query;
    }
}

pub extern "C" fn parse_queries(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let allow_raw = args.bool_or(1, false);
    let Some(queries) = args.get(0).and_then(|v| v.array()) else { return };
    let mut parsed = Vec::with_capacity(queries.len());
    for query in engine::values(queries) {
        let Some(query) = parse_in(scope, query, allow_raw, 2) else { return };
        parsed.push(query);
    }
    *retval = zend::list(parsed);
}
