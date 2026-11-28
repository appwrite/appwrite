use crate::server::Settings;

/// The two server shapes: many single-request workers, or a few concurrent
/// ones.
///
/// PHP: `Utopia\Http\Adapter\Swoole\Mode`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Mode {
    /// One request at a time per worker, many workers.
    ///
    /// PHP: `HYPERLOOP_A`.
    HyperloopA,
    /// Concurrent requests per worker, one worker per core.
    ///
    /// PHP: `HYPERLOOP_B`.
    HyperloopB,
}

impl Mode {
    /// The server settings of this mode, sized by the available CPUs.
    ///
    /// PHP: `$mode->settings()`.
    pub fn settings(self) -> Settings {
        // `(int) max(1, ceil(System::getCPU() * n))`: the cgroup-aware CPU
        // count, multiplied before rounding up.
        let cpu = utopia_system::System::new().cpu().unwrap_or(1.0);
        let scaled = |n: f64| (cpu * n).ceil().max(1.0) as usize;
        let cpus = scaled(1.0);
        let base = Settings {
            compression: false,
            tcp_nodelay: true,
            tcp_fastopen: true,
            tcp_defer_accept: 1,
            reuse_port: true,
            max_wait_time: 30,
            max_concurrency: 1_000,
            reload_async: true,
            reactors: cpus,
            workers: cpus,
            coroutine: true,
            dispatch_mode: 2,
            hook_all: None,
            send_yield: None,
            max_request: None,
            aio_workers: None,
            aio_core_workers: None,
        };
        match self {
            Mode::HyperloopA => Settings { coroutine: false, dispatch_mode: 3, workers: scaled(6.0), ..base },
            Mode::HyperloopB => Settings {
                coroutine: true,
                hook_all: Some(true),
                dispatch_mode: 2,
                send_yield: Some(true),
                workers: cpus,
                max_request: Some(10_000),
                aio_workers: Some(scaled(8.0)),
                aio_core_workers: Some(cpus),
                ..base
            },
        }
    }
}
