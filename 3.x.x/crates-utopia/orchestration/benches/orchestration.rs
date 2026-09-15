use std::collections::HashMap;
use std::time::Instant;

use utopia_orchestration::{filter_env_key, parse_command_string, parse_io_stats};

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 100_000u64;
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
    let cmd = "sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'";
    bench("parse_command_string", || {
        std::hint::black_box(parse_command_string(cmd).unwrap());
    });
    bench("parse_io_stats", || {
        std::hint::black_box(parse_io_stats("2.133MiB / 62.8GiB"));
    });
    bench("filter_env_key", || {
        std::hint::black_box(filter_env_key("FOO$BAR.baz-1"));
    });
    let _ = HashMap::<String, String>::new();
}
