//! Code examples in doc comments, for `compat crates` and `compat examples`.
//!
//! A ```` ```rust ```` block (or an untagged one) is a doctest: `cargo test`
//! compiles and runs it. A ```` ```php ```` block right after it is the same
//! example in PHP; `compat examples` runs it. Both are turned into complete
//! programs a reader can copy and run:
//!
//! - Rust follows rustdoc: lines starting with `# ` are hidden from the
//!   reader but part of the program, and code without `fn main` is wrapped
//!   in one (returning `Result` when the example ends with
//!   `Ok::<(), Box<dyn std::error::Error>>(())`, as rustdoc requires for `?`).
//! - PHP without `<?php` gets the opening tag and Composer's autoloader.

use serde_json::{Value, json};

/// One example: the Rust doctest and, when the docs give one, its PHP twin.
#[derive(Debug, Clone, PartialEq)]
pub struct Example {
    /// The text before the code, when it is a short caption.
    pub title: Option<String>,
    pub rust: Option<Code>,
    pub php: Option<Code>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Code {
    /// What the page shows.
    pub shown: String,
    /// The complete program the copy button copies.
    pub program: String,
    /// rustdoc's attributes (`no_run`, `should_panic`), when any.
    pub attributes: Vec<String>,
}

impl Example {
    pub fn to_json(&self) -> Value {
        let code = |c: &Option<Code>| {
            c.as_ref().map(|c| json!({ "shown": c.shown, "program": c.program, "attributes": c.attributes }))
        };
        json!({ "title": self.title, "rust": code(&self.rust), "php": code(&self.php) })
    }
}

/// A doc comment or guide in reading order: Markdown text and examples.
#[derive(Debug, Clone, PartialEq)]
pub enum Block {
    Text(String),
    Example(Example),
}

impl Block {
    pub fn to_json(&self) -> Value {
        match self {
            Block::Text(t) => json!({ "text": t }),
            Block::Example(e) => json!({ "example": e.to_json() }),
        }
    }
}

/// The doc text without its examples, and the examples in order.
pub fn split(doc: &str) -> (String, Vec<Example>) {
    let mut text = Vec::new();
    let mut examples = Vec::new();
    for block in blocks(doc) {
        match block {
            Block::Text(t) => text.push(t),
            Block::Example(e) => examples.push(e),
        }
    }
    (text.join("\n\n"), examples)
}

/// Text and examples in the order they are written: a guide reads as prose,
/// an example, more prose. A PHP block right after a Rust one is its twin.
pub fn blocks(doc: &str) -> Vec<Block> {
    let mut out: Vec<Block> = Vec::new();
    let mut prose: Vec<&str> = Vec::new();
    let flush = |prose: &mut Vec<&str>, out: &mut Vec<Block>| {
        let mut text = prose.join("\n");
        while text.contains("\n\n\n") {
            text = text.replace("\n\n\n", "\n\n");
        }
        let text = text.trim();
        if !text.is_empty() {
            out.push(Block::Text(text.to_owned()));
        }
        prose.clear();
    };
    let mut lines = doc.lines();
    while let Some(line) = lines.next() {
        let Some(tag) = line.trim_start().strip_prefix("```") else {
            prose.push(line);
            continue;
        };
        let attributes: Vec<String> = tag.split(',').map(|a| a.trim().to_owned()).filter(|a| !a.is_empty()).collect();
        let mut body = Vec::new();
        for inner in lines.by_ref() {
            if inner.trim_start().starts_with("```") {
                break;
            }
            body.push(inner);
        }
        let code = body.join("\n");
        match language(&attributes) {
            Lang::Rust => {
                let title = caption(&mut prose);
                flush(&mut prose, &mut out);
                out.push(Block::Example(Example { title, rust: Some(rust(&code, &attributes)), php: None }));
            }
            Lang::Php => {
                let twin = prose.iter().all(|l| l.trim().is_empty())
                    && matches!(out.last(), Some(Block::Example(e)) if e.rust.is_some() && e.php.is_none());
                if twin {
                    prose.clear();
                    if let Some(Block::Example(e)) = out.last_mut() {
                        e.php = Some(php(&code));
                    }
                } else {
                    let title = caption(&mut prose);
                    flush(&mut prose, &mut out);
                    out.push(Block::Example(Example { title, rust: None, php: Some(php(&code)) }));
                }
            }
            Lang::Other => {
                prose.push(line);
                prose.extend(body);
                prose.push("```");
            }
        }
    }
    flush(&mut prose, &mut out);
    out
}

enum Lang {
    Rust,
    Php,
    Other,
}

fn language(attributes: &[String]) -> Lang {
    const RUSTDOC: [&str; 6] = ["rust", "no_run", "should_panic", "edition2018", "edition2021", "edition2024"];
    if attributes.iter().any(|a| a == "php") {
        return Lang::Php;
    }
    if attributes.iter().any(|a| a == "ignore" || a == "compile_fail" || a == "text") {
        return Lang::Other;
    }
    if attributes.is_empty() || attributes.iter().all(|a| RUSTDOC.contains(&a.as_str())) {
        return Lang::Rust;
    }
    Lang::Other
}

/// A one-paragraph lead-in ending with `:` right before the code becomes its title.
fn caption(prose: &mut Vec<&str>) -> Option<String> {
    while prose.last().is_some_and(|l| l.trim().is_empty()) {
        prose.pop();
    }
    let last = prose.last()?.trim();
    if last.ends_with(':') && last.len() <= 120 && !last.starts_with('#') && !last.starts_with('|') {
        let title = last.trim_end_matches(':').to_owned();
        prose.pop();
        return Some(title);
    }
    None
}

/// rustdoc's rendering and program for one doctest.
fn rust(body: &str, attributes: &[String]) -> Code {
    let mut shown = Vec::new();
    let mut program = Vec::new();
    for line in body.lines() {
        let t = line.trim_start();
        if t == "#" {
            program.push(String::new());
        } else if let Some(rest) = t.strip_prefix("# ") {
            program.push(format!("{}{}", &line[..line.len() - t.len()], rest));
        } else if let Some(rest) = t.strip_prefix("##") {
            let l = format!("{}#{}", &line[..line.len() - t.len()], rest);
            shown.push(l.clone());
            program.push(l);
        } else {
            shown.push(line.to_owned());
            program.push(line.to_owned());
        }
    }
    let code = program.join("\n");
    let program = if code.contains("fn main") {
        code
    } else {
        let (head, rest): (Vec<&str>, Vec<&str>) =
            code.lines().partition(|l| l.starts_with("#![") || l.starts_with("extern crate"));
        let inner = rest
            .iter()
            .map(|l| if l.is_empty() { String::new() } else { format!("    {l}") })
            .collect::<Vec<_>>()
            .join("\n");
        let fallible = rest.iter().any(|l| l.trim_start().starts_with("Ok::<"));
        let signature = if fallible { "fn main() -> Result<(), Box<dyn std::error::Error>> {" } else { "fn main() {" };
        let mut out = head.join("\n");
        if !out.is_empty() {
            out.push_str("\n\n");
        }
        format!("{out}{signature}\n{inner}\n}}")
    };
    Code {
        shown: shown.join("\n").trim().to_owned(),
        program: format!("{}\n", program.trim()),
        attributes: attributes.to_vec(),
    }
}

/// A PHP example and the script it runs as.
fn php(body: &str) -> Code {
    let shown = body.trim().to_owned();
    let program = if shown.starts_with("<?php") {
        format!("{shown}\n")
    } else {
        format!("<?php\n\nrequire __DIR__ . '/vendor/autoload.php';\n\n{shown}\n")
    };
    Code { shown, program, attributes: Vec::new() }
}

/// `compat examples`: every example in the docs of `crates` (directories
/// such as `crates/utopia/dsn`) runs. Rust doctests run with
/// `cargo test --doc`; each PHP example is written as the program a reader
/// copies and run in its own PHP process (`php` is the PHP driver command,
/// which takes a script to run instead of the driver).
pub fn verify(root: &std::path::Path, crates: &[String], php: &[String]) -> Result<bool, String> {
    use super::docs;

    // One directory per run: several runs (one per crate) can go at once.
    let dir_buf = std::path::PathBuf::from(format!("/tmp/compat-fs/examples-{}", std::process::id()));
    let dir = dir_buf.as_path();
    let _ = std::fs::remove_dir_all(dir);
    let mut written: Vec<(String, String)> = Vec::new();
    let mut names = Vec::new();
    for krate in crates {
        let manifest = std::fs::read_to_string(root.join(krate).join("Cargo.toml")).unwrap_or_default();
        if let Some(name) = manifest
            .lines()
            .find_map(|l| l.trim().strip_prefix("name").and_then(|r| r.trim().strip_prefix('=')))
            .map(|v| v.trim().trim_matches('"').to_owned())
        {
            names.push(name);
        }
        let slug = krate.rsplit('/').next().unwrap_or(krate);
        let mut docs_of: Vec<(String, String)> = docs::modules(root, krate)
            .into_iter()
            .map(|m| (if m.path.is_empty() { slug.to_owned() } else { m.path }, m.doc))
            .collect();
        docs_of.extend(docs::items(root, krate).into_iter().map(|i| (i.path, i.doc)));
        if let Ok(guide) = std::fs::read_to_string(root.join(krate).join("guide.md")) {
            docs_of.push(("guide".to_owned(), guide));
        }
        for (label, doc) in docs_of {
            for (n, example) in split(&doc).1.into_iter().enumerate() {
                let Some(code) = example.php else {
                    continue;
                };
                let target = dir.join(slug);
                std::fs::create_dir_all(&target).map_err(|e| e.to_string())?;
                let file = target.join(format!("{:03}-{}-{n}.php", written.len(), label.replace("::", "-")));
                std::fs::write(&file, &code.program).map_err(|e| e.to_string())?;
                written.push((file.to_string_lossy().into_owned(), format!("{slug}: {label}")));
            }
        }
    }

    let mut ok = true;
    if written.is_empty() {
        eprintln!("php: no examples");
    } else {
        let (program, args) = php.split_first().ok_or("no PHP driver command configured")?;
        let output = std::process::Command::new(program)
            .args(args)
            .arg("tests/compat/php/examples.php")
            .arg(dir)
            .current_dir(root)
            .stdin(std::process::Stdio::null())
            .output()
            .map_err(|e| format!("cannot run the PHP examples: {e}"))?;
        let _ = std::fs::remove_dir_all(dir);
        let mut passed = 0;
        for line in String::from_utf8_lossy(&output.stdout).lines() {
            let Ok(result) = serde_json::from_str::<Value>(line) else {
                continue;
            };
            let file = result["file"].as_str().unwrap_or_default();
            let label = written.iter().find(|(f, _)| f == file).map(|(_, l)| l.as_str()).unwrap_or(file);
            if result["ok"] == true {
                passed += 1;
            } else {
                ok = false;
                eprintln!(
                    "FAIL  php example {label} (exit {}):\n{}{}",
                    result["exit"],
                    result["stdout"].as_str().unwrap_or_default(),
                    result["stderr"].as_str().unwrap_or_default()
                );
            }
        }
        if passed + usize::from(!ok) == 0 && !output.status.success() {
            return Err(format!("the PHP example runner failed: {}", String::from_utf8_lossy(&output.stderr)));
        }
        eprintln!("php: {passed} of {} examples ran", written.len());
        ok &= passed == written.len();
    }

    let mut cargo = std::process::Command::new("cargo");
    cargo.args(["test", "--doc", "--quiet"]).current_dir(root);
    for name in &names {
        cargo.args(["-p", name]);
    }
    let status = cargo.status().map_err(|e| format!("cannot run cargo: {e}"))?;
    eprintln!("rust: doctests {}", if status.success() { "pass" } else { "FAIL" });
    Ok(ok && status.success())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn pairs_rust_with_php_and_wraps_programs() {
        let doc = "Parses a DSN.\n\nParse one:\n\n```\n# use utopia_dsn::Dsn;\nlet dsn = Dsn::parse(\"redis://h\")?;\nassert_eq!(dsn.host(), \"h\");\n# Ok::<(), Box<dyn std::error::Error>>(())\n```\n\n```php\nuse Utopia\\DSN\\DSN;\n\n$dsn = new DSN('redis://h');\n```\n\nMore text.";
        let (text, examples) = split(doc);
        assert_eq!(text, "Parses a DSN.\n\nMore text.");
        assert_eq!(examples.len(), 1);
        let e = &examples[0];
        assert_eq!(e.title.as_deref(), Some("Parse one"));
        let r = e.rust.as_ref().unwrap();
        assert_eq!(r.shown, "let dsn = Dsn::parse(\"redis://h\")?;\nassert_eq!(dsn.host(), \"h\");");
        assert!(
            r.program.starts_with("fn main() -> Result<(), Box<dyn std::error::Error>> {\n    use utopia_dsn::Dsn;")
        );
        let p = e.php.as_ref().unwrap();
        assert!(p.program.starts_with("<?php\n\nrequire __DIR__ . '/vendor/autoload.php';\n\nuse Utopia\\DSN\\DSN;"));
    }

    #[test]
    fn keeps_other_blocks_as_prose() {
        let (text, examples) = split("A table:\n\n```text\nnot code\n```");
        assert!(examples.is_empty());
        assert!(text.contains("```text") || text.contains("not code"));
    }
}
