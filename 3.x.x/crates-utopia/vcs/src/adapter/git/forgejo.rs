//! Forgejo adapter (PHP `Utopia\VCS\Adapter\Git\Forgejo`).

use std::ops::{Deref, DerefMut};

use crate::adapter::git::gitea::{Gitea, Identity};
use crate::cache::CacheStore;

/// Forgejo is Gitea-compatible with different default host and webhook headers.
#[derive(Debug)]
pub struct Forgejo {
    inner: Gitea,
}

impl Forgejo {
    pub fn new(cache: impl CacheStore + 'static) -> Self {
        Self {
            inner: Gitea::new_with(cache, Identity::FORGEJO),
        }
    }
}

impl Deref for Forgejo {
    type Target = Gitea;
    fn deref(&self) -> &Gitea {
        &self.inner
    }
}

impl DerefMut for Forgejo {
    fn deref_mut(&mut self) -> &mut Gitea {
        &mut self.inner
    }
}
