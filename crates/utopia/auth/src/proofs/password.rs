//! User passwords: a registry of named hashes, the one in use, and random
//! password generation.

use indexmap::IndexMap;
use php_std::Array;
use php_std::types::KeyRef;

use super::Proof;
use crate::hash::random_int;
use crate::hashes::{Argon2, Bcrypt, Md5, PHPass, Scrypt, ScryptModified, Sha};
use crate::{Error, Hash};

/// The registered name of [`Argon2`].
///
/// PHP: `Password::ARGON2`, like the other names below.
pub const ARGON2: &str = "argon2";
/// The registered name of [`Bcrypt`].
pub const BCRYPT: &str = "bcrypt";
/// The registered name of [`Scrypt`].
pub const SCRYPT: &str = "scrypt";
/// The registered name of [`ScryptModified`].
pub const SCRYPT_MODIFIED: &str = "scryptMod";
/// The registered name of [`Sha`].
pub const SHA: &str = "sha";
/// The registered name of [`Md5`].
pub const MD5: &str = "md5";
/// The registered name of the phpass hash.
pub const PHPASS: &str = "phpass";

const CHARSET: &[u8] = b"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?";

/// The hash a [`Password`] hashes with: one of its registry, or its own.
#[derive(Debug, Clone)]
enum Current {
    Registered(String),
    Own(Box<dyn Hash>),
}

/// A user password: a registry of named hashes (every built-in by default),
/// the one in use, and how generated passwords look (16 characters from a
/// symbol-rich charset).
///
/// PHP: `Utopia\Auth\Proofs\Password`.
///
/// ```
/// use utopia_auth::proofs::{Password, Proof};
///
/// let mut password = Password::new();
/// password.use_hash("bcrypt")?;
/// let hash = password.hash(b"secret")?;
/// assert!(hash.starts_with(b"$2y$"));
/// assert!(password.verify(b"secret", &hash)?);
///
/// // Generated passwords are 16 characters long.
/// assert_eq!(password.generate()?.len(), 16);
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Proofs\Password;
///
/// $password = new Password();
/// $password->setHash($password->getHashByName('bcrypt'));
/// $hash = $password->hash('secret');
/// echo substr($hash, 0, 4), "\n";               // $2y$
/// var_dump($password->verify('secret', $hash)); // bool(true)
///
/// echo strlen($password->generate()), "\n";     // 16
/// ```
#[derive(Debug, Clone)]
pub struct Password {
    hashes: IndexMap<String, Box<dyn Hash>>,
    current: Current,
    length: i64,
    charset: Vec<u8>,
}

impl Default for Password {
    /// The same as [`Password::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Password {
    /// A password with every built-in hash registered, hashing with Argon2.
    ///
    /// PHP: `new Password()`.
    pub fn new() -> Self {
        Self::with_hashes(IndexMap::new())
    }

    /// A password with its own registry of named hashes; an empty registry
    /// means the built-ins.
    ///
    /// The hash in use is the registry's `argon2`, else its first.
    ///
    /// PHP: `new Password($hashes)`.
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

    /// Registers a hash under `name`, replacing any hash of that name.
    ///
    /// PHP: `addHash($name, $hash)`.
    pub fn add_hash(&mut self, name: &str, hash: Box<dyn Hash>) -> &mut Self {
        self.hashes.insert(name.to_owned(), hash);
        self
    }

    /// Unregisters the hash named `name`.
    ///
    /// Fails with [`Error::Exception`] when no hash has that name or it is
    /// the one in use.
    ///
    /// PHP: `removeHash($name)`.
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

    /// The registered hash named `name`.
    ///
    /// Fails with [`Error::Exception`] when no hash has that name.
    ///
    /// PHP: `getHashByName($name)`.
    pub fn hash_by_name(&self, name: &str) -> Result<&dyn Hash, Error> {
        self.hashes.get(name).map(|h| h.as_ref()).ok_or_else(|| Error::Exception(format!("Hash '{name}' not found")))
    }

    /// The registered hash named `name`, to change its options.
    ///
    /// Fails with [`Error::Exception`] when no hash has that name.
    ///
    /// PHP: `getHashByName($name)`.
    pub fn hash_by_name_mut(&mut self, name: &str) -> Result<&mut dyn Hash, Error> {
        match self.hashes.get_mut(name) {
            Some(h) => Ok(h.as_mut()),
            None => Err(Error::Exception(format!("Hash '{name}' not found"))),
        }
    }

    /// Hashes with the registered hash named `name` from now on.
    ///
    /// Fails with [`Error::Exception`] when no hash has that name.
    ///
    /// PHP: `setHash($this->getHashByName($name))`.
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

    /// Sets the length of generated passwords.
    ///
    /// Fails with [`Error::Exception`] when it is below 8.
    ///
    /// PHP: `setLength()`.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        if length < 8 {
            return Err(Error::Exception("Password length must be at least 8 characters".into()));
        }
        self.length = length;
        Ok(self)
    }

    /// Sets the characters generated passwords are drawn from.
    ///
    /// Fails with [`Error::Exception`] when it has fewer than 10 bytes.
    ///
    /// PHP: `setCharset()`.
    pub fn set_charset(&mut self, charset: &[u8]) -> Result<&mut Self, Error> {
        if charset.len() < 10 {
            return Err(Error::Exception("Password charset must contain at least 10 characters".into()));
        }
        self.charset = charset.to_vec();
        Ok(self)
    }

    /// A built-in hash by name, with `options` applied over its defaults;
    /// unknown options are kept.
    ///
    /// Use it to rebuild the hash a password was stored with from its saved
    /// name and options. Fails with [`Error::Exception`] for an unknown name.
    ///
    /// PHP: `Password::createHash($type, $options)`.
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

/// Sets every option in `options` on `hash`, in order.
///
/// Fails with [`Error::Type`] at the first integer key: option names are strings.
///
/// PHP: `Hash::setOptions($options)`, which passes every entry through
/// `setOption(string $key)`; with strict types it rejects integer keys.
pub fn set_options(hash: &mut dyn Hash, options: &Array) -> Result<(), Error> {
    for (key, value) in options.iter() {
        match key {
            KeyRef::Str(k) => hash.options_mut().set(&String::from_utf8_lossy(k), value.clone()),
            KeyRef::Int(_) => {
                return Err(Error::Type(
                    "Utopia\\Auth\\Hash::setOption(): Argument #1 ($key) must be of type string, int given, called in /usr/src/code/packages/auth/src/Hash.php on line 35".into(),
                ));
            }
        }
    }
    Ok(())
}

impl Proof for Password {
    /// A new random password of the set length, drawn from the charset.
    ///
    /// Fails with [`Error::Exception`] when the charset is empty.
    fn generate(&self) -> Result<Vec<u8>, Error> {
        if self.charset.is_empty() {
            return Err(Error::Exception("Password charset is empty".into()));
        }
        let max = self.charset.len() as u64 - 1;
        Ok((0..self.length).map(|_| self.charset[random_int(max) as usize]).collect())
    }

    /// The hash in use: a registered one, or one set with [`Proof::set_hash`].
    fn hasher(&self) -> &dyn Hash {
        match &self.current {
            Current::Own(h) => h.as_ref(),
            Current::Registered(name) => self.hashes.get(name).map(|h| h.as_ref()).unwrap_or(&*FALLBACK),
        }
    }

    /// The hash in use, to change its options.
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

    /// Hashes with `hash` from now on, without registering it.
    fn set_hash(&mut self, hash: Box<dyn Hash>) {
        self.current = Current::Own(hash);
    }
}

/// Never used: the hash in use is always registered or owned.
static FALLBACK: std::sync::LazyLock<Argon2> = std::sync::LazyLock::new(Argon2::new);
