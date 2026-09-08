use std::time::{Duration, Instant};

use parking_lot::{Condvar, Mutex as ParkingMutex};

use crate::error::Contention;
use crate::lock::Lock;

#[derive(Debug)]
struct Inner {
    held: usize,
}

/// PHP `Utopia\Lock\Semaphore`.
#[derive(Debug)]
pub struct Semaphore {
    permits: usize,
    inner: ParkingMutex<Inner>,
    cond: Condvar,
}

impl Semaphore {
    pub fn new(permits: usize) -> Result<Self, String> {
        if permits < 1 {
            return Err("Permits must be at least 1".into());
        }
        Ok(Self {
            permits,
            inner: ParkingMutex::new(Inner { held: 0 }),
            cond: Condvar::new(),
        })
    }
}

impl Lock for Semaphore {
    fn acquire(&self, timeout: f64) -> bool {
        let mut guard = self.inner.lock();
        if guard.held < self.permits {
            guard.held += 1;
            return true;
        }
        if timeout == 0.0 {
            return false;
        }
        if timeout < 0.0 {
            while guard.held >= self.permits {
                self.cond.wait(&mut guard);
            }
            guard.held += 1;
            return true;
        }
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        while guard.held >= self.permits {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return false;
            }
            if self.cond.wait_for(&mut guard, remaining).timed_out() && guard.held >= self.permits {
                return false;
            }
        }
        guard.held += 1;
        true
    }

    fn try_acquire(&self) -> bool {
        self.acquire(0.0)
    }

    fn release(&self) {
        let mut guard = self.inner.lock();
        if guard.held > 0 {
            guard.held -= 1;
            self.cond.notify_one();
        }
    }

    fn contention(&self) -> Contention {
        Contention::new("Failed to acquire semaphore within timeout")
    }
}
