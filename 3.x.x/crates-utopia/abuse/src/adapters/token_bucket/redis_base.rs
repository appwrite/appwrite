/// Atomic token-bucket refill-and-consume (PHP `LIMIT_CHECK_SCRIPT`).
pub const LIMIT_CHECK_SCRIPT: &str = r"
        local key = KEYS[1]
        local max_tokens = tonumber(ARGV[1])
        local refill_rate = tonumber(ARGV[2])
        local now = tonumber(ARGV[3])

        local data = redis.call('HMGET', key, 'tokens', 'last_refill')
        local tokens = tonumber(data[1]) or max_tokens
        local last_refill = tonumber(data[2]) or now

        -- Refill based on elapsed time, capped at the capacity.
        local elapsed = now - last_refill
        if elapsed < 0 then elapsed = 0 end
        tokens = math.min(max_tokens, tokens + elapsed * refill_rate)

        local allowed = 0
        if tokens >= 1 then
          tokens = tokens - 1
          allowed = 1
        end

        redis.call('HSET', key, 'tokens', tostring(tokens), 'last_refill', tostring(now))
        redis.call('EXPIRE', key, math.ceil(max_tokens / refill_rate) + 1)

        return { allowed, tostring(tokens) }
        ";

/// Read-only token estimate (PHP `TOKENS_SCRIPT`).
pub const TOKENS_SCRIPT: &str = r"
        local key = KEYS[1]
        local max_tokens = tonumber(ARGV[1])
        local refill_rate = tonumber(ARGV[2])
        local now = tonumber(ARGV[3])

        local data = redis.call('HMGET', key, 'tokens', 'last_refill')
        local tokens = tonumber(data[1]) or max_tokens
        local last_refill = tonumber(data[2]) or now

        local elapsed = now - last_refill
        if elapsed < 0 then elapsed = 0 end
        tokens = math.min(max_tokens, tokens + elapsed * refill_rate)

        return tostring(tokens)
        ";

use crate::error::AbuseError;
use crate::time_util::unix_now;

/// Shared bucket config for Redis-family adapters.
#[derive(Debug, Clone)]
pub struct BucketConfig {
    pub refill_rate: f64,
    pub tokens: i64,
}

impl BucketConfig {
    pub fn init(tokens: i64, refill_rate: f64) -> Result<Self, AbuseError> {
        if refill_rate <= 0.0 {
            return Err(AbuseError::InvalidRefillRate);
        }
        let _ = unix_now();
        Ok(Self {
            refill_rate,
            tokens,
        })
    }
}

/// Pure token-bucket math (same as the Lua scripts).
#[derive(Debug, Clone)]
pub struct BucketState {
    pub tokens: f64,
    pub last_refill: f64,
}

impl BucketState {
    #[must_use]
    pub fn refill(self, max_tokens: f64, refill_rate: f64, now: f64) -> Self {
        let elapsed = (now - self.last_refill).max(0.0);
        let tokens = (self.tokens + elapsed * refill_rate).min(max_tokens);
        Self {
            tokens,
            last_refill: now,
        }
    }

    #[must_use]
    pub fn consume(mut self, max_tokens: f64, refill_rate: f64, now: f64) -> (bool, Self) {
        self = self.refill(max_tokens, refill_rate, now);
        let allowed = self.tokens >= 1.0;
        if allowed {
            self.tokens -= 1.0;
        }
        self.last_refill = now;
        (allowed, self)
    }
}
