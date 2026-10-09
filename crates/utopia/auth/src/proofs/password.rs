use indexmap::IndexMap;
use php_std::zval::{Array, Key};

use super::Proof;
use crate::hash::random_int;
use crate::hashes::{Argon2, Bcrypt, Md5, PHPass, Scrypt, ScryptModified, Sha};
use crate::{Error, Hash};

/// Names of the built-in algorithms (`Password::ARGON2`, ...).
pub const ARGON2: &str = "argon2";
pub const BCRYPT: &str = "bcrypt";
pub const SCRYPT: &str = "scrypt";
pub const SCRYPT_MODIFIED: &str = "scryptMod";
pub const SHA: &str = "sha";
pub const MD5: &str = "md5";
pub const PHPASS: &str = "phpass";

const CHARSET: &[u8] = b"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?";

/// The hash a [`Password`] hashes with: one of its registry, or its own.
#[derive(Debug, Clone)]
enum Current {
    Registered(String),
    Own(Box<dyn Hash>),
}

/// A user password (`Utopia\Auth\Proofs\Password`): a registry of named
/// hashes (every built-in by default), the one in use, and how generated
/// passwords look (16 characters from a symbol-rich charset).
#[derive(Debug, Clone)]
pub struct Password {
    hashes: IndexMap<String, Box<dyn Hash>>,
    current: Current,
    length: i64,
    charset: Vec<u8>,
}

impl Default for Password {
    fn default() -> Self {
        Self::new()
    }
}

impl Password {
    /// `new Password()`: every built-in hash, Argon2 in use.
    pub fn new() -> Self {
        Self::with_hashes(IndexMap::new())
    }

    /// `new Password($hashes)`: an empty registry means the built-ins. The hash
    /// in use is the registry's `argon2`, else its first.
    pub fn with_hashes(hashes: IndexMap<String, Box<dyn Hash>>) -> Self {
        let hashes = if hashes.is_empty() {
            let mut defaults: IndexMap<String, Box<dyn Hash>> = IndexMap::new();
            defaults.insert(ARGON2.into(), Box::new(Argon2::new()));
            defaults.insert(BCRYPT.into(), Box::new(Bcrypt::new()));
            defaults.insert(SCRYPT.into(), Box::new(Scrypt::new()));
            defaults.insert(SCRYPT_MODIFIED.into(), Box::new(ScryptModified::new()));
            defaults.insert(SHA.into(), Box::new(Sha::new()));
            defaults.insert(MD5.into(), Box::new(Md5::new()));
            defaults.insert(PHPASS.into(), Box::new(PHPass::new()));
            defaults
        } else {
            hashes
        };
        let current = if hashes.contains_key(ARGON2) {
            ARGON2.to_owned()
        } else {
            hashes.keys().next().cloned().unwrap_or_default()
        };
        Self { hashes, current: Current::Registered(current), length: 16, charset: CHARSET.to_vec() }
    }

    /// `addHash($name, $hash)`: registers (or replaces) a named hash.
    pub fn add_hash(&mut self, name: &str, hash: Box<dyn Hash>) -> &mut Self {
        self.hashes.insert(name.to_owned(), hash);
        self
    }

    /// `removeHash($name)`: fails when it is unknown or in use.
    pub fn remove_hash(&mut self, name: &str) -> Result<&mut Self, Error> {
        if !self.hashes.contains_key(name) {
            return Err(Error::Exception(format!("Hash '{name}' not found")));
        }
        if matches!(&self.current, Current::Registered(n) if n == name) {
            return Err(Error::Exception("Cannot remove current hash".into()));
        }
        self.hashes.shift_remove(name);
        Ok(self)
    }

    /// `getHashByName($name)`.
    pub fn hash_by_name(&self, name: &str) -> Result<&dyn Hash, Error> {
        self.hashes.get(name).map(|h| h.as_ref()).ok_or_else(|| Error::Exception(format!("Hash '{name}' not found")))
    }

    /// `getHashByName($name)`, to change its options.
    pub fn hash_by_name_mut(&mut self, name: &str) -> Result<&mut dyn Hash, Error> {
        match self.hashes.get_mut(name) {
            Some(h) => Ok(h.as_mut()),
            None => Err(Error::Exception(format!("Hash '{name}' not found"))),
        }
    }

    /// `setHash($this->getHashByName($name))`: hash with a registered algorithm.
    pub fn use_hash(&mut self, name: &str) -> Result<&mut Self, Error> {
        self.hash_by_name(name)?;
        self.current = Current::Registered(name.to_owned());
        Ok(self)
    }

    /// The name of the registered hash in use, if the hash in use is registered.
    pub fn current_name(&self) -> Option<&str> {
        match &self.current {
            Current::Registered(name) if self.hashes.contains_key(name) => Some(name),
            _ => None,
        }
    }

    /// `setLength()`: at least 8.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        if length < 8 {
            return Err(Error::Exception("Password length must be at least 8 characters".into()));
        }
        self.length = length;
        Ok(self)
    }

    /// `setCharset()`: at least 10 bytes.
    pub fn set_charset(&mut self, charset: &[u8]) -> Result<&mut Self, Error> {
        if charset.len() < 10 {
            return Err(Error::Exception("Password charset must contain at least 10 characters".into()));
        }
        self.charset = charset.to_vec();
        Ok(self)
    }

    /// `Password::createHash($type, $options)`: a built-in hash by name, with
    /// options applied over its defaults (unknown options are kept).
    pub fn create_hash(kind: &str, options: &Array) -> Result<Box<dyn Hash>, Error> {
        let mut hash: Box<dyn Hash> = match kind {
            ARGON2 => Box::new(Argon2::new()),
            BCRYPT => Box::new(Bcrypt::new()),
            SCRYPT => Box::new(Scrypt::new()),
            SCRYPT_MODIFIED => Box::new(ScryptModified::new()),
            SHA => Box::new(Sha::new()),
            MD5 => Box::new(Md5::new()),
            PHPASS => Box::new(PHPass::new()),
            _ => return Err(Error::Exception(format!("Unsupported hash type: {kind}"))),
        };
        set_options(hash.as_mut(), options)?;
        Ok(hash)
    }
}

/// `Hash::setOptions($options)`: every entry through `setOption(string $key)`,
/// which (with strict types) rejects integer keys.
pub fn set_options(hash: &mut dyn Hash, options: &Array) -> Result<(), Error> {
    for (key, value) in options.iter() {
        match key {
            Key::Str(k) => hash.options_mut().set(&String::from_utf8_lossy(k), value.clone()),
            Key::Int(_) => {
                return Err(Error::Type(
                    "Utopia\\Auth\\Hash::setOption(): Argument #1 ($key) must be of type string, int given, called in /usr/src/code/packages/auth/src/Hash.php on line 35".into(),
                ));
            }
        }
    }
    Ok(())
}

impl Proof for Password {
    fn generate(&self) -> Result<Vec<u8>, Error> {
        if self.charset.is_empty() {
            return Err(Error::Exception("Password charset is empty".into()));
        }
        let max = self.charset.len() as u64 - 1;
        Ok((0..self.length).map(|_| self.charset[random_int(max) as usize]).collect())
    }

    fn hasher(&self) -> &dyn Hash {
        match &self.current {
            Current::Own(h) => h.as_ref(),
            Current::Registered(name) => self.hashes.get(name).map(|h| h.as_ref()).unwrap_or(&*FALLBACK),
        }
    }

    fn hasher_mut(&mut self) -> &mut dyn Hash {
        match &mut self.current {
            Current::Own(h) => h.as_mut(),
            Current::Registered(name) => {
                if !self.hashes.contains_key(name.as_str()) {
                    self.hashes.insert(name.clone(), Box::new(Argon2::new()));
                }
                self.hashes.get_mut(name.as_str()).map(|h| h.as_mut()).expect("inserted above")
            }
        }
    }

    fn set_hash(&mut self, hash: Box<dyn Hash>) {
        self.current = Current::Own(hash);
    }
}

/// Never used: the hash in use is always registered or owned.
static FALLBACK: std::sync::LazyLock<Argon2> = std::sync::LazyLock::new(Argon2::new);
