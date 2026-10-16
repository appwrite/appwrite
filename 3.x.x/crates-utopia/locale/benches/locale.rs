use std::time::Instant;

use utopia_locale::Locale;

fn bench(name: &str, mut f: impl FnMut()) {
    let iters = 500_000u64;
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
    Locale::set_language_from_array(
        "en-US",
        [
            ("hello", "Hello"),
            (
                "likes",
                "You have {{likesAmount}} likes and {{commentsAmount}} comments.",
            ),
        ],
    );
    let locale = Locale::new("en-US").unwrap();

    bench("locale_get_text_plain", || {
        std::hint::black_box(
            locale
                .get_text(
                    "hello",
                    Some(Locale::DEFAULT_DYNAMIC_KEY),
                    Locale::NO_PLACEHOLDERS,
                )
                .unwrap(),
        );
    });
    bench("locale_get_text_placeholders", || {
        std::hint::black_box(
            locale
                .get_text(
                    "likes",
                    Some(Locale::DEFAULT_DYNAMIC_KEY),
                    [("likesAmount", 12), ("commentsAmount", 55)],
                )
                .unwrap(),
        );
    });
}
