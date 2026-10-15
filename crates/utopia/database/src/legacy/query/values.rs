//! The values of a [`super::Query`] or an [`crate::Operator`]: a PHP array
//! held as a list, with its keys only when they are not `0, 1, 2, ...`.

use crate::value::{Array, ArrayKey, KeyRef, Value};

/// A PHP `array $values` kept as a `Vec` so callers get a slice.
///
/// PHP: the `$values` property of `Query` and `Operator`. Constructors and
/// setters store whatever array they are given, so a value list decoded
/// from a JSON object keeps its keys (`toArray()` and `getValue()` see
/// them); `keys` holds them in that case and is `None` for a list.
#[derive(Clone, PartialEq, Debug, Default)]
pub(crate) struct Values {
    items: Vec<Value>,
    keys: Option<Vec<ArrayKey>>,
}

impl Values {
    /// A list.
    pub(crate) fn list(items: Vec<Value>) -> Self {
        Self { items, keys: None }
    }

    /// Any PHP array, keys kept.
    pub(crate) fn array(array: Array) -> Self {
        if array.is_list() {
            return Self::list(array.into_iter().map(|(_, v)| v).collect());
        }
        let mut keys = Vec::with_capacity(array.len());
        let mut items = Vec::with_capacity(array.len());
        for (k, v) in array {
            keys.push(k);
            items.push(v);
        }
        Self { items, keys: Some(keys) }
    }

    pub(crate) fn as_slice(&self) -> &[Value] {
        &self.items
    }

    pub(crate) fn as_vec_mut(&mut self) -> &mut Vec<Value> {
        &mut self.items
    }

    /// The values as the PHP array they are. Values pushed through
    /// [`Values::as_vec_mut`] past the stored keys get the next free index.
    pub(crate) fn to_array(&self) -> Array {
        let Some(keys) = &self.keys else {
            return Array::from_list(self.items.clone());
        };
        let mut out = Array::new();
        for (k, v) in keys.iter().zip(&self.items) {
            out.set(k.clone(), v.clone());
        }
        for v in self.items.iter().skip(keys.len()) {
            let _ = out.push(v.clone());
        }
        out
    }

    /// The value at key `0`, unless it is `null` (PHP `$values[0] ?? null`).
    pub(crate) fn first(&self) -> Option<&Value> {
        let found = match &self.keys {
            None => self.items.first(),
            Some(keys) => keys.iter().position(|k| k.as_ref() == KeyRef::Int(0)).and_then(|i| self.items.get(i)),
        };
        found.filter(|v| !matches!(v, Value::Null))
    }
}
