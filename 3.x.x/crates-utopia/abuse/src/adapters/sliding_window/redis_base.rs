use crate::error::AbuseError;
use crate::time_util::{align_timestamp, unix_now};

/// Atomic sliding-window check-and-increment (PHP `LIMIT_CHECK_SCRIPT`).
pub const LIMIT_CHECK_SCRIPT: &str = r"
        local current_key = KEYS[1]
        local previous_key = KEYS[2]
        local max_requests = tonumber(ARGV[1])
        local elapsed = tonumber(ARGV[2])
        local ttl = tonumber(ARGV[3])

        local prev_count = tonumber(redis.call('GET', previous_key) or '0') or 0
        local current_count = tonumber(redis.call('GET', current_key) or '0') or 0

        local weighted_prev = prev_count * (1 - elapsed)
        local estimated = weighted_prev + current_count

        if estimated >= max_requests then
          return { 0, 0, math.floor(estimated) }
        end

        local new_count = redis.call('INCR', current_key)
        redis.call('EXPIRE', current_key, ttl)

        local new_estimate = weighted_prev + new_count
        local remaining = math.max(0, math.floor(max_requests - new_estimate))
        return { 1, remaining, math.floor(new_estimate) }
        ";

/// Window configuration shared by Redis-family adapters.
#[derive(Debug, Clone)]
pub struct WindowConfig {
    pub window_size: i64,
    pub ttl: i64,
    pub limit: i64,
}

impl WindowConfig {
    pub fn init(limit: i64, window_size: i64, ttl: i64) -> Result<Self, AbuseError> {
        if window_size <= 0 {
            return Err(AbuseError::InvalidWindowSize);
        }
        if ttl < window_size * 2 {
            return Err(AbuseError::InvalidTtl);
        }
        Ok(Self {
            window_size,
            ttl,
            limit,
        })
    }

    /// `[window start, elapsed fraction in [0, 1)]`.
    #[must_use]
    pub fn window(&self) -> (i64, f64) {
        let now = unix_now();
        let timestamp = align_timestamp(now, self.window_size);
        let elapsed = (now - timestamp) as f64 / self.window_size as f64;
        (timestamp, elapsed)
    }
}

/// Pure sliding-window estimate (same as Lua).
#[must_use]
pub fn estimate(current: i64, previous: i64, elapsed: f64) -> f64 {
    current as f64 + previous as f64 * (1.0 - elapsed)
}

/// Floor of the weighted estimate (PHP `count()`).
#[must_use]
pub fn count_estimate(current: i64, previous: i64, elapsed: f64) -> i64 {
    estimate(current, previous, elapsed).floor() as i64
}
