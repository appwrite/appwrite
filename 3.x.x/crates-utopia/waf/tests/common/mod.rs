use serde_json::{Map, Value};

pub fn attrs(value: Value) -> Map<String, Value> {
    value
        .as_object()
        .cloned()
        .expect("test helper expects a JSON object")
}
