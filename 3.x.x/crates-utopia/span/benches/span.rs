use std::sync::Arc;
use std::time::Instant;

use utopia_span::{AttrValue, Exporter, Memory, NoneExporter, Span};

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
    bench("span_construct", 200_000, || {
        std::hint::black_box(Span::new());
    });

    Span::set_storage(Some(Arc::new(Memory::new())));
    let none: Arc<dyn Exporter> = Arc::new(NoneExporter::new());
    Span::set_exporters([none]);
    bench("span_init_finish", 100_000, || {
        let span = Span::init("http.request", None);
        span.set("user.id", "123");
        span.set("cached", true);
        span.finish();
        std::hint::black_box(());
    });

    let span = Span::new();
    bench("span_set_get", 500_000, || {
        span.set("k", AttrValue::from("v"));
        std::hint::black_box(span.get("k"));
    });
}
