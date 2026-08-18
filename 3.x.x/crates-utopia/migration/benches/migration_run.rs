use std::time::Instant;
use utopia_migration::prelude::*;
use utopia_migration::resource::TYPE_DATABASE;
use utopia_migration::resources::database::Column;

fn main() {
    let mut source = MockSource::new();
    for i in 0..50 {
        source.push_mock_resource(Database::new(format!("db{i}"), format!("DB {i}")));
    }
    let mut transfer = Transfer::new(source, MockDestination::new());
    let iters = 5_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        transfer
            .run(&[TYPE_DATABASE], &mut |_| {}, None, None)
            .unwrap();
        std::hint::black_box(&transfer);
    }
    let elapsed = start.elapsed();
    let ops = iters as f64 / elapsed.as_secs_f64();
    println!("ops_per_s={ops:.0} migration_transfer ({elapsed:?} for {iters} iters)");

    let start = Instant::now();
    let iters = 200_000u64;
    for _ in 0..iters {
        std::hint::black_box(Column::resolve(
            serde_json::json!({"key":"email","type":"email"})
                .as_object()
                .unwrap(),
        ));
    }
    let elapsed = start.elapsed();
    let ops = iters as f64 / elapsed.as_secs_f64();
    println!("ops_per_s={ops:.0} migration_column_resolve ({elapsed:?} for {iters} iters)");
}
