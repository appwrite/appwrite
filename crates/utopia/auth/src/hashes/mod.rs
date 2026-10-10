//! The built-in algorithms (`Utopia\Auth\Hashes\*`).

mod argon2;
mod bcrypt;
mod md5;
mod password;
mod phpass;
mod plaintext;
mod scrypt;
mod scrypt_modified;
mod sha;

pub use argon2::Argon2;
pub use bcrypt::Bcrypt;
pub use md5::Md5;
pub use phpass::PHPass;
pub use plaintext::Plaintext;
pub use scrypt::Scrypt;
pub use scrypt_modified::ScryptModified;
pub use sha::{Sha, ShaVersion};

/// Implements the [`crate::Hash`] plumbing shared by every built-in algorithm.
macro_rules! hash_plumbing {
    ($name:literal) => {
        fn name(&self) -> &str {
            $name
        }

        fn options(&self) -> &$crate::Options {
            &self.options
        }

        fn options_mut(&mut self) -> &mut $crate::Options {
            &mut self.options
        }

        fn boxed_clone(&self) -> Box<dyn $crate::Hash> {
            Box::new(self.clone())
        }

        fn as_any(&self) -> &dyn std::any::Any {
            self
        }

        fn as_any_mut(&mut self) -> &mut dyn std::any::Any {
            self
        }
    };
}
pub(crate) use hash_plumbing;
