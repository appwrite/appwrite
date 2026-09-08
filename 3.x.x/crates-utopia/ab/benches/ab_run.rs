use std::time::Instant;
use utopia_ab::Test;

fn main() {
    let mut test = Test::new("bench");
    test.variation("title1", "Hello World", Some(40))
        .variation("title2", "Foo Bar", Some(30))
        .variation("title3", "Callback-like", Some(30));
    let _ = test.run().unwrap();

    let iters = 200_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(test.run().unwrap());
    }
    let elapsed = start.elapsed();
    println!(
        "ab_run: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
