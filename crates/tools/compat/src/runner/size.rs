//! Code size: lines of a library's Rust crate and PHP package, each split
//! into code, comments (doc comments included) and blank lines, with tests
//! counted apart.
//!
//! A line with any code on it is code; a line holding only a comment (or
//! the inside of a block comment) is a comment; a whitespace-only line is
//! blank, unless it sits inside a multi-line string, where it is code.
//! Strings are tracked so a `//` inside one is not read as a comment.
//!
//! Rust: `src/` and `build.rs`, with `#[cfg(test)]` modules (inline or in
//! their own files) moved to tests along with `tests/`. PHP: `src/`, and
//! `tests/` as tests.

use std::collections::BTreeSet;
use std::path::{Path, PathBuf};

use serde_json::{Value, json};

/// Lines of some source, split by kind.
#[derive(Debug, Default, Clone, Copy, PartialEq, Eq)]
pub struct Lines {
    pub code: usize,
    pub comments: usize,
    pub blank: usize,
    pub files: usize,
}

impl Lines {
    pub fn total(&self) -> usize {
        self.code + self.comments + self.blank
    }

    pub fn add(&mut self, other: Lines) {
        self.code += other.code;
        self.comments += other.comments;
        self.blank += other.blank;
        self.files += other.files;
    }

    pub fn to_json(self) -> Value {
        json!({ "total": self.total(), "code": self.code, "comments": self.comments, "blank": self.blank, "files": self.files })
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Lang {
    Rust,
    Php,
}

/// One line's kind.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Kind {
    Code,
    Comment,
    Blank,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum State {
    Normal,
    /// Inside `/* */`, with Rust's nesting depth.
    Block(usize),
    /// Inside a string closed by this quote (`"` or `'`).
    Str(u8),
    /// Inside a Rust raw string closed by `"` and this many `#`.
    Raw(usize),
}

/// The kind of every line of `text`.
fn kinds(text: &str, lang: Lang) -> Vec<Kind> {
    let mut state = State::Normal;
    let mut out = Vec::new();
    for line in text.lines() {
        let in_string = matches!(state, State::Str(_) | State::Raw(_));
        let (mut code, mut comment) = (false, matches!(state, State::Block(_)));
        let b = line.as_bytes();
        let mut i = 0;
        while i < b.len() {
            let c = b[i];
            let next = b.get(i + 1).copied();
            match state {
                State::Block(depth) => {
                    comment = true;
                    if c == b'*' && next == Some(b'/') {
                        state = if depth > 1 { State::Block(depth - 1) } else { State::Normal };
                        i += 2;
                        continue;
                    }
                    if lang == Lang::Rust && c == b'/' && next == Some(b'*') {
                        state = State::Block(depth + 1);
                        i += 2;
                        continue;
                    }
                }
                State::Str(quote) => {
                    code = true;
                    if c == b'\\' {
                        i += 2;
                        continue;
                    }
                    if c == quote {
                        state = State::Normal;
                    }
                }
                State::Raw(hashes) => {
                    code = true;
                    if c == b'"' && b[i + 1..].iter().take(hashes).filter(|h| **h == b'#').count() == hashes {
                        state = State::Normal;
                        i += 1 + hashes;
                        continue;
                    }
                }
                State::Normal => {
                    if c.is_ascii_whitespace() {
                        i += 1;
                        continue;
                    }
                    if c == b'/' && next == Some(b'/') || lang == Lang::Php && c == b'#' && next != Some(b'[') {
                        comment = true;
                        break;
                    }
                    if c == b'/' && next == Some(b'*') {
                        comment = true;
                        state = State::Block(1);
                        i += 2;
                        continue;
                    }
                    code = true;
                    if c == b'"' {
                        state = State::Str(b'"');
                    } else if lang == Lang::Php && c == b'\'' {
                        state = State::Str(b'\'');
                    } else if lang == Lang::Rust && c == b'\'' {
                        // A char literal ('a', '\n', '\''), not a lifetime ('a).
                        if next == Some(b'\\') {
                            if let Some(end) = b[i + 2..].iter().skip(1).position(|x| *x == b'\'') {
                                i += end + 4;
                                continue;
                            }
                        } else if let Some(len) = line[i + 1..].chars().next().map(char::len_utf8)
                            && b.get(i + 1 + len) == Some(&b'\'')
                        {
                            i += len + 2;
                            continue;
                        }
                    } else if lang == Lang::Rust
                        && c == b'r'
                        && !b[..i].last().is_some_and(|p| p.is_ascii_alphanumeric() || *p == b'_')
                    {
                        // r"..." or r#"..."#, possibly after a b (br"...").
                        let hashes = b[i + 1..].iter().take_while(|h| **h == b'#').count();
                        if b.get(i + 1 + hashes) == Some(&b'"') {
                            state = State::Raw(hashes);
                            i += 2 + hashes;
                            continue;
                        }
                    }
                }
            }
            i += 1;
        }
        out.push(if code || in_string {
            Kind::Code
        } else if comment {
            Kind::Comment
        } else {
            Kind::Blank
        });
    }
    out
}

/// Counts `text`, leaving out the 1-based line ranges in `skip` (returned
/// as a second count).
fn count(text: &str, lang: Lang, skip: &[(usize, usize)]) -> (Lines, Lines) {
    let (mut main, mut tests) = (Lines::default(), Lines::default());
    for (n, kind) in kinds(text, lang).into_iter().enumerate() {
        let target = if skip.iter().any(|(a, b)| (*a..=*b).contains(&(n + 1))) { &mut tests } else { &mut main };
        match kind {
            Kind::Code => target.code += 1,
            Kind::Comment => target.comments += 1,
            Kind::Blank => target.blank += 1,
        }
    }
    (main, tests)
}

/// Lines of `text`, whole.
pub fn lines(text: &str, lang: Lang) -> Lines {
    let mut l = count(text, lang, &[]).0;
    l.files = 1;
    l
}

/// A Rust file's inline `#[cfg(test)]` modules (their line ranges, attributes
/// included) and the names of those declared in their own file.
fn test_modules(text: &str) -> (Vec<(usize, usize)>, Vec<String>) {
    let (mut ranges, mut files) = (Vec::new(), Vec::new());
    let Ok(parsed) = syn::parse_file(text) else {
        return (ranges, files);
    };
    fn walk(items: &[syn::Item], ranges: &mut Vec<(usize, usize)>, files: &mut Vec<String>, top: bool) {
        use quote::ToTokens;
        for item in items {
            let syn::Item::Mod(m) = item else { continue };
            let test = m.attrs.iter().any(|a| {
                a.path().is_ident("cfg") && {
                    let t = a.meta.to_token_stream().to_string();
                    t.contains("test") && !t.contains("not")
                }
            });
            match (&m.content, test) {
                (Some((brace, _)), true) => {
                    let start = m
                        .attrs
                        .first()
                        .map(|a| a.pound_token.span.start().line)
                        .unwrap_or(m.mod_token.span.start().line);
                    ranges.push((start, brace.span.close().end().line));
                }
                (None, true) if top => files.push(m.ident.to_string()),
                (Some((_, inner)), false) => walk(inner, ranges, files, false),
                _ => {}
            }
        }
    }
    walk(&parsed.items, &mut ranges, &mut files, true);
    (ranges, files)
}

fn collect(dir: &Path, ext: &str, files: &mut Vec<PathBuf>) {
    let Ok(entries) = std::fs::read_dir(dir) else {
        return;
    };
    for entry in entries.flatten() {
        let path = entry.path();
        if path.is_dir() {
            collect(&path, ext, files);
        } else if path.extension().is_some_and(|e| e == ext) {
            files.push(path);
        }
    }
}

/// A Rust crate's size: `src/` and `build.rs` (`code`), and its tests
/// (`tests/` and `#[cfg(test)]` modules).
pub fn rust(crate_dir: &Path) -> Option<(Lines, Lines)> {
    let src = crate_dir.join("src");
    if !src.is_dir() {
        return None;
    }
    let mut files = Vec::new();
    collect(&src, "rs", &mut files);
    files.sort();
    let texts: Vec<(PathBuf, String)> =
        files.into_iter().filter_map(|f| std::fs::read_to_string(&f).ok().map(|t| (f, t))).collect();
    // Files that are `#[cfg(test)] mod x;` of another file.
    let mut test_files: BTreeSet<PathBuf> = BTreeSet::new();
    let mut ranges: Vec<Vec<(usize, usize)>> = Vec::new();
    for (path, text) in &texts {
        let (r, names) = test_modules(text);
        ranges.push(r);
        let stem = path.file_stem().map(|s| s.to_string_lossy().into_owned()).unwrap_or_default();
        let parent = path.parent().unwrap_or(&src);
        let dir =
            if matches!(stem.as_str(), "lib" | "main" | "mod") { parent.to_path_buf() } else { parent.join(&stem) };
        for name in names {
            test_files.insert(dir.join(format!("{name}.rs")));
            test_files.insert(dir.join(&name).join("mod.rs"));
        }
    }
    let (mut code, mut tests) = (Lines::default(), Lines::default());
    for ((path, text), skip) in texts.iter().zip(&ranges) {
        let target_whole = test_files.contains(path)
            || test_files.iter().any(|t| t.ends_with("mod.rs") && t.parent().is_some_and(|d| path.starts_with(d)));
        if target_whole {
            tests.add(lines(text, Lang::Rust));
            continue;
        }
        let (main, inline) = count(text, Lang::Rust, skip);
        code.add(Lines { files: 1, ..main });
        // A file with test modules counts as a test file too.
        tests.add(Lines { files: usize::from(inline.total() > 0), ..inline });
    }
    if let Ok(text) = std::fs::read_to_string(crate_dir.join("build.rs")) {
        code.add(lines(&text, Lang::Rust));
    }
    let mut extra = Vec::new();
    collect(&crate_dir.join("tests"), "rs", &mut extra);
    for f in extra {
        if let Ok(text) = std::fs::read_to_string(&f) {
            tests.add(lines(&text, Lang::Rust));
        }
    }
    Some((code, tests))
}

/// Lines of PHP sources given as (path, text).
pub fn php<'a>(files: impl IntoIterator<Item = (&'a str, &'a str)>) -> Lines {
    let mut out = Lines::default();
    for (_, text) in files {
        out.add(lines(text, Lang::Php));
    }
    out
}

/// The PHP files under `dir` on disk, as (path, text).
pub fn php_files(dir: &Path) -> Vec<(String, String)> {
    let mut files = Vec::new();
    collect(dir, "php", &mut files);
    files.sort();
    files
        .into_iter()
        .filter_map(|f| std::fs::read_to_string(&f).ok().map(|t| (f.to_string_lossy().into_owned(), t)))
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rust_lines(text: &str) -> (usize, usize, usize) {
        let l = lines(text, Lang::Rust);
        (l.code, l.comments, l.blank)
    }

    #[test]
    fn rust_comments_code_and_blank() {
        let text = "//! Crate docs.\n\n/// An item.\npub fn a() -> u8 { 1 } // trailing\n/* block\n   still */\nlet x = 1; /* inline */\n";
        assert_eq!(rust_lines(text), (2, 4, 1));
    }

    #[test]
    fn strings_hide_comment_markers() {
        assert_eq!(rust_lines("let u = \"http://x\";\nlet r = r#\"/* not\n\n a comment */\"#;\n"), (4, 0, 0));
        assert_eq!(rust_lines("let c = '\"'; // a quote char\nlet l: &'a str = \"x\";\n"), (2, 0, 0));
        assert_eq!(rust_lines("let c = '\\''; let s = \"//\";\n"), (1, 0, 0));
    }

    #[test]
    fn nested_block_comments() {
        assert_eq!(rust_lines("/* a /* b */ still\n*/\nfn f() {}\n"), (1, 2, 0));
    }

    #[test]
    fn php_hash_comments_and_attributes() {
        let l =
            lines("<?php\n# a comment\n#[Attribute]\nclass A {} // c\n/**\n * Doc.\n */\n$s = '// no';\n", Lang::Php);
        assert_eq!((l.code, l.comments, l.blank), (4, 4, 0));
    }

    #[test]
    fn inline_test_modules_count_as_tests() {
        let text = "pub fn a() {}\n\n#[cfg(test)]\nmod tests {\n    #[test]\n    fn t() {}\n}\n";
        let (ranges, files) = test_modules(text);
        assert_eq!(ranges, vec![(3, 7)]);
        assert!(files.is_empty());
        let (main, tests) = count(text, Lang::Rust, &ranges);
        assert_eq!((main.code, main.blank, tests.code), (1, 1, 5));
        let (_, files) = test_modules("#[cfg(test)]\nmod tests;\n#[cfg(not(test))]\nmod real;\n");
        assert_eq!(files, vec!["tests".to_owned()]);
    }
}
