//! API coverage: how much of the PHP library's public API the operations
//! cover, whether both drivers implement every operation, whether every
//! operation has cases and every PHP test class is ported.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use super::cases;
use super::engine::Engine;
use super::spec::{self, Spec};

#[derive(Default)]
pub struct Coverage {
    pub lib: String,
    pub complete: bool,
    pub symbols: usize,
    pub covered: usize,
    pub waived: usize,
    pub uncovered: Vec<String>,
    /// `covers` or waiver patterns that match nothing (typos, renamed APIs).
    pub dangling: Vec<String>,
    pub ops: usize,
    pub missing_php: Vec<String>,
    pub missing_rust: Vec<String>,
    pub unknown_php: Vec<String>,
    pub unknown_rust: Vec<String>,
    pub ops_without_cases: Vec<String>,
    pub cases: usize,
    pub steps: usize,
    pub steps_without_expect: usize,
    pub tests: usize,
    pub tests_unported: Vec<String>,
}

impl Coverage {
    pub fn percent(&self) -> f64 {
        if self.symbols == 0 { 100.0 } else { (self.covered + self.waived) as f64 * 100.0 / self.symbols as f64 }
    }

    /// Every structural rule of crates/CONVERSION.md §2 holds.
    pub fn ok(&self) -> bool {
        self.uncovered.is_empty()
            && self.dangling.is_empty()
            && self.missing_php.is_empty()
            && self.missing_rust.is_empty()
            && self.unknown_php.is_empty()
            && self.unknown_rust.is_empty()
            && self.ops_without_cases.is_empty()
            && self.tests_unported.is_empty()
            && self.steps_without_expect == 0
    }

    pub fn render(&self) -> String {
        let mut out = format!(
            "{}: API {:.1}% ({} symbols: {} covered, {} waived, {} uncovered); {} ops; {} cases, {} steps ({} without expect); PHP tests ported {}/{}\n",
            self.lib,
            self.percent(),
            self.symbols,
            self.covered,
            self.waived,
            self.uncovered.len(),
            self.ops,
            self.cases,
            self.steps,
            self.steps_without_expect,
            self.tests - self.tests_unported.len(),
            self.tests
        );
        let mut list = |title: &str, items: &[String]| {
            if !items.is_empty() {
                out.push_str(&format!("  {title} ({}):\n", items.len()));
                for i in items {
                    out.push_str(&format!("    - {i}\n"));
                }
            }
        };
        list("uncovered PHP API", &self.uncovered);
        list("patterns matching nothing", &self.dangling);
        list("ops missing in the PHP adapter", &self.missing_php);
        list("ops missing in the Rust adapter", &self.missing_rust);
        list("PHP adapter ops not in spec.json", &self.unknown_php);
        list("Rust adapter ops not in spec.json", &self.unknown_rust);
        list("ops without cases", &self.ops_without_cases);
        list("PHP test files not ported", &self.tests_unported);
        out
    }
}

/// Whether `symbol` matches a pattern: exact, `Class::*` or `Namespace\*`.
pub fn matches(pattern: &str, symbol: &str) -> bool {
    if let Some(prefix) = pattern.strip_suffix('*') { symbol.starts_with(prefix) } else { pattern == symbol }
}

fn test_files(root: &Path, dirs: &[String]) -> Vec<String> {
    fn walk(dir: &Path, root: &Path, out: &mut Vec<String>) {
        let Ok(entries) = std::fs::read_dir(dir) else { return };
        for e in entries.flatten() {
            let p = e.path();
            if p.is_dir() {
                walk(&p, root, out);
            } else if p.file_name().and_then(|n| n.to_str()).is_some_and(|n| n.ends_with("Test.php"))
                && let Ok(rel) = p.strip_prefix(root)
            {
                out.push(rel.display().to_string());
            }
        }
    }
    let mut out = Vec::new();
    for d in dirs {
        walk(&root.join(d), root, &mut out);
    }
    out.sort();
    out
}

pub fn coverage(engine: &mut Engine, lib: &str) -> Result<Coverage, String> {
    let root = engine.cfg.root.clone();
    let spec: Spec = spec::load(&root, lib)?;
    let inventory = engine.inventory(&spec)?;
    let mut c = Coverage {
        lib: lib.to_owned(),
        complete: spec.complete,
        symbols: inventory.len(),
        ops: spec.ops.len(),
        ..Default::default()
    };

    let covers: Vec<&String> = spec.ops.values().flat_map(|o| &o.covers).collect();
    let mut used: BTreeSet<&str> = BTreeSet::new();
    for symbol in &inventory {
        if let Some(p) = covers.iter().find(|p| matches(p, symbol)) {
            c.covered += 1;
            used.insert(p.as_str());
        } else if let Some(p) = spec.waivers.keys().find(|p| matches(p, symbol)) {
            c.waived += 1;
            used.insert(p.as_str());
        } else {
            c.uncovered.push(symbol.clone());
        }
    }
    // A library with no PHP source (php-std: engine built-ins) has nothing to
    // inventory, so its `covers` are documentation and cannot dangle.
    let patterns: Vec<&str> = if spec.php.src.is_empty() {
        Vec::new()
    } else {
        covers.iter().map(|p| p.as_str()).chain(spec.waivers.keys().map(String::as_str)).collect()
    };
    for p in patterns {
        if !used.contains(p) && !inventory.iter().any(|s| matches(p, s)) {
            c.dangling.push(p.to_owned());
        }
    }
    c.dangling.sort();
    c.dangling.dedup();

    let spec_ops: BTreeSet<&String> = spec.ops.keys().collect();
    let php: BTreeSet<String> = engine.hello("php", lib)?.into_iter().collect();
    let rust: BTreeSet<String> = engine.hello("rust", lib)?.into_iter().collect();
    c.missing_php = spec_ops.iter().filter(|o| !php.contains(**o)).map(|o| (*o).clone()).collect();
    c.missing_rust = spec_ops.iter().filter(|o| !rust.contains(**o)).map(|o| (*o).clone()).collect();
    c.unknown_php = php.iter().filter(|o| !spec_ops.contains(o)).cloned().collect();
    c.unknown_rust = rust.iter().filter(|o| !spec_ops.contains(o)).cloned().collect();

    let files = cases::load_dir(&spec::lib_dir(&root, lib).join("cases"))?;
    let mut per_op: BTreeMap<&str, usize> = BTreeMap::new();
    let mut sources = BTreeSet::new();
    for f in &files {
        sources.extend(f.sources.iter().cloned());
        for case in &f.cases {
            c.cases += 1;
            for s in &case.steps {
                c.steps += 1;
                if s.expect.is_none() {
                    c.steps_without_expect += 1;
                }
                *per_op.entry(s.op.as_str()).or_default() += 1;
            }
        }
    }
    c.ops_without_cases = spec.ops.keys().filter(|o| !per_op.contains_key(o.as_str())).cloned().collect();

    let tests = test_files(&root, &spec.php.tests);
    c.tests = tests.len();
    c.tests_unported =
        tests.into_iter().filter(|t| !sources.contains(t) && !spec.tests_waived.contains_key(t)).collect();
    Ok(c)
}

#[cfg(test)]
mod tests {
    use super::matches;

    #[test]
    fn patterns() {
        assert!(matches("Utopia\\Validator\\Text::isValid", "Utopia\\Validator\\Text::isValid"));
        assert!(matches("Utopia\\Validator\\Text::*", "Utopia\\Validator\\Text::getDescription"));
        assert!(matches("Utopia\\Validator\\JSON\\*", "Utopia\\Validator\\JSON\\Schema::isValid"));
        assert!(!matches("Utopia\\Validator\\Text::*", "Utopia\\Validator\\TextArea::isValid"));
    }
}
