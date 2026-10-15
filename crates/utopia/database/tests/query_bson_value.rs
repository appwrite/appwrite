//! A BSON value survives a query's values: `Database::convertQuery()` sets
//! a datetime query's values to the adapter's `castDatetime()`, a MongoDB
//! `UTCDateTime`, and the MongoDB adapter compares against that object
//! (`Adapter/Mongo.php` `castDatetime()`), not its properties as an array.

use utopia_database::value::{self, BsonValue, Value};

#[test]
fn a_bson_value_round_trips_through_a_query_value() {
    for bson in [
        BsonValue::UtcDateTime(1_735_117_221_891),
        BsonValue::UtcDateTime(-5),
        BsonValue::ObjectId([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]),
        // `MongoDB\BSON\Int64`, the object the extension decodes an int64 to.
        BsonValue::Int64(9),
        BsonValue::Regex { pattern: "^a".into(), flags: "i".into() },
    ] {
        let value = Value::from(bson);
        assert_eq!(value::from_query(&value::to_query(&value)), value);
    }
}
