//! PHP `Utopia\Lock` tests.

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::{Duration, Instant};

use utopia_lock::{Contention, FileLock, Lock, LockError, Mutex, Semaphore};

#[test]
fn all_implementations_satisfy_interface() {
    fn assert_lock<T: Lock>(_: &T) {}
    assert_lock(&Mutex::new());
    assert_lock(&Semaphore::new(2).expect("permits"));
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("iface.lock");
    assert_lock(&FileLock::with_exclusive(&path));
}

#[test]
fn contention_extends_base_exception() {
    let exception = Contention::new("boom");
    let wrapped = LockError::from(exception);
    assert!(wrapped.to_string().contains("boom"));
}

#[test]
fn with_lock_returns_callback_result() {
    let mutex = Mutex::new();
    let result = mutex.with_lock(|| "ok", 0.0).unwrap();
    assert_eq!(result, "ok");
}

#[test]
fn with_lock_releases_on_panic() {
    let mutex = Mutex::new();
    let panicked = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        mutex.with_lock(|| panic!("inner"), 0.0).unwrap();
    }));
    assert!(panicked.is_err());
    assert!(mutex.try_acquire(), "Mutex should be released after panic");
    mutex.release();
}

#[test]
fn mutex_serializes_threads() {
    let mutex = Arc::new(Mutex::new());
    let concurrent = Arc::new(AtomicUsize::new(0));
    let max = Arc::new(AtomicUsize::new(0));
    let count = Arc::new(AtomicUsize::new(0));
    let mut handles = Vec::new();
    for _ in 0..8 {
        let mutex = Arc::clone(&mutex);
        let concurrent = Arc::clone(&concurrent);
        let max = Arc::clone(&max);
        let count = Arc::clone(&count);
        handles.push(thread::spawn(move || {
            mutex
                .with_lock(
                    || {
                        let now = concurrent.fetch_add(1, Ordering::SeqCst) + 1;
                        max.fetch_max(now, Ordering::SeqCst);
                        thread::sleep(Duration::from_millis(10));
                        count.fetch_add(1, Ordering::SeqCst);
                        concurrent.fetch_sub(1, Ordering::SeqCst);
                    },
                    5.0,
                )
                .unwrap();
        }));
    }
    for handle in handles {
        handle.join().unwrap();
    }
    assert_eq!(count.load(Ordering::SeqCst), 8);
    assert_eq!(max.load(Ordering::SeqCst), 1);
}

#[test]
fn mutex_times_out_under_contention() {
    let mutex = Arc::new(Mutex::new());
    mutex.acquire(-1.0);
    let waiter = Arc::clone(&mutex);
    let handle = thread::spawn(move || waiter.with_lock(|| (), 0.05));
    thread::sleep(Duration::from_millis(10));
    let result = handle.join().unwrap();
    mutex.release();
    assert!(result.is_err());
}

#[test]
fn mutex_try_acquire_fails_when_held() {
    let mutex = Mutex::new();
    assert!(mutex.acquire(0.0));
    assert!(!mutex.try_acquire());
    mutex.release();
}

#[test]
fn mutex_release_is_idempotent() {
    let mutex = Mutex::new();
    mutex.acquire(0.0);
    mutex.release();
    mutex.release();
    assert!(mutex.try_acquire());
    mutex.release();
}

#[test]
fn semaphore_caps_at_permits() {
    let semaphore = Arc::new(Semaphore::new(3).unwrap());
    let concurrent = Arc::new(AtomicUsize::new(0));
    let max = Arc::new(AtomicUsize::new(0));
    let count = Arc::new(AtomicUsize::new(0));
    let mut handles = Vec::new();
    for _ in 0..10 {
        let semaphore = Arc::clone(&semaphore);
        let concurrent = Arc::clone(&concurrent);
        let max = Arc::clone(&max);
        let count = Arc::clone(&count);
        handles.push(thread::spawn(move || {
            semaphore
                .with_lock(
                    || {
                        let now = concurrent.fetch_add(1, Ordering::SeqCst) + 1;
                        max.fetch_max(now, Ordering::SeqCst);
                        thread::sleep(Duration::from_millis(20));
                        count.fetch_add(1, Ordering::SeqCst);
                        concurrent.fetch_sub(1, Ordering::SeqCst);
                    },
                    5.0,
                )
                .unwrap();
        }));
    }
    for handle in handles {
        handle.join().unwrap();
    }
    assert_eq!(count.load(Ordering::SeqCst), 10);
    assert!(max.load(Ordering::SeqCst) <= 3);
    assert!(max.load(Ordering::SeqCst) > 1);
}

#[test]
fn semaphore_rejects_invalid_permits() {
    assert!(Semaphore::new(0).is_err());
}

#[test]
fn semaphore_single_permit_behaves_like_mutex() {
    let semaphore = Arc::new(Semaphore::new(1).unwrap());
    let concurrent = Arc::new(AtomicUsize::new(0));
    let max = Arc::new(AtomicUsize::new(0));
    let mut handles = Vec::new();
    for _ in 0..4 {
        let semaphore = Arc::clone(&semaphore);
        let concurrent = Arc::clone(&concurrent);
        let max = Arc::clone(&max);
        handles.push(thread::spawn(move || {
            semaphore
                .with_lock(
                    || {
                        let now = concurrent.fetch_add(1, Ordering::SeqCst) + 1;
                        max.fetch_max(now, Ordering::SeqCst);
                        thread::sleep(Duration::from_millis(10));
                        concurrent.fetch_sub(1, Ordering::SeqCst);
                    },
                    5.0,
                )
                .unwrap();
        }));
    }
    for handle in handles {
        handle.join().unwrap();
    }
    assert_eq!(max.load(Ordering::SeqCst), 1);
}

#[test]
fn file_acquire_and_release() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("lock");
    let lock = FileLock::with_exclusive(&path);
    assert!(lock.try_acquire());
    lock.release();
    let other = FileLock::with_exclusive(&path);
    assert!(other.try_acquire());
    other.release();
}

#[test]
fn file_with_lock_releases_on_panic() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("lock");
    let lock = FileLock::with_exclusive(&path);
    let panicked = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        lock.with_lock(|| panic!("inner"), 0.0).unwrap();
    }));
    assert!(panicked.is_err());
    let other = FileLock::with_exclusive(&path);
    assert!(other.try_acquire());
    other.release();
}

#[test]
fn file_acquire_timeout_returns_quickly_when_free() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("lock");
    let lock = FileLock::with_exclusive(&path);
    let start = Instant::now();
    assert!(lock.acquire(0.1));
    assert!(start.elapsed() < Duration::from_millis(80));
    lock.release();
}

#[cfg(feature = "redis")]
#[test]
fn distributed_redis_e2e() {
    let url = std::env::var("REDIS_URL")
        .ok()
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| "redis://127.0.0.1:6379/".to_owned());
    let client = redis::Client::open(url)
        .expect("Redis container (docker compose -f docker-compose.test.yml up -d redis)");
    let lock = utopia_lock::Distributed::new(
        client,
        format!("utopia-lock-e2e-{}", std::process::id()),
        30,
    );
    assert!(lock.try_acquire(), "distributed lock acquire");
    assert!(lock.is_held());
    lock.release();
    assert!(!lock.is_held());
}
