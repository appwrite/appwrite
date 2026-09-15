use std::time::Instant;

use utopia_logger::{Adapter, Breadcrumb, Log, Logger, LoggerError, User};

struct NoopAdapter;

impl Adapter for NoopAdapter {
    fn get_name(&self) -> &'static str {
        "noop"
    }

    fn push(&self, _log: &Log) -> Result<u16, LoggerError> {
        Ok(200)
    }

    fn get_supported_types(&self) -> &'static [&'static str] {
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_VERBOSE,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ]
    }

    fn get_supported_environments(&self) -> &'static [&'static str] {
        &[Log::ENVIRONMENT_STAGING, Log::ENVIRONMENT_PRODUCTION]
    }

    fn get_supported_breadcrumb_types(&self) -> &'static [&'static str] {
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_VERBOSE,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ]
    }
}

fn sample_log() -> Log {
    let mut log = Log::new();
    log.set_action("controller.database.deleteDocument");
    log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
    log.set_namespace("api");
    log.set_server(Some("digitalocean-us-001"));
    log.set_type(Log::TYPE_ERROR).unwrap();
    log.set_version("0.11.5");
    log.set_message("Document efgh5678 not found");
    log.set_user(User::new(Some("efgh5678"), None, None));
    log.add_breadcrumb(
        Breadcrumb::new(
            Log::TYPE_DEBUG,
            "http",
            "DELETE /api/v1/database/abcd1234",
            1.0,
        )
        .unwrap(),
    );
    log.add_tag("sdk", "Flutter");
    log.add_extra("urgent", false);
    log
}

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(1_000) {
        f();
    }
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
    bench("log_construct", 200_000, || {
        std::hint::black_box(sample_log());
    });

    let logger = Logger::new(NoopAdapter);
    let log = sample_log();
    bench("log_add_log", 200_000, || {
        std::hint::black_box(logger.add_log(&log).unwrap());
    });
}
