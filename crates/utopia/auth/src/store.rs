//! Session data carried between requests in a cookie or header.

use php_std::encoding::{base64_decode, base64_encode};
use php_std::json::{self, Flags};
use php_std::{Array, ArrayKey, Value};

use crate::Error;

/// Session data carried in a cookie or header: properties encoded as base64
/// JSON, plus an optional key naming it.
///
/// PHP: `Utopia\Auth\Store`.
///
/// ```
/// use php_std::Value;
/// use utopia_auth::Store;
///
/// let mut store = Store::new();
/// store.set_property(b"id", Value::from("u1"));
/// assert_eq!(store.encode()?, "eyJpZCI6InUxIn0=");
///
/// let mut decoded = Store::new();
/// decoded.decode(b"eyJpZCI6InUxIn0=");
/// assert_eq!(decoded.property(b"id"), Some(&Value::from("u1")));
/// assert_eq!(decoded.property(b"missing"), None);
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Store;
///
/// $store = (new Store())->setProperty('id', 'u1');
/// echo $store->encode(), "\n"; // eyJpZCI6InUxIn0=
///
/// $decoded = (new Store())->decode('eyJpZCI6InUxIn0=');
/// echo $decoded->getProperty('id'), "\n";           // u1
/// var_dump($decoded->getProperty('missing'));       // NULL
/// ```
#[derive(Debug, Clone, PartialEq)]
pub struct Store {
    /// The properties: always an array, held as a value so encoding borrows it.
    data: Value,
    key: Option<Vec<u8>>,
}

impl Default for Store {
    /// An empty store: no properties, no key.
    fn default() -> Self {
        Self { data: Value::Array(Array::new()), key: None }
    }
}

impl Store {
    /// An empty store: no properties, no key.
    ///
    /// PHP: `new Store()`.
    pub fn new() -> Self {
        Self::default()
    }

    /// The property named `key`, or `None` when it is missing or null.
    ///
    /// PHP: `getProperty($key, $default)`, which is `$data[$key] ?? $default`.
    pub fn property(&self, key: &[u8]) -> Option<&Value> {
        self.properties().get(&ArrayKey::from_bytes(key)).filter(|v| !matches!(v, Value::Null))
    }

    /// Sets the property named `key`, keeping its position when it already exists.
    ///
    /// PHP: `setProperty($key, $value)`.
    pub fn set_property(&mut self, key: &[u8], value: Value) -> &mut Self {
        self.properties_mut().set(ArrayKey::from_bytes(key), value);
        self
    }

    /// The properties, in insertion order.
    pub fn properties(&self) -> &Array {
        match &self.data {
            Value::Array(data) => data,
            _ => unreachable!("a store holds an array"),
        }
    }

    fn properties_mut(&mut self) -> &mut Array {
        match &mut self.data {
            Value::Array(data) => data,
            _ => unreachable!("a store holds an array"),
        }
    }

    /// The key naming this store, if one is set.
    ///
    /// PHP: `getKey()`.
    pub fn key(&self) -> Option<&[u8]> {
        self.key.as_deref()
    }

    /// Sets or clears the key naming this store.
    ///
    /// PHP: `setKey()`.
    pub fn set_key(&mut self, key: Option<&[u8]>) -> &mut Self {
        self.key = key.map(<[u8]>::to_vec);
        self
    }

    /// The properties as base64-encoded JSON, ready for a cookie or header.
    ///
    /// Properties keyed `0`, `1`, ... in order encode as a JSON array, any
    /// others as an object. Fails when a value cannot be encoded as JSON.
    ///
    /// PHP: `encode()`, which is `base64_encode(json_encode($data))`.
    pub fn encode(&self) -> Result<String, Error> {
        let json = json::encode(&self.data, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH).map_err(Error::json)?;
        Ok(base64_encode(json.as_bytes()))
    }

    /// Merges in the properties of a store encoded by [`Store::encode`].
    ///
    /// Anything that is not base64 JSON of an array or object is ignored.
    ///
    /// PHP: `decode($data)`.
    pub fn decode(&mut self, data: &[u8]) -> &mut Self {
        let Some(decoded) = base64_decode(data, true) else {
            return self;
        };
        if let Ok(Value::Array(values)) = json::decode(&decoded, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR)
        {
            // The decoded keys and values move in, uncopied.
            let properties = self.properties_mut();
            for (key, value) in values {
                properties.set(key, value);
            }
        }
        self
    }
}
