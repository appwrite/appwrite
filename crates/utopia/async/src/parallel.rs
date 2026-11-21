//! [`Parallel`]: CPU-bound work spread over threads.

use std::sync::Arc;

use crate::sync::Semaphore;

/// Runs closures on the engine's blocking pool (threads of their own, off
/// the runtime's workers), so CPU-bound work uses every core without
/// stalling other tasks. A closure's panic is resumed in the caller.
///
/// ```
/// use utopia_async::Parallel;
///
/// utopia_async::run(async {
///     assert_eq!(Parallel::run(|| (1..=10).sum::<u64>()).await, 55);
///
///     let squares = Parallel::map(1..=8u64, |n, _index| n * n, Some(4)).await;
///     assert_eq!(squares, [1, 4, 9, 16, 25, 36, 49, 64]);
///
///     let sums = Parallel::pool((0..4u64).map(|n| move || n * 100), 2).await;
///     assert_eq!(sums, [0, 100, 200, 300]);
/// });
/// ```
///
/// ```php
/// use Utopia\Async\Parallel;
///
/// echo Parallel::run(fn () => array_sum(range(1, 10))), "\n"; // 55
///
/// $squares = Parallel::map(range(1, 8), fn (int $n) => $n * $n, 4);
/// echo json_encode($squares), "\n"; // [1,4,9,16,25,36,49,64]
/// ```
///
/// PHP: `Utopia\Async\Parallel`, whose adapters run closures in Swoole
/// threads or processes, ext-parallel, Amp or React workers (serialising
/// them); here they run on the engine's thread pool, without serialising.
#[derive(Debug, Clone, Copy)]
pub struct Parallel;

/// The number of CPUs, the default number of workers.
fn cpus() -> usize {
    std::thread::available_parallelism().map(usize::from).unwrap_or(1)
}

impl Parallel {
    /// Runs `task` on another thread and returns its output.
    ///
    /// PHP: `Parallel::run(callable $task, mixed ...$args)` (arguments are
    /// captured by the closure).
    pub async fn run<F, R>(task: F) -> R
    where
        F: FnOnce() -> R + Send + 'static,
        R: Send + 'static,
    {
        crate::task::join(crate::spawn_blocking(task)).await
    }

    /// Runs every task on threads of their own, all at once, and returns
    /// their outputs in order.
    ///
    /// PHP: `Parallel::all(array $tasks)`.
    pub async fn all<I, F, R>(tasks: I) -> Vec<R>
    where
        I: IntoIterator<Item = F>,
        F: FnOnce() -> R + Send + 'static,
        R: Send + 'static,
    {
        let tasks: Vec<_> = tasks.into_iter().map(crate::spawn_blocking).collect();
        let mut outputs = Vec::with_capacity(tasks.len());
        for task in tasks {
            outputs.push(crate::task::join(task).await);
        }
        outputs
    }

    /// Applies `callback` to each item and its index, the items split in
    /// `workers` chunks (one per CPU by default) that run in parallel;
    /// returns the outputs in the items' order.
    ///
    /// PHP: `Parallel::map(array $items, callable $callback, ?int $workers)`.
    pub async fn map<I, F, R>(items: I, callback: F, workers: Option<usize>) -> Vec<R>
    where
        I: IntoIterator,
        I::Item: Send + 'static,
        F: Fn(I::Item, usize) -> R + Send + Sync + 'static,
        R: Send + 'static,
    {
        let items: Vec<_> = items.into_iter().enumerate().collect();
        let size = items.len().div_ceil(workers.unwrap_or_else(cpus).max(1)).max(1);
        let callback = Arc::new(callback);
        let mut chunks = Vec::new();
        let mut items = items.into_iter().peekable();
        while items.peek().is_some() {
            let chunk: Vec<_> = items.by_ref().take(size).collect();
            let callback = callback.clone();
            chunks.push(crate::spawn_blocking(move || {
                chunk.into_iter().map(|(index, item)| callback(item, index)).collect::<Vec<R>>()
            }));
        }
        let mut outputs = Vec::new();
        for chunk in chunks {
            outputs.extend(crate::task::join(chunk).await);
        }
        outputs
    }

    /// Calls `callback` with each item and its index, the items split in
    /// `workers` chunks (one per CPU by default) that run in parallel.
    ///
    /// PHP: `Parallel::forEach(array $items, callable $callback, ?int
    /// $workers)`.
    pub async fn for_each<I, F>(items: I, callback: F, workers: Option<usize>)
    where
        I: IntoIterator,
        I::Item: Send + 'static,
        F: Fn(I::Item, usize) + Send + Sync + 'static,
    {
        Parallel::map(items, callback, workers).await;
    }

    /// Runs the tasks on threads, at most `max_concurrency` at a time, and
    /// returns their outputs in order.
    ///
    /// PHP: `Parallel::pool(array $tasks, int $maxConcurrency)`.
    pub async fn pool<I, F, R>(tasks: I, max_concurrency: usize) -> Vec<R>
    where
        I: IntoIterator<Item = F>,
        F: FnOnce() -> R + Send + 'static,
        R: Send + 'static,
    {
        let permits = Arc::new(Semaphore::new(max_concurrency.max(1)));
        let tasks: Vec<_> = tasks
            .into_iter()
            .map(|task| {
                let permits = permits.clone();
                crate::spawn(async move {
                    let _permit = permits.acquire_owned().await.expect("the semaphore is never closed");
                    crate::task::join(crate::spawn_blocking(task)).await
                })
            })
            .collect();
        let mut outputs = Vec::with_capacity(tasks.len());
        for task in tasks {
            outputs.push(crate::task::join(task).await);
        }
        outputs
    }
}
