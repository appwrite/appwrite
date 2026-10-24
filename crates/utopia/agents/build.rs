//! Generates the OpenRouter model catalog (`adapters::openrouter::models`)
//! from the data the `agents-sync-openrouter-models` task writes:
//! `data/openrouter-constants.txt` (`NAME id` per line, `#` comments) and
//! `data/openrouter-models.txt` (one model id per line). In both, a
//! backslash, newline or carriage return of an id is written `\\`, `\n` or
//! `\r`.

use std::fmt::Write as _;
use std::path::PathBuf;

fn main() {
    let data = PathBuf::from(std::env::var("CARGO_MANIFEST_DIR").expect("CARGO_MANIFEST_DIR")).join("data");
    let constants = data.join("openrouter-constants.txt");
    let models = data.join("openrouter-models.txt");
    println!("cargo:rerun-if-changed={}", constants.display());
    println!("cargo:rerun-if-changed={}", models.display());

    let constants = std::fs::read_to_string(&constants).expect("data/openrouter-constants.txt");
    let models = std::fs::read_to_string(&models).expect("data/openrouter-models.txt");

    let mut out = String::new();
    let mut names = Vec::new();
    for line in constants.lines().filter(|l| !l.is_empty() && !l.starts_with('#')) {
        let (name, id) = line.split_once(' ').expect("NAME id");
        let id = unescape(id);
        let _ = writeln!(out, "/// `{}`\npub const {name}: &str = {id:?};", id.escape_debug());
        names.push(name.to_owned());
    }
    out.push_str("\n/// Every named constant, in the catalog's order: `(name, id)`.\npub const CONSTANTS: &[(&str, &str)] = &[\n");
    for name in &names {
        let _ = writeln!(out, "    ({name:?}, {name}),");
    }
    out.push_str("];\n\n/// The full model catalog, sorted by id.\npub const MODELS: &[&str] = &[\n");
    for id in models.lines().filter(|l| !l.is_empty()) {
        let _ = writeln!(out, "    {:?},", unescape(id));
    }
    out.push_str("];\n");

    let dest = PathBuf::from(std::env::var("OUT_DIR").expect("OUT_DIR")).join("openrouter_models.rs");
    std::fs::write(dest, out).expect("write openrouter_models.rs");
}

/// A data line's id, its `\\`, `\n` and `\r` escapes resolved.
fn unescape(id: &str) -> String {
    let mut out = String::with_capacity(id.len());
    let mut chars = id.chars();
    while let Some(c) = chars.next() {
        if c != '\\' {
            out.push(c);
            continue;
        }
        match chars.next() {
            Some('n') => out.push('\n'),
            Some('r') => out.push('\r'),
            Some(other) => out.push(other),
            None => out.push('\\'),
        }
    }
    out
}
