//! The public interface of a library, method by method: each PHP method's
//! signature against its Rust counterpart's, for `compat report`.
//!
//! Static or instance, every parameter (matched by name, then by position)
//! and the return type are compared. PHP types map to Rust ones by kind:
//! `string` to `&str`, `String`, `&[u8]` or `Vec<u8>`, `int` to the integer
//! types, `?T` and a `null` default to `Option<T>`, `T|false` returns and
//! exceptions to `Option<T>` or `Result<T, E>`, `array` to the collections,
//! `mixed` to PHP value models, a class to the Rust type of the same name.
//! A mapping it cannot decide is left for review, never called a match.

use serde_json::{Value, json};

use super::docs::{Function, RustItem};

/// How a PHP type and a Rust type relate.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Fit {
    Same,
    Compatible,
    Review,
    Differs,
}

impl Fit {
    pub fn as_str(self) -> &'static str {
        match self {
            Fit::Same => "same",
            Fit::Compatible => "compatible",
            Fit::Review => "review",
            Fit::Differs => "differs",
        }
    }

    fn worst(self, other: Fit) -> Fit {
        let rank = |f: Fit| match f {
            Fit::Same => 0,
            Fit::Compatible => 1,
            Fit::Review => 2,
            Fit::Differs => 3,
        };
        if rank(other) > rank(self) { other } else { self }
    }
}

/// `getProperty` → `get_property`.
fn snake(name: &str) -> String {
    let mut out = String::new();
    for (i, c) in name.chars().enumerate() {
        if c.is_ascii_uppercase() {
            if i > 0 && !out.ends_with('_') {
                out.push('_');
            }
            out.push(c.to_ascii_lowercase());
        } else {
            out.push(c);
        }
    }
    out
}

/// Strips what does not change the kind of a Rust type: references,
/// lifetimes, smart pointers, `impl Into<_>`/`AsRef<_>`, `Cow<_>`.
fn core(rust: &str) -> String {
    let mut t = rust.trim().to_owned();
    loop {
        let before = t.clone();
        if let Some(rest) = t.strip_prefix('&') {
            t = rest.trim_start().to_owned();
            if t.starts_with('\'') {
                t = t.split_once(' ').map(|(_, r)| r.to_owned()).unwrap_or_default();
            }
            t = t.strip_prefix("mut ").unwrap_or(&t).to_owned();
        }
        for wrapper in ["Box<", "Arc<", "Rc<", "Cow<", "impl Into<", "impl AsRef<"] {
            if let Some(inner) = t.strip_prefix(wrapper).and_then(|r| r.strip_suffix('>')) {
                let inner = inner.trim();
                // `Cow<'a, str>`: drop the lifetime argument.
                t = match inner.split_once(',') {
                    Some((first, rest)) if first.trim().starts_with('\'') => rest.trim().to_owned(),
                    _ => inner.to_owned(),
                };
            }
        }
        if t == before {
            return t;
        }
    }
}

/// `Option<T>` → `T`.
fn option(rust: &str) -> Option<&str> {
    rust.trim().strip_prefix("Option<").and_then(|r| r.strip_suffix('>'))
}

/// `Result<T, E>` (or a crate's `Result<T>`) → `T`.
fn result(rust: &str) -> Option<String> {
    let inner = rust.trim().strip_prefix("Result<").and_then(|r| r.strip_suffix('>'))?;
    let mut depth = 0;
    for (i, c) in inner.char_indices() {
        match c {
            '<' | '(' | '[' => depth += 1,
            '>' | ')' | ']' => depth -= 1,
            ',' if depth == 0 => return Some(inner[..i].trim().to_owned()),
            _ => {}
        }
    }
    Some(inner.trim().to_owned())
}

fn last_segment(t: &str) -> &str {
    let head = t.split('<').next().unwrap_or(t);
    head.rsplit("::").next().unwrap_or(head).trim()
}

const STRINGS: [&str; 10] = ["str", "String", "[u8]", "Vec<u8>", "Bytes", "Str", "Path", "PathBuf", "OsStr", "char"];
const INTEGERS: [&str; 12] = ["i8", "i16", "i32", "i64", "i128", "isize", "u8", "u16", "u32", "u64", "u128", "usize"];
const VALUES: [&str; 6] = ["Value", "Zval", "Input", "Any", "View", "Mixed"];
const COLLECTIONS: [&str; 9] =
    ["Vec", "Array", "BTreeMap", "HashMap", "IndexMap", "Map", "BTreeSet", "HashSet", "Entries"];

/// One PHP type (no union) against a Rust type already stripped by [`core`].
fn single(php: &str, rust: &str) -> Fit {
    let php = php.trim_start_matches('\\');
    let rust_name = last_segment(rust);
    let generic = rust.len() == 1 && rust.chars().all(|c| c.is_ascii_uppercase());
    match php.to_ascii_lowercase().as_str() {
        "string" if STRINGS.contains(&rust) || rust.starts_with("[u8") => Fit::Same,
        "int" if rust == "i64" => Fit::Same,
        "int" if INTEGERS.contains(&rust) => Fit::Compatible,
        "float" if rust == "f64" => Fit::Same,
        "float" if rust == "f32" => Fit::Compatible,
        "bool" if rust == "bool" => Fit::Same,
        "array" | "iterable" if COLLECTIONS.contains(&rust_name) || rust.starts_with('[') || rust.starts_with('(') => {
            Fit::Compatible
        }
        "mixed" if VALUES.contains(&rust_name) || generic => Fit::Compatible,
        "mixed" => Fit::Review,
        "callable" | "closure"
            if rust.starts_with("impl Fn") || rust.starts_with("dyn Fn") || generic || rust.contains("Fn(") =>
        {
            Fit::Compatible
        }
        "void" | "never" if rust.is_empty() || rust == "()" || rust == "!" => Fit::Same,
        "static" | "self" if rust == "Self" || rust_name == "Self" => Fit::Same,
        "string" | "int" | "float" | "bool" | "void" | "never" => {
            // Another primitive is a different type; a named Rust type may
            // model the value (an enum of PHP's string constants, a newtype).
            let primitive = STRINGS.contains(&rust)
                || INTEGERS.contains(&rust)
                || matches!(rust, "f32" | "f64" | "bool" | "" | "()");
            if primitive { Fit::Differs } else { Fit::Review }
        }
        "array" | "iterable" | "callable" | "closure" | "static" | "self" | "object" => Fit::Review,
        _ => {
            let class = php.rsplit('\\').next().unwrap_or(php);
            if class.eq_ignore_ascii_case(rust_name) { Fit::Same } else { Fit::Review }
        }
    }
}

/// A PHP type (unions, `?T`, `null`, `false` included) against a Rust type.
/// `optional`: the PHP parameter has a default, so Rust may make it an
/// `Option` or fix it.
pub fn fit(php: &str, rust: &str, returning: bool) -> (Fit, Option<String>) {
    let php = php.trim();
    if php.is_empty() {
        return (Fit::Review, Some("PHP declares no type".into()));
    }
    let mut members: Vec<&str> = php.trim_start_matches('?').split('|').map(str::trim).collect();
    let mut nullable = php.starts_with('?') || members.iter().any(|m| m.eq_ignore_ascii_case("null"));
    let falsy = members.iter().any(|m| m.eq_ignore_ascii_case("false"));
    members.retain(|m| !m.eq_ignore_ascii_case("null") && !m.eq_ignore_ascii_case("false"));
    if members.iter().any(|m| m.eq_ignore_ascii_case("mixed")) {
        nullable = false;
    }
    let mut rust = rust.trim().to_owned();
    let mut note = None;
    if returning && let Some(ok) = result(&rust) {
        rust = ok;
    }
    let r_optional = option(&rust).map(str::to_owned);
    if let Some(inner) = &r_optional {
        rust = inner.clone();
    }
    let rust = core(&rust);
    if (nullable || falsy) && r_optional.is_none() && !VALUES.contains(&last_segment(&rust)) {
        note = Some(if returning {
            format!("PHP may return {}; Rust always returns a value", if falsy { "false" } else { "null" })
        } else {
            "PHP accepts null; Rust requires a value".to_owned()
        });
    }
    if !nullable && !falsy && r_optional.is_some() && !returning {
        note = Some("Rust takes an Option where PHP requires a value".into());
    }
    let mut verdict = if members.len() > 1 {
        let all: Vec<Fit> = members.iter().map(|m| single(m, &rust)).collect();
        if VALUES.contains(&last_segment(&rust)) || rust.len() == 1 {
            Fit::Compatible
        } else if all.contains(&Fit::Same) || all.contains(&Fit::Compatible) {
            // A Rust enum or one arm of the union.
            Fit::Review
        } else {
            Fit::Review
        }
    } else {
        single(members.first().copied().unwrap_or("mixed"), &rust)
    };
    if note.is_some() {
        verdict = verdict.worst(Fit::Review);
    }
    (verdict, note)
}

/// A PHP method (from the PHP driver's `$docs`) against one Rust function.
pub fn compare(symbol: &str, php: &Value, rust: &RustItem, f: &Function) -> Value {
    let mut notes: Vec<String> = Vec::new();
    let mut verdict = Fit::Same;
    let constructor = symbol.ends_with("::__construct");
    let is_static = php["static"].as_bool().unwrap_or(false) || !symbol.contains("::");
    let instance = f.receiver.is_some();
    let kind = if constructor {
        if instance {
            verdict = verdict.worst(Fit::Review);
            notes.push("PHP constructor; Rust method takes self".into());
        }
        "constructor"
    } else if is_static && instance {
        verdict = verdict.worst(Fit::Review);
        notes.push("PHP static method; Rust takes self".into());
        "static"
    } else if !is_static && !instance {
        verdict = verdict.worst(Fit::Review);
        notes.push("PHP instance method; Rust associated function".into());
        "instance"
    } else if is_static {
        "static"
    } else {
        "instance"
    };

    let php_params: Vec<&Value> = php["params"].as_array().map(|a| a.iter().collect()).unwrap_or_default();
    let mut used = vec![false; f.params.len()];
    let mut rows = Vec::new();
    let mut positional = 0usize;
    for (i, p) in php_params.iter().enumerate() {
        let name = p["name"].as_str().unwrap_or_default();
        let snaked = snake(name);
        let words: Vec<&str> = snaked.split('_').collect();
        let by_name = f.params.iter().position(|(n, _)| n.trim_start_matches('_') == snaked).or_else(|| {
            // `currentCounter` / `current`: one name's words within the other's.
            f.params.iter().position(|(n, _)| {
                let theirs: Vec<&str> = n.trim_start_matches('_').split('_').collect();
                !theirs.is_empty()
                    && (theirs.iter().all(|w| words.contains(w)) || words.iter().all(|w| theirs.contains(w)))
            })
        });
        let matched = by_name.or_else(|| {
            // Next unused Rust parameter at or after this position.
            (positional.max(i.min(f.params.len()))..f.params.len()).find(|k| !used[*k])
        });
        let php_type = p["type"].as_str().unwrap_or_default();
        let optional = p["optional"].as_bool().unwrap_or(false);
        let default = p["default"].as_str();
        match matched.filter(|k| !used[*k]) {
            Some(k) => {
                used[k] = true;
                positional = k + 1;
                let (rn, rt) = &f.params[k];
                let (mut fit, mut note) = fit(php_type, rt, false);
                if default == Some("NULL") && option(rt).is_some() {
                    note = None;
                    fit = fit.worst(Fit::Compatible);
                }
                let renamed = rn.trim_start_matches('_') != snaked;
                if renamed {
                    fit = fit.worst(Fit::Compatible);
                }
                verdict = verdict.worst(fit);
                rows.push(json!({
                    "php": { "name": name, "type": php_type, "default": default, "optional": optional, "variadic": p["variadic"], "reference": p["reference"] },
                    "rust": { "name": rn, "type": rt },
                    "fit": fit.as_str(),
                    "note": note.or_else(|| renamed.then(|| format!("named `{rn}` in Rust"))),
                }));
            }
            None => {
                let fit = if optional { Fit::Review } else { Fit::Differs };
                verdict = verdict.worst(fit);
                rows.push(json!({
                    "php": { "name": name, "type": php_type, "default": default, "optional": optional, "variadic": p["variadic"], "reference": p["reference"] },
                    "rust": null,
                    "fit": fit.as_str(),
                    "note": if optional { format!("no Rust parameter: PHP's default {} applies", default.unwrap_or("value")) } else { "required in PHP, absent in Rust".to_owned() },
                }));
            }
        }
    }
    for (k, (rn, rt)) in f.params.iter().enumerate() {
        if !used[k] {
            verdict = verdict.worst(Fit::Review);
            rows.push(json!({
                "php": null,
                "rust": { "name": rn, "type": rt },
                "fit": "review",
                "note": "Rust-only parameter",
            }));
        }
    }

    let php_return = php["returns"].as_str().unwrap_or_default();
    let (rfit, rnote) = if constructor {
        let ok = f.output.is_empty()
            || f.output.contains("Self")
            || f.output.contains(&rust.owner.clone().unwrap_or_default());
        (if ok || !f.output.is_empty() { Fit::Compatible } else { Fit::Review }, None)
    } else if php_return.is_empty() {
        (Fit::Review, Some("PHP declares no return type".to_owned()))
    } else {
        fit(php_return, &f.output, true)
    };
    verdict = verdict.worst(rfit);
    let throws = result(&f.output).is_some();
    json!({
        "rust": rust.path,
        "kind": kind,
        "receiver": f.receiver,
        "async": f.is_async,
        "params": rows,
        "returns": {
            "php": if constructor { "static" } else { php_return },
            "rust": if f.output.is_empty() { "()" } else { f.output.as_str() },
            "fit": rfit.as_str(),
            "note": rnote,
            "throws": throws,
        },
        "notes": notes,
        "verdict": verdict.as_str(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn scalars_map_by_kind() {
        assert_eq!(fit("string", "&str", false).0, Fit::Same);
        assert_eq!(fit("string", "&[u8]", false).0, Fit::Same);
        assert_eq!(fit("int", "i64", false).0, Fit::Same);
        assert_eq!(fit("int", "usize", false).0, Fit::Compatible);
        assert_eq!(fit("bool", "bool", true).0, Fit::Same);
        assert_eq!(fit("string", "bool", false).0, Fit::Differs);
    }

    #[test]
    fn nullability_and_errors() {
        assert_eq!(fit("?string", "Option<&str>", false).0, Fit::Same);
        let (f, note) = fit("?string", "&str", false);
        assert_eq!(f, Fit::Review);
        assert!(note.is_some());
        assert_eq!(fit("string|false", "Option<String>", true).0, Fit::Same);
        assert_eq!(fit("string", "Result<String, Error>", true).0, Fit::Same);
        assert_eq!(fit("void", "", true).0, Fit::Same);
        assert_eq!(fit("void", "Result<(), Error>", true).0, Fit::Same);
    }

    #[test]
    fn wrappers_and_classes() {
        assert_eq!(fit("string", "impl Into<String>", false).0, Fit::Same);
        assert_eq!(fit("string", "Cow<'a, str>", true).0, Fit::Same);
        assert_eq!(fit("Utopia\\Auth\\Store", "&Store", false).0, Fit::Same);
        assert_eq!(fit("static", "&mut Self", true).0, Fit::Same);
        assert_eq!(fit("mixed", "&Zval", false).0, Fit::Compatible);
        assert_eq!(fit("array", "Vec<String>", false).0, Fit::Compatible);
    }
}
