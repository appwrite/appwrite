//! The parameter registry.

use std::sync::{LazyLock, RwLock};

use php_std::ArrayKey;
use php_std::types::KeyRef;

use crate::{Array, Value};

/// Named parameters, read with plain or dotted keys.
///
/// PHP: the static `Config::$params` array.
///
/// ```
/// use utopia_config::{Array, Params, Value};
///
/// let mut params = Params::new();
/// let mut db = Array::default();
/// db.set("host".into(), Value::from("db.internal"));
/// params.set("db", Value::Array(db));
///
/// assert_eq!(params.get("db.host"), Some(&Value::from("db.internal")));
/// assert_eq!(params.get("db.port"), None);
/// ```
///
/// ```php
/// use Utopia\Config\Config;
///
/// Config::setParam('db', ['host' => 'db.internal']);
/// echo Config::getParam('db.host'), "\n";             // db.internal
/// echo Config::getParam('db.port', 'default'), "\n";  // default
/// ```
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Params {
    params: Array,
}

impl Params {
    /// No parameters.
    pub fn new() -> Self {
        Self::default()
    }

    /// Sets a parameter, replacing its value.
    ///
    /// PHP: `Config::setParam($key, $value)`.
    pub fn set(&mut self, key: &str, value: Value) {
        self.params.set(ArrayKey::from(key), value);
    }

    /// A parameter's value. A key with dots reads nested arrays
    /// (`platform.hostname` is `$params['platform']['hostname']`). A key that
    /// is absent or whose value is null is `None`.
    ///
    /// PHP: `Config::getParam($key)`, which returns `$default` where this
    /// returns `None`.
    pub fn get(&self, key: &str) -> Option<&Value> {
        if !key.contains('.') {
            return self.params.get_ref(KeyRef::normalize(key.as_bytes())).filter(|v| !matches!(v, Value::Null));
        }
        let mut node: Option<&Value> = None;
        let mut array = &self.params;
        for segment in key.split('.') {
            if let Some(n) = node {
                let Value::Array(a) = n else { return None };
                array = a;
            }
            node = Some(array.get_ref(KeyRef::normalize(segment.as_bytes())).filter(|v| !matches!(v, Value::Null))?);
        }
        node
    }

    /// Every parameter.
    pub fn all(&self) -> &Array {
        &self.params
    }

    /// Removes every parameter.
    pub fn clear(&mut self) {
        self.params = Array::default();
    }
}

static PARAMS: LazyLock<RwLock<Params>> = LazyLock::new(RwLock::default);

/// Sets a process-wide parameter.
///
/// PHP: `Config::setParam($key, $value)`.
pub fn set_param(key: &str, value: Value) {
    PARAMS.write().unwrap_or_else(|e| e.into_inner()).set(key, value);
}

/// A process-wide parameter (see [`Params::get`]); `None` where PHP returns
/// the default.
///
/// PHP: `Config::getParam($key, $default)`.
///
/// ```
/// use utopia_config::{Value, get_param, set_param};
///
/// set_param("region", Value::from("fra"));
/// assert_eq!(get_param("region"), Some(Value::from("fra")));
/// assert_eq!(get_param("missing").unwrap_or(Value::from("default")), Value::from("default"));
/// ```
///
/// ```php
/// use Utopia\Config\Config;
///
/// Config::setParam('region', 'fra');
/// echo Config::getParam('region'), "\n";             // fra
/// echo Config::getParam('missing', 'default'), "\n"; // default
/// ```
pub fn get_param(key: &str) -> Option<Value> {
    PARAMS.read().unwrap_or_else(|e| e.into_inner()).get(key).cloned()
}

/// Runs `f` with the process-wide parameters, to read or replace several at
/// once (`Config::$params` in PHP).
pub fn with_params<R>(f: impl FnOnce(&mut Params) -> R) -> R {
    f(&mut PARAMS.write().unwrap_or_else(|e| e.into_inner()))
}
