//! [`Spatial`]: values of point, linestring and polygon attributes.
//!
//! PHP: `Utopia\Database\Validator\Spatial`.

use crate::validator::{Message, is_numeric, nth};
use crate::value::{Array, Str, Value};
use utopia_query::schema::ColumnType;

/// A geometry of one spatial type: `null`, a WKT string (`POINT(...)`,
/// `LINESTRING(...)`, `POLYGON(...)`), or coordinates as arrays: a point
/// `[x, y]`, a linestring of at least two points, a polygon of closed rings
/// of at least four points (or one such ring). Longitudes are within
/// ±180, latitudes within ±90.
///
/// ```
/// use utopia_database::validator::spatial::Spatial;
/// use utopia_database::value::{list, Value};
///
/// let point = Spatial::new("point");
/// assert!(point.is_valid(&list([Value::Float(10.0), Value::Float(20.0)])));
/// assert!(!point.is_valid(&list([Value::Float(200.0), Value::Float(20.0)])));
/// assert_eq!(point.description(), "Value must be a valid point: Longitude (x) must be between -180 and 180, got 200");
/// ```
///
/// PHP: `Utopia\Database\Validator\Spatial` (`new Spatial($spatialType)`).
#[derive(Clone, Debug)]
pub struct Spatial {
    spatial_type: String,
    message: Message,
}

/// Text of a float as PHP interpolates it.
fn float_text(f: f64) -> String {
    php_std::number::to_string(f)
}

/// `is_array($value) && count($value) === 2 && array_is_list($value)`.
fn is_pair(value: &Value) -> bool {
    matches!(value, Value::Array(a) if a.len() == 2 && a.is_list())
}

/// `parseWktPoint($text)`: two numeric tokens between spaces, tabs and line
/// breaks, as floats.
fn wkt_point(text: &[u8]) -> Option<Value> {
    let mut tokens = text.split(|c| matches!(c, b' ' | b'\t' | b'\n' | b'\r')).filter(|t| !t.is_empty());
    let (x, y) = (tokens.next()?, tokens.next()?);
    if tokens.next().is_some() {
        return None;
    }
    let number = |token: &[u8]| {
        php_std::value::numeric_bytes_ex(token)?;
        Some(Value::Float(Value::Str(Str::from(token)).to_double()))
    };
    Some(crate::value::list([number(x)?, number(y)?]))
}

/// `parseWktPoints($text)`: points between commas.
fn wkt_points(text: &[u8]) -> Option<Value> {
    let points = text.split(|&c| c == b',').map(wkt_point).collect::<Option<Vec<_>>>()?;
    Some(crate::value::list(points))
}

/// `parseWktRings($text)`: parenthesized lists of points between commas.
fn wkt_rings(text: &[u8]) -> Option<Value> {
    let trim = |t: &[u8]| -> Vec<u8> { php_std::string::trim(t, php_std::string::TRIM_CHARACTERS).to_vec() };
    let mut rings = Vec::new();
    let mut rest = trim(text);
    while !rest.is_empty() {
        if !rest.starts_with(b"(") {
            return None;
        }
        let close = rest.iter().position(|&c| c == b')')?;
        rings.push(wkt_points(&rest[1..close])?);
        rest = trim(&rest[close + 1..]);
        if rest.is_empty() {
            break;
        }
        if !rest.starts_with(b",") {
            return None;
        }
        rest = trim(&rest[1..]);
        if rest.is_empty() {
            return None;
        }
    }
    if rings.is_empty() { None } else { Some(crate::value::list(rings)) }
}

impl Spatial {
    /// `new Spatial($spatialType)`.
    pub fn new(spatial_type: impl Into<String>) -> Self {
        Self { spatial_type: spatial_type.into(), message: Message::new("") }
    }

    /// `getSpatialType()`.
    pub fn spatial_type(&self) -> &str {
        &self.spatial_type
    }

    /// `getDescription()`: `Value must be a valid <type>: <last failure>`.
    pub fn description(&self) -> String {
        format!("Value must be a valid {}: {}", self.spatial_type, self.message.get())
    }

    /// `Spatial::isWktString($value)`: whether the value is well-formed WKT
    /// of a point, linestring or polygon ([`Spatial::parse_wkt`]); its
    /// coordinates are not checked.
    pub fn is_wkt_string(value: &[u8]) -> bool {
        Self::parse_wkt(value).is_some()
    }

    /// `Spatial::parseWkt($value)`: the type and coordinates WKT names, as a
    /// stored value of that type holds them (`[x, y]` for a point, a list of
    /// points for a linestring, a list of rings for a polygon), each
    /// coordinate a float. `None` for anything that is not well-formed WKT of
    /// a point, linestring or polygon.
    ///
    /// ```
    /// use utopia_database::validator::spatial::Spatial;
    /// use utopia_query::schema::ColumnType;
    ///
    /// let (kind, _) = Spatial::parse_wkt(b" point(1 2) ").unwrap();
    /// assert_eq!(kind, ColumnType::Point);
    /// assert!(Spatial::parse_wkt(b"POINT(1)").is_none());
    /// ```
    pub fn parse_wkt(value: &[u8]) -> Option<(ColumnType, Value)> {
        let value = php_std::string::trim(value, php_std::string::TRIM_CHARACTERS);
        let open = value.iter().position(|&c| c == b'(')?;
        if !value.ends_with(b")") {
            return None;
        }
        let name = php_std::string::trim(&value[..open], php_std::string::TRIM_CHARACTERS).to_ascii_uppercase();
        let kind = match name.as_slice() {
            b"POINT" => ColumnType::Point,
            b"LINESTRING" => ColumnType::Linestring,
            b"POLYGON" => ColumnType::Polygon,
            _ => return None,
        };
        let body = value.get(open + 1..value.len() - 1).unwrap_or(&[]);
        let coordinates = match kind {
            ColumnType::Point => wkt_point(body)?,
            ColumnType::Linestring => wkt_points(body)?,
            _ => wkt_rings(body)?,
        };
        Some((kind, coordinates))
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let parsed;
        let value = match value {
            Value::Null => return true,
            Value::Str(s) => {
                let Some((kind, coordinates)) = Self::parse_wkt(s.as_bytes()) else {
                    self.message.set("WKT must be a well-formed POINT, LINESTRING or POLYGON");
                    return false;
                };
                if kind.as_str() != self.spatial_type {
                    self.message.set(format!(
                        "WKT must be a {}, got a {}",
                        self.spatial_type.to_ascii_uppercase(),
                        kind.as_str().to_ascii_uppercase()
                    ));
                    return false;
                }
                parsed = coordinates;
                &parsed
            }
            other => other,
        };
        match value {
            Value::Array(a) => match self.check(a) {
                Ok(()) => true,
                Err(message) => {
                    self.message.set(message);
                    false
                }
            },
            _ => {
                self.message.set("Spatial value must be array or WKT string");
                false
            }
        }
    }

    fn check(&self, value: &Array) -> Result<(), String> {
        match ColumnType::try_from_value(&self.spatial_type) {
            Some(ColumnType::Point) => point(value),
            Some(ColumnType::Linestring) => linestring(value),
            Some(ColumnType::Polygon) => polygon(value),
            _ => Err(format!("Unknown spatial type: {}", self.spatial_type)),
        }
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }
}

/// `isValidCoordinate((float) $x, (float) $y)`: finite (8.3.4), then in
/// range.
#[allow(clippy::manual_range_contains)]
fn coordinate(x: &Value, y: &Value) -> Result<(), String> {
    let (x, y) = (x.to_double(), y.to_double());
    if !x.is_finite() || !y.is_finite() {
        return Err("Coordinates must be finite numbers".to_owned());
    }
    if x < -180.0 || x > 180.0 {
        return Err(format!("Longitude (x) must be between -180 and 180, got {}", float_text(x)));
    }
    if y < -90.0 || y > 90.0 {
        return Err(format!("Latitude (y) must be between -90 and 90, got {}", float_text(y)));
    }
    Ok(())
}

fn key_text(k: php_std::types::KeyRef<'_>) -> String {
    match k {
        php_std::types::KeyRef::Int(i) => i.to_string(),
        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

fn point(value: &Array) -> Result<(), String> {
    if value.len() != 2 || !value.is_list() {
        return Err("Point must be an array of two numeric values [x, y]".to_owned());
    }
    let v = Value::Array(value.clone());
    let (x, y) = (nth(&v, 0), nth(&v, 1));
    if !is_numeric(x) || !is_numeric(y) {
        return Err("Point coordinates must be numeric values".to_owned());
    }
    coordinate(x, y)
}

fn linestring(value: &Array) -> Result<(), String> {
    if value.len() < 2 {
        return Err("LineString must contain at least two points".to_owned());
    }
    for (index, point) in value.iter() {
        if !is_pair(point) {
            return Err("Each point in LineString must be an array of two values [x, y]".to_owned());
        }
        let (x, y) = (nth(point, 0), nth(point, 1));
        if !is_numeric(x) || !is_numeric(y) {
            return Err("Each point in LineString must have numeric coordinates".to_owned());
        }
        if let Err(m) = coordinate(x, y) {
            return Err(format!("Invalid coordinates at point #{}: {m}", key_text(index)));
        }
    }
    Ok(())
}

fn polygon(value: &Array) -> Result<(), String> {
    if value.is_empty() {
        return Err("Polygon must contain at least one ring".to_owned());
    }
    let v = Value::Array(value.clone());
    let first = nth(&v, 0);
    let single_ring = is_pair(first) && is_numeric(nth(first, 0)) && is_numeric(nth(first, 1));
    let rings = if single_ring {
        let mut wrapped = Array::new();
        let _ = wrapped.push(v.clone());
        wrapped
    } else {
        value.clone()
    };
    for (ring_index, ring) in rings.iter() {
        let ring_index = key_text(ring_index);
        let Value::Array(points) = ring else {
            return Err(format!("Ring #{ring_index} must be an array of points"));
        };
        if points.is_empty() || !points.is_list() {
            return Err(format!("Ring #{ring_index} must be an array of points"));
        }
        if points.len() < 4 {
            return Err(format!("Ring #{ring_index} must contain at least 4 points to form a closed polygon"));
        }
        for (point_index, point) in points.iter() {
            let point_index = key_text(point_index);
            if !is_pair(point) {
                return Err(format!(
                    "Point #{point_index} in ring #{ring_index} must be an array of two values [x, y]"
                ));
            }
            let (x, y) = (nth(point, 0), nth(point, 1));
            if !is_numeric(x) || !is_numeric(y) {
                return Err(format!("Coordinates of point #{point_index} in ring #{ring_index} must be numeric"));
            }
            if let Err(m) = coordinate(x, y) {
                return Err(format!("Invalid coordinates at point #{point_index} in ring #{ring_index}: {m}"));
            }
        }
        let first = nth(ring, 0);
        let last = nth(ring, points.len() as i64 - 1);
        if !first.strict_eq(last) {
            return Err(format!("Ring #{ring_index} must be closed (first point must equal last point)"));
        }
    }
    Ok(())
}

super::validator!(Spatial, utopia_validators::Type::Array, false);

#[cfg(test)]
mod tests {
    use super::*;
    use crate::value::{list, string};

    fn pair(x: f64, y: f64) -> Value {
        list([Value::Float(x), Value::Float(y)])
    }

    fn keyed(entries: &[(&str, f64)]) -> Value {
        let mut array = Array::new();
        for (key, value) in entries {
            array.set(crate::value::ArrayKey::normalize(Str::from(*key)), Value::Float(*value));
        }
        Value::Array(array)
    }

    /// PHP 8.3.6 Validator/Spatial.php:29, 58, 103, 116: a coordinate pair,
    /// a ring and a point of a ring are lists, never objects.
    #[test]
    fn refuses_coordinates_given_as_objects() {
        let point = Spatial::new("point");
        assert!(!point.is_valid(&keyed(&[("x", 1.0), ("y", 2.0)])));
        assert_eq!(
            point.description(),
            "Value must be a valid point: Point must be an array of two numeric values [x, y]"
        );
        let line = Spatial::new("linestring");
        assert!(!line.is_valid(&list([pair(1.0, 2.0), keyed(&[("x", 1.0), ("y", 2.0)])])));
        assert_eq!(
            line.description(),
            "Value must be a valid linestring: Each point in LineString must be an array of two values [x, y]"
        );
    }

    /// Spatial.php:340: a coordinate that is infinite or NaN is refused
    /// before its range is checked.
    #[test]
    fn refuses_coordinates_that_are_not_finite() {
        let point = Spatial::new("point");
        assert!(!point.is_valid(&pair(f64::NAN, 2.0)));
        assert_eq!(point.description(), "Value must be a valid point: Coordinates must be finite numbers");
    }

    /// Spatial.php:161-189: WKT parses into the coordinates of its type,
    /// every number a float.
    #[test]
    fn parses_wkt_into_coordinates() {
        let (kind, value) = Spatial::parse_wkt(b"LINESTRING(1 2, 3\t4)").unwrap();
        assert_eq!(kind, ColumnType::Linestring);
        assert!(value.strict_eq(&list([pair(1.0, 2.0), pair(3.0, 4.0)])));
        let (kind, value) = Spatial::parse_wkt(b"POLYGON((0 0, 1 0, 1 1, 0 0), (0 0, 1 1, 0 0))").unwrap();
        assert_eq!(kind, ColumnType::Polygon);
        assert!(matches!(&value, Value::Array(rings) if rings.len() == 2));
        for malformed in [&b"POINT(1 2 3)"[..], b"POINT 1 2", b"CIRCLE(1 2)", b"POLYGON((0 0, 1 1),)", b"POLYGON()"] {
            assert!(Spatial::parse_wkt(malformed).is_none(), "{}", String::from_utf8_lossy(malformed));
        }
    }

    /// Spatial.php:298-312: WKT is validated as the coordinates it names,
    /// and must be of the validator's type.
    #[test]
    fn validates_wkt_as_its_coordinates() {
        let point = Spatial::new("point");
        assert!(point.is_valid(&string("POINT(10 20)")));
        assert!(!point.is_valid(&string("POINT(10 100)")));
        assert_eq!(
            point.description(),
            "Value must be a valid point: Latitude (y) must be between -90 and 90, got 100"
        );
        assert!(!point.is_valid(&string("LINESTRING(1 2, 3 4)")));
        assert_eq!(point.description(), "Value must be a valid point: WKT must be a POINT, got a LINESTRING");
        assert!(!point.is_valid(&string("POINT(a b)")));
        assert_eq!(
            point.description(),
            "Value must be a valid point: WKT must be a well-formed POINT, LINESTRING or POLYGON"
        );
    }
}
