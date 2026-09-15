use std::time::Instant;
use utopia_waf::{Bypass, Condition, Deny, Firewall, RateLimit};

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(1_000) {
        f();
    }
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    let equal = Condition::equal("ip", vec!["127.0.0.1".into(), "10.0.0.1".into()]);
    let mut equal_attrs = serde_json::Map::new();
    equal_attrs.insert("ip".into(), serde_json::json!("127.0.0.1"));

    let contains = Condition::contains("path", vec!["admin".into(), "dashboard".into()]);
    let mut contains_attrs = serde_json::Map::new();
    contains_attrs.insert("path".into(), serde_json::json!("/admin/users"));

    bench("condition_equal", 100_000, || {
        std::hint::black_box(equal.matches(&equal_attrs));
    });
    bench("condition_contains", 100_000, || {
        std::hint::black_box(contains.matches(&contains_attrs));
    });

    let mut firewall = Firewall::new();
    firewall.set_attribute("requestIP", "10.4.20.9");
    firewall.set_attribute("requestPath", "/v1/users");
    firewall.set_attribute("requestMethod", "GET");
    firewall.add_rule(Deny::new(vec![
        Condition::equal("ip", vec!["203.0.113.10".into()]),
        Condition::equal("method", vec!["POST".into()]),
    ]));
    firewall.add_rule(Bypass::new(vec![
        Condition::equal("ip", vec!["10.0.0.0/8".into()]),
        Condition::starts_with("path", "/v1"),
    ]));
    firewall.add_rule(
        RateLimit::new(
            vec![Condition::equal("method", vec!["GET".into()])],
            100,
            60,
        )
        .unwrap(),
    );

    bench("firewall_verify", 50_000, || {
        std::hint::black_box(firewall.verify());
    });
}
