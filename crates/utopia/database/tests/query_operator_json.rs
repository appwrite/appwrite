//! An operator among a query's values is written as `{}` by `toString()`:
//! `json_encode()` sees no public properties on an `Operator`
//! (`Operator.php` declares them protected); a document is written as its
//! attributes.

use utopia_database::value::{self, Value};
use utopia_database::{Document, Operator, Query};
use utopia_query::{Array, Method};

#[test]
fn an_operator_value_is_an_empty_object() {
    let values: Array =
        [value::to_query(&Value::from(Document::empty())), value::to_query(&Value::from(Operator::toggle()))]
            .into_iter()
            .collect();
    let query = Query::new(Method::Equal, "a", values, "");
    assert_eq!(query.to_json().unwrap(), r#"{"method":"equal","attribute":"a","values":[{},{}]}"#);
}
