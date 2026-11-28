use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;

use crate::error::Result;
use crate::hook::{Callback, IntoResult, Scope};

/// A boxed, sendable future, as hook and route callbacks return.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// A hook or route callback: takes the [`Scope`] of a call and returns a
/// future.
pub type Action = Arc<dyn for<'a> Fn(Scope<'a>) -> BoxFuture<'a, Result<()>> + Send + Sync>;

/// Wraps an async closure into an [`Action`].
pub fn action<F, R>(f: F) -> Action
where
    F: for<'a> Callback<'a> + AsyncFn(Scope<'_>) -> R + Send + Sync + 'static,
    R: IntoResult,
{
    Arc::new(move |scope| {
        let future = f(scope);
        Box::pin(async move { future.await.into_result() })
    })
}
