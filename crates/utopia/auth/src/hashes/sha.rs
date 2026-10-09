use super::hash_plumbing;
use crate::hash::hash_equals;
use crate::{Error, Hash, Options};

/// The versions `Sha::setVersion()` accepts.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ShaVersion {
    Sha1,
    Sha224,
    Sha256,
    Sha384,
    Sha512,
    Sha3_224,
    Sha3_256,
    Sha3_384,
    Sha3_512,
}

impl ShaVersion {
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

    /// The `hash()` algorithm name (`Sha::SHA256` is `"sha256"`).
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

    /// The version named `name`, exactly as `setVersion()` accepts it.
    pub fn from_name(name: &[u8]) -> Option<Self> {
        Self::ALL.into_iter().find(|v| v.as_str().as_bytes() == name)
    }
}

/// Unsalted SHA digests, lowercase hex (`Utopia\Auth\Hashes\Sha`).
///
/// Its options hold only `version` (no `type`), the `hash()` algorithm.
#[derive(Debug, Clone, PartialEq)]
pub struct Sha {
    options: Options,
}

impl Default for Sha {
    fn default() -> Self {
        Self::new()
    }
}

impl Sha {
    /// `new Sha()`: SHA-256.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("version", "sha256");
        Self { options }
    }

    /// `setVersion()` with one of [`ShaVersion`].
    pub fn set_version(&mut self, version: ShaVersion) -> &mut Self {
        self.options.set("version", version.as_str());
        self
    }

    /// `setVersion()` with a name, rejected unless it is a [`ShaVersion`].
    pub fn set_version_name(&mut self, version: &[u8]) -> Result<&mut Self, Error> {
        let version = ShaVersion::from_name(version).ok_or_else(|| {
            let names: Vec<&str> = ShaVersion::ALL.iter().map(|v| v.as_str()).collect();
            Error::InvalidArgument(format!("Invalid SHA version. Valid versions are: {}", names.join(", ")))
        })?;
        Ok(self.set_version(version))
    }
}

/// `hash($algo, $data)` for the algorithm a `version` option names (php-std
/// ports ext/hash's algorithms that need no large constant tables).
fn digest(algo: &[u8], data: &[u8]) -> Result<String, Error> {
    php_std::string::hash(algo, data)
        .ok_or_else(|| Error::Value("hash(): Argument #1 ($algo) must be a valid hashing algorithm".into()))
}

impl Hash for Sha {
    hash_plumbing!("sha");

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        let Some(version) = self.options.get("version").and_then(|_| self.options.string("version")) else {
            return Err(Error::Runtime("SHA version must be a string".into()));
        };
        Ok(digest(version, value)?.into_bytes())
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}
