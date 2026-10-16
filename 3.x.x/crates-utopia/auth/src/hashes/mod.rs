//! Password hashing algorithm implementations.

#[cfg(feature = "argon2")]
mod argon2;
#[cfg(feature = "bcrypt")]
mod bcrypt;
#[cfg(feature = "legacy")]
mod md5;
#[cfg(feature = "legacy")]
mod phpass;
#[cfg(feature = "legacy")]
mod plaintext;
#[cfg(feature = "legacy")]
mod scrypt;
#[cfg(feature = "legacy")]
mod scrypt_modified;
#[cfg(feature = "legacy")]
mod sha;

#[cfg(feature = "argon2")]
pub use argon2::Argon2;
#[cfg(feature = "bcrypt")]
pub use bcrypt::Bcrypt;
#[cfg(feature = "legacy")]
pub use md5::Md5;
#[cfg(feature = "legacy")]
pub use phpass::PHPass;
#[cfg(feature = "legacy")]
pub use plaintext::Plaintext;
#[cfg(feature = "legacy")]
pub use scrypt::Scrypt;
#[cfg(feature = "legacy")]
pub use scrypt_modified::ScryptModified;
#[cfg(feature = "legacy")]
pub use sha::Sha;
