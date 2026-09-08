use std::thread;
use std::time::{Duration, Instant};

use rand::{thread_rng, Rng};

use crate::error::Contention;
use crate::lock::Lock;

const RELEASE_SCRIPT: &str = r#"
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
"#;

const REFRESH_SCRIPT: &str = r#"
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("expire", KEYS[1], ARGV[2])
else
    return 0
end
"#;

/// Minimal Redis surface used by [`Distributed`].
pub trait RedisCommands: Send + Sync {
    fn set_nx_ex(&self, key: &str, value: &str, ttl: i64) -> Result<bool, String>;
    fn get(&self, key: &str) -> Result<Option<String>, String>;
    fn eval(&self, script: &str, keys: &[&str], args: &[&str]) -> Result<i64, String>;
}

type LogFn = Box<dyn Fn(&str) + Send + Sync>;

/// PHP `Utopia\Lock\Distributed`.
pub struct Distributed<R: RedisCommands> {
    redis: R,
    key: String,
    ttl: i64,
    token: parking_lot::Mutex<Option<String>>,
    logger: parking_lot::Mutex<Option<LogFn>>,
}

impl<R: RedisCommands> std::fmt::Debug for Distributed<R> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Distributed")
            .field("key", &self.key)
            .field("ttl", &self.ttl)
            .finish_non_exhaustive()
    }
}

impl<R: RedisCommands> Distributed<R> {
    pub fn new(redis: R, key: impl Into<String>, ttl: i64) -> Self {
        Self {
            redis,
            key: key.into(),
            ttl,
            token: parking_lot::Mutex::new(None),
            logger: parking_lot::Mutex::new(None),
        }
    }

    pub fn set_logger<F>(&self, logger: F) -> &Self
    where
        F: Fn(&str) + Send + Sync + 'static,
    {
        *self.logger.lock() = Some(Box::new(logger));
        self
    }

    pub fn refresh(&self) -> bool {
        let token = self.token.lock();
        let Some(token) = token.as_deref() else {
            return false;
        };
        let ttl = self.ttl.to_string();
        matches!(
            self.redis
                .eval(REFRESH_SCRIPT, &[&self.key], &[token, ttl.as_str()]),
            Ok(1)
        )
    }

    pub fn is_held(&self) -> bool {
        let token = self.token.lock();
        let Some(token) = token.as_deref() else {
            return false;
        };
        self.redis.get(&self.key).ok().flatten().as_deref() == Some(token)
    }

    pub fn adopt(&self, token: impl Into<String>) -> Result<&Self, String> {
        let token = token.into();
        if token.is_empty() {
            return Err("Token must not be empty".into());
        }
        let mut slot = self.token.lock();
        if slot.is_some() {
            return Err("Cannot replace the token of a distributed lock".into());
        }
        *slot = Some(token);
        Ok(self)
    }

    pub fn token(&self) -> Option<String> {
        self.token.lock().clone()
    }

    fn generate_token() -> String {
        let host = std::fs::read_to_string("/etc/hostname")
            .ok()
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty())
            .or_else(|| std::env::var("HOSTNAME").ok())
            .unwrap_or_else(|| "unknown".into());
        let pid = std::process::id();
        let unique: u128 = thread_rng().gen();
        format!("{host}:{pid}:{unique:x}")
    }

    fn log(&self, message: &str) {
        if let Some(logger) = self.logger.lock().as_ref() {
            logger(message);
        }
    }
}

impl<R: RedisCommands> Lock for Distributed<R> {
    fn acquire(&self, timeout: f64) -> bool {
        if self.try_acquire() {
            return true;
        }
        if timeout <= 0.0 {
            return false;
        }
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        let mut delay = Duration::from_millis(50);
        while Instant::now() < deadline {
            let remaining = deadline.saturating_duration_since(Instant::now());
            let sleep = delay.min(remaining);
            if !sleep.is_zero() {
                thread::sleep(sleep);
            }
            if self.try_acquire() {
                return true;
            }
            self.log(&format!("Lock contention for {}, retrying", self.key));
            delay = (delay * 2).min(Duration::from_secs(1));
        }
        self.log(&format!(
            "Failed to acquire lock for {} within {timeout}s",
            self.key
        ));
        false
    }

    fn try_acquire(&self) -> bool {
        let token = Self::generate_token();
        match self.redis.set_nx_ex(&self.key, &token, self.ttl) {
            Ok(true) => {
                *self.token.lock() = Some(token);
                true
            }
            _ => false,
        }
    }

    fn release(&self) {
        let mut token = self.token.lock();
        let Some(held) = token.take() else {
            return;
        };
        let _ = self.redis.eval(RELEASE_SCRIPT, &[&self.key], &[&held]);
    }

    fn contention(&self) -> Contention {
        Contention::new(format!("Failed to acquire distributed lock: {}", self.key))
    }
}

#[cfg(feature = "redis")]
mod redis_impl {
    use super::RedisCommands;

    impl RedisCommands for redis::Client {
        fn set_nx_ex(&self, key: &str, value: &str, ttl: i64) -> Result<bool, String> {
            let mut conn = self.get_connection().map_err(|err| err.to_string())?;
            redis::cmd("SET")
                .arg(key)
                .arg(value)
                .arg("NX")
                .arg("EX")
                .arg(ttl)
                .query::<Option<String>>(&mut conn)
                .map(|v| v.is_some())
                .map_err(|err| err.to_string())
        }

        fn get(&self, key: &str) -> Result<Option<String>, String> {
            let mut conn = self.get_connection().map_err(|err| err.to_string())?;
            redis::cmd("GET")
                .arg(key)
                .query(&mut conn)
                .map_err(|err| err.to_string())
        }

        fn eval(&self, script: &str, keys: &[&str], args: &[&str]) -> Result<i64, String> {
            let mut conn = self.get_connection().map_err(|err| err.to_string())?;
            let mut cmd = redis::cmd("EVAL");
            cmd.arg(script).arg(keys.len());
            for key in keys {
                cmd.arg(*key);
            }
            for arg in args {
                cmd.arg(*arg);
            }
            cmd.query(&mut conn).map_err(|err| err.to_string())
        }
    }
}
