use std::time::Instant;

use serde_json::Value;
use utopia_cli::adapters::Generic;
use utopia_cli::{Cli, Params};
use utopia_validators::Text;

fn main() {
    let iters = 50_000u64;

    let start = Instant::now();
    for _ in 0..iters {
        let mut cli = Cli::new(
            Some(Box::new(Generic::new())),
            vec![
                "bench.php".into(),
                "build".into(),
                "--email=me@example.com".into(),
            ],
            None,
        )
        .unwrap();
        cli.task("build")
            .param("email", Value::Null, Text::new(0), "email", false)
            .action(|params: &Params| {
                std::hint::black_box(params.get_str("email").map(ToOwned::to_owned));
                Value::Null
            });
        std::hint::black_box(cli.match_task().map(utopia_cli::Task::get_name));
        std::hint::black_box(cli.get_args().len());
        cli.run();
    }
    let elapsed = start.elapsed();
    println!(
        "cli_dispatch: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );

    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(utopia_cli::camel_case_it("commit-changes"));
    }
    let elapsed = start.elapsed();
    println!(
        "cli_camel_case: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
