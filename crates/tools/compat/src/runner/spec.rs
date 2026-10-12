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
    #[serde(default)]
    pub rust: Value,
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

/// Libraries that have a spec, in name order.
pub fn libs(root: &Path) -> Vec<String> {
    let mut out: Vec<String> = std::fs::read_dir(root.join("tests/compat"))
        .into_iter()
        .flatten()
        .flatten()
        .filter(|e| e.path().join("spec.json").is_file())
        .filter_map(|e| e.file_name().to_str().map(str::to_owned))
        .collect();
    out.sort();
    out
}

impl Spec {
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
