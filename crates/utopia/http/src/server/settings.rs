/// The server settings of a [`Mode`](crate::Mode), with the CPU count limited by the
/// cgroup quota. [`ServerOptions`](crate::ServerOptions) can be built from them.
///
/// PHP: `Mode::settings()`, the Swoole server settings.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Settings {
    /// Server-side compression (off: the application compresses).
    pub compression: bool,
    /// Whether Nagle's algorithm is disabled on connections.
    pub tcp_nodelay: bool,
    /// Whether TCP Fast Open is enabled.
    pub tcp_fastopen: bool,
    /// Seconds `accept()` waits for the first bytes.
    pub tcp_defer_accept: u32,
    /// Whether several processes may bind the same port.
    pub reuse_port: bool,
    /// Seconds in-flight requests get to finish on shutdown.
    pub max_wait_time: u64,
    /// Requests in flight before the server answers 503.
    pub max_concurrency: usize,
    /// Whether workers reload one at a time, finishing their requests first.
    pub reload_async: bool,
    /// Event-loop threads: one per CPU.
    pub reactors: usize,
    /// Worker processes.
    pub workers: usize,
    /// Whether a worker serves requests concurrently.
    pub coroutine: bool,
    /// How connections reach workers: 3 to an idle worker, 2 by connection.
    pub dispatch_mode: u8,
    /// Whether blocking I/O yields to other requests.
    ///
    /// PHP: `hook_flags = SWOOLE_HOOK_ALL`.
    pub hook_all: Option<bool>,
    /// Whether a send waits for buffer space instead of failing.
    ///
    /// PHP: Swoole's `send_yield`.
    pub send_yield: Option<bool>,
    /// Requests before a worker is recycled.
    pub max_request: Option<u64>,
    /// Threads for asynchronous file I/O.
    ///
    /// PHP: Swoole's `aio_worker_num`.
    pub aio_workers: Option<usize>,
    /// Asynchronous I/O threads kept alive when idle.
    ///
    /// PHP: Swoole's `aio_core_worker_num`.
    pub aio_core_workers: Option<usize>,
}
