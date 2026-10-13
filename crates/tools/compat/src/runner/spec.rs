//! `tests/compat/<lib>/spec.json`: the operations both runtimes implement,
//! what PHP API each covers, fuzz profiles, waivers and recorded quirks.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

use serde::Deserialize;
use serde_json::Value;

use super::cases::Case;
use super::scrub::Scrub;
pub use super::tasks::TaskSpec;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Spec {
    pub lib: String,
    #[serde(default)]
    pub description: String,
    /// Set once the library meets every rule of crates/CONVERSION.md §2;
    /// `compat check` then fails on any regression.
    #[serde(default)]
    pub complete: bool,
    pub php: PhpSpec,
    /// `{"crate": "crates/utopia/<lib>"}`; `null` for a library not
    /// converted by design.
    #[serde(default)]
    pub rust: Value,
    /// Set for a library retired from conversion (crates/CONVERSION.md §8):
    /// it fills a PHP gap that a Rust ecosystem standard covers. It has no
    /// crate, cases, operations or adapters; every command but `status`
    /// and `crates` skips it.
    #[serde(default)]
    pub not_converted: Option<NotConverted>,
    /// Services the cases need (`redis`, `postgres`, ...). Cases needing
    /// services are skipped by the PHP-free replay unless COMPAT_SERVICES=1.
    #[serde(default)]
    pub services: Vec<String>,
    /// State compared after every case unless the case says otherwise.
    #[serde(default)]
    pub state: Vec<String>,
    /// Masks applied to state snapshots, per kind (random values the
    /// library writes, such as lock tokens), each with a reason.
    #[serde(default)]
    pub state_mask: BTreeMap<String, Mask>,
    /// Nondeterministic values (the clock, ids made from it) replaced in
    /// every result and snapshot before comparing or recording; see
    /// [`super::scrub`].
    #[serde(default)]
    pub scrub: Vec<Scrub>,
    #[serde(default)]
    pub ops: BTreeMap<String, OpSpec>,
    /// Named generators, referenced as `{"gen": "ref", "name": ...}`.
    #[serde(default)]
    pub generators: BTreeMap<String, Value>,
    /// PHP symbol pattern -> reason (crates/CONVERSION.md §6).
    #[serde(default)]
    pub waivers: BTreeMap<String, String>,
    /// PHP test file (repository path) -> reason it has no case file.
    #[serde(default)]
    pub tests_waived: BTreeMap<String, String>,
    /// The library's tasks ported to Rust binaries, by name
    /// (crates/CONVERSION.md §2 rule 6; see [`super::tasks`]).
    #[serde(default)]
    pub tasks: BTreeMap<String, TaskSpec>,
    /// PHP task (as [`super::tasks::discover`] names it) -> why it has no
    /// Rust binary (an example, a benchmark).
    #[serde(default)]
    pub tasks_waived: BTreeMap<String, String>,
    /// CI shards of a long library (`bin/compat ci <lib>@<shard>`): shard
    /// name -> case file name prefixes. A case file belongs to the shard
    /// with the longest matching prefix; `"*"` names the shard of every
    /// other file, which also runs the library's fuzzing and coverage.
    #[serde(default)]
    pub shards: BTreeMap<String, Vec<String>>,
    /// PHP behaviour kept on purpose; each entry names its pinning case.
    #[serde(default)]
    pub quirks: Vec<Quirk>,
    /// Inputs PHP accepts that a Rust type makes unrepresentable
    /// (crates/CONVERSION.md §6), with PHP's behaviour for them.
    #[serde(default)]
    pub deviations: Vec<Deviation>,
    /// Benchmark settings for `compat bench`: `bound` names the benchmarks
    /// bound by work both runtimes wait on equally, with the reason.
    #[serde(default)]
    pub bench: Option<serde_json::Value>,
}

/// Why a library is not converted (see [`Spec::not_converted`]).
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct NotConverted {
    /// What the PHP library is for, and what Rust uses instead.
    pub reason: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Deviation {
    pub symbol: String,
    /// What PHP does with the input.
    pub php: String,
    pub reason: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PhpSpec {
    /// Source directories whose public API must be covered.
    pub src: Vec<String>,
    /// Test directories whose `*Test.php` files must be ported.
    #[serde(default)]
    pub tests: Vec<String>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct OpSpec {
    #[serde(default)]
    pub doc: String,
    /// PHP symbols this operation exercises: `Class::method`, `Class::*`,
    /// `Namespace\*` or `function()`.
    #[serde(default)]
    pub covers: Vec<String>,
    #[serde(default)]
    pub fuzz: Vec<FuzzSpec>,
    /// Paths replaced before comparing, for values that legitimately differ
    /// between runs (random ids). Prefer cross-checking over masking.
    #[serde(default)]
    pub mask: Option<Mask>,
    /// Services (of `compat.json`) this operation needs. Once any operation
    /// of a spec declares some, the PHP-free replay decides per case: a case
    /// needs services when it snapshots state or one of its steps' operations
    /// declares them (otherwise every case of a spec with `services` does).
    #[serde(default)]
    pub services: Vec<String>,
    /// Arguments under which a step of this operation opens none of its
    /// `services` (a database on a local engine): each pattern maps an
    /// argument to the values it may take, `null` standing for an absent
    /// argument and `"*"` for any present one. A step matching every entry
    /// of one pattern needs no services in the replay.
    #[serde(default)]
    pub services_unless: Vec<BTreeMap<String, Vec<Value>>>,
}

impl OpSpec {
    /// Whether a step of this operation with `args` needs its services.
    pub fn step_needs_services(&self, args: &Value) -> bool {
        if self.services.is_empty() {
            return false;
        }
        !self.services_unless.iter().any(|pattern| {
            pattern.iter().all(|(key, allowed)| {
                let given = args.get(key).filter(|v| !v.is_null());
                allowed.iter().any(|a| match (a, given) {
                    (Value::Null, None) => true,
                    (Value::String(any), Some(_)) if any == "*" => true,
                    (a, Some(v)) => a == v,
                    _ => false,
                })
            })
        })
    }
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FuzzSpec {
    #[serde(default)]
    pub name: String,
    /// Generator producing the operation's arguments.
    pub args: Value,
    #[serde(default = "default_iterations")]
    pub iterations: u64,
    /// Start fresh PHP and Rust driver processes for this profile, for
    /// functions with process-wide state that other operations also touch
    /// on one side only (PHP's PCRE cache and JIT switch, which the
    /// engine's own functions such as `filter_var` fill too).
    #[serde(default)]
    pub isolate: bool,
}

fn default_iterations() -> u64 {
    1000
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Mask {
    pub paths: Vec<String>,
    pub reason: String,
    /// Instead of hiding the values at `paths`, compare the objects there
    /// with their keys sorted: for an order neither runtime decides (a
    /// service's hash iteration order).
    #[serde(default)]
    pub order: bool,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Quirk {
    pub symbol: String,
    pub description: String,
    /// `<case file>#<case name>` pinning the behaviour.
    pub case: String,
}

pub fn lib_dir(root: &Path, lib: &str) -> PathBuf {
    root.join("tests/compat").join(lib)
}

/// Parts of a spec kept in `spec.d/*.json` (one per area or owner), merged
/// into `spec.json`. A name defined twice is an error.
#[derive(Debug, Default, Deserialize)]
#[serde(deny_unknown_fields)]
struct Fragment {
    #[serde(default)]
    ops: BTreeMap<String, OpSpec>,
    #[serde(default)]
    generators: BTreeMap<String, Value>,
    #[serde(default)]
    waivers: BTreeMap<String, String>,
    #[serde(default)]
    tests_waived: BTreeMap<String, String>,
    #[serde(default)]
    tasks: BTreeMap<String, TaskSpec>,
    #[serde(default)]
    tasks_waived: BTreeMap<String, String>,
    #[serde(default)]
    quirks: Vec<Quirk>,
    #[serde(default)]
    deviations: Vec<Deviation>,
    #[serde(default)]
    scrub: Vec<Scrub>,
}

fn merge<V>(into: &mut BTreeMap<String, V>, from: BTreeMap<String, V>, what: &str, file: &Path) -> Result<(), String> {
    for (k, v) in from {
        if into.insert(k.clone(), v).is_some() {
            return Err(format!("{}: {what} `{k}` is defined twice", file.display()));
        }
    }
    Ok(())
}

pub fn load(root: &Path, lib: &str) -> Result<Spec, String> {
    let path = lib_dir(root, lib).join("spec.json");
    let raw = std::fs::read_to_string(&path).map_err(|e| format!("{}: {e}", path.display()))?;
    let mut spec: Spec = serde_json::from_str(&raw).map_err(|e| format!("{}: {e}", path.display()))?;
    let mut fragments: Vec<PathBuf> = std::fs::read_dir(lib_dir(root, lib).join("spec.d"))
        .into_iter()
        .flatten()
        .flatten()
        .map(|e| e.path())
        .filter(|p| p.extension().is_some_and(|e| e == "json"))
        .collect();
    fragments.sort();
    for file in fragments {
        let text = std::fs::read_to_string(&file).map_err(|e| format!("{}: {e}", file.display()))?;
        let f: Fragment = serde_json::from_str(&text).map_err(|e| format!("{}: {e}", file.display()))?;
        merge(&mut spec.ops, f.ops, "operation", &file)?;
        merge(&mut spec.generators, f.generators, "generator", &file)?;
        merge(&mut spec.waivers, f.waivers, "waiver", &file)?;
        merge(&mut spec.tests_waived, f.tests_waived, "waived test", &file)?;
        merge(&mut spec.tasks, f.tasks, "task", &file)?;
        merge(&mut spec.tasks_waived, f.tasks_waived, "waived task", &file)?;
        spec.quirks.extend(f.quirks);
        spec.deviations.extend(f.deviations);
        spec.scrub.extend(f.scrub);
    }
    if spec.lib != lib {
        return Err(format!("{}: `lib` is `{}`, expected `{lib}`", path.display(), spec.lib));
    }
    if let Some(retired) = &spec.not_converted {
        if retired.reason.trim().is_empty() {
            return Err(format!("{}: `not_converted` has no reason", path.display()));
        }
        if spec.complete || !spec.rust.is_null() || !spec.ops.is_empty() {
            return Err(format!(
                "{}: a library not converted by design has no `rust` crate, no `ops` and is not `complete`",
                path.display()
            ));
        }
    }
    for (kind, mask) in &spec.state_mask {
        if mask.reason.trim().is_empty() {
            return Err(format!("{}: state_mask `{kind}` has no reason", path.display()));
        }
    }
    if let Some(s) = spec.scrub.iter().find(|s| s.reason.trim().is_empty()) {
        return Err(format!("{}: scrub `{:?}` has no reason", path.display(), s.kind));
    }
    for (name, op) in &spec.ops {
        if let Some(mask) = &op.mask
            && mask.reason.trim().is_empty()
        {
            return Err(format!("{}: op `{name}` masks values without a reason", path.display()));
        }
    }
    Ok(spec)
}

/// Libraries that have a spec, in name order, but those not converted by
/// design ([`retired`]): what `run`, `ci`, `coverage`, `report` and the
/// replay go through.
pub fn libs(root: &Path) -> Vec<String> {
    specs(root).into_iter().filter(|(_, retired)| retired.is_none()).map(|(lib, _)| lib).collect()
}

/// Libraries not converted by design, with the reason, in name order.
pub fn retired(root: &Path) -> Vec<(String, String)> {
    specs(root).into_iter().filter_map(|(lib, retired)| Some((lib, retired?))).collect()
}

/// Whether `lib` is not converted by design; its reason.
pub fn retired_reason(root: &Path, lib: &str) -> Option<String> {
    not_converted(&lib_dir(root, lib).join("spec.json"))
}

/// Every library with a spec, and its `not_converted` reason.
fn specs(root: &Path) -> Vec<(String, Option<String>)> {
    let mut out: Vec<(String, Option<String>)> = std::fs::read_dir(root.join("tests/compat"))
        .into_iter()
        .flatten()
        .flatten()
        .filter(|e| e.path().join("spec.json").is_file())
        .filter_map(|e| {
            let lib = e.file_name().to_str()?.to_owned();
            Some((lib, not_converted(&e.path().join("spec.json"))))
        })
        .collect();
    out.sort();
    out
}

/// The `not_converted.reason` of a spec file, read without the rest.
fn not_converted(path: &Path) -> Option<String> {
    let text = std::fs::read_to_string(path).ok()?;
    let spec: Value = serde_json::from_str(&text).ok()?;
    spec.get("not_converted")?.get("reason")?.as_str().map(str::to_owned)
}

impl Spec {
    /// The shard a case file belongs to (see [`Spec::shards`]); `None` for
    /// a library without shards.
    pub fn shard_of(&self, file: &str) -> Option<&str> {
        let mut best: Option<(&str, usize)> = None;
        for (shard, prefixes) in &self.shards {
            for prefix in prefixes {
                let len = if prefix == "*" {
                    0
                } else if file.starts_with(prefix.as_str()) {
                    prefix.len()
                } else {
                    continue;
                };
                if best.is_none_or(|(_, l)| len > l) {
                    best = Some((shard, len));
                }
            }
        }
        best.map(|(shard, _)| shard)
    }

    /// The shard that holds every file no other shard claims (`"*"`).
    pub fn default_shard(&self) -> Option<&str> {
        self.shards.iter().find(|(_, p)| p.iter().any(|p| p == "*")).map(|(s, _)| s.as_str())
    }

    /// Whether the PHP-free replay needs services for `case`, which
    /// snapshots `kinds` (a kind on a service, see
    /// [`super::snapshot::needs_service`]), lists the `services`
    /// requirement, or has a step needing its operation's services (see
    /// [`OpSpec::services`] and [`OpSpec::services_unless`]).
    pub fn case_needs_services(&self, case: &Case, kinds: &[String]) -> bool {
        if kinds.iter().any(|k| super::snapshot::needs_service(k)) || case.requires.iter().any(|r| r == "services") {
            return true;
        }
        if !self.ops.values().any(|o| !o.services.is_empty()) {
            return !self.services.is_empty();
        }
        case.steps.iter().any(|s| self.ops.get(&s.op).is_some_and(|o| o.step_needs_services(&s.args)))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn write(root: &Path, lib: &str, spec: &str) {
        let dir = lib_dir(root, lib);
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("spec.json"), spec).unwrap();
    }

    #[test]
    fn libraries_not_converted_by_design_are_recorded_apart() {
        let root = std::env::temp_dir().join(format!("compat-spec-{}", std::process::id()));
        write(
            &root,
            "kept",
            r#"{"lib": "kept", "php": {"src": []}, "rust": {"crate": "crates/utopia/kept"}, "ops": {}}"#,
        );
        write(
            &root,
            "gone",
            r#"{"lib": "gone", "php": {"src": []}, "rust": null, "not_converted": {"reason": "Rust has a standard"}}"#,
        );
        write(
            &root,
            "empty",
            r#"{"lib": "empty", "php": {"src": []}, "rust": null, "not_converted": {"reason": " "}}"#,
        );
        write(
            &root,
            "ported",
            r#"{"lib": "ported", "php": {"src": []}, "complete": true, "rust": null, "not_converted": {"reason": "x"}}"#,
        );

        assert_eq!(libs(&root), ["kept"]);
        assert_eq!(retired(&root).iter().map(|(l, _)| l.as_str()).collect::<Vec<_>>(), ["empty", "gone", "ported"]);
        assert_eq!(retired_reason(&root, "gone").as_deref(), Some("Rust has a standard"));
        assert_eq!(retired_reason(&root, "kept"), None);
        assert!(load(&root, "gone").unwrap().not_converted.is_some());
        assert!(load(&root, "empty").unwrap_err().contains("has no reason"));
        assert!(load(&root, "ported").unwrap_err().contains("not converted by design"));
        std::fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn the_repository_records_psr7_as_not_converted() {
        let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..");
        let spec = load(&root, "psr7").unwrap();
        assert!(spec.not_converted.is_some_and(|n| n.reason.contains("http crate")));
        assert!(!libs(&root).iter().any(|l| l == "psr7"));
    }
}
