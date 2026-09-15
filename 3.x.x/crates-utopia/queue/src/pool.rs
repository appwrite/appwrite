use std::fmt;

use utopia_pools::{Pool as UtopiaPool, Recover, RecoverCall, Stack};

/// Local wrapper so [`Recover`] can be implemented (orphan rules).
struct Recyclable<T: Send>(T);

impl<T: Send> Recover for Recyclable<T> {
    fn reset(&mut self) -> RecoverCall {
        RecoverCall::Succeeded
    }
}

/// [`utopia_pools::Pool`] of a resource type, used by [`crate::broker::Pool`].
///
/// PHP `Utopia\Pools\Pool`.
pub struct ResourcePool<T: Send + 'static> {
    inner: UtopiaPool<Recyclable<T>>,
}

impl<T: Send + 'static> fmt::Debug for ResourcePool<T> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ResourcePool")
            .field("name", &self.inner.name())
            .field("size", &self.inner.size())
            .finish_non_exhaustive()
    }
}

impl<T: Send + 'static> ResourcePool<T> {
    pub fn new(
        name: impl Into<String>,
        size: usize,
        factory: impl Fn() -> T + Send + Sync + 'static,
    ) -> Self {
        let size = size.max(1);
        Self {
            inner: UtopiaPool::new(
                Stack::new(),
                name,
                size,
                move || Recyclable(factory()),
                30.0,
            )
            .expect("queue pool"),
        }
    }

    pub fn name(&self) -> &str {
        self.inner.name()
    }

    pub fn size(&self) -> usize {
        self.inner.size()
    }

    pub fn use_item<R>(&self, f: impl FnOnce(&T) -> R) -> R {
        self.inner
            .use_sync(|item| f(&item.0))
            .expect("queue pool checkout")
    }
}
