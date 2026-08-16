//! Appwrite platform composition layer (stub).

use appwrite_exception::Exception;
use utopia_di::Container;
use utopia_platform::{Module, Platform};

/// Appwrite platform facade.
#[derive(Debug)]
pub struct AppwritePlatform {
    inner: Platform,
    di: Container,
}

impl AppwritePlatform {
    /// Create an empty platform with a root DI container.
    #[must_use]
    pub fn new() -> Self {
        Self {
            inner: Platform::new(Module::new()),
            di: Container::new(),
        }
    }

    /// Access the utopia platform.
    #[must_use]
    pub fn platform(&self) -> &Platform {
        &self.inner
    }

    /// Access the DI container.
    #[must_use]
    pub fn di(&self) -> &Container {
        &self.di
    }

    /// Stub readiness check that touches sibling appwrite crates.
    pub fn ensure_ready(&self) -> Result<(), Exception> {
        let _ = appwrite_hooks::stub();
        let _ = appwrite_locale::stub();
        let _ = appwrite_auth::stub();
        let _ = appwrite_event::stub();
        let _ = appwrite_response::stub();
        let _ = appwrite_database::stub();
        let _ = utopia_http::Mode::Development;
        Ok(())
    }
}

impl Default for AppwritePlatform {
    fn default() -> Self {
        Self::new()
    }
}

/// Placeholder used by early stubs.
#[must_use]
pub fn stub() -> AppwritePlatform {
    AppwritePlatform::new()
}
