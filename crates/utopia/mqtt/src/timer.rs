//! Keep-alive timers: [`TimingWheel`] (one-second buckets drained by a
//! heartbeat) and [`NoTimer`] (never reaps).

use std::collections::{BTreeMap, HashMap};
use std::sync::{Arc, Mutex, MutexGuard, RwLock};

use indexmap::IndexSet;

use crate::adapter::{Timer, TimerHook};

/// A timer that never reaps: scheduling and clearing do nothing.
///
/// PHP: `Utopia\Mqtt\Adapter\Swoole\Timers\NoTimer`.
#[derive(Debug, Clone, Copy, Default)]
pub struct NoTimer;

impl Timer for NoTimer {
    fn on_tick(&self, _hook: TimerHook) {}

    fn on_clear(&self, _hook: TimerHook) {}

    fn schedule(&self, _id: i64, _keep_alive: i64) {}

    fn clear(&self, _id: i64) {}
}

#[derive(Default)]
struct Wheel {
    /// Deadline second => ids due then, in the order scheduled.
    buckets: BTreeMap<i64, IndexSet<i64>>,
    /// The last second drained.
    cursor: i64,
    /// Id => the bucket it occupies.
    slots: HashMap<i64, i64>,
    heartbeat: bool,
}

impl Wheel {
    fn drop_id(&mut self, id: i64) {
        let Some(slot) = self.slots.remove(&id) else {
            return;
        };
        if let Some(bucket) = self.buckets.get_mut(&slot) {
            bucket.shift_remove(&id);
            if bucket.is_empty() {
                self.buckets.remove(&slot);
            }
        }
    }
}

struct Inner {
    wheel: Mutex<Wheel>,
    tick: RwLock<Option<TimerHook>>,
    clear: RwLock<Option<TimerHook>>,
}

/// Keep-alive deadlines in one-second buckets. Scheduling an id puts it in
/// the bucket of the second its keep-alive (times the multiplier) runs out;
/// a heartbeat every `interval` seconds drains the buckets that are due and
/// calls the tick hook with each id in them. Rescheduling (every packet a
/// client sends) moves the id to a later bucket, so only silent clients are
/// reaped, at most `interval` seconds late.
///
/// The heartbeat runs on the runtime the first id is scheduled from
/// (feature `network`); without one, call [`drain`](TimingWheel::drain)
/// yourself.
///
/// ```
/// use std::sync::{Arc, Mutex};
///
/// use utopia_mqtt::{Timer, TimingWheel};
///
/// let now = php_std::datetime::time();
/// let wheel = TimingWheel::new(20, 1.5, Some(now));
/// let reaped = Arc::new(Mutex::new(Vec::new()));
/// let seen = reaped.clone();
/// wheel.on_tick(Arc::new(move |id| seen.lock().unwrap().push(id)));
///
/// wheel.schedule(7, 10); // due in 15 seconds
/// wheel.drain(now + 5);
/// assert!(reaped.lock().unwrap().is_empty());
/// wheel.drain(now + 30);
/// assert_eq!(*reaped.lock().unwrap(), [7]);
/// ```
///
/// ```php
/// use Utopia\Mqtt\Adapter\Swoole\Timers\TimingWheel;
///
/// $now = time();
/// $wheel = new TimingWheel(20, 1.5, $now);
/// $wheel->onTick(fn (int $id) => print("reaped {$id}\n"));
/// $wheel->schedule(7, 10);
/// $wheel->drain($now + 5);
/// $wheel->drain($now + 30); // reaped 7
/// \Swoole\Timer::clearAll();
/// \Swoole\Event::wait();
/// ```
///
/// PHP: `Utopia\Mqtt\Adapter\Swoole\Timers\TimingWheel`, whose heartbeat is
/// a Swoole timer.
#[derive(Clone)]
pub struct TimingWheel {
    /// Seconds between heartbeats.
    pub interval: i64,
    /// How many keep-alive intervals a client may stay silent.
    pub multiplier: f64,
    inner: Arc<Inner>,
}

impl Default for TimingWheel {
    /// A heartbeat every 20 seconds, reaping after 1.5 keep-alive intervals.
    fn default() -> Self {
        Self::new(20, 1.5, None)
    }
}

impl TimingWheel {
    /// A wheel draining every `interval` seconds and reaping after
    /// `multiplier` keep-alive intervals, whose drained time starts at `now`
    /// (the current Unix second when `None`).
    ///
    /// PHP: `new TimingWheel(int $interval = 20, float $multiplier = 1.5, ?int $now = null)`.
    pub fn new(interval: i64, multiplier: f64, now: Option<i64>) -> Self {
        let wheel = Wheel { cursor: now.unwrap_or_else(php_std::datetime::time), ..Wheel::default() };
        Self {
            interval,
            multiplier,
            inner: Arc::new(Inner { wheel: Mutex::new(wheel), tick: RwLock::default(), clear: RwLock::default() }),
        }
    }

    fn wheel(&self) -> MutexGuard<'_, Wheel> {
        self.inner.wheel.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Calls the tick hook with every id due in a second after the last one
    /// drained, up to `now`, in order.
    ///
    /// PHP: `drain(int $now)`.
    pub fn drain(&self, now: i64) {
        drain(&self.inner, now);
    }

    #[cfg(feature = "network")]
    fn heartbeat(&self) {
        let Some(runtime) = utopia_async::Handle::current() else {
            return;
        };
        let inner = Arc::downgrade(&self.inner);
        let period = std::time::Duration::from_secs(self.interval.max(1) as u64);
        runtime.spawn(async move {
            let mut ticker = utopia_async::time::interval_at(std::time::Instant::now() + period, period);
            loop {
                ticker.tick().await;
                let Some(inner) = inner.upgrade() else {
                    return;
                };
                drain(&inner, php_std::datetime::time());
            }
        });
    }

    #[cfg(not(feature = "network"))]
    fn heartbeat(&self) {}
}

fn drain(inner: &Inner, now: i64) {
    let mut second = {
        let wheel = inner.wheel.lock().unwrap_or_else(|e| e.into_inner());
        wheel.cursor
    };
    loop {
        let from = second.saturating_add(1);
        if from > now {
            break;
        }
        let ids: Vec<i64> = {
            let wheel = inner.wheel.lock().unwrap_or_else(|e| e.into_inner());
            match wheel.buckets.range(from..=now).next() {
                Some((&due, ids)) => {
                    second = due;
                    ids.iter().copied().collect()
                }
                None => break,
            }
        };
        for id in ids {
            inner.wheel.lock().unwrap_or_else(|e| e.into_inner()).slots.remove(&id);
            let hook = inner.tick.read().unwrap_or_else(|e| e.into_inner()).clone();
            if let Some(hook) = hook {
                hook(id);
            }
        }
        inner.wheel.lock().unwrap_or_else(|e| e.into_inner()).buckets.remove(&second);
    }
    let mut wheel = inner.wheel.lock().unwrap_or_else(|e| e.into_inner());
    wheel.cursor = wheel.cursor.max(now);
}

impl Timer for TimingWheel {
    fn on_tick(&self, hook: TimerHook) {
        *self.inner.tick.write().unwrap_or_else(|e| e.into_inner()) = Some(hook);
    }

    fn on_clear(&self, hook: TimerHook) {
        *self.inner.clear.write().unwrap_or_else(|e| e.into_inner()) = Some(hook);
    }

    fn schedule(&self, id: i64, keep_alive: i64) {
        let start = {
            let mut wheel = self.wheel();
            wheel.drop_id(id);
            if keep_alive <= 0 {
                return;
            }
            let due = (php_std::datetime::microtime_float() + keep_alive as f64 * self.multiplier).ceil();
            let slot = php_std::format::dval_to_lval(due).max(wheel.cursor.saturating_add(1));
            wheel.buckets.entry(slot).or_default().insert(id);
            wheel.slots.insert(id, slot);
            !std::mem::replace(&mut wheel.heartbeat, true)
        };
        if start {
            self.heartbeat();
        }
    }

    fn clear(&self, id: i64) {
        {
            let mut wheel = self.wheel();
            if !wheel.slots.contains_key(&id) {
                return;
            }
            wheel.drop_id(id);
        }
        let hook = self.inner.clear.read().unwrap_or_else(|e| e.into_inner()).clone();
        if let Some(hook) = hook {
            hook(id);
        }
    }
}
