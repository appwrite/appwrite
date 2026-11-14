//! What the SQL dialects (MySQL, MariaDB, PostgreSQL, SQLite) share beyond
//! the base builder: full-text search, spatial, JSON and array filters, and
//! the upsert conflict clause.
//!
//! PHP: `Utopia\Query\Builder\SQL` and the `Spatial` trait.

use php_std::ArrayKey;

use crate::builder::core::{Builder, Dialect};
use crate::builder::types::SpatialDistanceFilter;
use crate::error::Result;
use crate::method::Method;
use crate::query::{Query, is_numeric};
use crate::value::{Array, Param, Value};

/// The hooks a SQL dialect implements: how it compiles search, spatial and
/// JSON predicates, and its conflict clause.
pub trait SqlDialect: Dialect {
    /// Full-text search on the wrapped attribute.
    fn compile_search_expression(b: &mut Builder<Self>, attribute: &str, values: &Array, not: bool) -> Result<String>;

    /// A `distance*` predicate.
    fn compile_spatial_distance(
        b: &mut Builder<Self>,
        method: Method,
        attribute: &str,
        values: &Array,
    ) -> Result<String>;

    /// A spatial predicate function (`ST_Intersects`, ...), negated with `NOT`.
    fn compile_spatial_predicate(
        b: &mut Builder<Self>,
        function: &str,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String>;

    /// `covers` / `notCovers` (MySQL `ST_Contains`, PostgreSQL `ST_Covers`).
    fn compile_spatial_covers_predicate(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String>;

    /// `jsonContains` / `jsonNotContains`.
    fn compile_json_contains_expression(
        b: &mut Builder<Self>,
        attribute: &str,
        values: &Array,
        not: bool,
    ) -> Result<String>;

    /// `jsonOverlaps`.
    fn compile_json_overlaps_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String>;

    /// `jsonPath`.
    fn compile_json_path_expression(b: &mut Builder<Self>, attribute: &str, values: &Array) -> Result<String>;

    /// The start of the conflict clause (`ON DUPLICATE KEY UPDATE`).
    fn compile_conflict_header(b: &mut Builder<Self>) -> Result<String>;

    /// The new value of a conflicting column (`VALUES(col)`, `excluded.col`).
    fn compile_conflict_assignment(wrapped: &str) -> String;

    /// A spatial filter (MariaDB checks units first).
    fn compile_spatial_filter(b: &mut Builder<Self>, method: Method, attribute: &str, query: &Query) -> Result<String> {
        sql_compile_spatial_filter(b, method, attribute, query)
    }

    /// A filter on an array attribute (`contains` on arrays, ...).
    fn compile_array_filter(b: &mut Builder<Self>, method: Method, attribute: &str, query: &Query) -> Result<String> {
        sql_compile_array_filter(b, method, attribute, query)
    }
}

/// PHP's `SQL::compileFilter()`: search, spatial, JSON and array filters,
/// then the base filters.
pub(crate) fn sql_compile_filter<D: SqlDialect>(b: &mut Builder<D>, query: &Query) -> Result<String> {
    let method = query.method();
    let attribute = b.resolve_and_wrap(query.attribute())?;
    match method {
        Method::Search => return D::compile_search_expression(b, &attribute, query.values(), false),
        Method::NotSearch => return D::compile_search_expression(b, &attribute, query.values(), true),
        _ => {}
    }
    if method.is_spatial() {
        return D::compile_spatial_filter(b, method, &attribute, query);
    }
    if matches!(query.attribute_type(), "point" | "linestring" | "polygon") {
        let spatial = match method {
            Method::Equal => Some(Method::SpatialEquals),
            Method::NotEqual => Some(Method::NotSpatialEquals),
            Method::Contains => Some(Method::Covers),
            Method::NotContains => Some(Method::NotCovers),
            _ => None,
        };
        if let Some(spatial) = spatial {
            return D::compile_spatial_filter(b, spatial, &attribute, query);
        }
    }
    if method.is_json() {
        return sql_compile_json_filter(b, method, &attribute, query);
    }
    if query.on_array()
        && matches!(method, Method::Contains | Method::ContainsAny | Method::NotContains | Method::ContainsAll)
    {
        return D::compile_array_filter(b, method, &attribute, query);
    }
    b.base_compile_filter(query)
}

/// PHP's `SQL::compileArrayFilter()`.
pub(crate) fn sql_compile_array_filter<D: SqlDialect>(
    b: &mut Builder<D>,
    method: Method,
    attribute: &str,
    query: &Query,
) -> Result<String> {
    let wrapped = crate::value::list([Value::Array(query.values().clone())]);
    match method {
        Method::Contains | Method::ContainsAny => D::compile_json_overlaps_expression(b, attribute, &wrapped),
        Method::NotContains => Ok(format!("NOT {}", D::compile_json_overlaps_expression(b, attribute, &wrapped)?)),
        Method::ContainsAll => D::compile_json_contains_expression(b, attribute, &wrapped, false),
        _ => b.base_compile_filter(query),
    }
}

/// PHP's `SQL::compileSpatialFilter()`.
pub(crate) fn sql_compile_spatial_filter<D: SqlDialect>(
    b: &mut Builder<D>,
    method: Method,
    attribute: &str,
    query: &Query,
) -> Result<String> {
    let values = query.values();
    use Method::*;
    match method {
        DistanceLessThan | DistanceGreaterThan | DistanceEqual | DistanceNotEqual => {
            D::compile_spatial_distance(b, method, attribute, values)
        }
        Intersects => D::compile_spatial_predicate(b, "ST_Intersects", attribute, values, false),
        NotIntersects => D::compile_spatial_predicate(b, "ST_Intersects", attribute, values, true),
        Crosses => D::compile_spatial_predicate(b, "ST_Crosses", attribute, values, false),
        NotCrosses => D::compile_spatial_predicate(b, "ST_Crosses", attribute, values, true),
        Overlaps => D::compile_spatial_predicate(b, "ST_Overlaps", attribute, values, false),
        NotOverlaps => D::compile_spatial_predicate(b, "ST_Overlaps", attribute, values, true),
        Touches => D::compile_spatial_predicate(b, "ST_Touches", attribute, values, false),
        NotTouches => D::compile_spatial_predicate(b, "ST_Touches", attribute, values, true),
        Covers => D::compile_spatial_covers_predicate(b, attribute, values, false),
        NotCovers => D::compile_spatial_covers_predicate(b, attribute, values, true),
        SpatialEquals => D::compile_spatial_predicate(b, "ST_Equals", attribute, values, false),
        NotSpatialEquals => D::compile_spatial_predicate(b, "ST_Equals", attribute, values, true),
        _ => b.base_compile_filter(query),
    }
}

/// PHP's `SQL::compileJsonFilter()`.
pub(crate) fn sql_compile_json_filter<D: SqlDialect>(
    b: &mut Builder<D>,
    method: Method,
    attribute: &str,
    query: &Query,
) -> Result<String> {
    let values = query.values();
    match method {
        Method::JsonContains => D::compile_json_contains_expression(b, attribute, values, false),
        Method::JsonNotContains => D::compile_json_contains_expression(b, attribute, values, true),
        Method::JsonOverlaps => D::compile_json_overlaps_expression(b, attribute, values),
        Method::JsonPath => D::compile_json_path_expression(b, attribute, values),
        _ => b.base_compile_filter(query),
    }
}

/// PHP's `(float) $value . ''`: a coordinate as the string PHP prints.
fn coordinate(value: Option<&Value>) -> String {
    php_std::number::to_string(value.map(Value::to_double).unwrap_or(0.0))
}

fn at(array: &Array, index: i64) -> Option<&Value> {
    array.get(&ArrayKey::Int(index))
}

/// A coordinate array as WKT: `[x, y]` a point, a list of points a line, a
/// list of closed rings of at least 4 points a polygon. Fails with
/// [`Error::Validation`](crate::Error::Validation) on any other shape, or a
/// point that is not an `[x, y]` pair of finite numbers.
///
/// PHP: `Spatial::geometryToWkt()`.
pub fn geometry_to_wkt(geometry: &Array) -> Result<String> {
    if is_coordinate(geometry) {
        return Ok(format!("POINT({})", coordinate_wkt(geometry)));
    }
    let Some(Value::Array(first)) = at(geometry, 0).filter(|_| geometry.is_list()) else {
        return Err(invalid("expected an [x, y] point, a list of points or a list of rings"));
    };
    if let Some(Value::Array(_)) = at(first, 0) {
        let rings = geometry.values().map(|ring| Ok(format!("({})", ring_wkt(ring)?))).collect::<Result<Vec<_>>>()?;
        return Ok(format!("POLYGON({})", rings.join(", ")));
    }
    if geometry.len() == 1 {
        return Ok(format!("POINT({})", point_wkt(at(geometry, 0))?));
    }
    Ok(format!("LINESTRING({})", points_wkt(geometry)?))
}

fn invalid(reason: &str) -> crate::Error {
    crate::Error::Validation(format!("Invalid geometry: {reason}"))
}

/// PHP's `Spatial::ringToWkt()`: a closed ring of at least 4 points.
fn ring_wkt(ring: &Value) -> Result<String> {
    let ring = match ring {
        Value::Array(ring) if !ring.is_empty() && ring.is_list() => ring,
        _ => return Err(invalid("a polygon ring must be a list of points")),
    };
    let wkt = points_wkt(ring)?;
    if ring.len() < 4 {
        return Err(invalid("a polygon ring must have at least 4 points"));
    }
    let xy = |p: Option<&Value>| match p {
        Some(Value::Array(p)) => (number(at(p, 0)), number(at(p, 1))),
        _ => (0.0, 0.0),
    };
    if xy(at(ring, 0)) != xy(at(ring, ring.len() as i64 - 1)) {
        return Err(invalid("a polygon ring must be closed, with its first point equal to its last"));
    }
    Ok(wkt)
}

/// PHP's `Spatial::coordinatesToWkt()`.
fn points_wkt(points: &Array) -> Result<String> {
    Ok(points.values().map(|p| point_wkt(Some(p))).collect::<Result<Vec<_>>>()?.join(", "))
}

/// PHP's `Spatial::coordinateToWkt()`: `x y`, or an error unless `point` is
/// an `[x, y]` pair of finite numbers.
pub(crate) fn point_wkt(point: Option<&Value>) -> Result<String> {
    match point {
        Some(Value::Array(p)) if is_coordinate(p) => Ok(coordinate_wkt(p)),
        _ => Err(invalid("every point must be an [x, y] pair of finite numbers")),
    }
}

fn coordinate_wkt(point: &Array) -> String {
    format!("{} {}", coordinate(at(point, 0)), coordinate(at(point, 1)))
}

/// PHP's `Spatial::isCoordinate()`: a list of two finite numbers.
fn is_coordinate(value: &Array) -> bool {
    let finite = |v: Option<&Value>| v.is_some_and(|v| is_numeric(v) && v.to_double().is_finite());
    value.len() == 2 && value.is_list() && finite(at(value, 0)) && finite(at(value, 1))
}

fn number(value: Option<&Value>) -> f64 {
    value.map(Value::to_double).unwrap_or(0.0)
}

/// `$this->geometryToWkt($values[0])` in `class`, called from `file` at
/// `line`: the WKT of a spatial predicate's geometry.
pub(crate) fn predicate_wkt(values: &Array, class: &str, file: &'static str, line: u32) -> Result<String> {
    let geometry = values.get(&ArrayKey::Int(0)).unwrap_or(&Value::Null);
    let function = format!("{class}::geometryToWkt");
    geometry_to_wkt(Param::method(&function, 1, "geometry", file, line).array(geometry)?)
}

/// `SpatialDistanceFilter::fromTuple($values[0])`, called from `file` at `line`.
pub(crate) fn distance_filter(values: &Array, file: &'static str, line: u32) -> Result<SpatialDistanceFilter> {
    let tuple = values.get(&ArrayKey::Int(0)).unwrap_or(&Value::Null);
    let param = Param::method("Utopia\\Query\\Builder\\SpatialDistanceFilter::fromTuple", 1, "tuple", file, line);
    SpatialDistanceFilter::from_tuple(param.array(tuple)?)
}

/// The WKT of a distance filter's geometry (a WKT string stays as is).
pub(crate) fn wkt_of(geometry: &Value) -> Result<Value> {
    Ok(match geometry {
        Value::Array(a) => crate::value::string(geometry_to_wkt(a)?),
        other => other.clone(),
    })
}

/// The operator of a `distance*` method.
pub(crate) fn distance_operator(method: Method) -> &'static str {
    match method {
        Method::DistanceGreaterThan => ">",
        Method::DistanceEqual => "=",
        Method::DistanceNotEqual => "!=",
        _ => "<",
    }
}
