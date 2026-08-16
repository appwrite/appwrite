//! Circuit breaker for Utopia.
//!
//! Rust port of [`utopia-php/circuit-breaker`](https://github.com/utopia-php/circuit-breaker).

pub mod adapter;
mod breaker;
mod error;
mod state;

pub use adapter::{Adapter, CacheValue, Memory, Table};
pub use breaker::CircuitBreaker;
pub use error::CircuitBreakerError;
pub use state::CircuitState;

pub mod prelude {
    pub use crate::adapter::Memory;
    pub use crate::{Adapter, CircuitBreaker, CircuitBreakerError, CircuitState};
}
