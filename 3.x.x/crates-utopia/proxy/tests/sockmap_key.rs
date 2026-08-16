//! Cross-check `sockmap::tuple::tuple_key` against a fixture shared with PHP.
//!
//! The same JSON file is loaded by `tests/Unit/SockmapKeyTest.php` so both
//! implementations stay byte-for-byte compatible.

use std::fs;

use serde_json::Value;
use utopia_proxy::sockmap::tuple::tuple_key;

const FIXTURE: &str = include_str!("fixtures/sockmap_keys.json");

#[test]
fn fixture_parses() {
    let parsed: Value = serde_json::from_str(FIXTURE).unwrap();
    assert!(parsed.as_array().unwrap().len() >= 4);
}

#[test]
fn fixture_file_exists_on_disk() {
    // Sanity: the PHP test reads from disk, ours embeds via include_str!. Both
    // must resolve to the same bytes.
    let path = concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/tests/fixtures/sockmap_keys.json"
    );
    let on_disk = fs::read_to_string(path).unwrap();
    assert_eq!(on_disk, FIXTURE);
}

#[test]
fn tuple_key_matches_php_fixture() {
    let cases: Value = serde_json::from_str(FIXTURE).unwrap();
    for case in cases.as_array().unwrap() {
        let name = case["name"].as_str().unwrap();
        let local_port = case["local_port"].as_u64().unwrap() as u16;
        let rport: Vec<u8> = case["remote_port_be"]
            .as_array()
            .unwrap()
            .iter()
            .map(|v| v.as_u64().unwrap() as u8)
            .collect();
        let rip: Vec<u8> = case["remote_ip_be"]
            .as_array()
            .unwrap()
            .iter()
            .map(|v| v.as_u64().unwrap() as u8)
            .collect();
        let expected_be: Vec<u8> = case["expected_be"]
            .as_array()
            .unwrap()
            .iter()
            .map(|v| v.as_u64().unwrap() as u8)
            .collect();

        let rport: [u8; 2] = rport.try_into().expect("remote_port_be must be 2 bytes");
        let rip: [u8; 4] = rip.try_into().expect("remote_ip_be must be 4 bytes");
        let expected_be: [u8; 8] = expected_be.try_into().expect("expected_be must be 8 bytes");

        let key = tuple_key(local_port, rport, rip);
        let got_be = key.to_be_bytes();
        assert_eq!(got_be, expected_be, "case {name}: key mismatch");
    }
}
