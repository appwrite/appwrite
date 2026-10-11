//! Unsalted SHA-1, SHA-2 and SHA-3 digests, for imported legacy passwords.

use super::hash_plumbing;
use crate::hash::hash_equals;
use crate::{Error, Hash, Options};

/// A SHA algorithm a [`Sha`] hash can use.
///
/// PHP: the versions `Sha::setVersion()` accepts.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ShaVersion {
    /// SHA-1, named `sha1`.
    Sha1,
    /// SHA-224, named `sha224`.
    Sha224,
    /// SHA-256, named `sha256`.
    Sha256,
    /// SHA-384, named `sha384`.
    Sha384,
    /// SHA-512, named `sha512`.
    Sha512,
    /// SHA3-224, named `sha3-224`.
    Sha3_224,
    /// SHA3-256, named `sha3-256`.
    Sha3_256,
    /// SHA3-384, named `sha3-384`.
    Sha3_384,
    /// SHA3-512, named `sha3-512`.
    Sha3_512,
}

impl ShaVersion {
    /// Every version, SHA-1 first, then SHA-2, then SHA-3.
    ///
    /// PHP: the order PHP lists them in.
    pub const ALL: [ShaVersion; 9] = [
        ShaVersion::Sha1,
        ShaVersion::Sha224,
        ShaVersion::Sha256,
        ShaVersion::Sha384,
        ShaVersion::Sha512,
        ShaVersion::Sha3_224,
        ShaVersion::Sha3_256,
        ShaVersion::Sha3_384,
        ShaVersion::Sha3_512,
    ];

    /// The algorithm's name, as stored in the `version` option (`"sha256"`).
    ///
    /// PHP: the `hash()` algorithm name; `Sha::SHA256` is `"sha256"`.
    pub fn as_str(self) -> &'static str {
        match self {
            ShaVersion::Sha1 => "sha1",
            ShaVersion::Sha224 => "sha224",
            ShaVersion::Sha256 => "sha256",
            ShaVersion::Sha384 => "sha384",
            ShaVersion::Sha512 => "sha512",
            ShaVersion::Sha3_224 => "sha3-224",
            ShaVersion::Sha3_256 => "sha3-256",
            ShaVersion::Sha3_384 => "sha3-384",
            ShaVersion::Sha3_512 => "sha3-512",
        }
    }

    /// The version named `name` (`sha256`, `sha3-512`, ...), or `None`.
    ///
    /// PHP: the names `setVersion()` accepts.
    pub fn from_name(name: &[u8]) -> Option<Self> {
        Self::ALL.into_iter().find(|v| v.as_str().as_bytes() == name)
    }
}

/// The unsalted SHA digest of a password, as lowercase hex.
///
/// Its options hold only `version` (no `type`): the name of the algorithm.
/// Weak: use it only to verify passwords imported from another system.
///
/// PHP: `Utopia\Auth\Hashes\Sha`; `version` is a `hash()` algorithm name.
///
/// ```
/// use utopia_auth::Hash;
/// use utopia_auth::hashes::{Sha, ShaVersion};
///
/// let mut sha = Sha::new();
/// sha.set_version(ShaVersion::Sha256);
/// let hash = sha.hash(b"password")?;
/// assert_eq!(hash, b"5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8");
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Hashes\Sha;
///
/// $sha = (new Sha())->setVersion(Sha::SHA256);
/// echo $sha->hash('password'), "\n"; // 5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8
/// ```
#[derive(Debug, Clone, PartialEq)]
pub struct Sha {
    options: Options,
}

impl Default for Sha {
    /// The same as [`Sha::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Sha {
    /// The SHA-256 hash.
    ///
    /// PHP: `new Sha()`.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("version", "sha256");
        Self { options }
    }

    /// Sets the algorithm.
    ///
    /// PHP: `setVersion()` with one of the `SHA*` constants.
    pub fn set_version(&mut self, version: ShaVersion) -> &mut Self {
        self.options.set("version", version.as_str());
        self
    }

    /// Sets the algorithm by name (`sha256`, `sha3-512`, ...).
    ///
    /// Fails with [`Error::InvalidArgument`] unless it names a [`ShaVersion`].
    ///
    /// PHP: `setVersion()` with a string.
    pub fn set_version_name(&mut self, version: &[u8]) -> Result<&mut Self, Error> {
        let version = ShaVersion::from_name(version).ok_or_else(|| {
            let names: Vec<&str> = ShaVersion::ALL.iter().map(|v| v.as_str()).collect();
            Error::InvalidArgument(format!("Invalid SHA version. Valid versions are: {}", names.join(", ")))
        })?;
        Ok(self.set_version(version))
    }
}

/// `hash($algo, $data)` for the algorithm a `version` option names (any of
/// `hash_algos()`, through php-std).
fn digest(algo: &[u8], data: &[u8]) -> Result<String, Error> {
    php_std::string::hash(algo, data)
        .ok_or_else(|| Error::Value("hash(): Argument #1 ($algo) must be a valid hashing algorithm".into()))
}

impl Hash for Sha {
    hash_plumbing!("sha");

    /// The digest of `value` as lowercase hex.
    ///
    /// Fails when the `version` option is not a string or names no known
    /// algorithm.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        let Some(version) = self.options.get("version").and_then(|_| self.options.string("version")) else {
            return Err(Error::Runtime("SHA version must be a string".into()));
        };
        Ok(digest(version, value)?.into_bytes())
    }

    /// Whether the digest of `value` equals `hash`, compared in constant time.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}
