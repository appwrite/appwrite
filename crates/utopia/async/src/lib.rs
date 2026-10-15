//! Promises, timers and parallel work, and the async engine they run on,
//! behind one adapter.
//!
//! [`Promise`] runs futures alone or together, [`Timer`] runs callbacks
//! after a delay or on every tick, and [`Parallel`] spreads CPU-bound
//! closures over threads. Below them, the engine schedules tasks, keeps
//! timers, drives sockets and runs blocking work; every Utopia crate reaches
//! it through this crate ([`spawn`], [`sleep`], [`net`], [`fs`],
//! [`process`], ...) and never names it.
//!
//! The engine is the [`Adapter`] of the build, [`Engine`]: Tokio by default
//! ([`Tokio`], feature `tokio`). Another engine is a new adapter in
//! [`adapter`] and a feature flag; no crate that uses this one changes. It
//! is chosen at compile time, so a call is a direct call into the engine:
//! no boxing, no dynamic dispatch. Each engine type is wrapped in a type of
//! this crate with only what the [`Adapter`] contract promises, so code
//! cannot come to rely on one engine's extras.
//!
//! ```
//! use std::time::Duration;
//!
//! use utopia_async::{Parallel, Promise, Timer};
//!
//! let (user, posts, hash) = Promise::run(async {
//!     let user = Promise::spawn(async { "ada" });
//!     let posts = Promise::all([Promise::resolve::<_, String>(3), Promise::resolve(4)]);
//!     let hash = Parallel::run(|| (1..=1000u64).sum::<u64>());
//!     let once = Timer::after(Duration::from_secs(60), || unreachable!());
//!     Timer::clear(once);
//!     (user.await.unwrap(), posts.await.unwrap(), hash.await)
//! });
//! assert_eq!((user, posts, hash), ("ada", vec![3, 4], 500500));
//! ```
//!
//! ```php
//! use Utopia\Async\Parallel;
//! use Utopia\Async\Promise;
//! use Utopia\Async\Timer;
//!
//! $hash = Parallel::run(fn () => array_sum(range(1, 1000)));
//! Swoole\Coroutine\run(function () use ($hash) {
//!     [$user, $posts] = Promise::run(function () {
//!         $user = Promise::async(fn () => 'ada');
//!         $posts = Promise::all([Promise::resolve(3), Promise::resolve(4)]);
//!         Timer::clear(Timer::after(60000, fn () => throw new \LogicException('cleared')));
//!         return [$user->await(), $posts->await()];
//!     });
//!     echo json_encode([$user, $posts, $hash]), "\n"; // ["ada",[3,4],500500]
//! });
//! ```
//!
//! Tests run on a fresh single-threaded runtime with
//! [`#[utopia_async::test]`](test) (`threads = N` for a multi-threaded
//! one).
//!
//! PHP: ports `utopia-php/async`. PHP picks an adapter per facade at run
//! time (Swoole, React, Amp, then Sync); Rust picks one engine for the build
//! at compile time ([`Engine`]), which every facade and crate uses. The
//! engine's own API is the one PHP code gets from Swoole and its coroutine
//! hooks.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Async\Promise` | [`Promise`] |
//! | `Promise::run()`, `async()`, `delay()`, `resolve()`, `reject()` | [`Promise::run`], [`Promise::spawn`], [`Promise::delay`], [`Promise::resolve`], [`Promise::reject`] |
//! | `Promise::all()`, `map()`, `race()`, `any()`, `allSettled()` | [`Promise::all`], [`Promise::map`], [`Promise::race`], [`Promise::any`], [`Promise::all_settled`] |
//! | `->then()`, `->catch()`, `->finally()`, `->await()`, `->timeout()` | the future's combinators (`futures_util::FutureExt`), `.await`, [`Promise::timeout`] |
//! | `Utopia\Async\Timer` (`after()`, `tick()`, `clear()`, `clearAll()`, `exists()`, `getTimers()`) | [`Timer`] ([`Timer::after`], [`Timer::tick`], [`Timer::clear`], [`Timer::clear_all`], [`Timer::exists`], [`Timer::timers`]) |
//! | `Utopia\Async\Parallel` (`run()`, `all()`, `map()`, `forEach()`, `pool()`) | [`Parallel`] ([`Parallel::run`], [`Parallel::all`], [`Parallel::map`], [`Parallel::for_each`], [`Parallel::pool`]) |
//! | `Promise\Adapter`, `Timer\Adapter`, `Parallel\Adapter` | [`Adapter`] (one engine contract) |
//! | `Promise\Adapter\Swoole\Coroutine`, `Timer\Adapter\Swoole\Coroutine`, `Parallel\Adapter\Swoole\Thread` | [`Tokio`] |
//! | `Promise::setAdapter()`, the adapter detection | the engine feature ([`Engine`]) |
//! | `Exception\Promise`, `Exception\Timeout` | [`Error::Promise`], [`Error::Timeout`] |
//! | `Swoole\Coroutine\run()`, `Swoole\Coroutine\Scheduler` | [`run`], [`Runtime`], [`Builder`] |
//! | `go()`, `Swoole\Coroutine::getCid()`, `getContext()` | [`spawn`], [`task::id`], [`task_local!`] |
//! | `Swoole\Coroutine::sleep()`, a call's `$timeout` | [`sleep`], [`timeout`] |
//! | `Swoole\Coroutine\Channel`, `Lock`, `WaitGroup`, `Barrier` | [`sync`], [`task::JoinSet`] |
//! | Swoole task workers | [`spawn_blocking`], [`block_in_place`] |
//! | `Swoole\Coroutine\Socket`, `Client`, `Server`, `System::getaddrinfo()` | [`net`] |
//! | the coroutine hooks over files, streams and `proc_open()` | [`fs`], [`io`], [`process`] |
//! | `Swoole\Process::signal()` | [`signal`] |

#![warn(missing_docs)]

extern crate self as utopia_async;

pub mod adapter;
mod error;
pub mod fs;
#[cfg(feature = "hyper")]
pub mod hyper;
pub mod io;
pub mod net;
mod parallel;
pub mod process;
mod promise;
mod runtime;
pub mod signal;
pub mod sync;
pub mod task;
pub mod time;
pub mod timer;

pub use adapter::Adapter;
#[cfg(feature = "tokio")]
pub use adapter::Tokio;
pub use error::Error;
pub use parallel::Parallel;
pub use promise::Promise;
pub use runtime::{Builder, Flavor, Handle, Runtime, run};
pub use task::{JoinError, JoinHandle, block_in_place, spawn, spawn_blocking, yield_now};
pub use time::{sleep, timeout};
pub use timer::Timer;

/// Runs an async test function to its end on a new runtime: single-threaded,
/// or multi-threaded with `threads = N` workers.
///
/// ```
/// #[utopia_async::test]
/// async fn adds() {
///     let task = utopia_async::spawn(async { 1 + 1 });
///     assert_eq!(task.await.unwrap(), 2);
/// }
///
/// #[utopia_async::test(threads = 2)]
/// async fn adds_in_parallel() -> std::io::Result<()> {
///     let task = utopia_async::spawn_blocking(|| 2 + 2);
///     assert_eq!(task.await?, 4);
///     Ok(())
/// }
/// ```
///
/// PHP: a PHPUnit test whose body runs in `Swoole\Coroutine\run()`.
pub use utopia_async_macros::test;

/// Waits on several futures at once and runs the branch of the first to
/// complete, dropping the others.
#[doc(inline)]
pub use ::tokio::select;

/// Runs several futures concurrently in the current task and waits for all.
#[doc(inline)]
pub use ::tokio::join;

/// Runs several fallible futures concurrently in the current task: all
/// their outputs, or the first error.
#[doc(inline)]
pub use ::tokio::try_join;

/// Pins values on the stack.
#[doc(inline)]
pub use ::tokio::pin;

/// Declares task-local storage: a value set for the duration of a future
/// with [`task::LocalKey::scope`] and read by the code it runs.
///
/// PHP: `Swoole\Coroutine::getContext()`.
#[cfg(feature = "tokio")]
#[doc(inline)]
pub use ::tokio::task_local;

/// The engine of this build.
#[cfg(feature = "tokio")]
pub type Engine = adapter::Tokio;

#[cfg(not(feature = "tokio"))]
compile_error!("utopia-async needs an engine: enable its `tokio` feature");

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
