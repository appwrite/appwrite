use std::time::Instant;

use utopia_detector::prelude::*;

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 50_000u64;
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
    bench("packager_detect", || {
        let mut detector = Packager::new();
        detector
            .add_option(PNPM::new())
            .add_option(Yarn::new())
            .add_option(NPM::new())
            .add_input("package.json", "")
            .add_input("pnpm-lock.yaml", "");
        std::hint::black_box(detector.detect());
    });

    bench("runtime_detect", || {
        let mut detector = Runtime::new(Strategy::new(Strategy::FILEMATCH).unwrap(), "pnpm");
        detector
            .add_option(Node::new())
            .add_option(PHP::new())
            .add_option(Python::new())
            .add_input("package-lock.json", "")
            .add_input("tsconfig.json", "");
        std::hint::black_box(detector.detect());
    });

    bench("framework_detect", || {
        let mut detector = Framework::new("pnpm");
        detector
            .add_option(NextJs::new())
            .add_option(SvelteKit::new())
            .add_option(Astro::new())
            .add_input("next.config.js", Framework::INPUT_FILE)
            .unwrap()
            .add_input("package.json", Framework::INPUT_FILE)
            .unwrap();
        std::hint::black_box(detector.detect());
    });
}
