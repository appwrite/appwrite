use utopia_domains::sync::{encode_psl_json, parse_psl_dat, SyncError};

const SAMPLE: &str = r"
// license header
// ===BEGIN ICANN DOMAINS===
// ac : http://en.wikipedia.org/wiki/.ac
ac
com.ac
com
// ===END ICANN DOMAINS===

// ===BEGIN PRIVATE DOMAINS===
github.io
// ===END PRIVATE DOMAINS===
";

#[test]
fn parse_psl_dat_extracts_icann_and_private() {
    let entries = parse_psl_dat(SAMPLE).unwrap();
    assert_eq!(
        entries,
        vec![
            ("ac".into(), "ICANN".into()),
            ("com.ac".into(), "ICANN".into()),
            ("com".into(), "ICANN".into()),
            ("github.io".into(), "PRIVATE".into()),
        ]
    );
}

#[test]
fn parse_psl_dat_requires_com() {
    let err = parse_psl_dat("// ===BEGIN ICANN DOMAINS===\nnet\n").unwrap_err();
    assert!(matches!(err, SyncError::CorruptPsl));
}

#[test]
fn encode_psl_json_preserves_order() {
    let json = encode_psl_json(&[
        ("ac".into(), "ICANN".into()),
        ("com".into(), "ICANN".into()),
    ])
    .unwrap();
    assert_eq!(json, r#"{"ac":"ICANN","com":"ICANN"}"#);
}
