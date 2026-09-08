use std::collections::HashMap;

use serde_json::Value;
use utopia_di::Resource;

/// One bound action argument: a CLI param or an injected DI resource.
#[derive(Clone, Debug)]
pub enum BoundArg {
    /// Coerced CLI flag / option (`--key=value`).
    Param(Value),
    /// Value from [`utopia_di::Container`].
    Inject(Resource),
}

/// Named arguments passed to task / hook actions.
///
/// Keys are camelCased like PHP `CLI::getParams()` (`foo-bar` → `fooBar`).
#[derive(Clone, Debug, Default)]
pub struct Params {
    inner: HashMap<String, BoundArg>,
}

impl Params {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn insert(&mut self, key: impl Into<String>, value: BoundArg) {
        self.inner.insert(key.into(), value);
    }

    pub fn contains_key(&self, key: &str) -> bool {
        self.inner.contains_key(key)
    }

    pub fn get(&self, key: &str) -> Option<&BoundArg> {
        self.inner.get(key)
    }

    pub fn get_value(&self, key: &str) -> Option<&Value> {
        match self.inner.get(key)? {
            BoundArg::Param(value) => Some(value),
            BoundArg::Inject(_) => None,
        }
    }

    pub fn get_resource(&self, key: &str) -> Option<&Resource> {
        match self.inner.get(key)? {
            BoundArg::Inject(resource) => Some(resource),
            BoundArg::Param(_) => None,
        }
    }

    /// String CLI param or injected `String` resource.
    pub fn get_str(&self, key: &str) -> Option<&str> {
        match self.inner.get(key)? {
            BoundArg::Param(Value::String(s)) => Some(s),
            BoundArg::Inject(resource) => resource.downcast_ref::<String>().map(String::as_str),
            BoundArg::Param(_) => None,
        }
    }

    /// Boolean CLI param (after PHP `Boolean` coercion) or injected `bool`.
    pub fn get_bool(&self, key: &str) -> Option<bool> {
        match self.inner.get(key)? {
            BoundArg::Param(Value::Bool(b)) => Some(*b),
            BoundArg::Inject(resource) => resource.downcast_ref::<bool>().copied(),
            BoundArg::Param(_) => None,
        }
    }

    /// Repeated CLI flag (`--list=a --list=b`) or a single string wrapped as a one-element list.
    pub fn get_list(&self, key: &str) -> Option<Vec<String>> {
        match self.inner.get(key)? {
            BoundArg::Param(Value::Array(items)) => Some(
                items
                    .iter()
                    .filter_map(|item| item.as_str().map(ToOwned::to_owned))
                    .collect(),
            ),
            BoundArg::Param(Value::String(s)) => Some(vec![s.clone()]),
            BoundArg::Inject(resource) => resource.downcast_ref::<Vec<String>>().cloned(),
            BoundArg::Param(_) => None,
        }
    }

    pub fn len(&self) -> usize {
        self.inner.len()
    }

    pub fn is_empty(&self) -> bool {
        self.inner.is_empty()
    }
}

/// Parsed CLI argument after `--` stripping.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum ArgValue {
    String(String),
    List(Vec<String>),
}

impl ArgValue {
    pub fn as_value(&self) -> Value {
        match self {
            Self::String(s) => Value::String(s.clone()),
            Self::List(items) => Value::Array(items.iter().cloned().map(Value::String).collect()),
        }
    }
}
