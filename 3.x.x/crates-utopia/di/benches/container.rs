use std::time::Instant;
use utopia_di::{Container, Resource};

fn main() {
    let di = Container::new();
    di.set("age", || Ok(Resource::i64(25)));
    di.set_with_deps("john", &["age"], |deps| {
        let age = deps[0].get_as::<i64>("age")?;
        Ok(Resource::string(format!("John {age}")))
    });
    let _ = di.get("john").unwrap();

    let iters = 1_000_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(di.get("john").unwrap());
    }
    let elapsed = start.elapsed();
    let ops = iters as f64 / elapsed.as_secs_f64();
    println!("di_warm_get: {ops:.0} ops/s ({elapsed:?} for {iters} iters)");
}
