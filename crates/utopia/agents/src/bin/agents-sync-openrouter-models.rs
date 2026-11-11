//! Refreshes the OpenRouter model catalog the crate embeds
//! (`adapters::openrouter::models`) from OpenRouter's model list:
//! `data/openrouter-models.txt`, every model id sorted, and
//! `data/openrouter-constants.txt`, a named constant per model of the
//! curated providers.
//!
//! ```sh
//! cargo run -p utopia-agents --bin agents-sync-openrouter-models
//! cargo run -p utopia-agents --bin agents-sync-openrouter-models -- /tmp/catalog
//! ```
//!
//! Models without a string id are dropped and the rest sorted by id. The
//! curated providers (`anthropic`, `openai`, `google`, `meta-llama`,
//! `deepseek`, `mistralai`, `x-ai`) get a constant named after the id
//! (`openai/gpt-4o` is `MODEL_OPENAI_GPT_4O`), except date-pinned
//! snapshots and `:extended`, `:free`, `:beta`, preview, GPT-3.5 and
//! GPT-4 Turbo variants; a name taken twice gets the first eight hex
//! digits of the id's SHA-1 appended. Rebuild the crate after a sync: the
//! catalog is embedded at compile time.
//!
//! # Arguments
//!
//! - `[output-path]`: the directory the two data files are written to,
//!   created when missing (default: the crate's `data/`).
//!
//! # Environment
//!
//! - `OPENROUTER_MODELS_ENDPOINT`: where the catalog is fetched from
//!   (default `https://openrouter.ai/api/v1/models`).
//! - `OPENROUTER_API_KEY`, else `LLM_KEY_OPENROUTER`: sent as a bearer
//!   token when set.
//!
//! # Exit codes
//!
//! - `0`: written (`Wrote <n> named constants + <m> model IDs to <path>`).
//! - `1`: the catalog could not be fetched, has no `data` array, or has no
//!   model; the reason is on standard error.
//!
//! PHP: `php scripts/sync-openrouter-models.php [output-path]` in
//! `packages/agents`, which writes the class `src/Adapters/OpenRouter/Models.php`
//! (its output path is that file) from the same catalog, with the same
//! constants, order, output and exit codes. PHP's `file_get_contents()`
//! also prints its own warning before the failure line when the fetch
//! fails.

use std::io::Write as _;
use std::path::PathBuf;
use std::process::ExitCode;

use php_std::Value;
use php_std::pcre::Regex;
use sha1::{Digest, Sha1};
use utopia_client::{Client, Http, Request};

/// The catalog endpoint.
const ENDPOINT: &str = "https://openrouter.ai/api/v1/models";

/// Providers whose models get named constants.
const CURATED: [&str; 7] = ["anthropic", "openai", "google", "meta-llama", "deepseek", "mistralai", "x-ai"];

/// Model ids (old or niche variants) that get no constant.
const SKIP: [&[u8]; 9] = [
    br"/:extended$/",
    br"/:free$/",
    br"/:beta$/",
    br"/-\d{4}-\d{2}-\d{2}/",
    br"/-\d{4}$/",
    br"/-\d{4}-preview/",
    br"/gpt-3\.5/",
    br"/gpt-4-turbo/",
    br"/-preview$/",
];

/// `getenv($name) ?: ...`: unset, `""` and `"0"` read as false.
fn env(name: &str) -> Option<String> {
    std::env::var(name).ok().filter(|v| !v.is_empty() && v != "0")
}

fn main() -> ExitCode {
    let endpoint = env("OPENROUTER_MODELS_ENDPOINT").unwrap_or_else(|| ENDPOINT.to_owned());
    let output = std::env::args()
        .nth(1)
        .unwrap_or_else(|| PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("data").to_string_lossy().into_owned());
    let key = env("OPENROUTER_API_KEY").or_else(|| env("LLM_KEY_OPENROUTER"));

    let runtime = match utopia_async::Builder::current_thread().build() {
        Ok(runtime) => runtime,
        Err(e) => {
            eprintln!("{e}");
            return ExitCode::FAILURE;
        }
    };
    let Some(response) = runtime.block_on(fetch(&endpoint, key.as_deref())) else {
        eprintln!("Failed to fetch OpenRouter models from {endpoint}");
        return ExitCode::FAILURE;
    };

    let payload =
        php_std::json::decode(&response, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
            .unwrap_or(Value::Null);
    let data = match &payload {
        Value::Array(a) => match a.get_str("data") {
            Some(Value::Array(data)) => data,
            _ => return fail("OpenRouter models response did not include a data array"),
        },
        _ => return fail("OpenRouter models response did not include a data array"),
    };

    let mut ids: Vec<&[u8]> = data
        .values()
        .filter_map(|model| match model {
            Value::Array(m) => match m.get_str("id") {
                Some(Value::Str(id)) if !id.is_empty() => Some(id.as_bytes()),
                _ => None,
            },
            _ => None,
        })
        .collect();
    // usort() with strcmp(): byte order, stable.
    ids.sort();
    if ids.is_empty() {
        return fail("OpenRouter models response was empty");
    }

    let skip: Vec<_> = SKIP.iter().filter_map(|p| Regex::compiled(p).ok()).collect();
    let mut constants: Vec<(String, &[u8])> = Vec::new();
    for &id in &ids {
        let provider = id.split(|&b| b == b'/').next().unwrap_or_default();
        if !CURATED.iter().any(|c| c.as_bytes() == provider) {
            continue;
        }
        if skip.iter().any(|re| re.is_match(id)) {
            continue;
        }
        let mut name = constant_name(id);
        if constants.iter().any(|(n, _)| *n == name) {
            let digest = hex::encode(Sha1::digest(id));
            name.push('_');
            name.push_str(&digest[..8].to_ascii_uppercase());
        }
        match constants.iter_mut().find(|(n, _)| *n == name) {
            Some(entry) => entry.1 = id,
            None => constants.push((name, id)),
        }
    }

    let synced = chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ");
    let mut header = format!(
        "# Generated by agents-sync-openrouter-models: do not edit by hand.\n# Source: {endpoint}\n# Synced at: {synced}\n"
    );
    let mut list = String::new();
    for (name, id) in &constants {
        header.push_str(&format!("{name} {}\n", escape(id)));
    }
    for id in &ids {
        list.push_str(&escape(id));
        list.push('\n');
    }

    let dir = PathBuf::from(&output);
    let _ = std::fs::create_dir_all(&dir);
    for (file, contents) in [("openrouter-constants.txt", header), ("openrouter-models.txt", list)] {
        let path = dir.join(file);
        if let Err(e) = std::fs::write(&path, contents) {
            eprintln!("Warning: could not write {}: {e}", path.display());
        }
    }

    println!("Wrote {} named constants + {} model IDs to {output}", constants.len(), ids.len());
    let _ = std::io::stdout().flush();
    ExitCode::SUCCESS
}

fn fail(message: &str) -> ExitCode {
    eprintln!("{message}");
    ExitCode::FAILURE
}

/// Downloads the catalog: `None` for a network failure or an error status,
/// where PHP's `file_get_contents()` returns `false` (it follows up to 20
/// redirects and waits 30 seconds).
async fn fetch(endpoint: &str, key: Option<&str>) -> Option<Vec<u8>> {
    let client = Client::new(Http::new()).with_follow_redirects(true, 20).ok()?.with_timeout(30.0).ok()?;
    let mut request = Request::new("GET", endpoint).ok()?.header("Accept", "application/json").ok()?;
    if let Some(key) = key {
        request = request.header("Authorization", &format!("Bearer {key}")).ok()?;
    }
    let response = client.send(request).await.ok()?;
    if response.status() >= 400 {
        return None;
    }
    Some(response.bytes().to_vec())
}

/// `toConstantName($id)`: `MODEL_` and the id upper-cased, every run of
/// other characters than `A-Z0-9` an underscore, trimmed of underscores.
fn constant_name(id: &[u8]) -> String {
    let upper = id.to_ascii_uppercase();
    let mut name = String::with_capacity(upper.len());
    for &b in &upper {
        if b.is_ascii_uppercase() || b.is_ascii_digit() {
            name.push(char::from(b));
        } else if !name.ends_with('_') {
            name.push('_');
        }
    }
    let name = name.trim_matches('_');
    if name.is_empty() { "MODEL_UNKNOWN".to_owned() } else { format!("MODEL_{name}") }
}

/// An id as a data line: `\`, newline and carriage return escaped.
fn escape(id: &[u8]) -> String {
    String::from_utf8_lossy(id).replace('\\', "\\\\").replace('\n', "\\n").replace('\r', "\\r")
}
