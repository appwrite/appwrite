use std::collections::HashMap;
use std::path::PathBuf;

use tempfile::tempdir;
use utopia_emails::sync::{
    commit_update, is_valid_email_domain, load_json_list, merge_sources, parse_hash_comment_lines,
    parse_json_string_array, parse_whitespace_tokens, plan_update, write_json_list, Fetcher,
    ListKind, Source, SourceKind, SyncError,
};

struct MapFetcher(HashMap<String, Result<String, String>>);

impl Fetcher for MapFetcher {
    fn fetch_text(&self, url: &str) -> Result<String, SyncError> {
        match self.0.get(url) {
            Some(Ok(body)) => Ok(body.clone()),
            Some(Err(err)) => Err(SyncError::Network(err.clone())),
            None => Err(SyncError::Network(format!("missing {url}"))),
        }
    }
}

#[test]
fn parse_hash_comment_lines_skips_comments() {
    let body = "# header\nmailinator.com\n\n# skip\ntemp-mail.org\n";
    let domains = parse_hash_comment_lines(body);
    assert!(domains.contains(&"mailinator.com".to_string()));
    assert!(domains.contains(&"temp-mail.org".to_string()));
}

#[test]
fn parse_whitespace_tokens_splits() {
    let body = "mailinator.com  yopmail.com\nbogus";
    let domains = parse_whitespace_tokens(body);
    assert!(domains.contains(&"mailinator.com".to_string()));
    assert!(domains.contains(&"yopmail.com".to_string()));
}

#[test]
fn parse_json_array() {
    let domains = parse_json_string_array(r#"["gmail.com","yahoo.com"]"#).unwrap();
    assert_eq!(domains, vec!["gmail.com", "yahoo.com"]);
}

#[test]
fn is_valid_email_domain_rejects_test_tlds() {
    assert!(is_valid_email_domain("gmail.com"));
    assert!(!is_valid_email_domain(""));
    assert!(!is_valid_email_domain("example.test"));
}

#[test]
fn write_and_load_roundtrip() {
    let dir = tempdir().unwrap();
    let path = dir.path().join("list.json");
    write_json_list(&path, &["zeta.com".into(), "alpha.com".into()]).unwrap();
    let loaded = load_json_list(&path).unwrap();
    assert_eq!(loaded, vec!["alpha.com", "zeta.com"]);
}

#[test]
fn merge_sources_uniques_and_continues_on_error() {
    let dir = tempdir().unwrap();
    std::fs::write(
        dir.path().join("disposable-domains-manual.json"),
        r#"["manual.com"]"#,
    )
    .unwrap();
    let mut map = HashMap::new();
    map.insert(
        "https://example.test/list".into(),
        Ok("# c\nmailinator.com\nmanual.com\n".into()),
    );
    map.insert("https://example.test/fail".into(), Err("boom".into()));
    let fetcher = MapFetcher(map);
    let sources = [
        Source {
            key: "manual",
            name: "Manual",
            url: None,
            kind: SourceKind::Manual,
        },
        Source {
            key: "ok",
            name: "Ok",
            url: Some("https://example.test/list"),
            kind: SourceKind::HashCommentLines,
        },
        Source {
            key: "bad",
            name: "Bad",
            url: Some("https://example.test/fail"),
            kind: SourceKind::HashCommentLines,
        },
    ];
    let refs: Vec<&Source> = sources.iter().collect();
    let merged = merge_sources(&fetcher, &refs, dir.path(), ListKind::Disposable);
    assert!(merged.domains.contains(&"manual.com".to_string()));
    assert!(merged.domains.contains(&"mailinator.com".to_string()));
    assert_eq!(
        merged.reports.iter().filter(|r| r.error.is_some()).count(),
        1
    );
}

#[test]
fn plan_update_unknown_source_is_no_sources() {
    let dir = tempdir().unwrap();
    let fetcher = MapFetcher(HashMap::new());
    let err = plan_update(&fetcher, ListKind::Disposable, "nope", dir.path(), false).unwrap_err();
    assert!(matches!(err, SyncError::NoSources));
}

#[test]
fn commit_update_writes_combined_file() {
    let dir = tempdir().unwrap();
    commit_update(
        ListKind::Free,
        dir.path(),
        &["gmail.com".into(), "yahoo.com".into()],
    )
    .unwrap();
    let path = PathBuf::from(dir.path()).join("free-domains.json");
    let loaded = load_json_list(&path).unwrap();
    assert_eq!(loaded, vec!["gmail.com", "yahoo.com"]);
}
