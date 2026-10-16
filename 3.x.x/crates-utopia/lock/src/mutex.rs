use std::time::{Duration, Instant};

use parking_lot::{Condvar, Mutex as ParkingMutex};

use crate::error::Contention;
use crate::lock::Lock;

#[derive(Debug)]
struct Inner {
    held: bool,
}

/// PHP `Utopia\Lock\Mutex`.
///
/// Rust waits on a condvar (Swoole coroutine equivalent) so threads serialize.
/// PHP's non-coroutine path is a process-local flag because PHP has no
/// preemption outside Swoole.
#[derive(Debug)]
pub struct Mutex {
    inner: ParkingMutex<Inner>,
    cond: Condvar,
}

impl Default for Mutex {
    fn default() -> Self {
        Self::new()
    }
}

impl Mutex {
    #[must_use]
    pub fn new() -> Self {
        Self {
            inner: ParkingMutex::new(Inner { held: false }),
            cond: Condvar::new(),
        }
    }
}

impl Lock for Mutex {
    fn acquire(&self, timeout: f64) -> bool {
        let mut guard = self.inner.lock();
        if !guard.held {
            guard.held = true;
            return true;
        }
        if timeout == 0.0 {
            return false;
        }
        if timeout < 0.0 {
            while guard.held {
                self.cond.wait(&mut guard);
            }
            guard.held = true;
            return true;
        }
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        while guard.held {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return false;
            }
            if self.cond.wait_for(&mut guard, remaining).timed_out() && guard.held {
                return false;
            }
        }
        guard.held = true;
        true
    }

    fn try_acquire(&self) -> bool {
        self.acquire(0.0)
    }

    fn release(&self) {
        let mut guard = self.inner.lock();
        if guard.held {
            guard.held = false;
            self.cond.notify_one();
        }
    }

    fn contention(&self) -> Contention {
        Contention::new("Failed to acquire mutex within timeout")
    }
}
