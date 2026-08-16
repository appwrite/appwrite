use std::fs;
use std::path::{Path, PathBuf};

use thiserror::Error;

/// Public Suffix List download URL (PHP `data/import.php`).
pub const PSL_URL: &str = "https://publicsuffix.org/list/public_suffix_list.dat";

/// Errors from PSL import / serialization.
#[derive(Debug, Error)]
pub enum SyncError {
    /// PHP `RuntimeException('Could not download public suffix list')`.
    #[error("Could not download public suffix list")]
    Download,
    /// PHP `RuntimeException('.com is missing from public suffix list; it must be corrupted')`.
    #[error(".com is missing from public suffix list; it must be corrupted")]
    CorruptPsl,
    #[error("HTTP {0}")]
    Http(String),
    #[error("{0}")]
    Io(#[from] std::io::Error),
}

/// Default data directory: this crate's `data/` (PHP `__DIR__`).
pub fn default_data_dir() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("data")
}

/// Parse `public_suffix_list.dat` into insertion-ordered `(suffix, ICANN|PRIVATE)` pairs.
///
/// Mirrors PHP `data/import.php`. Comment lines are ignored (Rust `psl.json` stores
/// only suffix → section). Suffixes outside a BEGIN/END section are dropped.
pub fn parse_psl_dat(data: &str) -> Result<Vec<(String, String)>, SyncError> {
    let mut kind: Option<&'static str> = None;
    let mut entries = Vec::new();

    for raw in data.split('\n') {
        let line = raw.trim_end_matches('\r');

        if line.contains("===BEGIN ICANN DOMAINS===") {
            kind = Some("ICANN");
            continue;
        }
        if line.contains("===END ICANN DOMAINS===") {
            kind = None;
            continue;
        }
        if line.contains("===BEGIN PRIVATE DOMAINS===") {
            kind = Some("PRIVATE");
            continue;
        }
        if line.contains("===END PRIVATE DOMAINS===") {
            kind = None;
            continue;
        }
        if line.is_empty() {
            continue;
        }
        if line.starts_with("// ") {
            continue;
        }
        if let Some(kind) = kind {
            entries.push((line.to_string(), kind.to_string()));
        }
    }

    if !entries.iter().any(|(suffix, _)| suffix == "com") {
        return Err(SyncError::CorruptPsl);
    }
    Ok(entries)
}

/// Compact JSON object preserving PSL file order (matches shipped `data/psl.json`).
pub fn encode_psl_json(entries: &[(String, String)]) -> Result<String, SyncError> {
    let mut out = String::from("{");
    for (i, (suffix, kind)) in entries.iter().enumerate() {
        if i > 0 {
            out.push(',');
        }
        out.push_str(
            &serde_json::to_string(suffix)
                .map_err(|err| std::io::Error::new(std::io::ErrorKind::InvalidData, err))?,
        );
        out.push(':');
        out.push_str(
            &serde_json::to_string(kind)
                .map_err(|err| std::io::Error::new(std::io::ErrorKind::InvalidData, err))?,
        );
    }
    out.push('}');
    Ok(out)
}

/// Load the current snapshot if present.
pub fn load_psl_json(path: &Path) -> Result<Option<String>, SyncError> {
    if !path.exists() {
        return Ok(None);
    }
    Ok(Some(fs::read_to_string(path)?))
}

/// Write `psl.json` into `data_dir`.
pub fn write_psl_json(data_dir: &Path, json: &str) -> Result<PathBuf, SyncError> {
    fs::create_dir_all(data_dir)?;
    let path = data_dir.join("psl.json");
    fs::write(&path, json)?;
    Ok(path)
}

/// Download the PSL and return encoded JSON.
pub fn fetch_psl_json(url: &str) -> Result<String, SyncError> {
    let client = utopia_client::Client::new(utopia_client::adapter::curl::Client::new())
        .with_timeout(60.0)
        .and_then(|client| client.with_connect_timeout(60.0))
        .map_err(|_| SyncError::Download)?;
    let request = http::Request::builder()
        .method(http::Method::GET)
        .uri(url)
        .header("user-agent", "utopia-domains-sync/0.1")
        .body(bytes::Bytes::new())
        .map_err(|_| SyncError::Download)?;
    let response = utopia_client::StreamingClient::send_request(&client, request)
        .map_err(|_| SyncError::Download)?;
    if !response.status().is_success() {
        return Err(SyncError::Http(response.status().to_string()));
    }
    let body = String::from_utf8_lossy(response.body()).into_owned();
    let entries = parse_psl_dat(&body)?;
    encode_psl_json(&entries)
}
