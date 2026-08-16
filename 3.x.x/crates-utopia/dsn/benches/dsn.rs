use std::time::Instant;

use utopia_dsn::Dsn;

const TYPICAL: &str = "mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC";

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 200_000u64;
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
    bench("dsn_parse", || {
        std::hint::black_box(Dsn::new(TYPICAL).unwrap());
    });

    let dsn = Dsn::new(TYPICAL).unwrap();
    bench("dsn_get_param", || {
        std::hint::black_box(dsn.get_param("charset", ""));
        std::hint::black_box(dsn.get_param("timezone", ""));
    });
}
