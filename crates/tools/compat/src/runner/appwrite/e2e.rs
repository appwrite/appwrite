//! The PHP E2E suites, as the API's contract:
//!
//! - their test classes and methods, counted from `tests/e2e/Services/<Suite>`;
//! - which suites CI runs against Rust (a workflow step that sets
//!   `_APP_E2E_ENDPOINT` and names the suite);
//! - recorded runs in `tests/e2e/results/<suite>.<runtime>.json`, written by
//!   `bin/compat appwrite e2e <Suite> --write` (see [`run`]).

use std::collections::BTreeMap;
use std::path::Path;

use serde_json::{Value, json};

use super::inventory::{Index, php_files};

#[derive(Debug, Clone, PartialEq)]
pub struct TestClass {
    pub name: String,
    pub file: String,
    /// Test methods, the ones from its traits (`use UsersBase;`) included.
    pub tests: usize,
}

#[derive(Debug, Clone)]
pub struct Suite {
    /// `Users`, the directory under `tests/e2e/Services`.
    pub name: String,
    pub dir: String,
    pub classes: Vec<TestClass>,
}

impl Suite {
    pub fn tests(&self) -> usize {
        self.classes.iter().map(|c| c.tests).sum()
    }
}

/// Every suite under `tests/e2e/Services`, with its test classes.
pub fn suites(root: &Path) -> Vec<Suite> {
    let base = "tests/e2e/Services";
    let index = Index::new(root, &php_files(root, base));
    // Traits from the shared scopes (`tests/e2e/Scopes`) hold no tests; only the suite's own.
    let mut out = Vec::new();
    for name in super::dirs(&root.join(base)) {
        let dir = format!("{base}/{name}");
        let mut classes = Vec::new();
        for file in index.files.iter().filter(|f| f.path.starts_with(&format!("{dir}/"))) {
            for class in &file.classes {
                if class.kind != "class" || class.is_abstract || !class.name.ends_with("Test") {
                    continue;
                }
                let own = class.methods.keys().filter(|m| m.starts_with("test")).count();
                let inherited: usize = class
                    .traits
                    .iter()
                    .filter_map(|t| index.class(t))
                    .map(|(_, t)| t.methods.keys().filter(|m| m.starts_with("test")).count())
                    .sum();
                classes.push(TestClass { name: class.name.clone(), file: file.path.clone(), tests: own + inherited });
            }
        }
        if !classes.is_empty() {
            classes.sort_by(|a, b| a.name.cmp(&b.name));
            out.push(Suite { name, dir, classes });
        }
    }
    out
}

/// A CI step that runs a suite through the proxy, so against the Rust API.
#[derive(Debug, Clone, PartialEq)]
pub struct RustRun {
    pub suite: String,
    pub workflow: String,
    pub job: String,
    pub line: usize,
}

/// Suites the workflows run with `_APP_E2E_ENDPOINT` set.
pub fn ci_rust_runs(root: &Path) -> Vec<RustRun> {
    let mut out = Vec::new();
    let dir = root.join(".github/workflows");
    let mut files: Vec<_> = std::fs::read_dir(&dir).into_iter().flatten().flatten().map(|e| e.path()).collect();
    files.sort();
    for path in files {
        if !path.extension().is_some_and(|x| x == "yml" || x == "yaml") {
            continue;
        }
        let Ok(src) = std::fs::read_to_string(&path) else { continue };
        let rel = path.strip_prefix(root).map(|p| p.to_string_lossy().into_owned()).unwrap_or_default();
        out.extend(rust_runs_in(&rel, &src));
    }
    out
}

pub fn rust_runs_in(workflow: &str, src: &str) -> Vec<RustRun> {
    let lines: Vec<&str> = src.lines().collect();
    let mut out: Vec<RustRun> = Vec::new();
    for (i, l) in lines.iter().enumerate() {
        if !l.contains("_APP_E2E_ENDPOINT=") || l.trim_start().starts_with('#') {
            continue;
        }
        // The job: the nearest key indented by two spaces above.
        let job = lines[..i]
            .iter()
            .rev()
            .find(|l| l.starts_with("  ") && !l.starts_with("   ") && l.trim_end().ends_with(':'))
            .map(|l| l.trim().trim_end_matches(':').to_owned())
            .unwrap_or_default();
        // The suite: named in the same step (the lines up to the next step).
        for next in lines.iter().skip(i).take(12) {
            if next.trim_start().starts_with("- name:") {
                break;
            }
            let mut rest = *next;
            while let Some(p) = rest.find("tests/e2e/Services/") {
                let after = &rest[p + "tests/e2e/Services/".len()..];
                let suite: String = after.chars().take_while(|c| c.is_ascii_alphanumeric()).collect();
                if !suite.is_empty() && !out.iter().any(|r| r.suite == suite && r.workflow == workflow) {
                    out.push(RustRun { suite, workflow: workflow.to_owned(), job: job.clone(), line: i + 1 });
                }
                rest = after;
            }
        }
    }
    out
}

/// Recorded runs: `tests/e2e/results/*.json`.
pub fn results(root: &Path) -> Vec<Value> {
    let dir = root.join("tests/e2e/results");
    let mut files: Vec<_> = std::fs::read_dir(&dir).into_iter().flatten().flatten().map(|e| e.path()).collect();
    files.sort();
    files
        .into_iter()
        .filter(|p| p.extension().is_some_and(|x| x == "json"))
        .filter_map(|p| serde_json::from_str::<Value>(&std::fs::read_to_string(&p).ok()?).ok())
        .collect()
}

/// Counts from a JUnit report (`--log-junit`).
#[derive(Debug, Default, Clone, PartialEq)]
pub struct Junit {
    pub tests: usize,
    pub assertions: usize,
    pub failures: usize,
    pub errors: usize,
    pub skipped: usize,
    pub time: f64,
    /// Per class: (name, tests, failures + errors, skipped, time).
    pub classes: Vec<(String, usize, usize, usize, f64)>,
    /// Failed tests: (class, method, first line of the message).
    pub failed: Vec<(String, String, String)>,
}

/// Attributes of the XML tag starting at `tag` (`<testsuite name="x" ...>`).
fn attrs(tag: &str) -> BTreeMap<String, String> {
    let mut out = BTreeMap::new();
    let mut rest = tag;
    while let Some(eq) = rest.find("=\"") {
        let key = rest[..eq].rsplit([' ', '\n', '\t']).next().unwrap_or("").to_owned();
        let after = &rest[eq + 2..];
        let Some(end) = after.find('"') else { break };
        out.insert(key, unescape(&after[..end]));
        rest = &after[end + 1..];
    }
    out
}

fn unescape(s: &str) -> String {
    s.replace("&lt;", "<").replace("&gt;", ">").replace("&quot;", "\"").replace("&apos;", "'").replace("&amp;", "&")
}

/// The totals PHPUnit prints last: `OK (68 tests, 971 assertions)`, or
/// `Tests: 68, Assertions: 900, Failures: 2, Errors: 1, Skipped: 3.`
/// ParaTest's JUnit report in functional mode leaves test cases out, so
/// this line is the run's count; the report only names failed tests.
pub fn summary(output: &str) -> Option<Junit> {
    // Without ANSI colours.
    let mut clean = String::with_capacity(output.len());
    let mut chars = output.chars().peekable();
    while let Some(c) = chars.next() {
        if c == '\u{1b}' {
            for n in chars.by_ref() {
                if n.is_ascii_alphabetic() {
                    break;
                }
            }
            continue;
        }
        clean.push(c);
    }
    let number = |s: &str| s.trim().trim_end_matches('.').parse::<usize>().ok();
    let mut j = Junit::default();
    for line in clean.lines().rev() {
        let line = line.trim();
        if let Some(rest) = line.strip_prefix("OK (") {
            let mut parts = rest.trim_end_matches(')').split(", ");
            j.tests = number(parts.next()?.split(' ').next()?)?;
            j.assertions = parts.next().and_then(|p| number(p.split(' ').next()?)).unwrap_or(0);
            return Some(j);
        }
        if line.starts_with("Tests: ") {
            for pair in line.split(", ") {
                let (key, value) = pair.split_once(": ")?;
                let value = number(value)?;
                match key {
                    "Tests" => j.tests = value,
                    "Assertions" => j.assertions = value,
                    "Failures" => j.failures = value,
                    "Errors" => j.errors = value,
                    "Skipped" => j.skipped = value,
                    _ => {}
                }
            }
            return Some(j);
        }
    }
    None
}

/// Reads a JUnit report as PHPUnit and ParaTest write it, from its test cases.
pub fn junit(xml: &str) -> Junit {
    let mut j = Junit::default();
    let mut rest = xml;
    while let Some(p) = rest.find("<testcase ") {
        let open_end = rest[p..].find('>').map(|e| p + e).unwrap_or(rest.len() - 1);
        let a = attrs(&rest[p..open_end]);
        let self_closing = rest[..=open_end].ends_with("/>");
        let close =
            if self_closing { open_end } else { rest[p..].find("</testcase>").map(|e| p + e).unwrap_or(open_end) };
        let body = &rest[open_end.min(close)..close];
        let class = a.get("class").or_else(|| a.get("classname")).cloned().unwrap_or_default();
        let class = class.rsplit(['\\', '.']).next().unwrap_or("").to_owned();
        let time: f64 = a.get("time").and_then(|v| v.parse().ok()).unwrap_or(0.0);
        j.tests += 1;
        j.assertions += a.get("assertions").and_then(|v| v.parse::<usize>().ok()).unwrap_or(0);
        j.time += time;
        let mut failed = false;
        for kind in ["<failure", "<error"] {
            if let Some(f) = body.find(kind) {
                let text_start = body[f..].find('>').map(|e| f + e + 1).unwrap_or(body.len());
                let message = unescape(body[text_start..].lines().find(|l| !l.trim().is_empty()).unwrap_or("").trim());
                if kind == "<failure" {
                    j.failures += 1;
                } else {
                    j.errors += 1;
                }
                j.failed.push((
                    class.clone(),
                    a.get("name").cloned().unwrap_or_default(),
                    message.chars().take(300).collect(),
                ));
                failed = true;
                break;
            }
        }
        let skipped = !failed && body.contains("<skipped");
        j.skipped += usize::from(skipped);
        match j.classes.iter_mut().find(|c| c.0 == class) {
            Some(c) => {
                c.1 += 1;
                c.2 += usize::from(failed);
                c.3 += usize::from(skipped);
                c.4 += time;
            }
            None => j.classes.push((class, 1, usize::from(failed), usize::from(skipped), time)),
        }
        rest = &rest[(close + 1).max(p + 1).min(rest.len())..];
    }
    j
}

fn command(program: &str, args: &[&str]) -> Result<std::process::Output, String> {
    std::process::Command::new(program).args(args).output().map_err(|e| format!("{program}: {e}"))
}

fn stdout(program: &str, args: &[&str]) -> Option<String> {
    let out = command(program, args).ok()?;
    out.status.success().then(|| String::from_utf8_lossy(&out.stdout).trim().to_owned())
}

/// Runs a suite in the dev stack's `appwrite` container (it must be up; this
/// never starts, builds or restarts containers) and returns the result
/// document. `rust`: through Traefik (`_APP_E2E_ENDPOINT=http://appwrite.test/v1`),
/// as CI's Rust job does, so routes Traefik sends to Rust are served by it.
pub fn run(root: &Path, suite: &str, rust: bool) -> Result<Value, String> {
    if !root.join("tests/e2e/Services").join(suite).is_dir() {
        return Err(format!("no suite tests/e2e/Services/{suite}"));
    }
    let container = std::env::var("COMPAT_E2E_CONTAINER").unwrap_or_else(|_| "appwrite".into());
    let rust_container = std::env::var("COMPAT_E2E_RUST_CONTAINER").unwrap_or_else(|_| "appwrite-rust".into());
    let endpoint = std::env::var("COMPAT_E2E_ENDPOINT").unwrap_or_else(|_| "http://appwrite.test/v1".into());
    // Each process holds its own database connections; the dev stack's
    // PostgreSQL allows 100 in all, shared with the API's pools.
    let processes = std::env::var("COMPAT_E2E_PROCESSES").unwrap_or_else(|_| "4".into());
    let junit_path = format!("/tmp/compat-e2e-{}.xml", suite.to_ascii_lowercase());
    let target = format!("/usr/src/code/tests/e2e/Services/{suite}");
    let env = format!("_APP_E2E_ENDPOINT={}", if rust { endpoint.as_str() } else { "" });
    let args = [
        "exec",
        "-e",
        env.as_str(),
        container.as_str(),
        "vendor/bin/paratest",
        "--processes",
        processes.as_str(),
        "--functional",
        target.as_str(),
        "--exclude-group",
        "abuseEnabled",
        "--log-junit",
        junit_path.as_str(),
    ];
    eprintln!("compat appwrite e2e: docker {}", args.join(" "));
    let started = std::time::Instant::now();
    let out = command("docker", &args)?;
    let elapsed = started.elapsed().as_secs_f64();
    // The report names the failed tests; the totals come from PHPUnit's summary.
    let xml = stdout("docker", &["exec", &container, "cat", &junit_path]).unwrap_or_default();
    let _ = command("docker", &["exec", &container, "rm", "-f", &junit_path]);
    let report = junit(&xml);
    let output = format!("{}\n{}", String::from_utf8_lossy(&out.stdout), String::from_utf8_lossy(&out.stderr));
    // A crashed worker (e.g. the database refusing connections) ends the run
    // without PHPUnit's totals: not a result to record.
    let Some(j) = summary(&output) else {
        let reason = output.lines().find(|l| l.contains("Fatal error") || l.contains("Exception")).unwrap_or("").trim();
        return Err(format!(
            "paratest exited with {} without a test summary (a worker crashed?){}",
            out.status.code().unwrap_or(-1),
            if reason.is_empty() { String::new() } else { format!(": {reason}") }
        ));
    };
    let image = |c: &str| {
        stdout("docker", &["inspect", "-f", "{{.Config.Image}} {{.Image}} {{.Created}}", c]).map(|s| {
            let mut p = s.split_whitespace();
            json!({ "container": c, "image": p.next(), "id": p.next().map(|i| i.trim_start_matches("sha256:").chars().take(12).collect::<String>()), "created": p.next() })
        })
    };
    let git = |args: &[&str]| {
        std::process::Command::new("git")
            .args(args)
            .current_dir(root)
            .output()
            .ok()
            .filter(|o| o.status.success())
            .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_owned())
    };
    let failed = j.failures + j.errors;
    Ok(json!({
        "suite": suite,
        "dir": format!("tests/e2e/Services/{suite}"),
        "runtime": if rust { "rust" } else { "php" },
        "endpoint": if rust { endpoint.clone() } else { "http://appwrite/v1".to_owned() },
        "date": utc_now(),
        "commit": git(&["rev-parse", "--short", "HEAD"]),
        "branch": git(&["rev-parse", "--abbrev-ref", "HEAD"]),
        "command": format!("docker {}", args.join(" ")),
        "tests": j.tests,
        "assertions": j.assertions,
        "passed": j.tests.saturating_sub(failed + j.skipped),
        "failures": j.failures,
        "errors": j.errors,
        "skipped": j.skipped,
        "wall": (elapsed * 10.0).round() / 10.0,
        "failed": report.failed.iter().map(|(class, name, message)| json!({ "class": class, "name": name, "message": message })).collect::<Vec<_>>(),
        "environment": {
            "php": image(&container),
            "rust": if rust { image(&rust_container) } else { None },
            "processes": processes.parse::<u64>().ok(),
        },
    }))
}

/// `2026-10-11T12:00:00Z`, from the system clock.
pub fn utc_now() -> String {
    let secs = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
    let days = (secs / 86_400) as i64;
    let rem = secs % 86_400;
    // Civil from days (Howard Hinnant's algorithm).
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    let y = if m <= 2 { y + 1 } else { y };
    format!("{y:04}-{m:02}-{d:02}T{:02}:{:02}:{:02}Z", rem / 3600, rem % 3600 / 60, rem % 60)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_junit() {
        let xml = r#"<?xml version="1.0"?>
<testsuites>
  <testsuite name="tests" tests="3" assertions="10" errors="0" failures="1" skipped="1" time="2.5">
    <testsuite name="Tests\E2E\Services\Users\UsersCustomServerTest" file="/x/UsersCustomServerTest.php" tests="3" assertions="10" errors="0" failures="1" skipped="1" time="2.5">
      <testcase name="testCreateUser" class="Tests\E2E\Services\Users\UsersCustomServerTest" file="/x" line="1" assertions="5" time="1.0"/>
      <testcase name="testGetUser" class="Tests\E2E\Services\Users\UsersCustomServerTest" file="/x" line="2" assertions="5" time="1.0">
        <failure type="AssertionFailedError">Tests\E2E\Services\Users\UsersCustomServerTest::testGetUser
Failed asserting that 404 matches expected 200.</failure>
      </testcase>
      <testcase name="testSkipped" class="Tests\E2E\Services\Users\UsersCustomServerTest" file="/x" line="3" assertions="0" time="0"><skipped/></testcase>
    </testsuite>
  </testsuite>
</testsuites>"#;
        let j = junit(xml);
        assert_eq!((j.tests, j.assertions, j.failures, j.errors, j.skipped), (3, 10, 1, 0, 1));
        assert_eq!(j.classes, [("UsersCustomServerTest".to_owned(), 3, 1, 1, 2.0)]);
        assert_eq!(j.failed.len(), 1);
        assert_eq!(j.failed[0].1, "testGetUser");

        // ParaTest's functional mode: one suite per method, no wrapper.
        let xml = r#"<testsuites>
  <testsuite name="A\BTest" file="/b.php" tests="1"><testcase name="testOne" class="A\BTest" assertions="2" time="0.5"/></testsuite>
  <testsuite name="A\BTest" file="/b.php" tests="1"><testcase name="testTwo" class="A\BTest" assertions="3" time="0.5"/></testsuite>
</testsuites>"#;
        let j = junit(xml);
        assert_eq!((j.tests, j.assertions), (2, 5));
        assert_eq!(j.classes, [("BTest".to_owned(), 2, 0, 0, 1.0)]);
    }

    #[test]
    fn reads_phpunit_totals() {
        let ok =
            summary("...  68 / 68 (100%)\n\nTime: 00:21.895\n\n\u{1b}[30;42mOK (68 tests, 971 assertions)\u{1b}[0m\n")
                .unwrap();
        assert_eq!((ok.tests, ok.assertions, ok.failures), (68, 971, 0));
        let failed = summary("FAILURES!\nTests: 68, Assertions: 900, Failures: 2, Errors: 1, Skipped: 3.\n").unwrap();
        assert_eq!(
            (failed.tests, failed.assertions, failed.failures, failed.errors, failed.skipped),
            (68, 900, 2, 1, 3)
        );
        assert!(summary("Fatal error: too many clients").is_none());
    }

    #[test]
    fn finds_rust_runs_in_workflows() {
        let yml = "jobs:\n  e2e_rust:\n    steps:\n      - name: Run tests\n        run: |\n          docker compose exec -T \\\n            -e _APP_E2E_ENDPOINT=http://appwrite.test/v1 \\\n            appwrite vendor/bin/paratest --functional /usr/src/code/tests/e2e/Services/Users \\\n      - name: Logs\n        run: echo tests/e2e/Services/Teams\n";
        let runs = rust_runs_in("ci.yml", yml);
        assert_eq!(runs.len(), 1);
        assert_eq!(runs[0].suite, "Users");
        assert_eq!(runs[0].job, "e2e_rust");
    }

    #[test]
    fn counts_trait_tests() {
        let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..");
        let users = suites(&root).into_iter().find(|s| s.name == "Users").expect("Users suite");
        let server = users.classes.iter().find(|c| c.name == "UsersCustomServerTest").expect("server tests");
        // Its own tests plus every test of the UsersBase trait.
        assert!(server.tests > 40, "{}", server.tests);
    }

    #[test]
    fn formats_dates() {
        assert_eq!(utc_now().len(), 20);
    }
}
