//! How far each Rust crate is behind its PHP library.
//!
//! The team keeps changing the PHP libraries while they are ported, so each
//! crate records the PHP commit it last matched: `php-sync` in
//! `[package.metadata.utopia]` of its `Cargo.toml`. `compat sync <lib>`
//! writes it, after `compat run <lib>` proves the crate matches PHP as the
//! checkout has it. `compat crates` then measures from it with git: the
//! commits to the PHP library's source since, how much they change, and the
//! releases tagged since (`<lib>/<version>` tags, as `bin/monorepo release`
//! makes them).
//!
//! A crate with no marker is measured from the last PHP commit before the
//! crate was created, and marked as an estimate.

use std::path::Path;
use std::process::Command;

use serde_json::{Value, json};

/// The PHP paths whose changes a port must follow: the source and the manifest.
fn tracked(slug: &str) -> [String; 2] {
    [format!("packages/{slug}/src"), format!("packages/{slug}/composer.json")]
}

fn git(root: &Path, args: &[&str]) -> Option<String> {
    let out = Command::new("git").args(args).current_dir(root).output().ok()?;
    out.status.success().then(|| String::from_utf8_lossy(&out.stdout).trim().to_owned())
}

const SEP: char = '\u{1f}';
const FORMAT: &str = "--format=%H%x1f%h%x1f%aI%x1f%an%x1f%s";

fn commit(line: &str) -> Option<Value> {
    let f: Vec<&str> = line.split(SEP).collect();
    let [hash, short, date, author, subject] = f[..] else {
        return None;
    };
    Some(json!({ "hash": hash, "short": short, "date": date, "author": author, "subject": subject }))
}

/// The last commit to the PHP library's tracked paths (before `until`, when given).
fn last_change(root: &Path, slug: &str, until: Option<&str>) -> Option<Value> {
    let [src, manifest] = tracked(slug);
    let mut args = vec!["log", "-1", FORMAT];
    let before;
    if let Some(date) = until {
        before = format!("--before={date}");
        args.push(&before);
    }
    args.extend(["--", src.as_str(), manifest.as_str()]);
    commit(&git(root, &args)?)
}

/// `php-sync` in a crate manifest's `[package.metadata.utopia]`.
pub fn marker(manifest: &str) -> Option<String> {
    let mut inside = false;
    for line in manifest.lines() {
        let line = line.trim();
        if line.starts_with('[') {
            inside = line == "[package.metadata.utopia]";
            continue;
        }
        if inside
            && let Some((k, v)) = line.split_once('=')
            && k.trim() == "php-sync"
        {
            return Some(v.trim().trim_matches('"').to_owned());
        }
    }
    None
}

/// The manifest with `php-sync` set to `commit`.
pub fn with_marker(manifest: &str, commit: &str) -> String {
    let entry = format!("php-sync = \"{commit}\"");
    let mut out: Vec<String> = Vec::new();
    // Inside the table, after its last line (before blank lines that follow it).
    let insert = |out: &mut Vec<String>, entry: &str| {
        let at = out.len() - out.iter().rev().take_while(|l| l.trim().is_empty()).count();
        out.insert(at, entry.to_owned());
    };
    let mut inside = false;
    let mut table = false;
    let mut written = false;
    for line in manifest.lines() {
        let t = line.trim();
        if t.starts_with('[') {
            if inside && !written {
                insert(&mut out, &entry);
                written = true;
            }
            inside = t == "[package.metadata.utopia]";
            table |= inside;
        } else if inside && t.split_once('=').is_some_and(|(k, _)| k.trim() == "php-sync") {
            out.push(entry.clone());
            written = true;
            continue;
        }
        out.push(line.to_owned());
    }
    if inside && !written {
        insert(&mut out, &entry);
        written = true;
    }
    if !table && !written {
        while out.last().is_some_and(|l| l.trim().is_empty()) {
            out.pop();
        }
        out.push(String::new());
        out.push("[package.metadata.utopia]".to_owned());
        out.push(entry);
    }
    format!("{}\n", out.join("\n"))
}

/// The commit `compat sync` records for a library: the last change to its PHP source.
pub fn latest(root: &Path, slug: &str) -> Option<String> {
    last_change(root, slug, None).and_then(|c| c["hash"].as_str().map(str::to_owned))
}

/// Releases of the PHP library (`<slug>/<version>` tags), newest first.
fn releases(root: &Path, slug: &str, after: Option<&str>) -> Vec<Value> {
    let pattern = format!("{slug}/*");
    let mut args = vec![
        "tag",
        "--list",
        pattern.as_str(),
        "--sort=-creatordate",
        "--format=%(refname:short)%1f%(creatordate:iso-strict)",
    ];
    let merged;
    if let Some(commit) = after {
        // Tags not reachable from the sync point: released since.
        merged = format!("--no-merged={commit}");
        args.push(&merged);
    }
    let [src, manifest] = tracked(slug);
    git(root, &args)
        .unwrap_or_default()
        .lines()
        .filter_map(|l| {
            let (tag, date) = l.split_once(SEP)?;
            // A release counts once it carries a change to the library's source.
            if let Some(commit) = after {
                let range = format!("{commit}..{tag}");
                let changed = git(root, &["rev-list", "--count", &range, "--", &src, &manifest])
                    .and_then(|c| c.parse::<usize>().ok())
                    .unwrap_or(0);
                if changed == 0 {
                    return None;
                }
            }
            Some(json!({ "tag": tag, "version": tag.split_once('/').map(|(_, v)| v).unwrap_or(tag), "date": date }))
        })
        .collect()
}

/// Changes to the PHP library on `origin/main` that this checkout has not
/// merged yet: what the team pushed meanwhile. Read from the local ref, as
/// last fetched; `None` without one.
fn upstream(root: &Path, slug: &str) -> Option<Value> {
    let main = "origin/main";
    git(root, &["rev-parse", "--verify", "--quiet", main])?;
    let [src, manifest] = tracked(slug);
    let range = format!("HEAD..{main}");
    let count: usize =
        git(root, &["rev-list", "--count", &range, "--", &src, &manifest]).and_then(|c| c.parse().ok()).unwrap_or(0);
    let commits: Vec<Value> = git(root, &["log", "--max-count=20", FORMAT, &range, "--", &src, &manifest])
        .unwrap_or_default()
        .lines()
        .filter_map(commit)
        .collect();
    let fetched = git(root, &["log", "-1", "--format=%cI", main]);
    Some(json!({ "ref": main, "head": fetched, "commits": count, "list": commits }))
}

/// The sync status of a library for `compat crates`. `crate_dir` is its Rust
/// crate, if any; libraries outside `packages/` (Composer dependencies) have
/// no history here and report only what is known.
pub fn status(root: &Path, slug: &str, crate_dir: Option<&str>, monorepo: bool) -> Value {
    if !monorepo {
        return json!({ "state": "untracked" });
    }
    let latest = last_change(root, slug, None);
    let all_releases = releases(root, slug, None);
    let Some(dir) = crate_dir else {
        return json!({ "state": "planned", "latest": latest, "release": all_releases.first() });
    };
    let manifest = std::fs::read_to_string(root.join(dir).join("Cargo.toml")).unwrap_or_default();
    let (base, estimated) = match marker(&manifest) {
        Some(m) => (git(root, &["log", "-1", FORMAT, &m]).and_then(|l| commit(&l)), false),
        None => {
            // When the crate was created, it followed PHP as it stood then.
            let created = git(root, &["log", "--diff-filter=A", "--format=%aI", "--", &format!("{dir}/Cargo.toml")])
                .and_then(|l| l.lines().last().map(str::to_owned));
            (created.and_then(|d| last_change(root, slug, Some(&d))), true)
        }
    };
    let Some(base) = base else {
        return json!({ "state": "unknown", "latest": latest, "release": all_releases.first() });
    };
    let hash = base["hash"].as_str().unwrap_or_default().to_owned();
    let [src, manifest_path] = tracked(slug);
    let range = format!("{hash}..HEAD");
    let count: usize = git(root, &["rev-list", "--count", &range, "--", &src, &manifest_path])
        .and_then(|c| c.parse().ok())
        .unwrap_or(0);
    let commits: Vec<Value> = git(root, &["log", "--max-count=50", FORMAT, &range, "--", &src, &manifest_path])
        .unwrap_or_default()
        .lines()
        .filter_map(commit)
        .collect();
    let stat = git(root, &["diff", "--shortstat", &hash, "HEAD", "--", &src, &manifest_path]).unwrap_or_default();
    let number = |word: &str| -> usize {
        stat.split(',')
            .find(|p| p.contains(word))
            .and_then(|p| p.split_whitespace().next())
            .and_then(|n| n.parse().ok())
            .unwrap_or(0)
    };
    json!({
        "state": if count == 0 { "synced" } else { "behind" },
        "upstream": upstream(root, slug),
        "estimated": estimated,
        "synced": base,
        "latest": latest,
        "behind": {
            "commits": count,
            "files": number("file"),
            "insertions": number("insertion"),
            "deletions": number("deletion"),
            "list": commits,
        },
        "releases": releases(root, slug, Some(&hash)),
        "release": all_releases.first(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn writes_and_reads_the_marker() {
        let plain = "[package]\nname = \"utopia-dsn\"\n\n[dependencies]\nthiserror = \"2\"\n";
        let once = with_marker(plain, "abc");
        assert_eq!(marker(&once).as_deref(), Some("abc"));
        let twice = with_marker(&once, "def");
        assert_eq!(marker(&twice).as_deref(), Some("def"));
        assert_eq!(twice.matches("php-sync").count(), 1);
        let tabled = "[package]\nname = \"x\"\n\n[package.metadata.utopia]\ncategory = \"data\"\n\n[dependencies]\n";
        let set = with_marker(tabled, "abc");
        assert!(set.contains("category = \"data\"\nphp-sync = \"abc\""));
    }
}
