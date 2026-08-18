use serde_json::json;
use std::time::Instant;
use utopia_pay::{Credit, Discount, Invoice};

fn main() {
    let iters = 50_000u64;
    let start = Instant::now();
    for i in 0..iters {
        let mut invoice = Invoice::new(format!("inv-{i}"), 100.0);
        invoice.add_discount(Discount::new("d", 10.0, "bench", Discount::TYPE_FIXED).unwrap());
        invoice.add_credit(Credit::new("c", 25.0));
        invoice.finalize();
        std::hint::black_box(invoice.to_array());
        std::hint::black_box(json!({"n": i}));
    }
    let elapsed = start.elapsed();
    println!(
        "pay_finalize: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
