//! Port of `tests/unit/DNS/ZoneTest.php`.

mod common;

use common::{example_soa, rec, soa};
use utopia_dns::message::Record;
use utopia_dns::Zone;

#[test]
fn constructor_rejects_non_soa_record() {
    let not_soa = Record::new("example.com", Record::TYPE_A);
    let err = Zone::new("example.com", vec![], not_soa).unwrap_err();
    assert_eq!(
        err.to_string(),
        "SOA parameter must be a Record with TYPE_SOA"
    );
}

#[test]
fn constructor_requires_matching_soa_name() {
    let soa = soa(
        "other.com",
        "ns1.other.com hostmaster.other.com 1 7200 3600 1209600 300",
        3600,
    );
    let err = Zone::new("example.com", vec![], soa).unwrap_err();
    assert_eq!(
        err.to_string(),
        "SOA record name must match zone name: expected 'example.com', got 'other.com'"
    );
}

#[test]
fn constructor_rejects_soa_records_in_zone_data() {
    let records = vec![Record::new("example.com", Record::TYPE_SOA)];
    let err = Zone::new("example.com", records, example_soa()).unwrap_err();
    assert_eq!(
        err.to_string(),
        "SOA records should be passed as the $soa parameter, not in $records"
    );
}

#[test]
fn constructor_rejects_out_of_zone_record() {
    let records = vec![rec("other.com", Record::TYPE_A, 300, "1.1.1.1")];
    let err = Zone::new("example.com", records, example_soa()).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Record name 'other.com' does not belong to zone 'example.com'"
    );
}

#[test]
fn constructor_rejects_out_of_zone_wildcard_record() {
    let records = vec![rec("*.other.com", Record::TYPE_A, 300, "1.1.1.1")];
    let err = Zone::new("example.com", records, example_soa()).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Record name '*.other.com' does not belong to zone 'example.com'"
    );
}

#[test]
fn constructor_accepts_nested_wildcard_record() {
    let records = vec![
        rec("*.api.example.com", Record::TYPE_A, 120, "203.0.113.10"),
        rec(
            "origin.api.example.com",
            Record::TYPE_A,
            120,
            "203.0.113.20",
        ),
    ];
    let zone = Zone::new("example.com", records, example_soa()).unwrap();
    assert_eq!(zone.records.len(), 2);
}

#[test]
fn constructor_accepts_template_records() {
    let records = vec![
        rec("api.example.com", Record::TYPE_A, 120, "a.a.a.a"),
        rec("api.example.com", Record::TYPE_AAAA, 120, "b:b::b:b:b"),
    ];
    let zone = Zone::new("example.com", records, example_soa()).unwrap();
    assert_eq!(zone.records.len(), 2);
}
