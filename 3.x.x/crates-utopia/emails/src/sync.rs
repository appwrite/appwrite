use std::collections::{BTreeMap, BTreeSet};
use std::fs;
use std::path::{Path, PathBuf};

use bytes::Bytes;
use http::{Method, Request};
use thiserror::Error;
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};
use utopia_domains::Domain;

/// PHP `DISPOSABLE_SOURCES` / `FREE_SOURCES` plus fetch/merge/save for JSON snapshots.
#[derive(Debug, Error)]
pub enum SyncError {
    #[error("No valid sources found")]
    NoSources,
    #[error("Failed to fetch {0} email domains or list is empty")]
    EmptyList(&'static str),
    #[error("Network error: {0}")]
    Network(String),
    #[error("HTTP {0}")]
    Http(u16),
    #[error("Invalid JSON response: {0}")]
    InvalidJson(String),
    #[error("Expected array in JSON response")]
    ExpectedArray,
    #[error("Failed to write config file: {0}")]
    Write(String),
    #[error("{0}")]
    Io(#[from] std::io::Error),
}

/// HTTP GET used by remote sources (PHP `Utopia\Fetch\Client`).
pub trait Fetcher {
    fn fetch_text(&self, url: &str) -> Result<String, SyncError>;
}

/// Blocking [`utopia-client`] adapter (PHP `Utopia\Fetch\Client`).
#[derive(Debug)]
pub struct BlockingFetcher {
    client: Client<curl::Client>,
}

impl BlockingFetcher {
    pub fn new() -> Result<Self, SyncError> {
        let client = Client::new(curl::Client::new())
            .with_timeout(60.0)
            .and_then(|client| client.with_connect_timeout(60.0))
            .map_err(|err| SyncError::Network(err.to_string()))?;
        Ok(Self { client })
    }
}

impl Fetcher for BlockingFetcher {
    fn fetch_text(&self, url: &str) -> Result<String, SyncError> {
        let request = Request::builder()
            .method(Method::GET)
            .uri(url)
            .header("user-agent", "utopia-emails-sync/0.1")
            .body(Bytes::new())
            .map_err(|err| SyncError::Network(err.to_string()))?;
        let response = self
            .client
            .send_request(request)
            .map_err(|err| SyncError::Network(err.to_string()))?;
        let status = response.status().as_u16();
        if !(200..300).contains(&status) {
            return Err(SyncError::Http(status));
        }
        Ok(String::from_utf8_lossy(response.body()).into_owned())
    }
}

/// One configured source (PHP `$sourceConfig`).
#[derive(Debug, Clone)]
pub struct Source {
    pub key: &'static str,
    pub name: &'static str,
    pub url: Option<&'static str>,
    pub kind: SourceKind,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SourceKind {
    Manual,
    HashCommentLines,
    WhitespaceTokens,
    JsonArray,
}

/// PHP `DISPOSABLE_SOURCES`.
pub fn disposable_sources() -> &'static [Source] {
    &[
        Source {
            key: "manual",
            name: "Manual Disposable Email Domains",
            url: None,
            kind: SourceKind::Manual,
        },
        Source {
            key: "martenson",
            name: "Martenson Disposable Email Domains",
            url: Some("https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf"),
            kind: SourceKind::HashCommentLines,
        },
        Source {
            key: "disposable",
            name: "Disposable Email Domains",
            url: Some("https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt"),
            kind: SourceKind::WhitespaceTokens,
        },
        Source {
            key: "wesbos",
            name: "Wes Bos Burner Email Providers",
            url: Some("https://raw.githubusercontent.com/wesbos/burner-email-providers/refs/heads/master/emails.txt"),
            kind: SourceKind::WhitespaceTokens,
        },
        Source {
            key: "fakefilter",
            name: "7c FakeFilter Domains",
            url: Some("https://raw.githubusercontent.com/7c/fakefilter/main/txt/data.txt"),
            kind: SourceKind::HashCommentLines,
        },
        Source {
            key: "adamloving",
            name: "Adam Loving Temporary Email Domains",
            url: Some("https://gist.githubusercontent.com/adamloving/4401361/raw/e81212c3caecb54b87ced6392e0a0de2b6466287/temporary-email-address-domains"),
            kind: SourceKind::WhitespaceTokens,
        },
    ]
}

/// PHP `FREE_SOURCES`.
pub fn free_sources() -> &'static [Source] {
    &[
        Source {
            key: "manual",
            name: "Manual Free Email Domains",
            url: None,
            kind: SourceKind::Manual,
        },
        Source {
            key: "kikobeats",
            name: "Kikobeats Free Email Domains",
            url: Some(
                "https://raw.githubusercontent.com/Kikobeats/free-email-domains/master/domains.json",
            ),
            kind: SourceKind::JsonArray,
        },
    ]
}

pub fn default_data_dir() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("data")
}

pub fn select_sources<'a>(all: &'a [Source], source: &str) -> Result<Vec<&'a Source>, SyncError> {
    if source.is_empty() {
        return Ok(all.iter().collect());
    }
    match all.iter().find(|item| item.key == source) {
        Some(found) => Ok(vec![found]),
        None => Err(SyncError::NoSources),
    }
}

/// PHP `isValidDomain()`.
pub fn is_valid_email_domain(domain: &str) -> bool {
    if domain.is_empty() {
        return false;
    }
    let Ok(parsed) = Domain::new(domain) else {
        return false;
    };
    if parsed.is_test() {
        return false;
    }
    !parsed.get_name().is_empty() && !parsed.get_tld().is_empty()
}

pub fn parse_hash_comment_lines(content: &str) -> Vec<String> {
    let mut domains = Vec::new();
    for line in content.lines() {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        if is_valid_email_domain(line) {
            domains.push(line.to_ascii_lowercase());
        }
    }
    domains
}

pub fn parse_whitespace_tokens(content: &str) -> Vec<String> {
    let mut domains = Vec::new();
    for token in content.split_whitespace() {
        let domain = token.trim();
        if domain.is_empty() {
            continue;
        }
        if is_valid_email_domain(domain) {
            domains.push(domain.to_ascii_lowercase());
        }
    }
    domains
}

pub fn parse_json_string_array(content: &str) -> Result<Vec<String>, SyncError> {
    let json: serde_json::Value =
        serde_json::from_str(content).map_err(|err| SyncError::InvalidJson(err.to_string()))?;
    let Some(array) = json.as_array() else {
        return Err(SyncError::ExpectedArray);
    };
    let mut domains = Vec::new();
    for value in array {
        let Some(domain) = value.as_str().map(str::trim) else {
            continue;
        };
        if domain.is_empty() {
            continue;
        }
        if is_valid_email_domain(domain) {
            domains.push(domain.to_ascii_lowercase());
        }
    }
    Ok(domains)
}

pub fn load_json_list(path: &Path) -> Result<Vec<String>, SyncError> {
    if !path.exists() {
        return Ok(Vec::new());
    }
    let raw = fs::read_to_string(path)?;
    let list: Vec<String> =
        serde_json::from_str(&raw).map_err(|err| SyncError::InvalidJson(err.to_string()))?;
    Ok(list)
}

pub fn write_json_list(path: &Path, domains: &[String]) -> Result<(), SyncError> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)?;
    }
    let mut sorted = domains.to_vec();
    sorted.sort();
    sorted.dedup();
    let json = serde_json::to_string(&sorted)
        .map_err(|err| SyncError::Write(format!("{}: {err}", path.display())))?;
    fs::write(path, json).map_err(|_| SyncError::Write(path.display().to_string()))?;
    let loaded = load_json_list(path)?;
    if loaded != sorted {
        return Err(SyncError::Write(format!(
            "Generated file does not contain expected domains: {}",
            path.display()
        )));
    }
    Ok(())
}

#[derive(Debug, Clone)]
pub struct SourceReport {
    pub key: String,
    pub name: String,
    pub fetched: usize,
    pub error: Option<String>,
}

#[derive(Debug, Clone)]
pub struct MergeResult {
    pub domains: Vec<String>,
    pub total_fetched: usize,
    pub duplicates_removed: usize,
    pub reports: Vec<SourceReport>,
}

pub fn fetch_source(
    fetcher: &dyn Fetcher,
    source: &Source,
    manual_path: &Path,
) -> Result<Vec<String>, SyncError> {
    match source.kind {
        SourceKind::Manual => load_json_list(manual_path),
        SourceKind::HashCommentLines => Ok(parse_hash_comment_lines(&fetch_body(fetcher, source)?)),
        SourceKind::WhitespaceTokens => Ok(parse_whitespace_tokens(&fetch_body(fetcher, source)?)),
        SourceKind::JsonArray => parse_json_string_array(&fetch_body(fetcher, source)?),
    }
}

fn fetch_body(fetcher: &dyn Fetcher, source: &Source) -> Result<String, SyncError> {
    let url = source
        .url
        .ok_or_else(|| SyncError::Network("missing url".into()))?;
    fetcher.fetch_text(url)
}

pub fn merge_sources(
    fetcher: &dyn Fetcher,
    sources: &[&Source],
    data_dir: &Path,
    list: ListKind,
) -> MergeResult {
    let mut unique = BTreeSet::new();
    let mut total_fetched = 0usize;
    let mut reports = Vec::new();

    for source in sources {
        match fetch_source(fetcher, source, &manual_path(data_dir, list)) {
            Ok(domains) => {
                total_fetched += domains.len();
                let fetched = domains.len();
                for domain in domains {
                    unique.insert(domain);
                }
                reports.push(SourceReport {
                    key: source.key.to_string(),
                    name: source.name.to_string(),
                    fetched,
                    error: None,
                });
            }
            Err(err) => {
                reports.push(SourceReport {
                    key: source.key.to_string(),
                    name: source.name.to_string(),
                    fetched: 0,
                    error: Some(err.to_string()),
                });
            }
        }
    }

    let domains: Vec<String> = unique.into_iter().collect();
    let duplicates_removed = total_fetched.saturating_sub(domains.len());
    MergeResult {
        domains,
        total_fetched,
        duplicates_removed,
        reports,
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ListKind {
    Disposable,
    Free,
}

impl ListKind {
    pub fn label(self) -> &'static str {
        match self {
            Self::Disposable => "disposable",
            Self::Free => "free",
        }
    }

    pub fn combined_file(self) -> &'static str {
        match self {
            Self::Disposable => "disposable-domains.json",
            Self::Free => "free-domains.json",
        }
    }

    pub fn manual_file(self) -> &'static str {
        match self {
            Self::Disposable => "disposable-domains-manual.json",
            Self::Free => "free-domains-manual.json",
        }
    }
}

fn manual_path(data_dir: &Path, list: ListKind) -> PathBuf {
    data_dir.join(list.manual_file())
}

#[derive(Debug, Clone)]
pub struct UpdatePlan {
    pub current: Vec<String>,
    pub next: Vec<String>,
    pub added: Vec<String>,
    pub removed: Vec<String>,
    pub up_to_date: bool,
    pub merge: MergeResult,
}

pub fn plan_update(
    fetcher: &dyn Fetcher,
    list: ListKind,
    source: &str,
    data_dir: &Path,
    force: bool,
) -> Result<UpdatePlan, SyncError> {
    let catalog = match list {
        ListKind::Disposable => disposable_sources(),
        ListKind::Free => free_sources(),
    };
    let sources = select_sources(catalog, source)?;
    if sources.is_empty() {
        return Err(SyncError::NoSources);
    }
    let merge = merge_sources(fetcher, &sources, data_dir, list);
    if merge.domains.is_empty() {
        return Err(SyncError::EmptyList(list.label()));
    }
    let current = load_json_list(&data_dir.join(list.combined_file()))?;
    let up_to_date = !force && current == merge.domains;
    let current_set: BTreeSet<_> = current.iter().cloned().collect();
    let next_set: BTreeSet<_> = merge.domains.iter().cloned().collect();
    let added: Vec<String> = next_set.difference(&current_set).cloned().collect();
    let removed: Vec<String> = current_set.difference(&next_set).cloned().collect();
    Ok(UpdatePlan {
        current,
        next: merge.domains.clone(),
        added,
        removed,
        up_to_date,
        merge,
    })
}

pub fn commit_update(list: ListKind, data_dir: &Path, domains: &[String]) -> Result<(), SyncError> {
    write_json_list(&data_dir.join(list.combined_file()), domains)
}

#[derive(Debug, Clone)]
pub struct DomainStats {
    pub total: usize,
    pub known: usize,
    pub icann: usize,
    pub private: usize,
    pub unknown: usize,
    pub top_tlds: Vec<(String, usize)>,
}

pub fn domain_statistics(domains: &[String]) -> DomainStats {
    let mut tld_stats: BTreeMap<String, usize> = BTreeMap::new();
    let mut known = 0usize;
    let mut icann = 0usize;
    let mut private = 0usize;
    let mut unknown = 0usize;

    for domain in domains {
        let Ok(parsed) = Domain::new(domain) else {
            continue;
        };
        *tld_stats.entry(parsed.get_tld().to_string()).or_insert(0) += 1;
        if parsed.is_known() {
            known += 1;
            if parsed.is_icann() {
                icann += 1;
            } else if parsed.is_private() {
                private += 1;
            }
        } else {
            unknown += 1;
        }
    }

    let mut top: Vec<(String, usize)> = tld_stats.into_iter().collect();
    top.sort_by(|a, b| b.1.cmp(&a.1).then_with(|| a.0.cmp(&b.0)));
    top.truncate(10);

    DomainStats {
        total: domains.len(),
        known,
        icann,
        private,
        unknown,
        top_tlds: top,
    }
}
