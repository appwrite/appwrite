//! Time sources for the scheduler: the wall clock, and a test clock that
//! stands still until advanced.

use std::future::Future;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use php_std::datetime::{DateTime, TzInfo};

use crate::Error;
use crate::moment;

/// The time source and sleeper of a [`Scheduler`](crate::Scheduler).
///
/// Isolating the clock is what makes timing defects testable: a scheduler
/// driven by a [`TestClock`] can reproduce a tick phase sitting milliseconds
/// before a minute boundary, the kind of state a wall clock only reaches in
/// production.
///
/// PHP: `Utopia\Schedule\Clock`.
pub trait Clock: Send + Sync {
    /// The current moment.
    ///
    /// PHP: `now()`.
    fn now(&self) -> Result<DateTime, Error>;

    /// Waits `seconds` (nothing when it is not positive).
    ///
    /// PHP: `sleep()`.
    fn sleep(&self, seconds: f64) -> impl Future<Output = ()> + Send;
}

impl<T: Clock + ?Sized> Clock for Arc<T> {
    /// The shared clock's moment.
    fn now(&self) -> Result<DateTime, Error> {
        (**self).now()
    }

    /// Sleeps on the shared clock.
    fn sleep(&self, seconds: f64) -> impl Future<Output = ()> + Send {
        (**self).sleep(seconds)
    }
}

/// The wall clock, in UTC. Sleeping yields to the Tokio runtime.
///
/// PHP: `Utopia\Schedule\Clock\System` (with Appwrite's default timezone,
/// UTC).
#[derive(Debug, Clone, Copy, Default)]
pub struct SystemClock;

impl Clock for SystemClock {
    /// Now, in UTC.
    fn now(&self) -> Result<DateTime, Error> {
        DateTime::new(b"now", None, &TzInfo::utc()).map_err(|e| Error::Runtime(e.to_string()))
    }

    /// Sleeps `seconds`, rounded to the microsecond.
    fn sleep(&self, seconds: f64) -> impl Future<Output = ()> + Send {
        let micros = if seconds > 0.0 { (seconds * 1_000_000.0).round().min(u64::MAX as f64) as u64 } else { 0 };
        async move {
            if micros > 0 {
                tokio::time::sleep(Duration::from_micros(micros)).await;
            }
        }
    }
}

/// A deterministic clock for tests: time stands still until advanced, and
/// sleeping advances it instead of blocking. Sub-second phases (a tick
/// starting 59 milliseconds before a minute boundary, a 60 second sleep that
/// resumes 1.5 milliseconds late) become exact, repeatable fixtures.
///
/// Share it with the scheduler through an `Arc` to advance it from the
/// test.
///
/// ```
/// use php_std::datetime::{DateTime, Timestamp, TzInfo};
/// use utopia_schedule::{Clock, TestClock};
///
/// let start = DateTime::parse(b"2026-08-17 15:59:59.941", None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
/// let clock = TestClock::new(&start);
///
/// clock.advance(0.059);
/// assert_eq!(clock.now()?.format(b"H:i:s.u"), b"16:00:00.000000");
/// # Ok::<(), utopia_schedule::Error>(())
/// ```
///
/// ```php
/// use Utopia\Schedule\Clock\Test;
///
/// $clock = new Test(new DateTimeImmutable('2026-08-17 15:59:59.941'));
///
/// $clock->advance(0.059);
/// echo $clock->now()->format('H:i:s.u'), "\n"; // 16:00:00.000000
/// ```
///
/// PHP: `Utopia\Schedule\Clock\Test`.
#[derive(Debug)]
pub struct TestClock {
    timestamp: Mutex<f64>,
}

impl TestClock {
    /// A clock standing at `start`.
    ///
    /// PHP: `new Test($start)`.
    pub fn new(start: &DateTime) -> Self {
        Self { timestamp: Mutex::new(moment::seconds(start)) }
    }

    /// Moves the clock `seconds` forward (backward when negative).
    ///
    /// PHP: `advance()`.
    pub fn advance(&self, seconds: f64) {
        *self.timestamp.lock().unwrap_or_else(|e| e.into_inner()) += seconds;
    }

    fn seconds(&self) -> f64 {
        *self.timestamp.lock().unwrap_or_else(|e| e.into_inner())
    }
}

impl Clock for TestClock {
    /// The moment the clock stands at, in UTC (`+00:00`). Fails with
    /// [`Error::Runtime`] when it was advanced beyond any date.
    fn now(&self) -> Result<DateTime, Error> {
        moment::from_seconds(self.seconds())
            .ok_or_else(|| Error::Runtime("The test clock holds an unrepresentable timestamp".into()))
    }

    /// Advances the clock by `seconds` at once.
    fn sleep(&self, seconds: f64) -> impl Future<Output = ()> + Send {
        self.advance(seconds);
        std::future::ready(())
    }
}
