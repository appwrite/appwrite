//! Case files: `tests/compat/<lib>/cases/*.json`.
//!
//! ```json
//! {
//!   "source": "packages/validators/tests/TextTest.php",
//!   "cases": [
//!     { "name": "short form", "op": "validate", "args": {...}, "expect": true },
//!     { "name": "steps", "steps": [
//!         { "op": "lock.new", "args": {"key": "${ns}:a"}, "bind": "l" },
//!         { "op": "lock.acquire", "args": {"lock": {"$ref": "l"}} }
//!     ]},
//!     { "name": "written by one runtime, read by the other", "interop": true, "steps": [
//!         { "side": "a", "op": "cache.save", "args": {...} },
//!         { "side": "b", "op": "cache.load", "args": {...} }
//!     ]}
//!   ]
//! }
//! ```
//!
//! `expect` is what PHP produced (written by `compat record`); an error is
//! `{"$error": {"class": ..., "message": ...}}`.

use std::path::{Path, PathBuf};

use serde_json::Value;

use super::spec::Mask;

pub struct CaseFile {
    pub path: PathBuf,
    /// The file as authored; `compat record` writes expectations into it.
    pub raw: Value,
    pub sources: Vec<String>,
    pub cases: Vec<Case>,
}

pub struct Case {
    pub name: String,
    pub steps: Vec<Step>,
    /// Run twice, sides a/b as PHP/Rust then Rust/PHP, on one shared namespace.
    pub interop: bool,
    /// State to snapshot and compare; `None` means the spec default.
    pub state: Option<Vec<String>>,
    /// Written in the short single-step form.
    pub short: bool,
}

pub struct Step {
    pub op: String,
    pub args: Value,
    pub bind: Option<String>,
    pub expect: Option<Value>,
    pub side: Option<String>,
    pub mask: Option<Mask>,
}

pub fn load_dir(dir: &Path) -> Result<Vec<CaseFile>, String> {
    let mut paths: Vec<PathBuf> = std::fs::read_dir(dir)
        .into_iter()
        .flatten()
        .flatten()
        .map(|e| e.path())
        .filter(|p| p.extension().is_some_and(|e| e == "json"))
        .collect();
    paths.sort();
    paths.iter().map(|p| load_file(p)).collect()
}

pub fn load_file(path: &Path) -> Result<CaseFile, String> {
    let at = |m: String| format!("{}: {m}", path.display());
    let text = std::fs::read_to_string(path).map_err(|e| at(e.to_string()))?;
    let raw: Value = serde_json::from_str(&text).map_err(|e| at(e.to_string()))?;
    let sources = match raw.get("source") {
        Some(Value::String(s)) => vec![s.clone()],
        Some(Value::Array(a)) => a.iter().filter_map(Value::as_str).map(str::to_owned).collect(),
        _ => Vec::new(),
    };
    let list = raw.get("cases").and_then(Value::as_array).ok_or_else(|| at("missing `cases`".into()))?;
    let mut cases = Vec::with_capacity(list.len());
    let mut names = std::collections::HashSet::new();
    for c in list {
        let name = c.get("name").and_then(Value::as_str).ok_or_else(|| at("a case has no `name`".into()))?.to_owned();
        if !names.insert(name.clone()) {
            return Err(at(format!("duplicate case name `{name}`")));
        }
        let interop = c.get("interop").and_then(Value::as_bool).unwrap_or(false);
        let state = c
            .get("state")
            .and_then(Value::as_array)
            .map(|a| a.iter().filter_map(Value::as_str).map(str::to_owned).collect());
        let (steps, short) = match c.get("steps").and_then(Value::as_array) {
            Some(list) => (
                list.iter()
                    .map(|s| step(s).map_err(|m| at(format!("case `{name}`: {m}"))))
                    .collect::<Result<Vec<_>, _>>()?,
                false,
            ),
            None => (vec![step(c).map_err(|m| at(format!("case `{name}`: {m}")))?], true),
        };
        for s in &steps {
            match (&s.side, interop) {
                (Some(side), true) if side == "a" || side == "b" => {}
                (None, false) => {}
                (_, true) => return Err(at(format!("case `{name}`: interop steps need `side`: \"a\" or \"b\""))),
                (Some(_), false) => return Err(at(format!("case `{name}`: `side` is only for interop cases"))),
            }
        }
        cases.push(Case { name, steps, interop, state, short });
    }
    Ok(CaseFile { path: path.to_path_buf(), raw, sources, cases })
}

fn step(v: &Value) -> Result<Step, String> {
    let op = v.get("op").and_then(Value::as_str).ok_or("missing `op`")?.to_owned();
    let mask = match v.get("mask") {
        None => None,
        Some(m) => {
            let mask: Mask = serde_json::from_value(m.clone()).map_err(|e| format!("bad `mask`: {e}"))?;
            if mask.reason.trim().is_empty() {
                return Err("`mask` needs a `reason`".into());
            }
            Some(mask)
        }
    };
    Ok(Step {
        op,
        args: v.get("args").cloned().unwrap_or(Value::Object(Default::default())),
        bind: v.get("bind").and_then(Value::as_str).map(str::to_owned),
        expect: v.get("expect").cloned(),
        side: v.get("side").and_then(Value::as_str).map(str::to_owned),
        mask,
    })
}

/// Writes PHP's results into the authored file as `expect`.
/// `results[case][step]` is `None` for steps left as they are.
pub fn write_expectations(file: &CaseFile, results: &[Vec<Option<Value>>]) -> Result<(), String> {
    let mut raw = file.raw.clone();
    let cases = raw.get_mut("cases").and_then(Value::as_array_mut).ok_or("missing cases")?;
    for ((case, parsed), steps) in cases.iter_mut().zip(&file.cases).zip(results) {
        if parsed.short {
            if let Some(Some(v)) = steps.first() {
                set_expect(case, v.clone());
            }
            continue;
        }
        if let Some(list) = case.get_mut("steps").and_then(Value::as_array_mut) {
            for (step, result) in list.iter_mut().zip(steps) {
                if let Some(v) = result {
                    set_expect(step, v.clone());
                }
            }
        }
    }
    let mut text = serde_json::to_string_pretty(&raw).map_err(|e| e.to_string())?;
    text.push('\n');
    std::fs::write(&file.path, text).map_err(|e| format!("{}: {e}", file.path.display()))
}

fn set_expect(target: &mut Value, v: Value) {
    if let Value::Object(o) = target {
        o.insert("expect".into(), v);
    }
}
