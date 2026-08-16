use std::path::PathBuf;
use std::time::Instant;

use serde_json::json;
use utopia_view::View;

fn mock(name: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/mocks/View")
        .join(name)
}

fn bench(name: &str, mut f: impl FnMut()) {
    let iters = 20_000u64;
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
    let simple = View::new(mock("template.phtml").to_string_lossy().into_owned());
    bench("view_render", || {
        std::hint::black_box(simple.render(true).unwrap());
    });

    let bulky = View::new(mock("minify.phtml").to_string_lossy().into_owned());
    bulky.set_param("unused", json!("x"), true).unwrap();
    bench("view_minify", || {
        std::hint::black_box(bulky.render(true).unwrap());
    });
}
