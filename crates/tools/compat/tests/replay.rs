//! Replays every recorded expectation (PHP's results, written by
//! `bin/compat record`) against the Rust adapters, without PHP. Cases that
//! need services (Redis, a database) run only with `COMPAT_SERVICES=1`.
//! `COMPAT_LIB=<lib>` and `COMPAT_CASE=<text>` narrow the run.

use std::path::Path;

use compat::runner::config::Config;
use compat::runner::engine::replay;
use compat::runner::spec;

#[tokio::test(flavor = "current_thread")]
async fn recorded_php_results_hold_in_rust() {
    let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..").canonicalize().expect("repository root");
    // The drivers run from the repository root, where cases' relative paths
    // (fixtures under packages/) resolve.
    std::env::set_current_dir(&root).expect("repository root");
    compat::driver::php_umask();
    let cfg = Config::load(&root).expect("tests/compat/compat.json");
    let mut problems = Vec::new();
    let mut passed = 0;
    // `COMPAT_LIB` replays one library only (and `COMPAT_CASE` some of its cases).
    let only = std::env::var("COMPAT_LIB").ok().filter(|l| !l.is_empty());
    for lib in spec::libs(&root) {
        if only.as_deref().is_some_and(|l| l != lib) {
            continue;
        }
        let report = replay(&cfg, &lib).await.unwrap_or_else(|e| panic!("{lib}: {e}"));
        passed += report.passed;
        problems.extend(report.differences);
        problems.extend(report.faults);
    }
    assert!(problems.is_empty(), "{} replayed cases failed:\n{}", problems.len(), problems.join("\n"));
    assert!(passed > 0, "no cases replayed");
}
