//! Every Appwrite endpoint has a benchmark scenario in its service's script
//! in `tests/benchmarks/api`, or the script skips it with a reason.

use std::path::Path;

#[test]
fn every_endpoint_has_a_scenario() {
    let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..");
    let gaps = compat::runner::appwrite::benchmark_gaps(&root);
    assert!(gaps.is_empty(), "benchmark coverage (tests/benchmarks/api/README.md):\n  {}", gaps.join("\n  "));
}
