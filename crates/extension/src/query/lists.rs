//! What works on lists of queries and on a query's tree: `shape()`, `fingerprint()`, `compile()`,
//! `getByType()`, `getCursorQueries()`, `groupByType()`, `page()`, `merge()`, `diff()`,
//! `validate()`, and the clone of a nested query.

use std::collections::HashMap;

use ext_php_rs::types::{ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use md5::{Digest, Md5};
use utopia_query::Method;

use super::zend::{self, Class, Dispatch};
use super::{
    ARRAY, ATTRIBUTE, Def, INT, METHOD, STATIC_PUBLIC, VALUES, fail, get, get_method_of, getter, invalid,
    is_nested_join_of, overridden, query_object, this, to_array_any,
};
use crate::classes::engine::{self, MAY_BE_STRING, has_exception};

/// Interns the method names this module calls (MINIT).
pub fn intern() {
    IS_NESTED.intern();
}

/// `clone $object`: its class's clone handler, with its `__clone()`; `None` with the exception
/// pending.
pub fn clone_object(object: &mut ZendObject) -> Option<Zval> {
    // SAFETY: a live object, its handlers and class.
    unsafe {
        let Some(clone) = (*object.handlers).clone_obj else {
            let class = object.get_class_name().unwrap_or_default();
            engine::error(&format!("Trying to clone an uncloneable object of class {class}"));
            return None;
        };
        let copy = clone(std::ptr::from_mut(object).cast());
        let mut zval = Zval::new();
        if let Some(copy) = copy.cast::<ZendObject>().as_mut() {
            zval.set_object(copy);
            copy.gc.refcount -= 1;
        }
        if has_exception() { None } else { Some(zval) }
    }
}

// shape() and fingerprint() ---------------------------------------------------------------------

/// Whether `node` contributes its children to its shape: one of `LOGICAL_TYPES`, or a nested join.
fn shapes_children(node: &mut ZendObject) -> Option<bool> {
    let (_, method) = super::method(node)?;
    if matches!(method, Method::And | Method::Or | Method::ElemMatch | Method::Relationship) {
        return Some(true);
    }
    is_nested_join_of(node)
}

/// The value of `$node->method`, and `$node->attribute`, for a shape.
fn head(node: &mut ZendObject) -> Option<Vec<u8>> {
    let case = get(node, &METHOD)?;
    let attribute = get(node, &ATTRIBUTE)?;
    let mut head = case.object().and_then(zend::case_value).map(|s| s.as_bytes().to_vec()).unwrap_or_default();
    head.push(b':');
    head.extend_from_slice(attribute.zend_str().map(|s| s.as_bytes()).unwrap_or_default());
    Some(head)
}

/// `shape()` of a query object, natively: the tree walked as `Query.php` walks it, preorder onto
/// a list, then shaped from the end so children come before their parents.
fn shape_of(root: &mut ZendObject) -> Option<Vec<u8>> {
    let mut nodes: Vec<Zval> = Vec::new();
    let mut root_zval = Zval::new();
    root_zval.set_object(root);
    let mut stack = vec![root_zval];
    while let Some(node) = stack.pop() {
        let object = query_object(&node)?;
        nodes.push(node.shallow_clone());
        if !shapes_children(object)? {
            continue;
        }
        let values = get(object, &VALUES)?;
        if let Some(values) = values.array() {
            for child in engine::values(values) {
                if query_object(child).is_some() {
                    stack.push(child.dereference().shallow_clone());
                }
            }
        }
    }
    let mut shapes: HashMap<u32, Vec<u8>> = HashMap::with_capacity(nodes.len());
    for node in nodes.iter().rev() {
        let object = query_object(node)?;
        let id = object.handle;
        let mut shape = head(object)?;
        if !shapes_children(object)? {
            shapes.insert(id, shape);
            continue;
        }
        let values = get(object, &VALUES)?;
        let mut children: Vec<&[u8]> = Vec::new();
        if let Some(values) = values.array() {
            for child in engine::values(values) {
                if let Some(child) = query_object(child) {
                    children.push(shapes.get(&child.handle).map_or(&[][..], Vec::as_slice));
                }
            }
        }
        children.sort_unstable();
        shape.push(b'(');
        shape.extend_from_slice(&children.join(&b'|'));
        shape.push(b')');
        shapes.insert(id, shape);
    }
    shapes.remove(&root.handle)
}

/// `$query->shape()`.
fn shape_any(query: &mut ZendObject) -> Option<Vec<u8>> {
    // SAFETY: the object's class lives as long as the object.
    let ce = unsafe { &*query.ce.cast::<ClassEntry>() };
    match overridden(ce, Dispatch::Shape) {
        Some(f) => {
            let shape = zend::call(f, Some(query), ce, &mut [])?;
            engine::to_string(&shape)
        }
        None => shape_of(query),
    }
}

pub extern "C" fn shape(ex: &mut ExecuteData, retval: &mut Zval) {
    if let Some(this) = this(ex)
        && let Some(shape) = shape_of(this)
    {
        *retval = engine::string(&shape);
    }
}

pub extern "C" fn fingerprint(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let Some(queries) = args.get(0).and_then(|v| v.array()) else { return };
    let mut shapes = Vec::with_capacity(queries.len());
    for query in engine::values(queries) {
        let query = query.dereference();
        let parsed;
        let query = if query.is_string() {
            let Some(q) = super::parse::parse_in(scope, query, false, 1) else { return };
            parsed = q;
            &parsed
        } else {
            query
        };
        let Some(object) = query_object(query) else {
            fail("Invalid query element for fingerprint: expected string or Query instance");
            return;
        };
        let Some(shape) = shape_any(object) else { return };
        shapes.push(shape);
    }
    shapes.sort_unstable();
    let digest = Md5::digest(shapes.join(&b'|'));
    let hex: String = digest.iter().map(|b| format!("{b:02x}")).collect();
    *retval = engine::string(hex.as_bytes());
}

// compile() --------------------------------------------------------------------------------------

pub extern "C" fn compile(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let Some(this) = this(ex) else { return };
    let Some(compiler) = args.get(0).and_then(|c| c.dereference_mut().object_mut()) else { return };
    let Some((_, method)) = super::method(this) else { return };
    let name = match method {
        Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => "compileorder",
        Method::Limit => "compilelimit",
        Method::Offset => "compileoffset",
        Method::CursorAfter | Method::CursorBefore => "compilecursor",
        Method::Select => "compileselect",
        m if m.is_aggregate() => "compileaggregate",
        Method::GroupBy | Method::GroupByTimeBucket => "compilegroupby",
        m if m.is_join() => "compilejoin",
        _ => "compilefilter",
    };
    // SAFETY: the compiler's class lives as long as the object.
    let ce = unsafe { &*compiler.ce.cast::<ClassEntry>() };
    let f = zend::function(ce, name);
    let mut query = Zval::new();
    query.set_object(this);
    let Some(compiled) = zend::call(f, Some(compiler), ce, &mut [query]) else { return };
    if let Some(compiled) = engine::verify_return(ex, compiled, MAY_BE_STRING, false) {
        *retval = compiled;
    }
}

// getByType(), getCursorQueries(), groupByType() -----------------------------------------------

/// `in_array($method, $types, true)`.
fn in_list(needle: &Zval, list: &[Zval]) -> bool {
    list.iter().any(|item| engine::identical(needle, item))
}

fn by_type(queries: &Zval, types: &[Zval], clone: bool) -> Option<Zval> {
    let mut filtered = Vec::new();
    if let Some(queries) = queries.array() {
        for query in engine::values(queries) {
            let query = query.dereference();
            let method = get_method_of(query)?;
            if in_list(&method, types) {
                filtered.push(if clone { clone_value(query)? } else { query.shallow_clone() });
            }
        }
    }
    Some(zend::list(filtered))
}

/// `clone $value` (PHP's `Error` for a value that is not an object).
fn clone_value(value: &Zval) -> Option<Zval> {
    match zend::object(value.dereference()) {
        Some(object) => clone_object(object),
        None => {
            engine::error("__clone method called on non-object");
            None
        }
    }
}

pub extern "C" fn get_by_type(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let types: Vec<Zval> = args
        .get(1)
        .and_then(|t| t.array())
        .map(|t| engine::values(t).map(|v| v.dereference().shallow_clone()).collect())
        .unwrap_or_default();
    if let Some(queries) = args.get(0)
        && let Some(list) = by_type(queries, &types, args.bool_or(2, true))
    {
        *retval = list;
    }
}

pub extern "C" fn get_cursor_queries(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let (Some(after), Some(before)) = (zend::case(Method::CursorAfter), zend::case(Method::CursorBefore)) else {
        return;
    };
    if let Some(queries) = args.get(0)
        && let Some(list) = by_type(queries, &[after, before], args.bool_or(1, true))
    {
        *retval = list;
    }
}

/// `toInteger()`: a limit or an offset as an int, or `null`.
fn to_integer(value: &Zval) -> Option<Zval> {
    if value.is_long() {
        return Some(value.shallow_clone());
    }
    let numeric = engine::call("is_numeric", &[value])?;
    if !engine::truthy(&numeric) {
        return Some(Zval::null());
    }
    /// `FILTER_VALIDATE_INT`.
    const FILTER_VALIDATE_INT: i64 = 257;
    let mut filter = Zval::new();
    filter.set_long(FILTER_VALIDATE_INT);
    let integer = engine::call("filter_var", &[value, &filter])?;
    if !integer.is_false() {
        return Some(integer);
    }
    let number = engine::call("floatval", &[value])?.double().unwrap_or(f64::NAN);
    #[allow(clippy::cast_precision_loss)]
    if !number.is_finite() || number < i64::MIN as f64 || number >= i64::MAX as f64 {
        return Some(Zval::null());
    }
    let mut zval = Zval::new();
    #[allow(clippy::cast_possible_truncation)]
    zval.set_long(number as i64);
    Some(zval)
}

pub extern "C" fn group_by_type(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let Some(queries) = args.get(0) else { return };
    let mut filters = Vec::new();
    let mut selections = Vec::new();
    let mut aggregations = Vec::new();
    let mut group_by = Vec::new();
    let mut time_buckets = Vec::new();
    let mut having = Vec::new();
    let mut distinct = false;
    let mut joins = Vec::new();
    let mut unions = Vec::new();
    let mut limit: Option<Zval> = None;
    let mut offset: Option<Zval> = None;
    let mut cursor: Option<Zval> = None;
    let mut cursor_direction: Option<Zval> = None;
    let first = |values: &Zval| -> Zval {
        values
            .array()
            .and_then(|a| a.get_index(0))
            .map(Zval::dereference)
            .filter(|v| !v.is_null())
            .map_or_else(Zval::null, Zval::shallow_clone)
    };
    let Some(queries) = queries.array() else { return };
    for query in engine::values(queries) {
        let query = query.dereference();
        if query_object(query).is_none() {
            continue;
        }
        let Some(method) = get_method_of(query) else { return };
        let Some(values) = getter(query, Dispatch::GetValues, &super::GET_VALUES, &VALUES) else { return };
        let method = method_value(&method);
        let push_clone = |list: &mut Vec<Zval>| -> Option<()> {
            list.push(clone_value(query)?);
            Some(())
        };
        let ok = match method {
            Some(Method::OrderAsc | Method::OrderDesc | Method::OrderRandom) => Some(()),
            Some(Method::Limit) => {
                if limit.as_ref().is_none_or(Zval::is_null) {
                    let Some(value) = to_integer(&first(&values)) else { return };
                    limit = Some(value);
                }
                Some(())
            }
            Some(Method::Offset) => {
                if offset.as_ref().is_none_or(Zval::is_null) {
                    let Some(value) = to_integer(&first(&values)) else { return };
                    offset = Some(value);
                }
                Some(())
            }
            Some(m @ (Method::CursorAfter | Method::CursorBefore)) => {
                if cursor.as_ref().is_none_or(Zval::is_null) {
                    cursor = Some(first(&values));
                    let name = if m == Method::CursorAfter { "After" } else { "Before" };
                    let Some(direction) = enum_case("Utopia\\Query\\CursorDirection", name) else { return };
                    cursor_direction = Some(direction);
                }
                Some(())
            }
            Some(Method::Select) => push_clone(&mut selections),
            Some(m) if m.is_aggregate() => push_clone(&mut aggregations),
            Some(Method::GroupBy) => {
                if let Some(values) = values.array() {
                    group_by.extend(engine::values(values).map(|v| v.dereference().shallow_clone()));
                }
                Some(())
            }
            Some(Method::GroupByTimeBucket) => {
                let interval = values
                    .array()
                    .and_then(|a| a.get_index(0))
                    .map(Zval::dereference)
                    .filter(|v| !v.is_null())
                    .map_or_else(|| engine::string(b""), Zval::shallow_clone);
                let Some(attribute) = getter(query, Dispatch::GetAttribute, &super::GET_ATTRIBUTE, &ATTRIBUTE) else {
                    return;
                };
                let mut bucket = ext_php_rs::types::ZendHashTable::with_capacity(2);
                let _ = bucket.insert("attribute", attribute);
                let _ = bucket.insert("interval", interval);
                let mut zval = Zval::new();
                zval.set_hashtable(bucket);
                time_buckets.push(zval);
                Some(())
            }
            Some(Method::Having) => push_clone(&mut having),
            Some(Method::Distinct) => {
                distinct = true;
                Some(())
            }
            Some(m) if m.is_join() => push_clone(&mut joins),
            Some(Method::Union | Method::UnionAll) => push_clone(&mut unions),
            _ => push_clone(&mut filters),
        };
        if ok.is_none() {
            return;
        }
    }
    let Some(ce) = Class::ParsedQuery.get() else { return };
    let mut distinct_zval = Zval::new();
    distinct_zval.set_bool(distinct);
    let mut args = [
        zend::list(filters),
        zend::list(selections),
        zend::list(aggregations),
        zend::list(group_by),
        zend::list(having),
        distinct_zval,
        zend::list(joins),
        zend::list(unions),
        limit.unwrap_or_else(Zval::null),
        offset.unwrap_or_else(Zval::null),
        cursor.unwrap_or_else(Zval::null),
        cursor_direction.unwrap_or_else(Zval::null),
        zend::list(time_buckets),
    ];
    let parsed = if ce.constructor.is_null() {
        zend::instantiate(ce)
    } else {
        zend::construct_with(ce, ce.constructor, &mut args)
    };
    if let Some(parsed) = parsed {
        *retval = parsed;
    }
}

/// The method a `$query->getMethod()` result is, if it is a `Method` case.
fn method_value(value: &Zval) -> Option<Method> {
    let ce = Class::Method.get()?;
    let object = value.object()?;
    if !std::ptr::eq(object.ce, ce) {
        return None;
    }
    zend::method_of(value)
}

/// The case `name` of the enum `class`.
fn enum_case(class: &str, name: &str) -> Option<Zval> {
    let ce = zend::lookup(class)?;
    let name = std::ffi::CString::new(name).ok()?;
    zend::enum_case(ce, &name)
}

// page(), merge(), diff(), validate() --------------------------------------------------------------

/// `static::limit($n)` / `static::offset($n)`.
fn page_query(scope: &ClassEntry, dispatch: Dispatch, name: &str, value: Zval) -> Option<Zval> {
    let f = overridden(scope, dispatch).unwrap_or_else(|| zend::function(scope, name));
    zend::call(f, None, scope, &mut [value])
}

extern "C" fn page(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let page = args.get(0).and_then(|v| v.long()).unwrap_or(0);
    let per_page = args.get(1).and_then(|v| v.long()).unwrap_or(25);
    if page < 1 {
        invalid(format!("Page must be >= 1, got {page}"));
        return;
    }
    if per_page < 1 {
        invalid(format!("Per page must be >= 1, got {per_page}"));
        return;
    }
    let mut limit = Zval::new();
    limit.set_long(per_page);
    let mut offset = Zval::new();
    match (page - 1).checked_mul(per_page) {
        Some(n) => offset.set_long(n),
        #[allow(clippy::cast_precision_loss)]
        None => offset.set_double((page - 1) as f64 * per_page as f64),
    }
    let Some(limit) = page_query(scope, Dispatch::Limit, "limit", limit) else { return };
    let Some(offset) = page_query(scope, Dispatch::Offset, "offset", offset) else { return };
    *retval = zend::list(vec![limit, offset]);
}

/// The `TypeError` of `merge()`'s and `diff()`'s closures (`fn (Query $q)`) for a value that is
/// not a query. PHP names the closure by its line in `Query.php`
/// (`{closure:Utopia\Query\Query::merge():1662}`), which this cannot.
fn closure_type_error(value: &Zval) {
    let message = format!(
        "Utopia\\Query\\Query::{{closure}}(): Argument #1 ($q) must be of type Utopia\\Query\\Query, {} given",
        engine::value_name(value)
    );
    let _ = ext_php_rs::exception::throw(ext_php_rs::zend::ce::type_error(), &message);
}

extern "C" fn merge(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let (Some(a), Some(b)) = (args.get(0), args.get(1)) else { return };
    let singular = [Method::Limit, Method::Offset, Method::CursorAfter, Method::CursorBefore];
    // `$result = $queriesA`, separated before it is written.
    let (mut result, _) = engine::map_values(a, |_| Some(None));
    if let Some(b) = b.array() {
        for query in engine::values(b) {
            let query = query.dereference();
            let Some(method) = get_method_of(query) else { return };
            if method_value(&method).is_some_and(|m| singular.contains(&m)) {
                let mut kept = Vec::new();
                if let Some(current) = result.array() {
                    for q in engine::values(current) {
                        let q = q.dereference();
                        if query_object(q).is_none() {
                            closure_type_error(q);
                            return;
                        }
                        let Some(m) = get_method_of(q) else { return };
                        if !engine::identical(&m, &method) {
                            kept.push(q.shallow_clone());
                        }
                    }
                }
                result = zend::list(kept);
            }
            let Some(table) = result.array_mut() else { return };
            if table.push(query.shallow_clone()).is_err() {
                engine::error("Cannot add element to the array as the next element is already occupied");
                return;
            }
        }
    }
    *retval = result;
}

extern "C" fn diff(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let (Some(a), Some(b)) = (args.get(0), args.get(1)) else { return };
    let mut arrays = Vec::new();
    if let Some(b) = b.array() {
        for query in engine::values(b) {
            if query_object(query).is_none() {
                closure_type_error(query.dereference());
                return;
            }
            let Some(array) = to_array_any(query) else { return };
            arrays.push(array);
        }
    }
    let mut result = Vec::new();
    if let Some(a) = a.array() {
        for query in engine::values(a) {
            let query = query.dereference();
            let Some(array) = to_array_any(query) else { return };
            if !arrays.iter().any(|b| engine::identical(&array, b)) {
                result.push(query.shallow_clone());
            }
        }
    }
    *retval = zend::list(result);
}

/// `static::validate($queries, $allowed)` for `scope`.
fn validate_in(scope: &ClassEntry, queries: &Zval, allowed: &Zval) -> Option<Vec<Zval>> {
    if let Some(f) = overridden(scope, Dispatch::Validate) {
        let errors = zend::call(f, None, scope, &mut [queries.shallow_clone(), allowed.shallow_clone()])?;
        return Some(errors.array().map(|e| engine::values(e).map(Zval::shallow_clone).collect()).unwrap_or_default());
    }
    validate_native(scope, queries, allowed)
}

fn validate_native(scope: &ClassEntry, queries: &Zval, allowed: &Zval) -> Option<Vec<Zval>> {
    let skip = [
        Method::Limit,
        Method::Offset,
        Method::CursorAfter,
        Method::CursorBefore,
        Method::OrderRandom,
        Method::Distinct,
        Method::Select,
        Method::Exists,
        Method::NotExists,
        Method::Relationship,
    ];
    let allowed_list: Vec<Zval> = allowed
        .array()
        .map(|a| engine::values(a).map(|v| v.dereference().shallow_clone()).collect())
        .unwrap_or_default();
    let mut errors = Vec::new();
    let Some(queries) = queries.array() else { return Some(errors) };
    for query in engine::values(queries) {
        let query = query.dereference();
        let case = get_method_of(query)?;
        let Some(method) = method_value(&case) else {
            // `$method->isNested()` on something that is not a `Method`: PHP's `Error`.
            engine::call_method(&case, &IS_NESTED, &[])?;
            continue;
        };
        if skip.contains(&method) {
            continue;
        }
        if method.is_nested() {
            let nested = getter(query, Dispatch::GetValues, &super::GET_VALUES, &VALUES)?;
            errors.extend(validate_in(scope, &nested, allowed)?);
            continue;
        }
        if method == Method::GroupBy {
            let columns = getter(query, Dispatch::GetValues, &super::GET_VALUES, &VALUES)?;
            if let Some(columns) = columns.array() {
                for column in engine::values(columns) {
                    let column = column.dereference();
                    if !in_list(column, &allowed_list) {
                        let mut message = b"Invalid attribute \"".to_vec();
                        engine::append_string(column, &mut message)?;
                        message.extend_from_slice(b"\" used in ");
                        message.extend_from_slice(method.as_str().as_bytes());
                        errors.push(engine::string(&message));
                    }
                }
            }
            continue;
        }
        let attribute = getter(query, Dispatch::GetAttribute, &super::GET_ATTRIBUTE, &ATTRIBUTE)?;
        let bytes = attribute.zend_str().map(|s| s.as_bytes()).unwrap_or_default();
        if bytes.is_empty() || bytes == b"*" {
            continue;
        }
        if !in_list(&attribute, &allowed_list) {
            let mut message = b"Invalid attribute \"".to_vec();
            message.extend_from_slice(bytes);
            message.extend_from_slice(b"\" used in ");
            message.extend_from_slice(method.as_str().as_bytes());
            errors.push(engine::string(&message));
        }
    }
    Some(errors)
}

static IS_NESTED: engine::Callee = engine::Callee::new("isNested");

extern "C" fn validate(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let (Some(queries), Some(allowed)) = (args.get(0), args.get(1)) else { return };
    if let Some(errors) = validate_native(scope, queries, allowed) {
        *retval = zend::list(errors);
    }
}

/// The helpers declared last in `Query.php`.
pub const LAST: &[Def] = &[
    Def {
        name: "page",
        handler: page,
        flags: STATIC_PUBLIC,
        params: &[("page", INT, None), ("perPage", INT, Some("25"))],
        returns: Some(ARRAY),
    },
    Def {
        name: "merge",
        handler: merge,
        flags: STATIC_PUBLIC,
        params: &[("queriesA", ARRAY, None), ("queriesB", ARRAY, None)],
        returns: Some(ARRAY),
    },
    Def {
        name: "diff",
        handler: diff,
        flags: STATIC_PUBLIC,
        params: &[("queriesA", ARRAY, None), ("queriesB", ARRAY, None)],
        returns: Some(ARRAY),
    },
    Def {
        name: "validate",
        handler: validate,
        flags: STATIC_PUBLIC,
        params: &[("queries", ARRAY, None), ("allowedAttributes", ARRAY, None)],
        returns: Some(ARRAY),
    },
];
