//! [`Promise`]: running futures, alone or together.

use std::future::{Future, Ready};
use std::time::Duration;

use futures_util::future;

use crate::Error;
use crate::task::JoinHandle;
use crate::time::Sleep;

/// Runs futures: to their end, as tasks, all together, the first to finish.
///
/// A Rust future is a promise that has not started: it does nothing until
/// awaited, then gives its output. A fallible operation's output is a
/// `Result`, which is how it fulfills (`Ok`) or rejects (`Err`). `then`,
/// `catch` and `finally` are the future's own combinators
/// (`futures_util::FutureExt::map`, `TryFutureExt::map_err`, `inspect`),
/// and `await()` is `.await`.
///
/// ```
/// use std::time::Duration;
///
/// use utopia_async::Promise;
///
/// let total = Promise::run(async {
///     let doubled = Promise::spawn(async { 10 * 2 });
///     let fetched = Promise::all([
///         Promise::resolve::<_, String>(1),
///         Promise::resolve(2),
///     ])
///     .await?;
///     Promise::delay(Duration::from_millis(1)).await;
///     Ok::<_, String>(doubled.await.unwrap() + fetched.iter().sum::<i32>())
/// });
/// assert_eq!(total, Ok(23));
/// ```
///
/// ```php
/// use Utopia\Async\Promise;
///
/// Swoole\Coroutine\run(function () {
///     $total = Promise::run(function () {
///         $doubled = Promise::async(fn () => 10 * 2);
///         $fetched = Promise::all([Promise::resolve(1), Promise::resolve(2)])->await();
///         Promise::delay(1)->await();
///         return $doubled->await() + array_sum($fetched);
///     });
///     echo $total, "\n"; // 23
/// });
/// ```
///
/// PHP: `Utopia\Async\Promise`, whose adapter (`Swoole\Coroutine`, React,
/// Amp, Sync) is the engine of the build here ([`crate::Engine`]).
#[derive(Debug, Clone, Copy)]
pub struct Promise;

impl Promise {
    /// Runs `future` to its end on a new single-threaded runtime and returns
    /// its output.
    ///
    /// PHP: `Promise::run(callable $callable)`.
    pub fn run<F: Future>(future: F) -> F::Output {
        crate::run(future)
    }

    /// Starts `future` as a task, running concurrently with the caller;
    /// await the handle for its output.
    ///
    /// PHP: `Promise::async(callable $callable)` (`async` is a keyword in
    /// Rust).
    pub fn spawn<F>(future: F) -> JoinHandle<F::Output>
    where
        F: Future + Send + 'static,
        F::Output: Send + 'static,
    {
        crate::spawn(future)
    }

    /// Completes after `duration`.
    ///
    /// PHP: `Promise::delay(int $milliseconds)`.
    pub fn delay(duration: Duration) -> Sleep {
        crate::sleep(duration)
    }

    /// A future that fulfills with `value` at once.
    ///
    /// PHP: `Promise::resolve(mixed $value)`.
    pub fn resolve<T, E>(value: T) -> Ready<Result<T, E>> {
        std::future::ready(Ok(value))
    }

    /// A future that rejects with `reason` at once.
    ///
    /// PHP: `Promise::reject(mixed $reason)`.
    pub fn reject<T, E>(reason: E) -> Ready<Result<T, E>> {
        std::future::ready(Err(reason))
    }

    /// Runs fallible futures together: every value in order, or the first
    /// error (the others are dropped).
    ///
    /// PHP: `Promise::all(array $promises)`.
    pub async fn all<I, T, E>(futures: I) -> Result<Vec<T>, E>
    where
        I: IntoIterator,
        I::Item: Future<Output = Result<T, E>>,
    {
        future::try_join_all(futures).await
    }

    /// Runs each future as its own task (on any worker of a multi-threaded
    /// runtime) and returns every output in order. A task's panic is
    /// resumed.
    ///
    /// PHP: `Promise::map(array $callables)`.
    pub async fn map<I>(futures: I) -> Vec<<I::Item as Future>::Output>
    where
        I: IntoIterator,
        I::Item: Future + Send + 'static,
        <I::Item as Future>::Output: Send + 'static,
    {
        let tasks: Vec<_> = futures.into_iter().map(crate::spawn).collect();
        let mut outputs = Vec::with_capacity(tasks.len());
        for task in tasks {
            outputs.push(crate::task::join(task).await);
        }
        outputs
    }

    /// The output of the first future to finish; the others are dropped.
    ///
    /// PHP: `Promise::race(array $promises)`; [`Error::Promise`] for none, as
    /// PHP rejects.
    pub async fn race<I>(futures: I) -> Result<<I::Item as Future>::Output, Error>
    where
        I: IntoIterator,
        I::Item: Future,
    {
        let futures: Vec<_> = futures.into_iter().map(Box::pin).collect();
        if futures.is_empty() {
            return Err(Error::Promise("Cannot race with an empty array of promises".to_owned()));
        }
        Ok(future::select_all(futures).await.0)
    }

    /// The value of the first future to succeed; the others are dropped.
    ///
    /// PHP: `Promise::any(array $promises)`; [`Error::Promise`] for none or
    /// when every one fails, as PHP rejects.
    pub async fn any<I, T, E>(futures: I) -> Result<T, Error>
    where
        I: IntoIterator,
        I::Item: Future<Output = Result<T, E>>,
    {
        let futures: Vec<_> = futures.into_iter().map(Box::pin).collect();
        if futures.is_empty() {
            return Err(Error::Promise("No promises provided to any()".to_owned()));
        }
        match future::select_ok(futures).await {
            Ok((value, _)) => Ok(value),
            Err(_) => Err(Error::Promise("All promises were rejected".to_owned())),
        }
    }

    /// Runs futures together and returns every output in order, failures
    /// included.
    ///
    /// PHP: `Promise::allSettled(array $promises)` (`fulfilled` is `Ok`,
    /// `rejected` is `Err`).
    pub async fn all_settled<I>(futures: I) -> Vec<<I::Item as Future>::Output>
    where
        I: IntoIterator,
        I::Item: Future,
    {
        future::join_all(futures).await
    }

    /// Runs `future` for at most `duration`: its output, or
    /// [`Error::Timeout`] (it is then dropped).
    ///
    /// PHP: `$promise->timeout(int $milliseconds)`.
    pub async fn timeout<F: Future>(duration: Duration, future: F) -> Result<F::Output, Error> {
        crate::timeout(duration, future)
            .await
            .map_err(|_| Error::Timeout(format!("Promise timed out after {}ms", duration.as_millis())))
    }
}
