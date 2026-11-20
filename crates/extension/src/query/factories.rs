//! The static factories (`Query::equal()`, `Query::limit()`, `Query::join()`...): each is
//! `new static(...)` of the called class, as `Query.php` builds it.

use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ExecuteData};
use utopia_query::Method;

use super::zend::{self, Args, Dispatch};
use super::{
    ARRAY, BOOL, Def, INT, MAY_BE_OBJECT, MIXED, NUMBER, SCALAR, STATIC, STATIC_PUBLIC, STR, Ty, concat, invalid,
    new_static, overridden,
};
use crate::classes::engine::{self, MAY_BE_ARRAY, MAY_BE_BOOL, MAY_BE_DOUBLE, MAY_BE_LONG, MAY_BE_NULL, MAY_BE_STRING};

/// `Query::GROUP_BY_TIME_BUCKET_INTERVALS`.
pub const INTERVALS: &[&str] = &["1m", "5m", "15m", "1h", "1d", "1w", "1M"];

/// How a factory's arguments become the query.
#[derive(Clone, Copy)]
enum Shape {
    /// `(string $attribute, array $values)`: `new static(M, $attribute, $values)`.
    AttrValues,
    /// `(string $attribute, $value)`: `new static(M, $attribute, [$value])`.
    AttrValue,
    /// `(string $attribute, $start, $end)`: `[$start, $end]`.
    AttrTwo,
    /// `(string $attribute)`.
    Attr,
    /// `(array $values)`: `new static(M, '', $values)`.
    Values,
    /// `($value)`: `new static(M, values: [$value])`.
    One,
    /// `()`: `new static(M)`.
    Bare,
    /// `(string $attribute = '', ?NullsPosition $nulls = null)`.
    Order,
    /// `notEqual()`.
    NotEqual,
    /// `(string $attribute, array $values, int|float $distance, bool $meters = false)`.
    Distance,
    /// `(string $attribute = '*'|…, string $alias = '')`: `new static(M, $attribute, [], $alias)`.
    Aggregate,
    /// `(string $collection, string $alias, array $on)`.
    Join,
    /// `(string $collection, string $alias)`.
    JoinBare,
    /// `jsonPath()`.
    JsonPath,
    /// `notExists()`.
    NotExists,
    /// `raw()`.
    Raw,
    /// `static::lessThan('$createdAt', $value)` and the like.
    Delegate(Dispatch, &'static str),
}

/// `E_USER_DEPRECATED`.
const E_USER_DEPRECATED: i32 = 1 << 14;

unsafe extern "C" {
    fn zend_error(ty: i32, format: *const std::ffi::c_char, ...);
}

fn factory(ex: &mut ExecuteData, retval: &mut Zval, method: Method, shape: Shape) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    if let Some(query) = build(scope, &args, method, shape) {
        *retval = query;
    }
}

fn empty() -> Zval {
    engine::string(b"")
}

fn build(scope: &ClassEntry, args: &Args, method: Method, shape: Shape) -> Option<Zval> {
    let a = |i| args.value(i);
    match shape {
        Shape::AttrValues => new_static(scope, method, a(0), a(1), empty(), 3),
        Shape::AttrValue => new_static(scope, method, a(0), zend::list(vec![a(1)]), empty(), 3),
        Shape::AttrTwo => new_static(scope, method, a(0), zend::list(vec![a(1), a(2)]), empty(), 3),
        Shape::Attr => new_static(scope, method, a(0), engine::empty_array(), empty(), 2),
        Shape::Values => new_static(scope, method, empty(), a(0), empty(), 3),
        Shape::One => new_static(scope, method, empty(), zend::list(vec![a(0)]), empty(), 3),
        Shape::Bare => new_static(scope, method, empty(), engine::empty_array(), empty(), 1),
        Shape::Order => {
            let nulls = args.get(1).map(|v| v.dereference().shallow_clone()).filter(|v| !v.is_null());
            let values = nulls.map_or_else(|| zend::list(Vec::new()), |n| zend::list(vec![n]));
            new_static(scope, method, args.str_or(0, b""), values, empty(), 3)
        }
        Shape::NotEqual => {
            let value = a(1);
            // Maps, and anything that is not an array, are one value.
            let values = if is_list(&value) { value } else { zend::list(vec![value]) };
            new_static(scope, method, a(0), values, empty(), 3)
        }
        Shape::Distance => {
            let mut meters = Zval::new();
            meters.set_bool(args.bool_or(3, false));
            let inner = zend::list(vec![a(1), a(2), meters]);
            new_static(scope, method, a(0), zend::list(vec![inner]), empty(), 3)
        }
        Shape::Aggregate => {
            let attribute = args.str_or(0, b"*");
            new_static(scope, method, attribute, engine::empty_array(), args.str_or(1, b""), 4)
        }
        Shape::Join => super::parse::create_join(scope, method, a(0), a(1), &a(2)),
        Shape::JoinBare => super::parse::create_join(scope, method, a(0), a(1), &engine::empty_array()),
        Shape::JsonPath => new_static(scope, method, a(0), zend::list(vec![a(1), a(2), a(3)]), empty(), 3),
        Shape::NotExists => {
            let value = a(0);
            let values = if value.is_array() { value } else { zend::list(vec![value]) };
            new_static(scope, method, empty(), values, empty(), 3)
        }
        Shape::Raw => new_static(scope, method, a(0), args.array_or_empty(1), empty(), 3),
        Shape::Delegate(dispatch, attribute) => {
            let mut values: Vec<Zval> = vec![engine::string(attribute.as_bytes())];
            values.push(a(0));
            if matches!(dispatch, Dispatch::Between) {
                values.push(a(1));
            }
            match overridden(scope, dispatch) {
                Some(f) => zend::call(f, None, scope, &mut values),
                None => {
                    let attribute = values.remove(0);
                    new_static(scope, method, attribute, zend::list(values), empty(), 3)
                }
            }
        }
    }
}

/// `array_is_list($value)`.
fn is_list(value: &Zval) -> bool {
    value.array().is_some_and(|array| {
        engine::keyed_values(array)
            .enumerate()
            .all(|(i, (key, _))| matches!(key, engine::KeyRef::Index(k) if k == i as i64))
    })
}

macro_rules! factories {
    ($($handler:ident => $method:ident, $shape:expr;)*) => {
        $(extern "C" fn $handler(ex: &mut ExecuteData, retval: &mut Zval) {
            factory(ex, retval, Method::$method, $shape);
        })*
    };
}

factories! {
    equal => Equal, Shape::AttrValues;
    not_equal => NotEqual, Shape::NotEqual;
    less_than => LessThan, Shape::AttrValue;
    less_than_equal => LessThanEqual, Shape::AttrValue;
    greater_than => GreaterThan, Shape::AttrValue;
    greater_than_equal => GreaterThanEqual, Shape::AttrValue;
    contains_string => Contains, Shape::AttrValues;
    contains_any => ContainsAny, Shape::AttrValues;
    not_contains => NotContains, Shape::AttrValues;
    between => Between, Shape::AttrTwo;
    not_between => NotBetween, Shape::AttrTwo;
    search => Search, Shape::AttrValue;
    not_search => NotSearch, Shape::AttrValue;
    select => Select, Shape::Values;
    order_desc => OrderDesc, Shape::Order;
    order_asc => OrderAsc, Shape::Order;
    order_random => OrderRandom, Shape::Bare;
    limit => Limit, Shape::One;
    offset => Offset, Shape::One;
    cursor_after => CursorAfter, Shape::One;
    cursor_before => CursorBefore, Shape::One;
    is_null => IsNull, Shape::Attr;
    is_not_null => IsNotNull, Shape::Attr;
    starts_with => StartsWith, Shape::AttrValue;
    not_starts_with => NotStartsWith, Shape::AttrValue;
    ends_with => EndsWith, Shape::AttrValue;
    not_ends_with => NotEndsWith, Shape::AttrValue;
    created_before => LessThan, Shape::Delegate(Dispatch::LessThan, "$createdAt");
    created_after => GreaterThan, Shape::Delegate(Dispatch::GreaterThan, "$createdAt");
    updated_before => LessThan, Shape::Delegate(Dispatch::LessThan, "$updatedAt");
    updated_after => GreaterThan, Shape::Delegate(Dispatch::GreaterThan, "$updatedAt");
    created_between => Between, Shape::Delegate(Dispatch::Between, "$createdAt");
    updated_between => Between, Shape::Delegate(Dispatch::Between, "$updatedAt");
    or => Or, Shape::Values;
    and => And, Shape::Values;
    contains_all => ContainsAll, Shape::AttrValues;
    distance_equal => DistanceEqual, Shape::Distance;
    distance_not_equal => DistanceNotEqual, Shape::Distance;
    distance_greater_than => DistanceGreaterThan, Shape::Distance;
    distance_less_than => DistanceLessThan, Shape::Distance;
    intersects => Intersects, Shape::AttrValue;
    not_intersects => NotIntersects, Shape::AttrValue;
    crosses => Crosses, Shape::AttrValue;
    not_crosses => NotCrosses, Shape::AttrValue;
    overlaps => Overlaps, Shape::AttrValue;
    not_overlaps => NotOverlaps, Shape::AttrValue;
    touches => Touches, Shape::AttrValue;
    not_touches => NotTouches, Shape::AttrValue;
    vector_dot => VectorDot, Shape::AttrValue;
    vector_cosine => VectorCosine, Shape::AttrValue;
    vector_euclidean => VectorEuclidean, Shape::AttrValue;
    regex => Regex, Shape::AttrValue;
    exists => Exists, Shape::Values;
    not_exists => NotExists, Shape::NotExists;
    elem_match => ElemMatch, Shape::AttrValues;
    relationship => Relationship, Shape::AttrValues;
    count => Count, Shape::Aggregate;
    count_distinct => CountDistinct, Shape::Aggregate;
    sum => Sum, Shape::Aggregate;
    avg => Avg, Shape::Aggregate;
    min => Min, Shape::Aggregate;
    max => Max, Shape::Aggregate;
    stddev => Stddev, Shape::Aggregate;
    stddev_pop => StddevPop, Shape::Aggregate;
    stddev_samp => StddevSamp, Shape::Aggregate;
    variance => Variance, Shape::Aggregate;
    var_pop => VarPop, Shape::Aggregate;
    var_samp => VarSamp, Shape::Aggregate;
    bit_and => BitAnd, Shape::Aggregate;
    bit_or => BitOr, Shape::Aggregate;
    bit_xor => BitXor, Shape::Aggregate;
    group_by => GroupBy, Shape::Values;
    having => Having, Shape::Values;
    distinct => Distinct, Shape::Bare;
    join => Join, Shape::Join;
    left_join => LeftJoin, Shape::Join;
    right_join => RightJoin, Shape::Join;
    full_outer_join => FullOuterJoin, Shape::Join;
    cross_join => CrossJoin, Shape::JoinBare;
    natural_join => NaturalJoin, Shape::JoinBare;
    union => Union, Shape::Values;
    union_all => UnionAll, Shape::Values;
    json_contains => JsonContains, Shape::AttrValue;
    json_not_contains => JsonNotContains, Shape::AttrValue;
    json_overlaps => JsonOverlaps, Shape::AttrValue;
    json_path => JsonPath, Shape::JsonPath;
    covers => Covers, Shape::AttrValue;
    not_covers => NotCovers, Shape::AttrValue;
    spatial_equals => SpatialEquals, Shape::AttrValue;
    not_spatial_equals => NotSpatialEquals, Shape::AttrValue;
    raw => Raw, Shape::Raw;
}

/// `contains()`'s deprecation, as `#[\Deprecated]` gives it.
pub const CONTAINS_DEPRECATED: &str =
    "Use containsString() for string substring matching or containsAny() for array attributes.";

/// `contains()`, deprecated as `#[\Deprecated]` marks it in PHP: the same `E_USER_DEPRECATED`
/// (the engine raises `E_DEPRECATED` for an internal function it flags as deprecated, so the
/// flag is left off and the attribute alone is declared, see [`super::register_class`]).
extern "C" fn contains(ex: &mut ExecuteData, retval: &mut Zval) {
    let message = c"Method Utopia\\Query\\Query::contains() is deprecated, Use containsString() for string substring matching or containsAny() for array attributes.";
    // SAFETY: the engine formats `%s` with a NUL-terminated string.
    unsafe { zend_error(E_USER_DEPRECATED, c"%s".as_ptr(), message.as_ptr()) };
    if engine::has_exception() {
        return;
    }
    factory(ex, retval, Method::Contains, Shape::AttrValues);
}

extern "C" fn group_by_time_bucket(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let interval = args.value(1);
    let bytes = interval.zend_str().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
    if !INTERVALS.iter().any(|i| i.as_bytes() == bytes) {
        invalid(concat(&[
            b"Invalid groupByTimeBucket interval: ",
            &bytes,
            b". Allowed: ",
            INTERVALS.join(", ").as_bytes(),
        ]));
        return;
    }
    if let Some(query) =
        new_static(scope, Method::GroupByTimeBucket, args.value(0), zend::list(vec![interval]), empty(), 3)
    {
        *retval = query;
    }
}

extern "C" fn on(ex: &mut ExecuteData, retval: &mut Zval) {
    let Some(args) = zend::args(ex) else { return };
    let scope = zend::called_scope(ex);
    let operator = args.str_or(2, b"=");
    let values = zend::list(vec![args.value(0), operator, args.value(1)]);
    if let Some(query) = new_static(scope, Method::On, empty(), values, empty(), 3) {
        *retval = query;
    }
}

const SCALAR_OR_ARRAY_OR_NULL: Ty =
    Ty::M(MAY_BE_STRING | MAY_BE_LONG | MAY_BE_DOUBLE | MAY_BE_BOOL | MAY_BE_ARRAY | MAY_BE_NULL);
const SCALAR_OR_ARRAY: Ty = Ty::M(MAY_BE_STRING | MAY_BE_LONG | MAY_BE_DOUBLE | MAY_BE_BOOL | MAY_BE_ARRAY);
const ARRAY_OR_OBJECT: Ty = Ty::M(MAY_BE_ARRAY | MAY_BE_OBJECT);
const NULLS: Ty = Ty::C("Utopia\\Query\\NullsPosition", MAY_BE_NULL);

macro_rules! def {
    ($name:literal, $handler:ident, [$($param:literal: $ty:ident $(= $default:literal)?),*]) => {
        Def {
            name: $name,
            handler: $handler,
            flags: STATIC_PUBLIC,
            params: &[$(($param, $ty, def!(@default $($default)?))),*],
            returns: Some(STATIC),
        }
    };
    (@default) => { None };
    (@default $default:literal) => { Some($default) };
}

/// The factories declared before `getByType()` in `Query.php`.
pub const FIRST: &[Def] = &[
    def!("equal", equal, ["attribute": STR, "values": ARRAY]),
    def!("notEqual", not_equal, ["attribute": STR, "value": SCALAR_OR_ARRAY_OR_NULL]),
    def!("lessThan", less_than, ["attribute": STR, "value": SCALAR]),
    def!("lessThanEqual", less_than_equal, ["attribute": STR, "value": SCALAR]),
    def!("greaterThan", greater_than, ["attribute": STR, "value": SCALAR]),
    def!("greaterThanEqual", greater_than_equal, ["attribute": STR, "value": SCALAR]),
    def!("contains", contains, ["attribute": STR, "values": ARRAY]),
    def!("containsString", contains_string, ["attribute": STR, "values": ARRAY]),
    def!("containsAny", contains_any, ["attribute": STR, "values": ARRAY]),
    def!("notContains", not_contains, ["attribute": STR, "values": ARRAY]),
    def!("between", between, ["attribute": STR, "start": SCALAR, "end": SCALAR]),
    def!("notBetween", not_between, ["attribute": STR, "start": SCALAR, "end": SCALAR]),
    def!("search", search, ["attribute": STR, "value": STR]),
    def!("notSearch", not_search, ["attribute": STR, "value": STR]),
    def!("select", select, ["attributes": ARRAY]),
    def!("orderDesc", order_desc, ["attribute": STR = "''", "nulls": NULLS = "null"]),
    def!("orderAsc", order_asc, ["attribute": STR = "''", "nulls": NULLS = "null"]),
    def!("orderRandom", order_random, []),
    def!("limit", limit, ["value": INT]),
    def!("offset", offset, ["value": INT]),
    def!("cursorAfter", cursor_after, ["value": ARRAY_OR_OBJECT]),
    def!("cursorBefore", cursor_before, ["value": ARRAY_OR_OBJECT]),
    def!("isNull", is_null, ["attribute": STR]),
    def!("isNotNull", is_not_null, ["attribute": STR]),
    def!("startsWith", starts_with, ["attribute": STR, "value": STR]),
    def!("notStartsWith", not_starts_with, ["attribute": STR, "value": STR]),
    def!("endsWith", ends_with, ["attribute": STR, "value": STR]),
    def!("notEndsWith", not_ends_with, ["attribute": STR, "value": STR]),
    def!("createdBefore", created_before, ["value": STR]),
    def!("createdAfter", created_after, ["value": STR]),
    def!("updatedBefore", updated_before, ["value": STR]),
    def!("updatedAfter", updated_after, ["value": STR]),
    def!("createdBetween", created_between, ["start": STR, "end": STR]),
    def!("updatedBetween", updated_between, ["start": STR, "end": STR]),
    def!("or", or, ["queries": ARRAY]),
    def!("and", and, ["queries": ARRAY]),
    def!("containsAll", contains_all, ["attribute": STR, "values": ARRAY]),
];

/// The factories declared after `getAttributeType()` in `Query.php`.
pub const SECOND: &[Def] = &[
    def!("distanceEqual", distance_equal, ["attribute": STR, "values": ARRAY, "distance": NUMBER, "meters": BOOL = "false"]),
    def!("distanceNotEqual", distance_not_equal, ["attribute": STR, "values": ARRAY, "distance": NUMBER, "meters": BOOL = "false"]),
    def!("distanceGreaterThan", distance_greater_than, ["attribute": STR, "values": ARRAY, "distance": NUMBER, "meters": BOOL = "false"]),
    def!("distanceLessThan", distance_less_than, ["attribute": STR, "values": ARRAY, "distance": NUMBER, "meters": BOOL = "false"]),
    def!("intersects", intersects, ["attribute": STR, "values": ARRAY]),
    def!("notIntersects", not_intersects, ["attribute": STR, "values": ARRAY]),
    def!("crosses", crosses, ["attribute": STR, "values": ARRAY]),
    def!("notCrosses", not_crosses, ["attribute": STR, "values": ARRAY]),
    def!("overlaps", overlaps, ["attribute": STR, "values": ARRAY]),
    def!("notOverlaps", not_overlaps, ["attribute": STR, "values": ARRAY]),
    def!("touches", touches, ["attribute": STR, "values": ARRAY]),
    def!("notTouches", not_touches, ["attribute": STR, "values": ARRAY]),
    def!("vectorDot", vector_dot, ["attribute": STR, "vector": ARRAY]),
    def!("vectorCosine", vector_cosine, ["attribute": STR, "vector": ARRAY]),
    def!("vectorEuclidean", vector_euclidean, ["attribute": STR, "vector": ARRAY]),
    def!("regex", regex, ["attribute": STR, "pattern": STR]),
    def!("exists", exists, ["attributes": ARRAY]),
    def!("notExists", not_exists, ["attribute": SCALAR_OR_ARRAY]),
    def!("elemMatch", elem_match, ["attribute": STR, "queries": ARRAY]),
    def!("relationship", relationship, ["attribute": STR, "queries": ARRAY]),
    def!("count", count, ["attribute": STR = "'*'", "alias": STR = "''"]),
    def!("countDistinct", count_distinct, ["attribute": STR, "alias": STR = "''"]),
    def!("sum", sum, ["attribute": STR, "alias": STR = "''"]),
    def!("avg", avg, ["attribute": STR, "alias": STR = "''"]),
    def!("min", min, ["attribute": STR, "alias": STR = "''"]),
    def!("max", max, ["attribute": STR, "alias": STR = "''"]),
    def!("stddev", stddev, ["attribute": STR, "alias": STR = "''"]),
    def!("stddevPop", stddev_pop, ["attribute": STR, "alias": STR = "''"]),
    def!("stddevSamp", stddev_samp, ["attribute": STR, "alias": STR = "''"]),
    def!("variance", variance, ["attribute": STR, "alias": STR = "''"]),
    def!("varPop", var_pop, ["attribute": STR, "alias": STR = "''"]),
    def!("varSamp", var_samp, ["attribute": STR, "alias": STR = "''"]),
    def!("bitAnd", bit_and, ["attribute": STR, "alias": STR = "''"]),
    def!("bitOr", bit_or, ["attribute": STR, "alias": STR = "''"]),
    def!("bitXor", bit_xor, ["attribute": STR, "alias": STR = "''"]),
    def!("groupBy", group_by, ["attributes": ARRAY]),
    def!("groupByTimeBucket", group_by_time_bucket, ["attribute": STR, "interval": STR]),
    def!("having", having, ["queries": ARRAY]),
    def!("distinct", distinct, []),
    def!("on", on, ["left": STR, "right": STR, "operator": STR = "'='"]),
    def!("join", join, ["collection": STR, "alias": STR, "on": ARRAY]),
    def!("leftJoin", left_join, ["collection": STR, "alias": STR, "on": ARRAY]),
    def!("rightJoin", right_join, ["collection": STR, "alias": STR, "on": ARRAY]),
    def!("fullOuterJoin", full_outer_join, ["collection": STR, "alias": STR, "on": ARRAY]),
    def!("crossJoin", cross_join, ["collection": STR, "alias": STR]),
    def!("naturalJoin", natural_join, ["collection": STR, "alias": STR]),
    def!("union", union, ["queries": ARRAY]),
    def!("unionAll", union_all, ["queries": ARRAY]),
    def!("jsonContains", json_contains, ["attribute": STR, "value": MIXED]),
    def!("jsonNotContains", json_not_contains, ["attribute": STR, "value": MIXED]),
    def!("jsonOverlaps", json_overlaps, ["attribute": STR, "values": ARRAY]),
    def!("jsonPath", json_path, ["attribute": STR, "path": STR, "operator": STR, "value": MIXED]),
    def!("covers", covers, ["attribute": STR, "values": ARRAY]),
    def!("notCovers", not_covers, ["attribute": STR, "values": ARRAY]),
    def!("spatialEquals", spatial_equals, ["attribute": STR, "values": ARRAY]),
    def!("notSpatialEquals", not_spatial_equals, ["attribute": STR, "values": ARRAY]),
    def!("raw", raw, ["sql": STR, "bindings": ARRAY = "[]"]),
];
