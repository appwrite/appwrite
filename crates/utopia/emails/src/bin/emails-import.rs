//! Refreshes the free and disposable email domain lists the crate embeds,
//! `data/free-domains.txt` and `data/disposable-domains.txt`, from their
//! sources, and reports statistics about them.
//!
//! ```sh
//! cargo run -p utopia-emails --bin emails-import -- disposable --commit=true
//! cargo run -p utopia-emails --bin emails-import -- stats
//! ```
//!
//! An update fetches every source of the list (or the one `--source` names),
//! keeps the domains that parse with a known name and suffix and are not test
//! domains, merges them with the manual list (`data/*-domains-manual.txt`),
//! removes duplicates and sorts them. It only previews the changes unless
//! `--commit=true` is given, and stops early when nothing changed unless
//! `--force=true` is. A source that fails is reported and skipped. Rebuild
//! the crate after a commit: the lists are embedded at compile time.
//!
//! # Commands
//!
//! - `disposable`: update the disposable domains from the manual list, martenson
//!   (disposable-email-domains), disposable, wesbos, fakefilter and adamloving.
//! - `free`: update the free domains from the manual list and kikobeats.
//! - `all`: `disposable`, then `free`, both from every source.
//! - `stats`: count the domains of both lists, by section and by TLD.
//!
//! # Arguments
//!
//! - `--commit=<bool>`: write the updated list (default `false`: preview the
//!   changes). Not for `stats`.
//! - `--force=<bool>`: update even when the list did not change (default
//!   `false`). Not for `stats`.
//! - `--source=<key>`: only this source, such as `martenson` or `kikobeats`
//!   (default: every source). `disposable` and `free` only.
//!
//! # Environment
//!
//! - `UTOPIA_EMAILS_DATA_DIR`: the directory the lists are read from and
//!   written to (default: the crate's `data/`).
//! - `UTOPIA_EMAILS_<TYPE>_<SOURCE>_URL`: where to download one source from,
//!   such as `UTOPIA_EMAILS_DISPOSABLE_MARTENSON_URL` or
//!   `UTOPIA_EMAILS_FREE_KIKOBEATS_URL`.
//!
//! # Exit codes
//!
//! - `0`: done, including a preview, a list already up to date (which ends
//!   `all` before the free domains), an unknown command and invalid
//!   arguments (which print nothing).
//! - `1`: no source given or found, every source failed, or the list could not
//!   be written; the reason is on standard error.
//! - `255`: no command.
//!
//! PHP: `php import.php <command>` in `packages/emails` (also `composer
//! import:disposable`, `import:free`, `import:all`, `import:stats`), which
//! reads and writes `data/*-domains.php` and reads the same environment
//! variables. Output, statistics, sorting (`sort()`) and exit codes are
//! PHP's. PHP reads a source's bytes as they are; here a domain that is not
//! UTF-8 is not valid, and a `null` in the free list's JSON is an empty
//! domain without PHP's deprecation notice.

use std::path::{Path, PathBuf};
use std::process::ExitCode;

use indexmap::IndexMap;
use php_std::format::{RoundingMode, round};
use php_std::sort::{SortFlags, sort};
use php_std::string::{TRIM_CHARACTERS, strtolower, trim};
use php_std::value::Number;
use php_std::{Array, Str, Value as Php};
use serde_json::{Value, json};
use utopia_cli::Cli;
use utopia_client::{Client, Http, Request};
use utopia_console as console;
use utopia_domains::Domain;
use utopia_servers::Param;
use utopia_validators::{Boolean, Text};

/// How a source lists its domains.
#[derive(Clone, Copy)]
enum Format {
    /// The manual list in the data directory.
    Manual,
    /// One domain per line; `#` starts a comment line.
    Lines,
    /// Domains separated by whitespace.
    Words,
    /// A JSON array of domains.
    Json,
}

struct Source {
    key: &'static str,
    name: &'static str,
    url: &'static str,
    format: Format,
}

/// One of the two lists.
#[derive(Clone, Copy)]
enum List {
    Disposable,
    Free,
}

impl List {
    /// The list's sources, in the order they are fetched.
    fn sources(self) -> &'static [Source] {
        match self {
            List::Disposable => DISPOSABLE,
            List::Free => FREE,
        }
    }

    /// `disposable` or `free`.
    fn word(self) -> &'static str {
        match self {
            List::Disposable => "disposable",
            List::Free => "free",
        }
    }

    /// `Disposable` or `Free`.
    fn title(self) -> &'static str {
        match self {
            List::Disposable => "Disposable",
            List::Free => "Free",
        }
    }

    /// The file the list is written to, in the data directory.
    fn file(self) -> String {
        format!("{}-domains.txt", self.word())
    }
}

const DISPOSABLE: &[Source] = &[
    Source { key: "manual", name: "Manual Disposable Email Domains", url: "", format: Format::Manual },
    Source {
        key: "martenson",
        name: "Martenson Disposable Email Domains",
        url: "https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf",
        format: Format::Lines,
    },
    Source {
        key: "disposable",
        name: "Disposable Email Domains",
        url: "https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt",
        format: Format::Words,
    },
    Source {
        key: "wesbos",
        name: "Wes Bos Burner Email Providers",
        url: "https://raw.githubusercontent.com/wesbos/burner-email-providers/refs/heads/master/emails.txt",
        format: Format::Words,
    },
    Source {
        key: "fakefilter",
        name: "7c FakeFilter Domains",
        url: "https://raw.githubusercontent.com/7c/fakefilter/main/txt/data.txt",
        format: Format::Lines,
    },
    Source {
        key: "adamloving",
        name: "Adam Loving Temporary Email Domains",
        url: "https://gist.githubusercontent.com/adamloving/4401361/raw/e81212c3caecb54b87ced6392e0a0de2b6466287/temporary-email-address-domains",
        format: Format::Words,
    },
];

const FREE: &[Source] = &[
    Source { key: "manual", name: "Manual Free Email Domains", url: "", format: Format::Manual },
    Source {
        key: "kikobeats",
        name: "Kikobeats Free Email Domains",
        url: "https://raw.githubusercontent.com/Kikobeats/free-email-domains/master/domains.json",
        format: Format::Json,
    },
];

/// Why a source could not be read. PHP's `fetchAllSources()` catches
/// `\Exception` only: an engine error ends the whole update.
enum Failure {
    /// Reported, and the next source is fetched.
    Exception(String),
    /// Ends the update.
    Engine(String),
}

fn info(message: &str) {
    let _ = console::info(message);
}

fn success(message: &str) {
    let _ = console::success(message);
}

fn warning(message: &str) {
    let _ = console::warning(message);
}

fn error(message: &str) {
    let _ = console::error(message);
}

/// An environment variable as PHP's `getenv($name) ?: $default` reads it.
fn env_or(name: &str, default: impl FnOnce() -> String) -> String {
    match std::env::var(name) {
        Ok(v) if !v.is_empty() && v != "0" => v,
        _ => default(),
    }
}

/// The directory the lists are read from and written to.
fn data_dir() -> PathBuf {
    PathBuf::from(env_or("UTOPIA_EMAILS_DATA_DIR", || concat!(env!("CARGO_MANIFEST_DIR"), "/data").to_owned()))
}

/// Where a source is downloaded from: its URL, unless
/// `UTOPIA_EMAILS_<TYPE>_<SOURCE>_URL` replaces it.
fn url(list: List, source: &Source) -> String {
    let name = format!("UTOPIA_EMAILS_{}_{}_URL", list.word().to_uppercase(), source.key.to_uppercase());
    match std::env::var(name) {
        Ok(v) if !v.is_empty() => v,
        _ => source.url.to_owned(),
    }
}

/// PHP's `empty()` of a string.
fn empty(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// Whether a domain is worth listing: it parses, is not a test domain, and
/// has a name and a TLD.
fn valid(domain: &[u8]) -> bool {
    if empty(domain) {
        return false;
    }
    let Ok(domain) = std::str::from_utf8(domain) else { return false };
    let Ok(domain) = Domain::new(domain) else { return false };
    !domain.is_test() && !empty(domain.name().as_bytes()) && !empty(domain.tld().as_bytes())
}

/// `preg_split('/\s+/', $s)`: pieces between runs of ASCII whitespace,
/// with an empty piece before leading and after trailing whitespace.
fn words(s: &[u8]) -> Vec<&[u8]> {
    let space = |b: u8| matches!(b, b' ' | b'\t' | b'\n' | b'\x0b' | b'\x0c' | b'\r');
    let mut out = Vec::new();
    let (mut start, mut i) = (0, 0);
    while i < s.len() {
        if space(s[i]) {
            out.push(&s[start..i]);
            while i < s.len() && space(s[i]) {
                i += 1;
            }
            start = i;
        } else {
            i += 1;
        }
    }
    out.push(&s[start..]);
    out
}

/// Keeps the valid domains of `candidates`, lower-cased; returns them with
/// the number of candidates.
fn keep<'a>(candidates: impl Iterator<Item = &'a [u8]>, skip_comments: bool) -> (Vec<String>, usize) {
    let mut domains = Vec::new();
    let mut processed = 0;
    for candidate in candidates {
        let candidate = trim(candidate, TRIM_CHARACTERS);
        processed += 1;
        if empty(candidate) || (skip_comments && candidate.starts_with(b"#")) {
            continue;
        }
        if valid(candidate) {
            domains.push(String::from_utf8_lossy(&strtolower(candidate)).into_owned());
        }
    }
    (domains, processed)
}

async fn download(url: &str) -> Result<Vec<u8>, Failure> {
    let network = |e: String| Failure::Exception(format!("Network error: {e}"));
    let client = Client::new(Http::new()).with_follow_redirects(true, 5).map_err(|e| network(e.to_string()))?;
    let request = Request::new("GET", url).map_err(|e| network(e.to_string()))?;
    let response = client.send(request).await.map_err(|e| network(e.to_string()))?;
    if response.status() != 200 {
        return Err(network(format!("HTTP {}", response.status())));
    }
    Ok(response.bytes().to_vec())
}

/// Reads one list file: a domain per line, `None` when it does not exist.
fn read(path: &Path) -> Option<Vec<String>> {
    let text = std::fs::read_to_string(path).ok()?;
    Some(text.split('\n').filter(|l| !l.is_empty()).map(str::to_owned).collect())
}

async fn fetch(list: List, source: &Source) -> Result<Vec<String>, Failure> {
    if let Format::Manual = source.format {
        let path = data_dir().join(format!("{}-domains-manual.txt", list.word()));
        return Ok(match read(&path) {
            Some(domains) => {
                info(&format!("  Loaded {} domains from manual config", domains.len()));
                domains
            }
            None => {
                info("  Manual config file not found, creating empty list");
                Vec::new()
            }
        });
    }
    let content = download(&url(list, source)).await?;
    let (domains, processed, unit) = match source.format {
        Format::Lines => {
            let (d, p) = keep(content.split(|b| *b == b'\n'), true);
            (d, p, "lines")
        }
        Format::Words => {
            let (d, p) = keep(words(trim(&content, TRIM_CHARACTERS)).into_iter(), false);
            (d, p, "domains")
        }
        Format::Json => {
            let decoded = php_std::json::decode(&content, Some(true), 512, php_std::json::Flags::NONE)
                .map_err(|e| Failure::Exception(format!("Invalid JSON response: {}", e.message())))?;
            let Php::Array(entries) = decoded else {
                return Err(Failure::Exception("Expected array in JSON response".to_owned()));
            };
            let mut texts: Vec<Str> = Vec::with_capacity(entries.len());
            for value in entries.values() {
                // trim() takes a string: scalars are converted, arrays are a TypeError.
                let text = match value {
                    Php::Array(_) | Php::Object(_) | Php::Ext(_) => {
                        return Err(Failure::Engine(format!(
                            "trim(): Argument #1 ($string) must be of type string, {} given",
                            value.debug_type()
                        )));
                    }
                    other => other.to_php_string().map_err(|e| Failure::Engine(e.to_string()))?,
                };
                texts.push(text);
            }
            let (d, p) = keep(texts.iter().map(Str::as_bytes), false);
            (d, p, "domains")
        }
        Format::Manual => unreachable!(),
    };
    info(&format!("  Processed {processed} {unit}, found {} valid domains", domains.len()));
    Ok(domains)
}

/// `sort($domains)`: PHP's `SORT_REGULAR` order, numeric strings by value.
fn php_sort(domains: Vec<String>) -> Vec<String> {
    let mut array: Array = Array::from_list(domains.into_iter().map(|d| Php::Str(Str::from(d))).collect());
    let _ = sort(&mut array, SortFlags::Regular);
    array
        .values()
        .map(|v| String::from_utf8_lossy(v.to_php_string().unwrap_or_default().as_bytes()).into_owned())
        .collect()
}

async fn fetch_all(list: List, sources: &[&Source]) -> Result<Vec<String>, String> {
    let total = sources.len();
    let mut all: IndexMap<String, ()> = IndexMap::new();
    let mut fetched = 0;
    info(&format!("Fetching from {total} sources..."));
    for (n, source) in sources.iter().enumerate() {
        info(&format!("[{}/{total}] Processing {}...", n + 1, source.name));
        match fetch(list, source).await {
            Ok(domains) => {
                fetched += domains.len();
                let count = domains.len();
                for domain in domains {
                    all.insert(domain, ());
                }
                info(&format!("✓ Fetched {count} domains from {}", source.name));
            }
            Err(Failure::Exception(message)) => warning(&format!("⚠ Failed to fetch from {}: {message}", source.name)),
            Err(Failure::Engine(message)) => return Err(message),
        }
    }
    let unique = php_sort(all.into_keys().collect());
    info(&format!("Total domains fetched: {fetched}"));
    info(&format!("Duplicates removed: {}", fetched as i64 - unique.len() as i64));
    info(&format!("Total unique domains after merging all sources: {}", unique.len()));
    Ok(unique)
}

/// `round(($part / $total) * 100, 1)` as PHP prints it: integer division
/// stays an integer when it is exact.
fn percent(part: usize, total: usize) -> String {
    let n = if part.is_multiple_of(total) {
        Number::Int((part / total) as i64 * 100)
    } else {
        Number::Float(part as f64 / total as f64 * 100.0)
    };
    php_std::number::to_string(round(n, 1, RoundingMode::HalfAwayFromZero))
}

fn statistics(domains: &[String]) {
    info("Analyzing domain statistics...");
    let mut tlds: IndexMap<String, usize> = IndexMap::new();
    let (mut known, mut icann, mut private, mut unknown) = (0, 0, 0, 0);
    for domain in domains {
        let Ok(domain) = Domain::new(domain) else { continue };
        *tlds.entry(domain.tld().to_owned()).or_default() += 1;
        if domain.is_known() {
            known += 1;
            if domain.is_icann() {
                icann += 1;
            } else if domain.is_private() {
                private += 1;
            }
        } else {
            unknown += 1;
        }
    }
    // arsort(): by count, highest first, ties in insertion order.
    let mut top: Vec<(String, usize)> = tlds.into_iter().collect();
    top.sort_by_key(|t| std::cmp::Reverse(t.1));
    let total = domains.len();
    info("Domain Statistics:");
    info(&format!("├─ Known domains: {known} ({}%)", percent(known, total)));
    info(&format!("├─ ICANN domains: {icann} ({}%)", percent(icann, total)));
    info(&format!("├─ Private domains: {private} ({}%)", percent(private, total)));
    info(&format!("└─ Unknown domains: {unknown} ({}%)", percent(unknown, total)));
    info("Top 10 TLDs:");
    for (tld, count) in top.into_iter().take(10) {
        info(&format!("  ├─ .{tld}: {count} domains"));
    }
}

fn preview(current: &[String], new: &[String]) {
    let show = |title: &str, sign: char, list: Vec<&String>| {
        if list.is_empty() {
            return;
        }
        info(&format!("{title} ({}):", list.len()));
        for domain in list.iter().take(10) {
            info(&format!("  ├─ {sign} {domain}"));
        }
        if list.len() > 10 {
            info(&format!("  └─ ... and {} more", list.len() - 10));
        }
    };
    show("Domains to be added", '+', new.iter().filter(|d| !current.contains(d)).collect());
    show("Domains to be removed", '-', current.iter().filter(|d| !new.contains(d)).collect());
}

/// `$current == $new`: the same length and loosely equal domains.
fn up_to_date(current: &[String], new: &[String]) -> bool {
    current.len() == new.len()
        && current
            .iter()
            .zip(new)
            .all(|(a, b)| php_std::value::loose_eq(&Value::String(a.clone()), &Value::String(b.clone())))
}

fn save(list: List, domains: Vec<String>) -> Result<(), String> {
    let path = data_dir().join(list.file());
    let domains = php_sort(domains);
    let mut text = String::new();
    for domain in &domains {
        text.push_str(domain);
        text.push('\n');
    }
    if let Some(dir) = path.parent() {
        let _ = std::fs::create_dir_all(dir);
    }
    std::fs::write(&path, text).map_err(|_| format!("Failed to write config file: {}", path.display()))?;
    // Read it back, as PHP includes the file it wrote.
    let mut written = read(&path).ok_or_else(|| format!("Generated file is not readable: {}", path.display()))?;
    let mut expected = domains;
    written.sort();
    expected.sort();
    expected.dedup();
    if written != expected {
        return Err(format!("Generated file does not contain expected domains: {}", path.display()));
    }
    info(&format!("✓ Saved {} Email Domains config: {}", list.title(), path.display()));
    Ok(())
}

async fn update(list: List, commit: bool, force: bool, source: &str) {
    let _ = console::title(&format!("{} Email Domains Update", list.title()));
    success(&format!("Utopia Emails {} domains update process has started", list.word()));
    if let Err(message) = run_update(list, commit, force, source).await {
        error(&format!("Error updating {} email domains: {message}", list.word()));
        console::exit(1);
    }
}

async fn run_update(list: List, commit: bool, force: bool, source: &str) -> Result<(), String> {
    let sources: Vec<&Source> = if source.is_empty() || source == "0" {
        list.sources().iter().collect()
    } else {
        list.sources().iter().filter(|s| s.key == source).collect()
    };
    if sources.is_empty() {
        error("No valid sources found");
        console::exit(1);
    }
    let domains = fetch_all(list, &sources).await?;
    let current = read(&data_dir().join(list.file())).unwrap_or_default();
    if domains.is_empty() {
        error(&format!("Failed to fetch {} email domains or list is empty", list.word()));
        console::exit(1);
    }
    info(&format!("Fetched {} {} email domains from all sources", domains.len(), list.word()));
    statistics(&domains);
    if !force && up_to_date(&current, &domains) {
        success(&format!("{} email domains are already up to date", list.title()));
        console::exit(0);
    }
    info("Changes detected:");
    info(&format!("- Previous domains count: {}", current.len()));
    info(&format!("- New domains count: {}", domains.len()));
    if commit {
        save(list, domains)?;
        success(&format!("Successfully updated {} email domains configuration", list.word()));
    } else {
        warning("Changes not yet committed to config file. Please provide --commit=true argument to commit changes.");
        info("Preview of changes:");
        preview(&current, &domains);
    }
    Ok(())
}

async fn update_all(commit: bool, force: bool) {
    let _ = console::title("All Email Domains Update");
    success("Utopia Emails all domains update process has started");
    update(List::Disposable, commit, force, "").await;
    info("");
    update(List::Free, commit, force, "").await;
    success("Successfully updated all email domains");
}

fn stats() {
    let _ = console::title("Email Domains Statistics");
    let disposable = read(&data_dir().join(List::Disposable.file())).unwrap_or_default();
    let free = read(&data_dir().join(List::Free.file())).unwrap_or_default();
    info("Current Domain Statistics:");
    info(&format!("├─ Disposable domains: {}", disposable.len()));
    info(&format!("└─ Free domains: {}", free.len()));
    if !disposable.is_empty() {
        info("");
        info("Disposable Domains Analysis:");
        statistics(&disposable);
    }
    if !free.is_empty() {
        info("");
        info("Free Domains Analysis:");
        statistics(&free);
    }
}

/// A boolean argument as the task receives it: `true`/`false` as given,
/// an empty value as `false`.
fn flag(value: Option<&Value>) -> bool {
    value.is_some_and(Boolean::to_php_bool)
}

fn text(value: Option<&Value>) -> String {
    value.and_then(Value::as_str).unwrap_or_default().to_owned()
}

fn main() -> ExitCode {
    let mut cli = match Cli::from_env() {
        Ok(cli) => cli,
        Err(e) => {
            // PHP: an uncaught exception, reported on the output (with its
            // stack trace), which ends the script with status 255.
            println!("PHP Fatal error:  Uncaught Exception: {e}");
            return ExitCode::from(255);
        }
    };
    let commit = || {
        Param::new(json!(false), Boolean::LOOSE, "If set will commit changes to config file. Default is false.")
            .optional(true)
    };
    let force = || {
        Param::new(json!(false), Boolean::LOOSE, "Force update even if no changes detected. Default is false.")
            .optional(true)
    };
    let source = || {
        Param::new(
            json!(""),
            Text::new(100),
            "Specific source to update (optional). Leave empty to update all sources.",
        )
        .optional(true)
    };
    cli.task("disposable")
        .desc("Update disposable email domains from multiple sources")
        .param("commit", commit())
        .param("force", force())
        .param("source", source())
        .action(|args| async move {
            update(
                List::Disposable,
                flag(args.value("commit")),
                flag(args.value("force")),
                &text(args.value("source")),
            )
            .await;
            Ok(())
        });
    cli.task("free")
        .desc("Update free email domains from multiple sources")
        .param("commit", commit())
        .param("force", force())
        .param("source", source())
        .action(|args| async move {
            update(List::Free, flag(args.value("commit")), flag(args.value("force")), &text(args.value("source")))
                .await;
            Ok(())
        });
    cli.task("all")
        .desc("Update both disposable and free email domains from all sources")
        .param("commit", commit())
        .param("force", force())
        .action(|args| async move {
            update_all(flag(args.value("commit")), flag(args.value("force"))).await;
            Ok(())
        });
    cli.task("stats").desc("Show statistics about current domain lists").action(|_| async {
        stats();
        Ok(())
    });
    let runtime = match tokio::runtime::Builder::new_current_thread().enable_all().build() {
        Ok(runtime) => runtime,
        Err(e) => {
            error(&e.to_string());
            return ExitCode::from(1);
        }
    };
    match runtime.block_on(cli.run()) {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            error(&e.to_string());
            ExitCode::from(255)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn split_like_preg_split() {
        assert_eq!(words(b"a  b\tc"), vec![b"a".as_slice(), b"b", b"c"]);
        assert_eq!(words(b"\x0ca"), vec![b"".as_slice(), b"a"]);
        assert_eq!(words(b""), vec![b"".as_slice()]);
    }

    #[test]
    fn percents_print_like_php() {
        assert_eq!(percent(72880, 72903), "100");
        assert_eq!(percent(72584, 72903), "99.6");
        assert_eq!(percent(0, 5), "0");
        assert_eq!(percent(1, 3), "33.3");
    }

    #[test]
    fn numeric_domains_sort_by_value() {
        let sorted = php_sort(vec!["b.com".into(), "10.5".into(), "9.5".into(), "a.com".into()]);
        assert_eq!(sorted, vec!["9.5", "10.5", "a.com", "b.com"]);
    }
}
