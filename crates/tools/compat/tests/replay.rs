//! Replays every recorded expectation (PHP's results, written by
//! `bin/compat record`) against the Rust adapters, without PHP. Cases that
//! need services (Redis, a database) run only with `COMPAT_SERVICES=1`.
//! `COMPAT_LIB=<lib>[ <lib>…]` (`!lib` leaves one out) and `COMPAT_CASE=<text>`
//! narrow the run.

use std::path::Path;

use compat::runner::config::Config;
use compat::runner::engine::replay;
use compat::runner::spec;

/// Replays on a thread with a 64 MiB stack: unoptimized builds poll the
/// database's document operations (writes through the relationships hook
/// back into the database) many frames deep, as `utopia-database`'s own
/// tests do.
#[test]
fn recorded_php_results_hold_in_rust() {
    std::thread::Builder::new()
        .stack_size(64 * 1024 * 1024)
        .spawn(|| utopia_async::Builder::current_thread().build().expect("a runtime").block_on(replay_every_library()))
        .expect("a thread")
        .join()
        .unwrap_or_else(|e| std::panic::resume_unwind(e));
}

async fn replay_every_library() {
    let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..").canonicalize().expect("repository root");
    // The drivers run from the repository root, where cases' relative paths
    // (fixtures under packages/) resolve.
    std::env::set_current_dir(&root).expect("repository root");
    compat::driver::php_umask();
    let cfg = Config::load(&root).expect("tests/compat/compat.json");
    let mut problems = Vec::new();
    let mut passed = 0;
    // `COMPAT_LIB` replays some libraries only, or all but `!lib` (and
    // `COMPAT_CASE` some of their cases).
    let selection = std::env::var("COMPAT_LIB").unwrap_or_default();
    let (except, only): (Vec<&str>, Vec<&str>) = selection.split_whitespace().partition(|l| l.starts_with('!'));
    for lib in spec::libs(&root) {
        if (!only.is_empty() && !only.contains(&lib.as_str())) || except.iter().any(|e| e[1..] == lib) {
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
