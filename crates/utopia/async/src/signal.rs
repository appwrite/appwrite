//! Signals: Ctrl-C everywhere, and any Unix signal on Unix.
//!
//! ```no_run
//! use utopia_async::signal::{Kind, Signal};
//!
//! utopia_async::run(async {
//!     let mut terminate = Signal::new(Kind::Terminate)?;
//!     utopia_async::select! {
//!         _ = terminate.recv() => {}
//!         _ = utopia_async::signal::ctrl_c() => {}
//!     }
//!     Ok::<(), std::io::Error>(())
//! })?;
//! # Ok::<(), std::io::Error>(())
//! ```
//!
//! PHP: `Swoole\Process::signal()`, `Swoole\Coroutine\System::waitSignal()`.

use std::io;

use crate::Engine;
use crate::adapter::Adapter;

/// Completes on the next Ctrl-C (`SIGINT`). Once called, Ctrl-C no longer
/// ends the process by itself.
pub async fn ctrl_c() -> io::Result<()> {
    Engine::ctrl_c().await
}

/// A kind of Unix signal.
#[cfg(unix)]
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// `SIGINT`: Ctrl-C.
    Interrupt,
    /// `SIGTERM`: a request to stop (what `docker stop` sends).
    Terminate,
    /// `SIGHUP`: the terminal closed, or a request to reload.
    Hangup,
    /// `SIGQUIT`: Ctrl-\.
    Quit,
    /// `SIGUSR1`.
    User1,
    /// `SIGUSR2`.
    User2,
}

/// A listener for one kind of Unix signal. While one exists, the signal no
/// longer has its default effect (ending the process).
#[cfg(unix)]
#[derive(Debug)]
pub struct Signal {
    inner: <Engine as Adapter>::Signal,
}

#[cfg(unix)]
impl Signal {
    /// Starts listening for `kind`.
    pub fn new(kind: Kind) -> io::Result<Signal> {
        Ok(Signal { inner: <<Engine as Adapter>::Signal as crate::adapter::Signal>::new(kind)? })
    }

    /// Waits for the next signal; `None` when no more can arrive.
    pub async fn recv(&mut self) -> Option<()> {
        crate::adapter::Signal::recv(&mut self.inner).await
    }
}
