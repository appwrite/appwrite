use std::time::Instant;
use utopia_console::Command;

fn main() {
    let command = Command::new("echo")
        .unwrap()
        .argument("hello", None)
        .unwrap();
    let _ = command.to_string_shell().unwrap();

    let iters = 500_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        let command = Command::new("tar")
            .unwrap()
            .flag("-cz")
            .unwrap()
            .option("-f", "archive.tar.gz", None)
            .unwrap()
            .option("-C", "/tmp/project", None)
            .unwrap()
            .argument(".", None)
            .unwrap();
        std::hint::black_box(command.to_string_shell().unwrap());
    }
    let elapsed = start.elapsed();
    println!(
        "console_command_build: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
