use std::time::Instant;

use utopia_pools::adapter::Stack;
use utopia_pools::Pool;

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
    let rt = tokio::runtime::Builder::new_current_thread()
        .enable_time()
        .build()
        .unwrap();

    let pool = Pool::new(Stack::new(), "bench", 8, || "x".to_string(), 1.0).unwrap();
    bench("pool_use", || {
        rt.block_on(async {
            pool.use_resource(|resource| {
                std::hint::black_box(resource.len());
                Ok(())
            })
            .await
            .unwrap();
        });
    });

    let pool = Pool::new(Stack::new(), "bench", 8, || "x".to_string(), 1.0).unwrap();
    bench("pool_pop_push", || {
        rt.block_on(async {
            let connection = pool.pop().await.unwrap();
            std::hint::black_box(connection.resource().len());
            pool.push(&connection);
        });
    });
}
