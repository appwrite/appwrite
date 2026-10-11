//! Rich authorization requests: the `authorization_details` parameter (RFC 9396).

use php_std::{Array, ArrayKey, Value};

use super::AuthorizationDetail;

/// The `authorization_details` of a request (RFC 9396): the object entries
/// of a list. Anything malformed contributes nothing.
///
/// PHP: `Utopia\Auth\OAuth2\AuthorizationDetails`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct AuthorizationDetails(Vec<Array>);

fn key(name: &[u8]) -> ArrayKey {
    ArrayKey::from_bytes(name)
}

fn strings(values: &Array) -> impl Iterator<Item = &[u8]> {
    values.iter().filter_map(|(_, v)| match v {
        Value::Str(s) => Some(s.as_bytes()),
        _ => None,
    })
}

fn lists(values: &Array, value: &[u8]) -> bool {
    values.iter().any(|(_, v)| matches!(v, Value::Str(s) if s == value))
}

impl AuthorizationDetails {
    /// The entries of a decoded `authorization_details` value: the arrays in
    /// a list. Any other value gives no entries.
    ///
    /// PHP: `new AuthorizationDetails($value)`.
    pub fn new(value: &Value) -> Self {
        match value {
            Value::Array(list) if list.is_list() => Self(
                list.iter()
                    .filter_map(|(_, e)| match e {
                        Value::Array(entry) => Some(entry.clone()),
                        _ => None,
                    })
                    .collect(),
            ),
            _ => Self::default(),
        }
    }

    /// Whether an entry of type `kind` lists `value` (or `wildcard`) in its
    /// array field `field`.
    ///
    /// PHP: `grants($type, $value, $field, $wildcard)`.
    pub fn grants(&self, kind: &[u8], value: &[u8], field: &[u8], wildcard: Option<&[u8]>) -> bool {
        if kind.is_empty() || value.is_empty() || field.is_empty() {
            return false;
        }
        let type_key = key(AuthorizationDetail::Type.as_str().as_bytes());
        let field_key = key(field);
        for entry in &self.0 {
            match entry.get(&type_key) {
                Some(Value::Str(t)) if t == kind => {}
                _ => continue,
            }
            let Some(values) = entry.get(&field_key).filter(|v| **v != Value::Null) else {
                continue;
            };
            let Value::Array(values) = values else {
                continue;
            };
            if !values.is_list() {
                continue;
            }
            if lists(values, value) || wildcard.is_some_and(|w| lists(values, w)) {
                return true;
            }
        }
        false
    }

    /// Narrows `field` of each entry to what `resolver(type, values)` allows.
    ///
    /// `None` from the resolver keeps the entry as is, an empty result drops
    /// it. Values the entry did not list are discarded, unless it lists
    /// `wildcard`.
    ///
    /// PHP: `restrict($field, $resolver, $wildcard)`.
    pub fn restrict(
        &self,
        field: &[u8],
        mut resolver: impl FnMut(&[u8], &[Vec<u8>]) -> Option<Array>,
        wildcard: Option<&[u8]>,
    ) -> Self {
        let type_key = key(AuthorizationDetail::Type.as_str().as_bytes());
        let field_key = key(field);
        let mut entries = Vec::with_capacity(self.0.len());
        for entry in &self.0 {
            let kind = match entry.get(&type_key) {
                Some(Value::Str(t)) if !t.is_empty() && !field.is_empty() => t,
                _ => {
                    entries.push(entry.clone());
                    continue;
                }
            };
            let values: Vec<Vec<u8>> = match entry.get(&field_key) {
                Some(Value::Array(v)) if v.is_list() => strings(v).map(<[u8]>::to_vec).collect(),
                _ => vec![],
            };
            let Some(allowed) = resolver(kind, &values) else {
                entries.push(entry.clone());
                continue;
            };
            let allowed: Vec<Vec<u8>> = strings(&allowed).map(<[u8]>::to_vec).collect();
            let expands = wildcard.is_some_and(|w| values.iter().any(|v| v == w));
            let allowed: Vec<Vec<u8>> =
                if expands { allowed } else { values.iter().filter(|v| allowed.contains(v)).cloned().collect() };
            if allowed.is_empty() {
                continue;
            }
            let mut entry = entry.clone();
            entry.set(field_key.clone(), Value::Array(allowed.into_iter().map(|s| Value::Str(s.into())).collect()));
            entries.push(entry);
        }
        Self(entries)
    }

    /// The entries, in request order.
    ///
    /// PHP: `toArray()`.
    pub fn entries(&self) -> &[Array] {
        &self.0
    }
}
