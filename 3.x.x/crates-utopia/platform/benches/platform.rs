use std::time::Instant;

use utopia_platform::{Action, HttpMethod, Module, Platform, Service};

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.2} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    platform_register_action();
}

fn platform_register_action() {
    bench("platform_register_action", 50_000, || {
        let action = Action::new()
            .desc("benchmark action")
            .groups(["bench"])
            .set_http_path("/bench")
            .set_http_method(HttpMethod::Get)
            .callback(|| {});

        let service = Service::http().add_action("bench", action);
        let platform = Platform::new(Module::new()).add_service("bench", service);
        std::hint::black_box(platform);
    });
}
