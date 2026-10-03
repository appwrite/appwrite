use serde_json::json;
use utopia_auth::Store;

#[test]
fn store_encode_decode_roundtrip() {
    let mut store = Store::new();
    let data = [
        ("name", json!("John Doe")),
        ("age", json!(30)),
        ("active", json!(true)),
        ("scores", json!([95, 87, 92])),
        ("details", json!({"city": "New York", "country": "USA"})),
    ];

    for (key, value) in &data {
        store.set_property(*key, value.clone());
    }
    store.set_key(Some("test-key"));

    let encoded = store.encode().expect("encode should succeed");

    let mut decoded = Store::new();
    decoded.decode(&encoded);

    for (key, value) in &data {
        assert_eq!(decoded.get_property(key), Some(value));
    }
}

#[test]
fn store_decode_invalid_data_is_ignored() {
    let mut store = Store::new();
    store.set_property("existing", json!("value"));
    store.decode("invalid-base64");
    assert_eq!(store.get_property("existing"), Some(&json!("value")));
}
