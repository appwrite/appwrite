/// Result of PHP `method_exists` + `reset()` / `reconnect()` on a pooled resource.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RecoverCall {
    /// The method does not exist (PHP `method_exists` is false).
    Missing,
    /// The method ran and did not return `false`.
    Succeeded,
    /// The method returned `false`.
    Failed,
}

/// PHP `Pool::recover()` hooks on a resource.
///
/// Default is an object with no `reset`/`reconnect` - recovery fails and `use()`
/// destroys the connection. PHP scalars (strings, ints) reclaim; implement
/// [`Recover::recover`] to return `true` for those.
pub trait Recover: Send {
    /// PHP `reset()`. [`RecoverCall::Missing`] when the method does not exist.
    fn reset(&mut self) -> RecoverCall {
        RecoverCall::Missing
    }

    /// PHP `reconnect()`. [`RecoverCall::Missing`] when the method does not exist.
    fn reconnect(&mut self) -> RecoverCall {
        RecoverCall::Missing
    }

    /// Full PHP `recover()` result: `true` means reclaim, `false` means destroy.
    ///
    /// Override for PHP non-objects (`!is_object($resource) && !is_resource($resource)`).
    fn recover(&mut self) -> bool {
        std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
            let mut recovered = false;
            match self.reset() {
                RecoverCall::Missing => {}
                RecoverCall::Succeeded => recovered = true,
                RecoverCall::Failed => return false,
            }
            match self.reconnect() {
                RecoverCall::Missing => {}
                RecoverCall::Succeeded => recovered = true,
                RecoverCall::Failed => return false,
            }
            recovered
        }))
        .unwrap_or(false)
    }
}

impl Recover for String {
    fn recover(&mut self) -> bool {
        // PHP scalar / non-resource: `return !is_resource($resource)`.
        true
    }
}

impl Recover for &str {
    fn recover(&mut self) -> bool {
        true
    }
}

impl Recover for i32 {
    fn recover(&mut self) -> bool {
        true
    }
}

impl Recover for u32 {
    fn recover(&mut self) -> bool {
        true
    }
}

impl Recover for i64 {
    fn recover(&mut self) -> bool {
        true
    }
}

impl Recover for usize {
    fn recover(&mut self) -> bool {
        true
    }
}
