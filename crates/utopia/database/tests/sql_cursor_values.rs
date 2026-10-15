//! A cursor value the order compares against must be a scalar: PHP builds
//! the comparison with `Query::greaterThan()` / `lessThan()`, typed
//! `string|int|float|bool`, so a missing (null) or list value is a
//! `TypeError` from the call at `Adapter/SQL.php` line 5467 / 5469.

#![cfg(feature = "sqlite")]

use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::adapter::{Adapter, Find};
use utopia_database::value::{Array, ArrayKey, Value};
use utopia_database::{Collection, Kind};
use utopia_query::OrderDirection;

async fn find(cursor: Value, order: OrderDirection) -> utopia_database::Error {
    let adapter = Sql::connect(Engine::Sqlite, "sqlite::memory:", None, None).await.unwrap();
    let collection = Collection::create("f", "f", vec![], vec![], None, true, Array::new()).unwrap();
    let mut values = Array::new();
    values.set(ArrayKey::from("title"), cursor);
    let options = Find {
        order_attributes: vec!["title".to_owned()],
        order_types: vec![order],
        cursor: values,
        ..Find::default()
    };
    adapter.find(&collection, vec![], options).await.unwrap_err()
}

#[utopia_async::test]
async fn a_null_or_list_cursor_value_is_a_type_error() {
    let error = find(Value::Null, OrderDirection::Asc).await;
    assert_eq!(error.kind(), Kind::TypeError);
    assert_eq!(
        error.message(),
        "Utopia\\Query\\Query::greaterThan(): Argument #2 ($value) must be of type string|int|float|bool, null given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/SQL.php on line 5469"
    );
    let error = find(Value::Array(Array::from_list(vec![Value::Int(1)])), OrderDirection::Desc).await;
    assert_eq!(
        error.message(),
        "Utopia\\Query\\Query::lessThan(): Argument #2 ($value) must be of type string|int|float|bool, array given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/SQL.php on line 5467"
    );
}
