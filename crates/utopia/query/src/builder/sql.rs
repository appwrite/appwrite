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

/// The PHP class whose `Spatial` trait [`geometry_to_wkt`] names in its errors.
const MYSQL: &str = "Utopia\\Query\\Builder\\MySQL";

/// A coordinate array as WKT: `[x, y]` a point, a list of points a line, a
/// list of rings a polygon. Fails with PHP's `TypeError` when a point or a
/// ring is not an array.
///
/// PHP: `Spatial::geometryToWkt()`.
pub fn geometry_to_wkt(geometry: &Array) -> Result<String> {
    wkt(geometry, MYSQL)
}

/// [`geometry_to_wkt`] as `class` (the class using PHP's `Spatial` trait)
/// runs it: its closures are named after the class.
pub(crate) fn wkt(geometry: &Array, class: &str) -> Result<String> {
    let first = at(geometry, 0);
    if geometry.len() == 2 && first.is_some_and(is_numeric) && at(geometry, 1).is_some_and(is_numeric) {
        return Ok(format!("POINT({} {})", coordinate(first), coordinate(at(geometry, 1))));
    }
    if let Some(Value::Array(first_item)) = first {
        let point = |line: u32, p: &Value| -> Result<String> {
            let closure =
                format!("{class}::{{closure:Utopia\\Query\\Builder\\Trait\\Spatial::geometryToWkt():{line}}}");
            let p = Param::closure(&closure, 1, "p").array(p)?;
            Ok(format!("{} {}", coordinate(at(p, 0)), coordinate(at(p, 1))))
        };
        if let Some(Value::Array(_)) = at(first_item, 0) {
            const ARRAY_MAP: Param<'static> = Param::internal("array_map", 2, "array");
            let mut rings = Vec::with_capacity(geometry.len());
            for ring in geometry.values() {
                let points = ARRAY_MAP.array(ring)?.values().map(|p| point(30, p)).collect::<Result<Vec<_>>>()?;
                rings.push(format!("({})", points.join(", ")));
            }
            return Ok(format!("POLYGON({})", rings.join(", ")));
        }
        if geometry.len() == 1 {
            return Ok(format!("POINT({} {})", coordinate(at(first_item, 0)), coordinate(at(first_item, 1))));
        }
        let points = geometry.values().map(|p| point(47, p)).collect::<Result<Vec<_>>>()?;
        return Ok(format!("LINESTRING({})", points.join(", ")));
    }
    Ok(format!(
        "POINT({} {})",
        coordinate(first.or(Some(&Value::Int(0)))),
        coordinate(at(geometry, 1).or(Some(&Value::Int(0))))
    ))
}

/// `$this->geometryToWkt($values[0])` in `class`, called from `file` at
/// `line`: the WKT of a spatial predicate's geometry.
pub(crate) fn predicate_wkt(values: &Array, class: &str, file: &'static str, line: u32) -> Result<String> {
    let geometry = values.get(&ArrayKey::Int(0)).unwrap_or(&Value::Null);
    let function = format!("{class}::geometryToWkt");
    wkt(Param::method(&function, 1, "geometry", file, line).array(geometry)?, class)
}

/// `SpatialDistanceFilter::fromTuple($values[0])`, called from `file` at `line`.
pub(crate) fn distance_filter(values: &Array, file: &'static str, line: u32) -> Result<SpatialDistanceFilter> {
    let tuple = values.get(&ArrayKey::Int(0)).unwrap_or(&Value::Null);
    let param = Param::method("Utopia\\Query\\Builder\\SpatialDistanceFilter::fromTuple", 1, "tuple", file, line);
    SpatialDistanceFilter::from_tuple(param.array(tuple)?)
}

/// The WKT of a distance filter's geometry in `class` (a WKT string stays as is).
pub(crate) fn wkt_of(geometry: &Value, class: &str) -> Result<Value> {
    Ok(match geometry {
        Value::Array(a) => crate::value::string(wkt(a, class)?),
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
