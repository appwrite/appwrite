use serde_json::json;
use std::time::Instant;
use utopia_servers::Hook;
use utopia_validators::Text;

fn main() {
    let iters = 50_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        let mut hook = Hook::new();
        for i in 0..8 {
            hook.param(format!("p{i}"), json!(""), Text::new(64), "param", true);
        }
        for i in 0..4 {
            hook.inject(format!("inj{i}")).unwrap();
        }
        std::hint::black_box(hook.get_dependencies());
    }
    let elapsed = start.elapsed();
    println!(
        "hook_build_params_injects: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
