use std::future::Future;

use crate::hook::{IntoResult, Scope};

/// An async closure over a call's [`Scope`] whose future is `Send`:
/// `async |scope| { .. }`, as [`action`](crate::action) takes it.
///
/// It reads what it needs from the scope's params and injections. A closure
/// that borrows state it captured is not a `Callback`: register that state as
/// a resource and inject it instead.
pub trait Callback<'a>: Fn(Scope<'a>) -> <Self as Callback<'a>>::Future {
    /// The future the closure returns.
    type Future: Future<Output: IntoResult> + Send + 'a;
}

impl<'a, F, Fut> Callback<'a> for F
where
    F: Fn(Scope<'a>) -> Fut,
    Fut: Future<Output: IntoResult> + Send + 'a,
{
    type Future = Fut;
}
