//! What a filter's values must be (utopia-php/database 8.3.4): a list, a
//! distance tuple of a geometry, a finite distance and a boolean meters
//! flag, a spatial geometry of a valid shape, a vector as a list, and a
//! finite double.
//!
//! PHP: `tests/unit/Validator/Query/FilterMalformedValuesTest.php` and
//! `FilterGeometryTest.php` of utopia-php/database 8.3.6; lines cited are of
//! `src/Database/Validator/Query/Filter.php` and `Having.php` at 8.3.6.

mod support;

use serde_json::json;
use support::*;
use utopia_database::Query;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::having::Having;
use utopia_query::Method;

fn validator(support_for_attributes: bool) -> Filter {
    let attributes = attrs(json!([
        {"$id": "name", "key": "name", "type": "string", "array": false},
        {"$id": "tags", "key": "tags", "type": "string", "array": true},
        {"$id": "location", "key": "location", "type": "point", "array": false},
        {"$id": "embedding", "key": "embedding", "type": "vector", "array": false, "size": 2},
        {"$id": "price", "key": "price", "type": "double", "array": false},
    ]));
    Filter::with_options(&attributes, "integer", FilterOptions { support_for_attributes, ..FilterOptions::default() })
}

/// Filter.php:542-546: values given as an object are refused, whatever
/// the method.
#[test]
fn values_given_as_an_object_are_refused() {
    let v = validator(true);
    for json in [
        r#"{"method":"equal","attribute":"name","values":{"value":"a"}}"#,
        r#"{"method":"contains","attribute":"tags","values":{"tag":"a"}}"#,
        r#"{"method":"regex","attribute":"name","values":{"pattern":"^a"}}"#,
        r#"{"method":"between","attribute":"name","values":{"from":"a","to":"b"}}"#,
        r#"{"method":"exists","attribute":"","values":{"attribute":"name"}}"#,
        r#"{"method":"intersects","attribute":"location","values":{"type":"Point","coordinates":[1,2]}}"#,
        r#"{"method":"distanceLessThan","attribute":"location","values":{"geometry":[[1,2],10,false]}}"#,
        r#"{"method":"vectorDot","attribute":"embedding","values":{"vector":[1,2]}}"#,
    ] {
        let query = Query::parse(json, false).unwrap();
        assert!(!v.check(&query), "{json}");
        let method = query.method().as_str();
        let expected = format!("{}{} query values must be a list", method[..1].to_uppercase(), &method[1..]);
        assert_eq!(v.description(), expected);
    }
}

/// Filter.php:457-490: a distance tuple is a list of a geometry, a finite
/// distance and a boolean meters flag.
#[test]
fn a_distance_tuple_is_a_list_of_geometry_distance_and_meters() {
    let v = validator(true);
    let object = Query::parse(
        r#"{"method":"distanceLessThan","attribute":"location","values":[{"geometry":[1,2],"distance":10,"meters":false}]}"#,
        false,
    )
    .unwrap();
    assert!(!v.check(&object));
    assert_eq!(v.description(), "Distance query requires [[geometry, distance]] parameters");

    assert!(!v.check(&q(Method::DistanceLessThan, "location", json!([[[1, 2], 10, ["yes"]]]))));
    assert_eq!(v.description(), "Distance query requires a boolean meters flag");

    assert!(!v.check(&q(Method::DistanceLessThan, "location", json!([[[1, 200], 10, false]]))));
    assert_eq!(
        v.description(),
        "Distance query requires a geometry as coordinates or WKT: Value must be a valid point: Latitude (y) must be between -90 and 90, got 200"
    );

    assert!(!v.check(&q(Method::DistanceLessThan, "location", json!([["POINT(1 2", 10, false]]))));
    assert_eq!(
        v.description(),
        "Distance query requires a geometry as coordinates or WKT: Value must be a valid point: WKT must be a well-formed POINT, LINESTRING or POLYGON"
    );

    assert!(v.check(&q(Method::DistanceLessThan, "location", json!([[[1, 2], 10, true]]))), "{}", v.description());
    assert!(
        v.check(&q(Method::DistanceLessThan, "location", json!([["POINT(1 2)", 10, false]]))),
        "{}",
        v.description()
    );
}

/// Filter.php:332-336: a spatial filter's geometry is read in the shape the
/// builders read it and held to the rules of a stored value of that shape.
#[test]
fn a_spatial_filter_geometry_is_validated() {
    let v = validator(true);
    assert!(!v.check(&q(Method::Intersects, "location", json!([[1, 100]]))));
    assert_eq!(
        v.description(),
        "Invalid spatial data for attribute \"location\": Value must be a valid point: Latitude (y) must be between -90 and 90, got 100"
    );
    assert!(!v.check(&q(Method::Intersects, "location", json!([[[[0, 0], [1, 1]]]]))));
    assert_eq!(
        v.description(),
        "Invalid spatial data for attribute \"location\": Value must be a valid polygon: Ring #0 must contain at least 4 points to form a closed polygon"
    );
    assert!(!v.check(&q(Method::Intersects, "location", json!([["a", "b"]]))));
    assert_eq!(
        v.description(),
        "Invalid spatial data for attribute \"location\": Value must be a valid point: Point coordinates must be numeric values"
    );
    assert!(v.check(&q(Method::Intersects, "location", json!([[1, 2]]))), "{}", v.description());
    assert!(v.check(&q(Method::Intersects, "location", json!([[[1, 2], [3, 4]]]))), "{}", v.description());
}

/// Filter.php:341: a vector given as an object is refused; Filter.php:642
/// reads an undeclared attribute's schema as empty.
#[test]
fn a_vector_is_a_list() {
    let v = validator(true);
    assert!(!v.check(&q(Method::VectorDot, "embedding", json!([{"x": 1.0, "y": 2.0}]))));
    assert_eq!(v.description(), "Vector query value must be an array");
    assert!(v.check(&q(Method::VectorDot, "embedding", json!([[1.0, 2.0]]))), "{}", v.description());

    let schemaless = validator(false);
    assert!(!schemaless.check(&q(Method::VectorDot, "undeclared", json!([[1.0, 2.0]]))));
    assert_eq!(schemaless.description(), "Vector queries can only be used on vector attributes");
}

/// Filter.php:266 and Having.php:199: a double filter or having value is
/// finite.
#[test]
fn double_values_are_finite() {
    let v = validator(true);
    let infinite = Query::new(Method::GreaterThan, "price", list_of(utopia_query::Value::Float(f64::INFINITY)), "");
    assert!(!v.check(&infinite));
    assert_eq!(v.description(), "Query value is invalid for attribute \"price\"");
    assert!(v.check(&Query::greater_than("price", 1.5)), "{}", v.description());

    let having = Having::default();
    having.set_filter(Some(std::sync::Arc::new(validator(true))));
    let total = Query::sum("price", "total");
    having.set_aggregations(&[&total]);
    let nan = Query::new(Method::GreaterThan, "total", list_of(utopia_query::Value::Float(f64::NAN)), "");
    assert!(!having.check(&Query::having([nan])));
    assert_eq!(having.description(), "Query value is invalid for aggregate alias \"total\"");
}

fn list_of(value: utopia_query::Value) -> utopia_query::Array {
    let mut array = utopia_query::Array::new();
    array.push(value).unwrap();
    array
}
