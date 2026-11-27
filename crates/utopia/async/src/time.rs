//! Time: [`sleep`], [`timeout`] and [`interval`], on the engine's timers.

use std::fmt;
use std::future::{Future, IntoFuture};
use std::pin::Pin;
use std::task::{Context, Poll, ready};

#[doc(no_inline)]
pub use std::time::{Duration, Instant};

use pin_project_lite::pin_project;

use crate::Engine;
use crate::adapter::{self, Adapter};

/// A deadline about 30 years away, for durations too long to add to now.
fn far_future() -> Instant {
    Instant::now() + Duration::from_secs(86400 * 365 * 30)
}

/// `duration` from now, or the far future when that does not fit.
fn after(duration: Duration) -> Instant {
    Instant::now().checked_add(duration).unwrap_or_else(far_future)
}

/// Waits for `duration`. The returned future does nothing until awaited.
///
/// ```
/// use std::time::{Duration, Instant};
///
/// utopia_async::run(async {
///     let start = Instant::now();
///     utopia_async::sleep(Duration::from_millis(10)).await;
///     assert!(start.elapsed() >= Duration::from_millis(10));
/// });
/// ```
///
/// PHP: `Swoole\Coroutine::sleep(float $seconds)`, `usleep()` inside a
/// coroutine.
#[inline]
pub fn sleep(duration: Duration) -> Sleep {
    sleep_until(after(duration))
}

/// Waits until `deadline`.
#[inline]
pub fn sleep_until(deadline: Instant) -> Sleep {
    Sleep { inner: Engine::sleep_until(deadline) }
}

pin_project! {
    /// The future of [`sleep`] and [`sleep_until`]: completes at its deadline,
    /// which [`Sleep::reset`] moves.
    #[must_use = "futures do nothing unless you `.await` or poll them"]
    pub struct Sleep {
        #[pin]
        inner: <Engine as Adapter>::Sleep,
    }
}

impl Sleep {
    /// When the sleep completes.
    #[inline]
    pub fn deadline(&self) -> Instant {
        adapter::Sleep::deadline(&self.inner)
    }

    /// Whether the deadline has passed.
    #[inline]
    pub fn is_elapsed(&self) -> bool {
        adapter::Sleep::is_elapsed(&self.inner)
    }

    /// Moves the deadline; a completed sleep can be awaited again.
    #[inline]
    pub fn reset(self: Pin<&mut Self>, deadline: Instant) {
        adapter::Sleep::reset(self.project().inner, deadline);
    }
}

impl Future for Sleep {
    type Output = ();

    #[inline]
    fn poll(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<()> {
        self.project().inner.poll(cx)
    }
}

impl fmt::Debug for Sleep {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Sleep").field("deadline", &self.deadline()).finish()
    }
}

/// Runs `future` for at most `duration`: its output, or [`Elapsed`] when it
/// did not finish in time (it is then dropped, cancelling it).
///
/// ```
/// use std::time::Duration;
///
/// utopia_async::run(async {
///     let slow = utopia_async::sleep(Duration::from_secs(5));
///     assert!(utopia_async::timeout(Duration::from_millis(5), slow).await.is_err());
/// });
/// ```
///
/// PHP: the `$timeout` of Swoole's coroutine calls
/// (`Channel::pop($timeout)`, `Client::recv($timeout)`).
#[inline]
pub fn timeout<F: IntoFuture>(duration: Duration, future: F) -> Timeout<F::IntoFuture> {
    timeout_at(after(duration), future)
}

/// Runs `future` until `deadline`: its output, or [`Elapsed`] when it did not
/// finish in time.
#[inline]
pub fn timeout_at<F: IntoFuture>(deadline: Instant, future: F) -> Timeout<F::IntoFuture> {
    Timeout { future: future.into_future(), sleep: sleep_until(deadline) }
}

pin_project! {
    /// The future of [`timeout`] and [`timeout_at`].
    #[must_use = "futures do nothing unless you `.await` or poll them"]
    #[derive(Debug)]
    pub struct Timeout<F> {
        #[pin]
        future: F,
        #[pin]
        sleep: Sleep,
    }
}

impl<F> Timeout<F> {
    /// The future being timed.
    pub fn get_ref(&self) -> &F {
        &self.future
    }

    /// The future being timed.
    pub fn get_mut(&mut self) -> &mut F {
        &mut self.future
    }

    /// The future being timed, without its deadline.
    pub fn into_inner(self) -> F {
        self.future
    }
}

impl<F: Future> Future for Timeout<F> {
    type Output = Result<F::Output, Elapsed>;

    #[inline]
    fn poll(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Self::Output> {
        let this = self.project();
        if let Poll::Ready(output) = this.future.poll(cx) {
            return Poll::Ready(Ok(output));
        }
        match this.sleep.poll(cx) {
            Poll::Ready(()) => Poll::Ready(Err(Elapsed(()))),
            Poll::Pending => Poll::Pending,
        }
    }
}

/// The error of a [`timeout`] whose deadline passed first.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Elapsed(());

impl fmt::Display for Elapsed {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("deadline has elapsed")
    }
}

impl std::error::Error for Elapsed {}

impl From<Elapsed> for std::io::Error {
    fn from(elapsed: Elapsed) -> std::io::Error {
        std::io::Error::new(std::io::ErrorKind::TimedOut, elapsed)
    }
}

/// Ticks every `period`, first right away.
///
/// # Panics
///
/// When `period` is zero.
///
/// PHP: `Swoole\Timer::tick(int $ms, callable $fn)`, as a loop.
pub fn interval(period: Duration) -> Interval {
    interval_at(Instant::now(), period)
}

/// Ticks every `period`, first at `start`.
///
/// ```
/// use std::time::{Duration, Instant};
///
/// use utopia_async::time::interval_at;
///
/// utopia_async::run(async {
///     let period = Duration::from_millis(5);
///     let mut ticks = interval_at(Instant::now() + period, period);
///     let first = ticks.tick().await;
///     let second = ticks.tick().await;
///     assert_eq!(second - first, period);
/// });
/// ```
///
/// # Panics
///
/// When `period` is zero.
pub fn interval_at(start: Instant, period: Duration) -> Interval {
    assert!(period > Duration::ZERO, "`period` must be non-zero.");
    Interval { delay: Box::pin(sleep_until(start)), period, missed: MissedTickBehavior::Burst }
}

/// What an [`Interval`] does about ticks it missed (the task was busy past
/// a tick's time).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum MissedTickBehavior {
    /// Ticks the missed ticks at once, then keeps the original schedule.
    #[default]
    Burst,
    /// Ticks once, then `period` after that tick.
    Delay,
    /// Ticks once, then at the next tick of the original schedule.
    Skip,
}

impl MissedTickBehavior {
    fn next(self, tick: Instant, now: Instant, period: Duration) -> Instant {
        match self {
            MissedTickBehavior::Burst => tick + period,
            MissedTickBehavior::Delay => now + period,
            MissedTickBehavior::Skip => {
                let late = (now - tick).as_nanos() % period.as_nanos();
                now + period - Duration::from_nanos(late as u64)
            }
        }
    }
}

/// The ticks of [`interval`] and [`interval_at`].
#[derive(Debug)]
pub struct Interval {
    delay: Pin<Box<Sleep>>,
    period: Duration,
    missed: MissedTickBehavior,
}

impl Interval {
    /// Waits for the next tick and returns its scheduled time.
    pub async fn tick(&mut self) -> Instant {
        std::future::poll_fn(|cx| self.poll_tick(cx)).await
    }

    /// Polls for the next tick.
    pub fn poll_tick(&mut self, cx: &mut Context<'_>) -> Poll<Instant> {
        ready!(self.delay.as_mut().poll(cx));
        let tick = self.delay.deadline();
        let now = Instant::now();
        // A tick a few milliseconds late is not a missed one.
        let next = if now > tick + Duration::from_millis(5) {
            self.missed.next(tick, now, self.period)
        } else {
            tick.checked_add(self.period).unwrap_or_else(far_future)
        };
        self.delay.as_mut().reset(next);
        Poll::Ready(tick)
    }

    /// Starts over: the next tick is one period from now.
    pub fn reset(&mut self) {
        self.delay.as_mut().reset(after(self.period));
    }

    /// The next tick is right away.
    pub fn reset_immediately(&mut self) {
        self.delay.as_mut().reset(Instant::now());
    }

    /// The next tick is at `deadline`.
    pub fn reset_at(&mut self, deadline: Instant) {
        self.delay.as_mut().reset(deadline);
    }

    /// The time between ticks.
    pub fn period(&self) -> Duration {
        self.period
    }

    /// What the interval does about missed ticks.
    pub fn missed_tick_behavior(&self) -> MissedTickBehavior {
        self.missed
    }

    /// Sets what the interval does about missed ticks.
    pub fn set_missed_tick_behavior(&mut self, behavior: MissedTickBehavior) {
        self.missed = behavior;
    }
}
