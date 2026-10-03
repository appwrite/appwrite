use appwrite_locale::{GeoRecord, UNKNOWN_CODE};

#[test]
fn unknown_record_is_empty() {
    let record = GeoRecord::unknown();
    assert!(record.is_empty());
    assert_eq!(record.country_code(), UNKNOWN_CODE);
    assert_eq!(record.continent_code(), UNKNOWN_CODE);
    assert!(record.currency().is_none());
    assert!(!record.is_eu());
}

#[test]
fn default_matches_unknown() {
    assert_eq!(GeoRecord::default(), GeoRecord::unknown());
}

#[test]
fn new_upper_cases_country_code() {
    let record = GeoRecord::new("us", "United States", "North America", "NA");
    assert_eq!(record.country_code(), "US");
    assert!(!record.is_empty());
}

#[test]
fn builder_methods_set_eu_and_currency() {
    let record = GeoRecord::new("DE", "Germany", "Europe", "EU")
        .with_eu(true)
        .with_currency("EUR");
    assert!(record.is_eu());
    assert_eq!(record.currency(), Some("EUR"));
    assert_eq!(record.country_name(), "Germany");
    assert_eq!(record.continent_name(), "Europe");
}

#[test]
fn serde_round_trip() {
    let record = GeoRecord::new("GB", "United Kingdom", "Europe", "EU").with_eu(false);
    let json = serde_json::to_value(&record).unwrap();
    assert_eq!(json["countryCode"], "GB");
    let round_tripped: GeoRecord = serde_json::from_value(json).unwrap();
    assert_eq!(round_tripped, record);
}
