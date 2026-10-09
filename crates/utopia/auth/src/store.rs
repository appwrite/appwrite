use php_std::encoding::{base64_decode, base64_encode};
use php_std::json::{self, Flags};
use php_std::zval::{Array, Key, Zval};

use crate::Error;

/// Session data carried in a cookie or header (`Utopia\Auth\Store`):
/// properties encoded as base64 JSON, plus an optional key naming it.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Store {
    data: Array,
    key: Option<Vec<u8>>,
}

impl Store {
    pub fn new() -> Self {
        Self::default()
    }

    /// `getProperty($key, $default)`: `$data[$key] ?? $default` (a stored `null` is absent).
    pub fn property(&self, key: &[u8]) -> Option<&Zval> {
        self.data.get(&Key::from_bytes(key)).filter(|v| !matches!(v, Zval::Null))
    }

    /// `setProperty($key, $value)`.
    pub fn set_property(&mut self, key: &[u8], value: Zval) -> &mut Self {
        self.data.insert(Key::from_bytes(key), value);
        self
    }

    /// The properties, in insertion order.
    pub fn properties(&self) -> &Array {
        &self.data
    }

    /// `getKey()`.
    pub fn key(&self) -> Option<&[u8]> {
        self.key.as_deref()
    }

    /// `setKey()`.
    pub fn set_key(&mut self, key: Option<&[u8]>) -> &mut Self {
        self.key = key.map(<[u8]>::to_vec);
        self
    }

    /// `encode()`: `base64_encode(json_encode($data))`. Properties that are a
    /// list (`0`, `1`, ... in order) encode as a JSON array, like PHP.
    pub fn encode(&self) -> Result<String, Error> {
        let json = json::encode(&Zval::Array(self.data.clone()).to_value(), Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
            .map_err(Error::json)?;
        Ok(base64_encode(json.as_bytes()))
    }

    /// `decode($data)`: merges the properties of a store encoded by
    /// [`Store::encode`]; anything that is not base64 JSON of an array or
    /// object is ignored.
    pub fn decode(&mut self, data: &[u8]) -> &mut Self {
        let Some(decoded) = base64_decode(data, true) else {
            return self;
        };
        if let Ok(Zval::Array(values)) =
            json::decode(&decoded, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR).map(|v| Zval::from_value(&v))
        {
            for (key, value) in values.iter() {
                self.data.insert(key.clone(), value.clone());
            }
        }
        self
    }
}
