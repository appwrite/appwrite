/// PHP `Utopia\Cache\Feature\Retryable::MIN_RETRIES`.
pub const MIN_RETRIES: i32 = 0;
/// PHP `Utopia\Cache\Feature\Retryable::MAX_RETRIES`.
pub const MAX_RETRIES: i32 = 10;

/// PHP `Utopia\Cache\Feature\Retryable`.
pub trait Retryable: Send + Sync {
    fn set_max_retries(&mut self, max_retries: i32) -> &mut Self;
    fn set_retry_delay(&mut self, retry_delay: i32) -> &mut Self;
    fn get_max_retries(&self) -> i32;
    fn get_retry_delay(&self) -> i32;
}

pub(crate) fn clamp_retries(max_retries: i32) -> i32 {
    max_retries.clamp(MIN_RETRIES, MAX_RETRIES)
}
