//! [`Geometry`]: the geometry a spatial query compares against.
//!
//! PHP: `Utopia\Database\Validator\Geometry` (8.3.4).

use utopia_query::schema::ColumnType;

use crate::validator::Message;
use crate::validator::spatial::Spatial;
use crate::value::{ArrayKey, Value};

const DEFAULT_MESSAGE: &str = "Geometry must be a point [x, y], a list of points or a list of rings of points";

/// The geometry of a spatial filter, read in the shape the builders read it:
/// `[x, y]` and `[[x, y]]` are a point, a deeper list of points a
/// linestring, and a list of rings a polygon. Each is held to the rules a
/// stored value of its type is ([`Spatial`]).
///
/// ```
/// use utopia_database::validator::geometry::Geometry;
/// use utopia_database::value::{list, Value};
///
/// let geometry = Geometry::new();
/// assert!(geometry.is_valid(&list([Value::Float(10.0), Value::Float(20.0)])));
/// assert!(!geometry.is_valid(&list([Value::Float(10.0), Value::Float(100.0)])));
/// assert_eq!(geometry.description(), "Value must be a valid point: Latitude (y) must be between -90 and 90, got 100");
/// ```
///
/// PHP: `Utopia\Database\Validator\Geometry` (`new Geometry()`).
#[derive(Clone, Debug)]
pub struct Geometry {
    message: Message,
}

impl Default for Geometry {
    fn default() -> Self {
        Self::new()
    }
}

impl Geometry {
    /// `new Geometry()`.
    pub fn new() -> Self {
        Self { message: Message::new(DEFAULT_MESSAGE) }
    }

    /// `getDescription()`: the last failure, as the type's [`Spatial`]
    /// describes it, or what a geometry must be.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let Value::Array(array) = value else { return false };
        if array.is_empty() || !array.is_list() {
            return false;
        }
        let first = array.get(&ArrayKey::Int(0)).unwrap_or(&Value::Null);
        let (kind, geometry) = match first {
            Value::Array(points) => {
                if matches!(points.get(&ArrayKey::Int(0)), Some(Value::Array(_))) {
                    (ColumnType::Polygon, value)
                } else if array.len() == 1 {
                    (ColumnType::Point, first)
                } else {
                    (ColumnType::Linestring, value)
                }
            }
            _ => (ColumnType::Point, value),
        };
        let spatial = Spatial::new(kind.as_str());
        if !spatial.is_valid(geometry) {
            self.message.set(spatial.description());
            return false;
        }
        true
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }
}

super::validator!(Geometry, utopia_validators::Type::Array, false);

#[cfg(test)]
mod tests {
    use super::*;
    use crate::value::{list, string};

    fn point(x: f64, y: f64) -> Value {
        list([Value::Float(x), Value::Float(y)])
    }

    /// PHP 8.3.6 Validator/Geometry.php:33-38: a flat pair and a list of one
    /// pair are points, a deeper list of pairs a linestring, a list of rings a
    /// polygon.
    #[test]
    fn reads_the_shape_the_builders_read() {
        let geometry = Geometry::new();
        assert!(geometry.is_valid(&point(1.0, 2.0)));
        assert!(geometry.is_valid(&list([point(1.0, 2.0)])));
        assert!(geometry.is_valid(&list([point(1.0, 2.0), point(3.0, 4.0)])));
        let ring = list([point(0.0, 0.0), point(1.0, 0.0), point(1.0, 1.0), point(0.0, 0.0)]);
        assert!(geometry.is_valid(&list([ring])));
    }

    /// Geometry.php:28: anything but a non-empty list fails with the
    /// default description.
    #[test]
    fn refuses_what_is_not_a_list() {
        let geometry = Geometry::new();
        assert!(!geometry.is_valid(&string("POINT(1 2)")));
        assert!(!geometry.is_valid(&list([])));
        assert_eq!(geometry.description(), DEFAULT_MESSAGE);
    }

    /// Geometry.php:41-44: a geometry of its shape failing as a stored value
    /// of that type reports that type's description.
    #[test]
    fn describes_the_type_it_failed_as() {
        let geometry = Geometry::new();
        let ring = list([point(0.0, 0.0), point(1.0, 0.0), point(0.0, 0.0)]);
        assert!(!geometry.is_valid(&list([ring])));
        assert_eq!(
            geometry.description(),
            "Value must be a valid polygon: Ring #0 must contain at least 4 points to form a closed polygon"
        );
    }
}
