//! Running cases and fuzz inputs against both drivers and comparing them.

use std::collections::HashMap;
use std::path::Path;
use std::time::{SystemTime, UNIX_EPOCH};

use serde_json::{Value, json};

use super::cases::{self, Case, CaseFile, Step};
use super::compare::{diff, mask, normalize, short, substitute};
use super::config::Config;
use super::generate::{self, Rng};
use super::process::Driver;
use super::snapshot;
use super::spec::{self, Mask, Spec};
use crate::adapter::{Fault, OpResult, Outcome, Session};

/// What went wrong in a run: behaviour differences and harness faults.
#[derive(Default)]
pub struct Report {
    pub passed: usize,
    pub differences: Vec<String>,
    pub faults: Vec<String>,
}

impl Report {
    pub fn ok(&self) -> bool {
        self.differences.is_empty() && self.faults.is_empty()
    }

    pub fn merge(&mut self, other: Report) {
        self.passed += other.passed;
        self.differences.extend(other.differences);
        self.faults.extend(other.faults);
    }
}

pub struct FuzzOptions {
    pub op: Option<String>,
    pub iterations: Option<u64>,
    pub seed: u64,
    pub save: bool,
}

pub struct Engine {
    pub cfg: Config,
    php: Option<Driver>,
    rust: Option<Driver>,
    run: String,
    counter: u64,
    pub verbose: bool,
}

/// The comparable JSON of one step's outcome.
fn comparable(result: &Outcome, ns: &str, masks: &[&Mask]) -> Value {
    let mut v = normalize(&result.to_json(), ns);
    for m in masks {
        mask(&mut v, &m.paths);
    }
    v
}

fn step_masks<'a>(spec: &'a Spec, step: &'a Step) -> Vec<&'a Mask> {
    spec.ops.get(&step.op).and_then(|o| o.mask.as_ref()).into_iter().chain(step.mask.as_ref()).collect()
}

fn label(lib: &str, file: &CaseFile, case: &Case) -> String {
    let name = file.path.file_name().and_then(|n| n.to_str()).unwrap_or("?");
    format!("{lib}/{name}#{}", case.name)
}

fn fault_text(side: &str, f: &Fault) -> String {
    format!("{side} fault: {f}")
}

impl Engine {
    pub fn new(cfg: Config) -> Self {
        let nanos = SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_nanos()).unwrap_or(0);
        let mut seed = (nanos as u64) ^ ((std::process::id() as u64) << 32);
        let mut run = String::new();
        for _ in 0..5 {
            run.push(char::from_digit((seed % 36) as u32, 36).unwrap_or('0'));
            seed /= 36;
        }
        Self { cfg, php: None, rust: None, run, counter: 0, verbose: false }
    }

    fn ensure(&mut self, side: &'static str) -> Result<(), String> {
        let slot = if side == "php" { &mut self.php } else { &mut self.rust };
        if slot.is_none() {
            let command = if side == "php" { &self.cfg.php } else { &self.cfg.rust };
            *slot = Some(Driver::spawn(side, command, &self.cfg.root)?);
        }
        Ok(())
    }

    fn drivers(&mut self) -> Result<(&mut Driver, &mut Driver), String> {
        self.ensure("php")?;
        self.ensure("rust")?;
        match (self.php.as_mut(), self.rust.as_mut()) {
            (Some(p), Some(r)) => Ok((p, r)),
            _ => Err("drivers not started".into()),
        }
    }

    pub fn driver(&mut self, side: &'static str) -> Result<&mut Driver, String> {
        self.ensure(side)?;
        let slot = if side == "php" { &mut self.php } else { &mut self.rust };
        slot.as_mut().ok_or_else(|| "driver not started".into())
    }

    /// A fresh namespace: short, lowercase, safe as a key, table or schema name.
    fn ns(&mut self, tag: char) -> String {
        self.counter += 1;
        format!("cx{}{}{tag}", self.run, self.counter)
    }

    fn configure(&mut self, side: &'static str, ns: &str) -> Result<(), String> {
        let services = self.cfg.services_for(side);
        self.driver(side)?.configure(ns, &services)
    }

    fn state_kinds(spec: &Spec, case: &Case) -> Vec<String> {
        case.state.clone().unwrap_or_else(|| spec.state.clone())
    }

    /// A normalized, masked snapshot of one kind of state.
    fn state(&self, spec: &Spec, kind: &str, ns: &str) -> Result<Value, String> {
        let mut v = normalize(&snapshot::snapshot(&self.cfg, kind, ns)?, ns);
        if let Some(m) = spec.state_mask.get(kind) {
            mask(&mut v, &m.paths);
        }
        Ok(v)
    }

    fn cleanup(&self, kinds: &[String], ns: &str) -> Result<(), String> {
        kinds.iter().try_for_each(|k| snapshot::cleanup(&self.cfg, k, ns))
    }

    /// Runs every case of `lib` (optionally only those whose label contains `filter`).
    pub fn run_lib(&mut self, lib: &str, filter: Option<&str>, fail_fast: bool) -> Result<Report, String> {
        let spec = spec::load(&self.cfg.root, lib)?;
        let files = cases::load_dir(&spec::lib_dir(&self.cfg.root, lib).join("cases"))?;
        let mut report = Report::default();
        for file in &files {
            for case in &file.cases {
                let name = label(lib, file, case);
                if filter.is_some_and(|f| !name.contains(f)) {
                    continue;
                }
                let r =
                    if case.interop { self.interop_case(lib, &spec, case)? } else { self.diff_case(lib, &spec, case)? };
                let failed = !r.differences.is_empty() || !r.faults.is_empty();
                if failed {
                    for d in &r.differences {
                        report.differences.push(format!("{name}: {d}"));
                    }
                    for f in &r.faults {
                        report.faults.push(format!("{name}: {f}"));
                    }
                    if fail_fast {
                        return Ok(report);
                    }
                } else {
                    report.passed += 1;
                    if self.verbose {
                        eprintln!("  ok  {name}");
                    }
                }
            }
        }
        Ok(report)
    }

    /// Both runtimes run every step, each in its own namespace; results and
    /// final state must match each other and the recorded expectations.
    fn diff_case(&mut self, lib: &str, spec: &Spec, case: &Case) -> Result<Report, String> {
        let mut report = Report::default();
        let kinds = Self::state_kinds(spec, case);
        let (ns_p, ns_r) = (self.ns('p'), self.ns('r'));
        self.cleanup(&kinds, &ns_p)?;
        self.cleanup(&kinds, &ns_r)?;
        self.configure("php", &ns_p)?;
        self.configure("rust", &ns_r)?;
        let (mut bp, mut br) = (HashMap::new(), HashMap::new());
        for (i, step) in case.steps.iter().enumerate() {
            let at = format!("step {} `{}`", i + 1, step.op);
            let ap = match substitute(&step.args, &bp, &ns_p) {
                Ok(a) => a,
                Err(e) => {
                    report.faults.push(format!("{at}: {e}"));
                    break;
                }
            };
            let ar = substitute(&step.args, &br, &ns_r)?;
            let (php, rust) = self.drivers()?;
            let idp = php.send(lib, &step.op, &ap)?;
            let idr = rust.send(lib, &step.op, &ar)?;
            let (rp, rr) = (php.recv(idp)?, rust.recv(idr)?);
            let (op, or) = match (rp, rr) {
                (Ok(p), Ok(r)) => (p, r),
                (p, r) => {
                    for (side, res) in [("php", &p), ("rust", &r)] {
                        if let Err(f) = res {
                            report.faults.push(format!("{at}: {}", fault_text(side, f)));
                        }
                    }
                    break;
                }
            };
            let masks = step_masks(spec, step);
            let (cp, cr) = (comparable(&op, &ns_p, &masks), comparable(&or, &ns_r, &masks));
            if let Some(d) = diff(&cp, &cr) {
                report.differences.push(format!(
                    "{at}: php and rust differ at {d}\n      php:  {}\n      rust: {}",
                    short(&cp),
                    short(&cr)
                ));
            } else if let Some(expect) = &step.expect
                && let Some(d) = diff(expect, &cp)
            {
                report.differences.push(format!(
                    "{at}: both runtimes differ from the recorded expectation at {d}\n      expect: {}\n      got:    {}\n      (re-record with `bin/compat record {lib}` if PHP changed on purpose)",
                    short(expect),
                    short(&cp)
                ));
            }
            if let Some(name) = &step.bind {
                bp.insert(name.clone(), op.to_json());
                br.insert(name.clone(), or.to_json());
            }
        }
        for kind in &kinds {
            let sp = self.state(spec, kind, &ns_p)?;
            let sr = self.state(spec, kind, &ns_r)?;
            if let Some(d) = diff(&sp, &sr) {
                report.differences.push(format!(
                    "`{kind}` state differs at {d}\n      php:  {}\n      rust: {}",
                    short(&sp),
                    short(&sr)
                ));
            }
        }
        self.cleanup(&kinds, &ns_p)?;
        self.cleanup(&kinds, &ns_r)?;
        Ok(report)
    }

    /// Runs the steps with sides a/b as php/rust, then as rust/php, each time on
    /// one namespace shared by both runtimes. Both executions must agree with
    /// each other (results and state) and with the recorded expectations.
    fn interop_case(&mut self, lib: &str, spec: &Spec, case: &Case) -> Result<Report, String> {
        let mut report = Report::default();
        let kinds = Self::state_kinds(spec, case);
        let mut runs: Vec<(Vec<Value>, Vec<Value>)> = Vec::new();
        for (a, b) in [("php", "rust"), ("rust", "php")] {
            let ns = self.ns('x');
            self.cleanup(&kinds, &ns)?;
            self.configure("php", &ns)?;
            self.configure("rust", &ns)?;
            let mut binds = HashMap::new();
            let mut results = Vec::new();
            for (i, step) in case.steps.iter().enumerate() {
                let side: &'static str = if step.side.as_deref() == Some("a") { a } else { b };
                let at =
                    format!("step {} `{}` (side {} = {side})", i + 1, step.op, step.side.as_deref().unwrap_or("?"));
                let args = substitute(&step.args, &binds, &ns)?;
                match self.driver(side)?.call(lib, &step.op, &args)? {
                    Ok(out) => {
                        let c = comparable(&out, &ns, &step_masks(spec, step));
                        if let Some(expect) = &step.expect
                            && let Some(d) = diff(expect, &c)
                        {
                            report.differences.push(format!(
                                "{at}: differs from the recorded expectation at {d}\n      expect: {}\n      got:    {}",
                                short(expect),
                                short(&c)
                            ));
                        }
                        if let Some(name) = &step.bind {
                            binds.insert(name.clone(), out.to_json());
                        }
                        results.push(c);
                    }
                    Err(f) => {
                        report.faults.push(format!("{at}: {}", fault_text(side, &f)));
                        break;
                    }
                }
            }
            let mut states = Vec::new();
            for kind in &kinds {
                states.push(self.state(spec, kind, &ns)?);
            }
            self.cleanup(&kinds, &ns)?;
            runs.push((results, states));
        }
        if let [(r1, s1), (r2, s2)] = runs.as_slice() {
            for (i, (x, y)) in r1.iter().zip(r2).enumerate() {
                if let Some(d) = diff(x, y) {
                    report.differences.push(format!(
                        "step {}: php→rust and rust→php disagree at {d}\n      a=php:  {}\n      a=rust: {}",
                        i + 1,
                        short(x),
                        short(y)
                    ));
                }
            }
            for (kind, (x, y)) in kinds.iter().zip(s1.iter().zip(s2)) {
                if let Some(d) = diff(x, y) {
                    report.differences.push(format!("`{kind}` state disagrees between directions at {d}"));
                }
            }
        }
        Ok(report)
    }

    /// Runs the cases on PHP only and writes the results as `expect`.
    pub fn record_lib(&mut self, lib: &str, filter: Option<&str>) -> Result<(usize, usize, Vec<String>), String> {
        let spec = spec::load(&self.cfg.root, lib)?;
        let files = cases::load_dir(&spec::lib_dir(&self.cfg.root, lib).join("cases"))?;
        let (mut written, mut changed, mut faults) = (0, 0, Vec::new());
        for file in &files {
            let mut results: Vec<Vec<Option<Value>>> = Vec::new();
            for case in &file.cases {
                let name = label(lib, file, case);
                if filter.is_some_and(|f| !name.contains(f)) {
                    results.push(vec![None; case.steps.len()]);
                    continue;
                }
                let kinds = Self::state_kinds(&spec, case);
                let ns = self.ns('p');
                self.cleanup(&kinds, &ns)?;
                self.configure("php", &ns)?;
                let mut binds = HashMap::new();
                let mut out = Vec::new();
                for (i, step) in case.steps.iter().enumerate() {
                    let args = substitute(&step.args, &binds, &ns)?;
                    match self.driver("php")?.call(lib, &step.op, &args)? {
                        Ok(o) => {
                            let c = comparable(&o, &ns, &step_masks(&spec, step));
                            if step.expect.as_ref() != Some(&c) {
                                changed += 1;
                            }
                            if let Some(name) = &step.bind {
                                binds.insert(name.clone(), o.to_json());
                            }
                            written += 1;
                            out.push(Some(c));
                        }
                        Err(f) => {
                            faults.push(format!("{name}: step {} `{}`: {}", i + 1, step.op, fault_text("php", &f)));
                            out.resize(case.steps.len(), None);
                            break;
                        }
                    }
                }
                out.resize(case.steps.len(), None);
                self.cleanup(&kinds, &ns)?;
                results.push(out);
            }
            cases::write_expectations(file, &results)?;
        }
        Ok((written, changed, faults))
    }

    /// Generates inputs for every fuzz profile and compares both runtimes.
    pub fn fuzz_lib(&mut self, lib: &str, opts: &FuzzOptions) -> Result<Report, String> {
        let spec = spec::load(&self.cfg.root, lib)?;
        let (ns_p, ns_r) = (self.ns('p'), self.ns('r'));
        self.configure("php", &ns_p)?;
        self.configure("rust", &ns_r)?;
        let mut report = Report::default();
        for (op_name, op) in &spec.ops {
            if opts.op.as_ref().is_some_and(|o| o != op_name) {
                continue;
            }
            for (pi, profile) in op.fuzz.iter().enumerate() {
                let title = if profile.name.is_empty() {
                    format!("{op_name}[{pi}]")
                } else {
                    format!("{op_name}[{}]", profile.name)
                };
                let iterations = opts.iterations.unwrap_or(profile.iterations);
                let mut rng = Rng::new(opts.seed ^ fnv(&title));
                let masks: Vec<&Mask> = op.mask.iter().collect();
                let mut done = 0u64;
                let mut stop = false;
                while done < iterations && !stop {
                    let n = (iterations - done).min(256);
                    let mut batch = Vec::with_capacity(n as usize);
                    for _ in 0..n {
                        batch.push((op_name.clone(), generate::generate(&profile.args, &spec.generators, &mut rng)?));
                    }
                    let (php, rust) = self.drivers()?;
                    let rp = php.batch(lib, &batch)?;
                    let rr = rust.batch(lib, &batch)?;
                    for (k, ((_, args), (p, r))) in batch.iter().zip(rp.into_iter().zip(rr)).enumerate() {
                        let (p, r) = match (p, r) {
                            (Ok(p), Ok(r)) => (p, r),
                            (p, r) => {
                                let side = if p.is_err() { "php" } else { "rust" };
                                let f = p.err().or(r.err()).unwrap_or(Fault::new("?"));
                                report.faults.push(format!(
                                    "{lib} fuzz {title} input {}: {} (fix the generator or the adapter)\n      args: {}",
                                    done + k as u64 + 1,
                                    fault_text(side, &f),
                                    short(args)
                                ));
                                stop = true;
                                break;
                            }
                        };
                        let (cp, cr) = (comparable(&p, &ns_p, &masks), comparable(&r, &ns_r, &masks));
                        if diff(&cp, &cr).is_some() {
                            let (min_args, mp, mr) =
                                self.shrink(lib, op_name, args.clone(), (cp, cr), &ns_p, &ns_r, &masks)?;
                            let d = diff(&mp, &mr).unwrap_or_default();
                            report.differences.push(format!(
                                "{lib} fuzz {title} (seed {}, input {}): php and rust differ at {d}\n      args: {}\n      php:  {}\n      rust: {}",
                                opts.seed,
                                done + k as u64 + 1,
                                short(&min_args),
                                short(&mp),
                                short(&mr)
                            ));
                            if opts.save {
                                save_regression(&self.cfg.root, lib, op_name, &min_args, &mp)?;
                            }
                            stop = true;
                            break;
                        }
                        report.passed += 1;
                    }
                    done += n;
                }
                if self.verbose || !stop {
                    eprintln!(
                        "  {lib} fuzz {title}: {done} inputs{}",
                        if stop { ", stopped at the first problem" } else { ", no differences" }
                    );
                }
            }
        }
        Ok(report)
    }

    #[allow(clippy::too_many_arguments)]
    fn shrink(
        &mut self,
        lib: &str,
        op: &str,
        mut args: Value,
        mut outs: (Value, Value),
        ns_p: &str,
        ns_r: &str,
        masks: &[&Mask],
    ) -> Result<(Value, Value, Value), String> {
        let mut budget = 400;
        'outer: loop {
            for candidate in generate::shrink(&args) {
                if budget == 0 {
                    break 'outer;
                }
                budget -= 1;
                let (php, rust) = self.drivers()?;
                let p = php.call(lib, op, &candidate)?;
                let r = rust.call(lib, op, &candidate)?;
                if let (Ok(p), Ok(r)) = (p, r) {
                    let (cp, cr) = (comparable(&p, ns_p, masks), comparable(&r, ns_r, masks));
                    if diff(&cp, &cr).is_some() {
                        args = candidate;
                        outs = (cp, cr);
                        continue 'outer;
                    }
                }
            }
            break;
        }
        Ok((args, outs.0, outs.1))
    }

    /// Runs one operation on the chosen runtimes and returns their results.
    pub fn call(
        &mut self,
        lib: &str,
        op: &str,
        args: &Value,
        sides: &[&'static str],
    ) -> Result<Vec<(String, Value)>, String> {
        let mut out = Vec::new();
        for side in sides {
            let ns = self.ns(side.chars().next().unwrap_or('x'));
            self.configure(side, &ns)?;
            let args = substitute(args, &HashMap::new(), &ns)?;
            let v = match self.driver(side)?.call(lib, op, &args)? {
                Ok(o) => normalize(&o.to_json(), &ns),
                Err(f) => json!({ "$fault": f.0 }),
            };
            out.push(((*side).to_owned(), v));
        }
        Ok(out)
    }

    /// The PHP library's public API, by reflection.
    pub fn inventory(&mut self, spec: &Spec) -> Result<Vec<String>, String> {
        match self.driver("php")?.call("", "$inventory", &json!({ "src": spec.php.src }))? {
            Ok(Outcome::Ok(Value::Array(items))) => {
                Ok(items.into_iter().filter_map(|v| v.as_str().map(str::to_owned)).collect())
            }
            other => Err(format!("php $inventory failed: {other:?}")),
        }
    }

    /// Operations each driver implements for `lib`.
    pub fn hello(&mut self, side: &'static str, lib: &str) -> Result<Vec<String>, String> {
        match self.driver(side)?.call("", "$hello", &json!({}))? {
            Ok(Outcome::Ok(v)) => Ok(v
                .get("libs")
                .and_then(|l| l.get(lib))
                .and_then(Value::as_array)
                .map(|a| a.iter().filter_map(Value::as_str).map(str::to_owned).collect())
                .unwrap_or_default()),
            other => Err(format!("{side} $hello failed: {other:?}")),
        }
    }
}

fn fnv(s: &str) -> u64 {
    s.bytes().fold(0xcbf2_9ce4_8422_2325u64, |h, b| (h ^ b as u64).wrapping_mul(0x100_0000_01b3))
}

fn save_regression(root: &Path, lib: &str, op: &str, args: &Value, expect: &Value) -> Result<(), String> {
    let path = spec::lib_dir(root, lib).join("cases").join("fuzz.json");
    let mut file: Value = std::fs::read_to_string(&path)
        .ok()
        .and_then(|t| serde_json::from_str(&t).ok())
        .unwrap_or_else(|| json!({ "source": "fuzz", "cases": [] }));
    let cases = file.get_mut("cases").and_then(Value::as_array_mut).ok_or("bad fuzz.json")?;
    let name = format!("{op} {}", short(args));
    if !cases.iter().any(|c| c.get("name").and_then(Value::as_str) == Some(name.as_str())) {
        cases.push(json!({ "name": name, "op": op, "args": args, "expect": expect }));
    }
    std::fs::create_dir_all(path.parent().ok_or("bad path")?).map_err(|e| e.to_string())?;
    let mut text = serde_json::to_string_pretty(&file).map_err(|e| e.to_string())?;
    text.push('\n');
    std::fs::write(&path, text).map_err(|e| e.to_string())
}

/// Replays recorded expectations against the Rust adapter in-process, with no
/// PHP: what `cargo test -p compat` runs. Cases needing services are skipped
/// unless `COMPAT_SERVICES=1`.
pub async fn replay(cfg: &Config, lib: &str) -> Result<Report, String> {
    let spec = spec::load(&cfg.root, lib)?;
    let services = std::env::var("COMPAT_SERVICES").is_ok_and(|v| v == "1");
    let mut report = Report::default();
    if !spec.services.is_empty() && !services {
        return Ok(report);
    }
    let files = cases::load_dir(&spec::lib_dir(&cfg.root, lib).join("cases"))?;
    let mut counter = 0;
    for file in &files {
        for case in &file.cases {
            let kinds = Engine::state_kinds(&spec, case);
            if !kinds.is_empty() && !services {
                continue;
            }
            counter += 1;
            let ns = format!("cxreplay{}{counter}", std::process::id());
            for k in &kinds {
                snapshot::cleanup(cfg, k, &ns)?;
            }
            let mut session = Session::new(&ns, cfg.services_for("rust"));
            let mut binds = HashMap::new();
            let mut failed = false;
            for (i, step) in case.steps.iter().enumerate() {
                let args = substitute(&step.args, &binds, &ns)?;
                let request = json!({ "lib": lib, "op": step.op, "args": args });
                let result: OpResult = crate::driver::handle(&request, &mut session).await;
                match result {
                    Ok(out) => {
                        let c = comparable(&out, &ns, &step_masks(&spec, step));
                        if let Some(expect) = &step.expect
                            && let Some(d) = diff(expect, &c)
                        {
                            report.differences.push(format!(
                                "{}: step {} `{}` differs from PHP's recorded result at {d}\n      expect: {}\n      rust:   {}",
                                label(lib, file, case),
                                i + 1,
                                step.op,
                                short(expect),
                                short(&c)
                            ));
                            failed = true;
                        }
                        if let Some(name) = &step.bind {
                            binds.insert(name.clone(), out.to_json());
                        }
                    }
                    Err(f) => {
                        report.faults.push(format!(
                            "{}: step {}: {}",
                            label(lib, file, case),
                            i + 1,
                            fault_text("rust", &f)
                        ));
                        failed = true;
                        break;
                    }
                }
            }
            for k in &kinds {
                snapshot::cleanup(cfg, k, &ns)?;
            }
            if !failed {
                report.passed += 1;
            }
        }
    }
    Ok(report)
}
