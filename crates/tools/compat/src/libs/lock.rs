//! Compat adapter for `lock`: maps `tests/compat/lock/spec.json` operations onto `utopia-lock`.

use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex as StdMutex};
use std::time::Duration;

use redis::aio::ConnectionManager;
use serde_json::{Value, json};
use utopia_lock::{Distributed, Error, File, Lock, Mode, Mutex, Semaphore, Wait};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

pub const OPS: &[&str] = &[
    "distributed.new",
    "mutex.new",
    "semaphore.new",
    "file.new",
    "lock.acquire",
    "lock.try_acquire",
    "lock.release",
    "lock.with_lock",
    "lock.concurrency",
    "distributed.refresh",
    "distributed.is_held",
    "distributed.adopt",
    "distributed.token",
    "distributed.has_token",
    "distributed.set_logger",
    "distributed.logs",
    "distributed.retry_spread",
    "lock.acquire_elapsed",
    "redis.set",
    "redis.get",
    "redis.del",
    "redis.ttl",
];

async fn connection(session: &Session) -> Result<ConnectionManager, Fault> {
    let url = session.service("redis")?.to_owned();
    redis::Client::open(url.as_str())
        .map_err(|e| Fault::new(e.to_string()))?
        .get_connection_manager()
        .await
        .map_err(|e| Fault::new(e.to_string()))
}

/// A Distributed lock with its captured log messages.
struct Leased {
    lock: Distributed<ConnectionManager>,
    logs: Arc<StdMutex<Vec<String>>>,
    capture: Arc<AtomicBool>,
}

#[derive(Clone)]
enum AnyLock {
    Distributed(Arc<Leased>),
    Mutex(Arc<Mutex>),
    Semaphore(Arc<Semaphore>),
    File(Arc<File>),
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn wait(a: &Args) -> Result<Wait, Fault> {
    Ok(Wait::from_secs_f64(a.opt_f64("timeout")?.unwrap_or(0.0)))
}

macro_rules! each {
    ($lock:expr, $l:ident => $body:expr) => {
        match $lock {
            AnyLock::Distributed(x) => {
                let $l = &x.lock;
                $body
            }
            AnyLock::Mutex(x) => {
                let $l = x.as_ref();
                $body
            }
            AnyLock::Semaphore(x) => {
                let $l = x.as_ref();
                $body
            }
            AnyLock::File(x) => {
                let $l = x.as_ref();
                $body
            }
        }
    };
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let lock = |s: &Session| -> Result<AnyLock, Fault> { s.get::<AnyLock>(a.value("lock")?).cloned() };
    let leased = |s: &Session| -> Result<Arc<Leased>, Fault> {
        match lock(s)? {
            AnyLock::Distributed(d) => Ok(d),
            _ => Err(Fault::new("not a distributed lock")),
        }
    };
    let result = match op {
        "distributed.new" => {
            let conn = connection(session).await?;
            let logs = Arc::new(StdMutex::new(Vec::new()));
            let capture = Arc::new(AtomicBool::new(false));
            let (l, c) = (logs.clone(), capture.clone());
            let lock = Distributed::new(conn, a.str("key")?, a.opt_i64("ttl")?.unwrap_or(600)).with_logger(move |m| {
                if c.load(Ordering::SeqCst) {
                    l.lock().unwrap_or_else(|e| e.into_inner()).push(m.to_owned());
                }
            });
            Outcome::Ok(session.handle(AnyLock::Distributed(Arc::new(Leased { lock, logs, capture }))))
        }
        "mutex.new" => Outcome::Ok(session.handle(AnyLock::Mutex(Arc::new(Mutex::new())))),
        "semaphore.new" => match Semaphore::new(a.i64("permits")?) {
            Ok(s) => Outcome::Ok(session.handle(AnyLock::Semaphore(Arc::new(s)))),
            Err(e) => err(e),
        },
        "file.new" => {
            let mode = match a.opt_i64("mode")?.unwrap_or(2) {
                2 => Mode::Exclusive,
                1 => Mode::Shared,
                other => return Err(Fault::new(format!("mode {other} is not LOCK_EX or LOCK_SH"))),
            };
            Outcome::Ok(session.handle(AnyLock::File(Arc::new(File::new(a.str("path")?, mode)))))
        }
        "lock.acquire" => {
            let w = wait(&a)?;
            match each!(&lock(session)?, l => l.acquire(w).await) {
                Ok(b) => Outcome::ok(b),
                Err(e) => err(e),
            }
        }
        "lock.try_acquire" => match each!(&lock(session)?, l => l.try_acquire().await) {
            Ok(b) => Outcome::ok(b),
            Err(e) => err(e),
        },
        "lock.release" => match each!(&lock(session)?, l => l.release().await) {
            Ok(()) => Outcome::Ok(Value::Null),
            Err(e) => err(e),
        },
        "lock.with_lock" => {
            let w = wait(&a)?;
            let throw = a.opt_str("throw")?.map(str::to_owned);
            let value = a.opt("result").cloned().unwrap_or(Value::Null);
            let section = async move {
                match throw {
                    Some(m) => Err(m),
                    None => Ok(value),
                }
            };
            match each!(&lock(session)?, l => l.with_lock(w, section).await) {
                Ok(Ok(v)) => Outcome::Ok(v),
                Ok(Err(message)) => Outcome::err("RuntimeException", message),
                Err(e) => err(e),
            }
        }
        "lock.concurrency" => {
            let w = wait(&a)?;
            let tasks = a.i64("tasks")?.max(0) as usize;
            let hold = Duration::from_secs_f64(a.f64("hold_ms")? / 1000.0);
            let lock = lock(session)?;
            let (live, max, count, contention) =
                (AtomicUsize::new(0), AtomicUsize::new(0), AtomicUsize::new(0), AtomicUsize::new(0));
            let task = || async {
                let section = async {
                    let now = live.fetch_add(1, Ordering::SeqCst) + 1;
                    max.fetch_max(now, Ordering::SeqCst);
                    tokio::time::sleep(hold).await;
                    live.fetch_sub(1, Ordering::SeqCst);
                };
                match each!(&lock, l => l.with_lock(w, section).await) {
                    Ok(()) => count.fetch_add(1, Ordering::SeqCst),
                    Err(Error::Contention(_)) => contention.fetch_add(1, Ordering::SeqCst),
                    Err(_) => 0,
                };
            };
            futures_util::future::join_all((0..tasks).map(|_| task())).await;
            Outcome::Ok(json!({
                "count": count.load(Ordering::SeqCst),
                "max": max.load(Ordering::SeqCst),
                "contention": contention.load(Ordering::SeqCst),
            }))
        }
        "distributed.refresh" => match leased(session)?.lock.refresh().await {
            Ok(b) => Outcome::ok(b),
            Err(e) => err(e),
        },
        "distributed.is_held" => match leased(session)?.lock.is_held().await {
            Ok(b) => Outcome::ok(b),
            Err(e) => err(e),
        },
        "distributed.adopt" => match leased(session)?.lock.adopt(a.str("token")?) {
            Ok(()) => Outcome::Ok(Value::Null),
            Err(e) => err(e),
        },
        "distributed.token" => Outcome::Ok(leased(session)?.lock.token().map(Value::String).unwrap_or(Value::Null)),
        "distributed.has_token" => Outcome::ok(leased(session)?.lock.token().is_some()),
        "distributed.set_logger" => {
            leased(session)?.capture.store(true, Ordering::SeqCst);
            Outcome::Ok(Value::Null)
        }
        "distributed.logs" => {
            let d = leased(session)?;
            let mut logs = d.logs.lock().unwrap_or_else(|e| e.into_inner()).clone();
            logs.sort();
            logs.dedup();
            Outcome::ok(logs)
        }
        "distributed.retry_spread" => {
            // Port of DistributedTest::testWaitersDoNotRetryInLockstep.
            let conn = connection(session).await?;
            let key = a.str("key")?;
            let holder = Distributed::new(conn.clone(), key, 30);
            let _ = holder.try_acquire().await;
            let (mut observed, mut lowest, mut highest) = (0, f64::MAX, 0.0_f64);
            for _ in 0..a.i64("runs")? {
                let start = std::time::Instant::now();
                let first: Arc<StdMutex<Option<f64>>> = Arc::new(StdMutex::new(None));
                let f = first.clone();
                let waiter = Distributed::new(conn.clone(), key, 30).with_logger(move |_| {
                    let mut f = f.lock().unwrap_or_else(|e| e.into_inner());
                    if f.is_none() {
                        *f = Some(start.elapsed().as_secs_f64());
                    }
                });
                let _ = waiter.acquire(Wait::from_secs_f64(0.05)).await;
                if let Some(t) = *first.lock().unwrap_or_else(|e| e.into_inner()) {
                    observed += 1;
                    lowest = lowest.min(t);
                    highest = highest.max(t);
                }
            }
            let _ = holder.release().await;
            Outcome::Ok(json!({ "observed": observed > 0, "spread": highest - lowest > 0.010 }))
        }
        "lock.acquire_elapsed" => {
            let w = wait(&a)?;
            let start = std::time::Instant::now();
            match each!(&lock(session)?, l => l.acquire(w).await) {
                Ok(acquired) => {
                    let elapsed = start.elapsed().as_secs_f64();
                    Outcome::Ok(
                        json!({ "acquired": acquired, "within": elapsed >= a.f64("at_least")? && elapsed < a.f64("below")? }),
                    )
                }
                Err(e) => err(e),
            }
        }
        "redis.set" => {
            let mut c = connection(session).await?;
            let r: redis::RedisResult<String> = redis::cmd("SET")
                .arg(a.str("key")?)
                .arg(a.str("value")?)
                .arg("EX")
                .arg(a.i64("ex")?)
                .query_async(&mut c)
                .await;
            Outcome::ok(r.is_ok())
        }
        "redis.get" => {
            let mut c = connection(session).await?;
            let v: Option<String> = redis::cmd("GET")
                .arg(a.str("key")?)
                .query_async(&mut c)
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            Outcome::Ok(v.map(Value::String).unwrap_or(Value::Bool(false)))
        }
        "redis.del" => {
            let mut c = connection(session).await?;
            let n: i64 = redis::cmd("DEL")
                .arg(a.str("key")?)
                .query_async(&mut c)
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            Outcome::ok(n)
        }
        "redis.ttl" => {
            let mut c = connection(session).await?;
            let n: i64 = redis::cmd("TTL")
                .arg(a.str("key")?)
                .query_async(&mut c)
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            Outcome::ok(n > 0)
        }
        _ => return Err(Fault::new(format!("lock: unknown operation `{op}`"))),
    };
    Ok(result)
}
