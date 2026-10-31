//! [`Spatial`]: values of point, linestring and polygon attributes.
//!
//! PHP: `Utopia\Database\Validator\Spatial`.

use crate::database::Database;
use crate::validator::{Message, is_numeric, nth};
use crate::value::{Array, Value};

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

fn count(value: &Value) -> Option<usize> {
    match value {
        Value::Array(a) => Some(a.len()),
        _ => None,
    }
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

    /// `Spatial::isWKTString($value)`: trimmed, starts with `POINT`,
    /// `LINESTRING` or `POLYGON` (any case), optional whitespace, then `(`.
    pub fn is_wkt_string(value: &[u8]) -> bool {
        let value = php_std::string::trim(value, php_std::string::TRIM_CHARACTERS);
        for keyword in [&b"POINT"[..], b"LINESTRING", b"POLYGON"] {
            if value.len() >= keyword.len() && value[..keyword.len()].eq_ignore_ascii_case(keyword) {
                let rest = &value[keyword.len()..];
                let rest = &rest
                    [rest.iter().take_while(|c| matches!(c, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')).count()..];
                if rest.first() == Some(&b'(') {
                    return true;
                }
            }
        }
        false
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Null => true,
            Value::Str(s) => Self::is_wkt_string(s.as_bytes()),
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
        match self.spatial_type.as_str() {
            Database::VAR_POINT => point(value),
            Database::VAR_LINESTRING => linestring(value),
            Database::VAR_POLYGON => polygon(value),
            other => Err(format!("Unknown spatial type: {other}")),
        }
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }
}

/// `isValidCoordinate((float) $x, (float) $y)`. NaN passes, as in PHP
/// (hence comparisons, not `contains`).
#[allow(clippy::manual_range_contains)]
fn coordinate(x: &Value, y: &Value) -> Result<(), String> {
    let (x, y) = (x.to_double(), y.to_double());
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
    if value.len() != 2 {
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
        if count(point) != Some(2) {
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
    let single_ring = count(first) == Some(2) && is_numeric(nth(first, 0)) && is_numeric(nth(first, 1));
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
        if points.is_empty() {
            return Err(format!("Ring #{ring_index} must be an array of points"));
        }
        if points.len() < 4 {
            return Err(format!("Ring #{ring_index} must contain at least 4 points to form a closed polygon"));
        }
        for (point_index, point) in points.iter() {
            let point_index = key_text(point_index);
            if count(point) != Some(2) {
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
