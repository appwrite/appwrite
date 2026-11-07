//! [`Timer`]: callbacks run once after a delay, or on every tick, and their
//! [`Kind`]s.

use std::collections::BTreeMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Mutex, MutexGuard, OnceLock};
use std::time::{Duration, Instant};

use crate::task::JoinHandle;
use crate::time::{MissedTickBehavior, interval_at};

/// Callbacks run once after a delay ([`Timer::after`]) or every period
/// ([`Timer::tick`]), as tasks of the current runtime, until cleared. Each
/// timer has a process-wide id.
///
/// ```
/// use std::sync::Arc;
/// use std::sync::atomic::{AtomicUsize, Ordering};
/// use std::time::Duration;
///
/// use utopia_async::Timer;
///
/// utopia_async::run(async {
///     let ticks = Arc::new(AtomicUsize::new(0));
///     let counter = ticks.clone();
///     Timer::tick(Duration::from_millis(2), move |id| {
///         if counter.fetch_add(1, Ordering::SeqCst) + 1 == 3 {
///             Timer::clear(id);
///         }
///     });
///     let once = Timer::after(Duration::from_secs(60), || unreachable!());
///     assert!(Timer::exists(once));
///     assert!(Timer::clear(once));
///
///     utopia_async::sleep(Duration::from_millis(50)).await;
///     assert_eq!(ticks.load(Ordering::SeqCst), 3);
/// });
/// ```
///
/// ```php
/// use Utopia\Async\Timer;
///
/// Swoole\Coroutine\run(function () {
///     $ticks = 0;
///     Timer::tick(2, function (int $id) use (&$ticks) {
///         if (++$ticks === 3) {
///             Timer::clear($id);
///         }
///     });
///     $once = Timer::after(60000, fn () => throw new \LogicException('cleared'));
///     var_dump(Timer::exists($once)); // bool(true)
///     var_dump(Timer::clear($once)); // bool(true)
///
///     Swoole\Coroutine::sleep(0.05);
///     echo $ticks, "\n"; // 3
/// });
/// ```
///
/// PHP: `Utopia\Async\Timer`, whose adapter (`Swoole\Coroutine` over
/// `Swoole\Timer`, React, Amp, Sync) is the engine of the build here
/// ([`crate::Engine`]).
#[derive(Debug, Clone, Copy)]
pub struct Timer;

/// What a timer does.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// Runs its callback once ([`Timer::after`]).
    After,
    /// Runs its callback every period ([`Timer::tick`]).
    Tick,
}

struct Entry {
    kind: Kind,
    task: JoinHandle<()>,
}

static NEXT: AtomicU64 = AtomicU64::new(1);

fn registry() -> MutexGuard<'static, BTreeMap<u64, Entry>> {
    static TIMERS: OnceLock<Mutex<BTreeMap<u64, Entry>>> = OnceLock::new();
    TIMERS.get_or_init(Default::default).lock().unwrap_or_else(|e| e.into_inner())
}

impl Timer {
    /// Runs `callback` once, `delay` from now; returns the timer's id.
    ///
    /// # Panics
    ///
    /// Outside a runtime.
    ///
    /// PHP: `Timer::after(int $milliseconds, callable $callback)`.
    pub fn after(delay: Duration, callback: impl FnOnce() + Send + 'static) -> u64 {
        let id = NEXT.fetch_add(1, Ordering::Relaxed);
        // The registry stays locked until the timer is in it, so the task
        // finds its entry however soon it runs.
        let mut timers = registry();
        let task = crate::spawn(async move {
            crate::sleep(delay).await;
            let due = registry().remove(&id).is_some();
            if due {
                callback();
            }
        });
        timers.insert(id, Entry { kind: Kind::After, task });
        id
    }

    /// Runs `callback` with the timer's id every `period`, the first time
    /// one period from now, until the timer is cleared (from the callback
    /// too); returns the timer's id. A tick that runs late moves the next
    /// one period later.
    ///
    /// # Panics
    ///
    /// Outside a runtime, or when `period` is zero.
    ///
    /// PHP: `Timer::tick(int $milliseconds, callable $callback)`.
    pub fn tick(period: Duration, mut callback: impl FnMut(u64) + Send + 'static) -> u64 {
        assert!(period > Duration::ZERO, "`period` must be non-zero.");
        let id = NEXT.fetch_add(1, Ordering::Relaxed);
        let mut timers = registry();
        let task = crate::spawn(async move {
            let mut ticks = interval_at(Instant::now() + period, period);
            ticks.set_missed_tick_behavior(MissedTickBehavior::Delay);
            loop {
                ticks.tick().await;
                if !Timer::exists(id) {
                    return;
                }
                callback(id);
            }
        });
        timers.insert(id, Entry { kind: Kind::Tick, task });
        id
    }

    /// Stops the timer `id`; whether it was running.
    ///
    /// PHP: `Timer::clear(int $timerId)`.
    pub fn clear(id: u64) -> bool {
        let entry = registry().remove(&id);
        entry.map(|entry| entry.task.abort()).is_some()
    }

    /// Stops every timer.
    ///
    /// PHP: `Timer::clearAll()`.
    pub fn clear_all() {
        let timers = std::mem::take(&mut *registry());
        for entry in timers.into_values() {
            entry.task.abort();
        }
    }

    /// Whether the timer `id` is running.
    ///
    /// PHP: `Timer::exists(int $timerId)`.
    pub fn exists(id: u64) -> bool {
        registry().contains_key(&id)
    }

    /// The running timers' ids and kinds, oldest first.
    ///
    /// PHP: `Timer::getTimers()`.
    pub fn timers() -> Vec<(u64, Kind)> {
        registry().iter().map(|(id, entry)| (*id, entry.kind)).collect()
    }
}
