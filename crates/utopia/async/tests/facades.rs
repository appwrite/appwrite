//! The facades of `utopia-php/async`: Promise, Timer and Parallel.

use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use utopia_async::timer::Kind;
use utopia_async::{Error, Parallel, Promise, Timer};

#[test]
fn promises_run_to_their_end() {
    assert_eq!(Promise::run(async { 5 }), 5);
}

#[utopia_async::test]
async fn all_fulfills_in_order_or_rejects() {
    let values = Promise::all([Promise::resolve::<_, &str>(1), Promise::resolve(2), Promise::resolve(3)]).await;
    assert_eq!(values, Ok(vec![1, 2, 3]));
    let failed = Promise::all([Promise::resolve(1), Promise::reject("no")]).await;
    assert_eq!(failed, Err("no"));
    let none: Result<Vec<i32>, ()> = Promise::all(Vec::<std::future::Ready<Result<i32, ()>>>::new()).await;
    assert_eq!(none, Ok(vec![]));
}

#[utopia_async::test(threads = 2)]
async fn map_runs_each_future_as_a_task() {
    let outputs = Promise::map((0..5u64).map(|n| async move {
        utopia_async::sleep(Duration::from_millis(5 - n)).await;
        n * 2
    }))
    .await;
    assert_eq!(outputs, [0, 2, 4, 6, 8]);
}

#[utopia_async::test]
async fn race_takes_the_first_to_settle() {
    let slow = Box::pin(async {
        Promise::delay(Duration::from_secs(5)).await;
        "slow"
    }) as std::pin::Pin<Box<dyn std::future::Future<Output = &str> + Send>>;
    let fast = Box::pin(async { "fast" }) as std::pin::Pin<Box<dyn std::future::Future<Output = &str> + Send>>;
    assert_eq!(Promise::race([slow, fast]).await, Ok("fast"));
    let empty = Promise::race(Vec::<std::future::Ready<()>>::new()).await.unwrap_err();
    assert_eq!(empty, Error::Promise("Cannot race with an empty array of promises".into()));
    assert_eq!(empty.php_class(), "Utopia\\Async\\Exception\\Promise");
}

#[utopia_async::test]
async fn any_takes_the_first_success() {
    let first = Promise::any([Promise::reject::<i32, _>("a"), Promise::resolve(2), Promise::resolve(3)]).await;
    assert_eq!(first, Ok(2));
    let rejected = Promise::any([Promise::reject::<i32, _>("a"), Promise::reject("b")]).await;
    assert_eq!(rejected, Err(Error::Promise("All promises were rejected".into())));
    let none = Promise::any(Vec::<std::future::Ready<Result<i32, ()>>>::new()).await;
    assert_eq!(none, Err(Error::Promise("No promises provided to any()".into())));
}

#[utopia_async::test]
async fn all_settled_keeps_every_outcome() {
    let outcomes = Promise::all_settled([Promise::resolve(1), Promise::reject("no")]).await;
    assert_eq!(outcomes, [Ok(1), Err("no")]);
}

#[utopia_async::test]
async fn timeouts_reject_with_the_php_message() {
    let late = Promise::timeout(Duration::from_millis(5), Promise::delay(Duration::from_secs(5))).await;
    let error = late.unwrap_err();
    assert_eq!(error.to_string(), "Promise timed out after 5ms");
    assert_eq!(error.php_class(), "Utopia\\Async\\Exception\\Timeout");
    assert_eq!(Promise::timeout(Duration::from_secs(1), async { 1 }).await, Ok(1));
}

#[utopia_async::test]
async fn spawned_promises_resolve() {
    let task = Promise::spawn(async { "done" });
    assert_eq!(task.await.unwrap(), "done");
}

#[utopia_async::test]
async fn timers_run_once_or_tick_until_cleared() {
    let fired = Arc::new(AtomicUsize::new(0));
    let counter = fired.clone();
    let once = Timer::after(Duration::from_millis(2), move || {
        counter.fetch_add(1, Ordering::SeqCst);
    });
    assert!(Timer::timers().contains(&(once, Kind::After)));
    utopia_async::sleep(Duration::from_millis(20)).await;
    assert_eq!(fired.load(Ordering::SeqCst), 1);
    assert!(!Timer::exists(once));
    assert!(!Timer::clear(once));

    let ticks = Arc::new(AtomicUsize::new(0));
    let counter = ticks.clone();
    let tick = Timer::tick(Duration::from_millis(2), move |_| {
        counter.fetch_add(1, Ordering::SeqCst);
    });
    assert!(Timer::timers().contains(&(tick, Kind::Tick)));
    utopia_async::sleep(Duration::from_millis(30)).await;
    assert!(Timer::clear(tick));
    let seen = ticks.load(Ordering::SeqCst);
    assert!(seen >= 2, "{seen} ticks");
    utopia_async::sleep(Duration::from_millis(10)).await;
    assert_eq!(ticks.load(Ordering::SeqCst), seen);
}

#[utopia_async::test]
async fn cleared_timers_never_fire() {
    let fired = Arc::new(AtomicUsize::new(0));
    let ids: Vec<_> = (0..3)
        .map(|_| {
            let counter = fired.clone();
            Timer::after(Duration::from_millis(5), move || {
                counter.fetch_add(1, Ordering::SeqCst);
            })
        })
        .collect();
    assert!(Timer::clear(ids[0]));
    utopia_async::sleep(Duration::from_millis(30)).await;
    assert_eq!(fired.load(Ordering::SeqCst), 2);
}

#[utopia_async::test(threads = 2)]
async fn parallel_runs_on_threads() {
    let caller = std::thread::current().id();
    assert!(Parallel::run(move || std::thread::current().id() != caller).await);
    assert_eq!(Parallel::all([|| 1, || 2, || 3]).await, [1, 2, 3]);

    let indexed = Parallel::map(vec!["a", "b", "c", "d", "e"], |item, index| format!("{index}{item}"), Some(2)).await;
    assert_eq!(indexed, ["0a", "1b", "2c", "3d", "4e"]);
    assert!(Parallel::map(Vec::<u8>::new(), |n, _| n, None).await.is_empty());

    let seen = Arc::new(AtomicUsize::new(0));
    let counter = seen.clone();
    Parallel::for_each(
        1..=10usize,
        move |n, _| {
            counter.fetch_add(n, Ordering::SeqCst);
        },
        None,
    )
    .await;
    assert_eq!(seen.load(Ordering::SeqCst), 55);
}

#[utopia_async::test(threads = 2)]
async fn pools_cap_concurrency() {
    let running = Arc::new(AtomicUsize::new(0));
    let peak = Arc::new(AtomicUsize::new(0));
    let tasks = (0..8).map(|n| {
        let (running, peak) = (running.clone(), peak.clone());
        move || {
            let now = running.fetch_add(1, Ordering::SeqCst) + 1;
            peak.fetch_max(now, Ordering::SeqCst);
            std::thread::sleep(Duration::from_millis(5));
            running.fetch_sub(1, Ordering::SeqCst);
            n
        }
    });
    assert_eq!(Parallel::pool(tasks, 3).await, [0, 1, 2, 3, 4, 5, 6, 7]);
    assert!(peak.load(Ordering::SeqCst) <= 3);
}

#[utopia_async::test]
#[should_panic(expected = "worker failed")]
async fn parallel_panics_reach_the_caller() {
    Parallel::run(|| panic!("worker failed")).await;
}
