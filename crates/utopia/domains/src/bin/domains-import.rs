//! Downloads the Public Suffix List and writes `data/public-suffix-list.txt`,
//! the list the crate embeds.
//!
//! ```sh
//! cargo run -p utopia-domains --bin domains-import
//! ```
//!
//! Each rule keeps its section (`ICANN`, `PRIVATE`, or `-` for a rule outside
//! both) and the `// ` comment lines just above it. The task fails without
//! writing when the list cannot be downloaded or has no `com` rule. Rebuild
//! the crate afterwards: the list is embedded at compile time.
//!
//! # Arguments
//!
//! - none: the task takes no arguments.
//!
//! # Environment
//!
//! - `UTOPIA_DOMAINS_PSL_URL`: where to download the list from (default
//!   `https://publicsuffix.org/list/public_suffix_list.dat`).
//! - `UTOPIA_DOMAINS_DATA_DIR`: the directory to write
//!   `public-suffix-list.txt` to (default: the crate's `data/`).
//!
//! # Exit codes
//!
//! - `0`: the list was written.
//! - `1`: the list could not be downloaded, is corrupt (no `com` rule), is not
//!   UTF-8, or could not be written; the reason is on standard error.
//!
//! PHP: `php data/import.php` in `packages/domains`, which writes the same
//! rules, sections and comments to `data/data.php`. It reads the same
//! environment variables and prints the same messages; it writes a list that
//! is not UTF-8 as it is.

use std::path::PathBuf;
use std::process::ExitCode;
use std::time::Duration;

use indexmap::IndexMap;
use php_std::mb;
use utopia_domains::transport::{HttpTransport, Request, Transport};

const DEFAULT_URL: &str = "https://publicsuffix.org/list/public_suffix_list.dat";

/// A rule of the list: its section and the comments above it.
struct Rule {
    section: Option<&'static str>,
    comments: Vec<Vec<u8>>,
}

/// An environment variable as PHP's `getenv($name) ?: $default` reads it:
/// unset, empty and `"0"` fall back to the default.
fn env_or(name: &str, default: impl FnOnce() -> String) -> String {
    match std::env::var(name) {
        Ok(v) if !v.is_empty() && v != "0" => v,
        _ => default(),
    }
}

/// `mb_strpos($line, $needle)` as a condition: found after the first
/// character (a match at position 0 is falsy in PHP).
fn marks(line: &[u8], needle: &[u8]) -> bool {
    matches!(mb::mb_strpos(line, needle, 0), Ok(Some(at)) if at > 0)
}

/// The rules of the list, in order, by rule (a rule given twice keeps its
/// first place and its last section and comments, as a PHP array does).
fn parse(data: &[u8]) -> IndexMap<Vec<u8>, Rule> {
    let mut section: Option<&'static str> = None;
    let mut comments: Vec<Vec<u8>> = Vec::new();
    let mut rules: IndexMap<Vec<u8>, Rule> = IndexMap::new();
    for line in data.split(|b| *b == b'\n') {
        if marks(line, b"===BEGIN ICANN DOMAINS===") {
            section = Some("ICANN");
            comments.clear();
            continue;
        }
        if marks(line, b"===END ICANN DOMAINS===") {
            section = None;
            continue;
        }
        if marks(line, b"===BEGIN PRIVATE DOMAINS===") {
            section = Some("PRIVATE");
            comments.clear();
            continue;
        }
        if marks(line, b"===END PRIVATE DOMAINS===") {
            section = None;
            continue;
        }
        // PHP: empty($line).
        if line.is_empty() || line == b"0" {
            continue;
        }
        if mb::mb_substr(line, 0, Some(3)).is_ok_and(|start| start == b"// ") {
            comments.push(mb::mb_substr(line, 3, None).unwrap_or_default());
            continue;
        }
        rules.insert(line.to_vec(), Rule { section, comments: std::mem::take(&mut comments) });
    }
    rules
}

/// The list in the format `PublicSuffixList::parse` reads.
fn render(rules: &IndexMap<Vec<u8>, Rule>) -> Vec<u8> {
    let mut out = Vec::new();
    for (rule, r) in rules {
        for comment in &r.comments {
            out.extend_from_slice(b"#\t");
            out.extend_from_slice(comment);
            out.push(b'\n');
        }
        out.extend_from_slice(r.section.unwrap_or("-").as_bytes());
        out.push(b'\t');
        out.extend_from_slice(rule);
        out.push(b'\n');
    }
    out
}

async fn download(url: &str) -> Option<Vec<u8>> {
    let request = Request {
        method: "GET".to_owned(),
        url: url.to_owned(),
        headers: Vec::new(),
        body: Vec::new(),
        connect_timeout: Duration::from_secs(60),
        timeout: Duration::from_secs(600),
    };
    // PHP's HTTP stream wrapper follows redirects and fails from status 400.
    match HttpTransport::follow_redirects().send(request).await {
        Ok(response) if response.status < 400 => Some(response.body),
        _ => None,
    }
}

fn fail(message: &str) -> ExitCode {
    eprintln!("{message}");
    ExitCode::from(1)
}

fn main() -> ExitCode {
    let url = env_or("UTOPIA_DOMAINS_PSL_URL", || DEFAULT_URL.to_owned());
    let dir =
        PathBuf::from(env_or("UTOPIA_DOMAINS_DATA_DIR", || concat!(env!("CARGO_MANIFEST_DIR"), "/data").to_owned()));

    let runtime = match utopia_async::Builder::current_thread().build() {
        Ok(runtime) => runtime,
        Err(e) => return fail(&e.to_string()),
    };
    let Some(data) = runtime.block_on(download(&url)) else {
        return fail("Could not download public suffix list");
    };
    let rules = parse(&data);
    if !rules.contains_key(b"com".as_slice()) {
        return fail(".com is missing from public suffix list; it must be corrupted");
    }
    let text = render(&rules);
    if std::str::from_utf8(&text).is_err() {
        return fail("The public suffix list is not valid UTF-8");
    }
    let path = dir.join("public-suffix-list.txt");
    if std::fs::write(&path, text).is_err() {
        return fail(&format!("Could not write {}", path.display()));
    }
    ExitCode::SUCCESS
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sections_and_comments() {
        let list = b"// ===BEGIN ICANN DOMAINS===\n\n// ac : https://nic.ac\nac\ncom.ac\n// ===END ICANN DOMAINS===\n\
                     // ===BEGIN PRIVATE DOMAINS===\n// GitHub\ngithub.io\n// ===END PRIVATE DOMAINS===\nloose\n0\n";
        let text = render(&parse(list));
        assert_eq!(
            String::from_utf8(text).unwrap(),
            "#\tac : https://nic.ac\nICANN\tac\nICANN\tcom.ac\n#\tGitHub\nPRIVATE\tgithub.io\n-\tloose\n"
        );
    }

    #[test]
    fn markers_at_the_start_are_not_markers() {
        // mb_strpos() returns 0 there, which PHP reads as false.
        let text = render(&parse(b"===BEGIN ICANN DOMAINS===\ncom\n"));
        assert_eq!(String::from_utf8(text).unwrap(), "-\t===BEGIN ICANN DOMAINS===\n-\tcom\n");
    }
}
