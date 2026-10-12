//! Validators: documents against their collection ([`structure`]), queries
//! ([`queries`], [`query`]), attribute and index definitions, keys,
//! permissions and roles, and the [`authorization`] check of a permission
//! list against the current roles.
//!
//! PHP: `Utopia\Database\Validator\*`. Each validator implements
//! [`utopia_validators::Validator`] (description, `is_valid`, `is_array`,
//! type) the way PHP's does; the database calls their typed methods.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Validator\Attribute` | [`attribute::Attribute`] |
//! | `Validator\BigInt` | [`big_int::BigInt`] |
//! | `Validator\ByteLength` | [`byte_length::ByteLength`] |
//! | `Validator\Datetime` | [`datetime::Datetime`] |
//! | `Validator\Index` | [`index::Index`] |
//! | `Validator\IndexDependency` | [`index_dependency::IndexDependency`] |
//! | `Validator\IndexedQueries` | [`indexed_queries::IndexedQueries`] |
//! | `Validator\Key`, `UID`, `Label` | [`key::Key`], [`uid::Uid`], [`label::Label`] |
//! | `Validator\ObjectValidator` | [`object::ObjectValidator`] |
//! | `Validator\Operator` | [`operator::Operator`] |
//! | `Validator\PartialStructure` | [`partial_structure::PartialStructure`] |
//! | `Validator\Permissions`, `Roles` | [`permissions::Permissions`], [`roles::Roles`] |
//! | `Validator\Queries` | [`queries::Queries`] |
//! | `Validator\Queries\Document`, `Queries\Documents` | [`queries::document::Document`], [`queries::documents::Documents`] |
//! | `Validator\Query\Base` | [`query::base::QueryValidator`], [`query::base::MethodType`] |
//! | `Validator\Query\{Cursor, Filter, Limit, Offset, Order, Select}` | [`query::cursor::Cursor`], [`query::filter::Filter`], [`query::limit::Limit`], [`query::offset::Offset`], [`query::order::Order`], [`query::select::Select`] |
//! | `Validator\Sequence` | [`sequence::Sequence`] |
//! | `Validator\Spatial` | [`spatial::Spatial`] |
//! | `Validator\Structure` (and its format registry) | [`structure::Structure`], [`structure::formats`] |
//! | `Validator\Vector` | [`vector::Vector`] |
//!
//! # State
//!
//! PHP validators keep the message of their last failure, and
//! `getDescription()` reports it. The Rust validators do too (behind a
//! lock, so a validator is `Sync`): [`utopia_validators::Validator::description`]
//! and each validator's inherent `description()` read it after `is_valid`.

use std::borrow::Cow;
use std::sync::Mutex;

use crate::document::Document;
use crate::value::{ArrayKey, Str, Value};

pub mod attribute;
pub mod authorization;
pub mod big_int;
pub mod byte_length;
pub mod datetime;
pub mod index;
pub mod index_dependency;
pub mod indexed_queries;
pub mod key;
pub mod label;
pub mod object;
pub mod operator;
pub mod partial_structure;
pub mod permissions;
pub mod queries;
pub mod query;
pub mod roles;
pub mod sequence;
pub mod spatial;
pub mod structure;
pub mod uid;
pub mod vector;

/// The message a validator reports, changed by validations (PHP's
/// `protected string $message`).
#[derive(Debug)]
pub(crate) struct Message(Mutex<String>);

impl Message {
    pub(crate) fn new(message: impl Into<String>) -> Self {
        Self(Mutex::new(message.into()))
    }

    pub(crate) fn get(&self) -> String {
        self.0.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    pub(crate) fn set(&self, message: impl Into<String>) {
        *self.0.lock().unwrap_or_else(|e| e.into_inner()) = message.into();
    }
}

impl Clone for Message {
    fn clone(&self) -> Self {
        Self::new(self.get())
    }
}

/// The array key PHP uses for a value (`$array[$value]`): integers and
/// numeric strings are integer keys, `null` is `""`, booleans and floats
/// are integers.
pub(crate) fn array_key(value: &Value) -> ArrayKey {
    match value {
        Value::Int(i) => ArrayKey::Int(*i),
        Value::Bool(b) => ArrayKey::Int(i64::from(*b)),
        Value::Float(f) => ArrayKey::Int(if f.is_finite() { *f as i64 } else { 0 }),
        Value::Str(s) => ArrayKey::normalize(s.clone()),
        Value::Null => ArrayKey::normalize(Str::from("")),
        other => ArrayKey::normalize(Str::from(crate::value::to_text(other))),
    }
}

/// The array key of text.
pub(crate) fn text_key(text: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(text))
}

/// `(string) $value`, as text (bytes that are not UTF-8 lossily).
pub(crate) fn text(value: &Value) -> String {
    crate::value::to_text(value)
}

/// `strtolower((string) $value)`: ASCII only, as PHP 8.
pub(crate) fn lower(value: &Value) -> String {
    text(value).to_ascii_lowercase()
}

/// `is_numeric($value)`.
pub(crate) fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `$attribute->getAttribute('key', $attribute->getAttribute('$id'))`.
pub(crate) fn attribute_key(attribute: &Document) -> Value {
    match attribute.get("key") {
        Some(v) => v.clone(),
        None => attribute.get("$id").cloned().unwrap_or(Value::Null),
    }
}

/// `$value[$key]` on an array value: the element, or `null` when the key is
/// missing or the value is not an array (or a document).
pub(crate) fn index<'a>(value: &'a Value, key: &str) -> &'a Value {
    const NULL: &Value = &Value::Null;
    match value {
        Value::Array(a) => a.get(&text_key(key)).unwrap_or(NULL),
        Value::Ext(crate::value::Ext::Document(d)) => d.raw(key).unwrap_or(NULL),
        _ => NULL,
    }
}

/// `$value[$i]` on an array value.
pub(crate) fn nth(value: &Value, i: i64) -> &Value {
    const NULL: &Value = &Value::Null;
    match value {
        Value::Array(a) => a.get(&ArrayKey::Int(i)).unwrap_or(NULL),
        _ => NULL,
    }
}

/// `$value[$key] ?? $default`.
pub(crate) fn index_or<'a>(value: &'a Value, key: &str, default: &'a Value) -> &'a Value {
    match index(value, key) {
        Value::Null => default,
        v => v,
    }
}

/// Whether a utopia-php/validators validator accepts a database value.
pub(crate) fn accepts(validator: &(impl utopia_validators::Validator + ?Sized), value: &Value) -> bool {
    let plain = crate::value::to_plain(value);
    validator.validate(utopia_validators::Input::Value(&plain)).is_ok_and(|v| v.valid)
}

/// What a utopia-php/validators validator reports after rejecting `value`
/// (`None` when it accepts it: describing a validator can cost more than
/// validating, so only a rejection is described).
pub(crate) fn rejection(validator: &(impl utopia_validators::Validator + ?Sized), value: &Value) -> Option<String> {
    let plain = crate::value::to_plain(value);
    match validator.validate(utopia_validators::Input::Value(&plain)) {
        Ok(v) if v.valid => None,
        Ok(v) => Some(v.describe(validator)),
        Err(_) => Some(validator.description()),
    }
}

/// A request or exact value as a database value.
pub(crate) fn input_value(input: utopia_validators::Input<'_>) -> Value {
    match input {
        utopia_validators::Input::Json(j) => crate::value::from_plain(php_std::Value::from_json(j)),
        utopia_validators::Input::Value(v) => crate::value::from_plain(v.clone()),
    }
}

/// A database error as a utopia-php/validators error (PHP throws it out of
/// `isValid`).
pub(crate) fn engine_error(e: crate::Error) -> utopia_validators::Error {
    utopia_validators::Error::Exception(e.message().to_owned())
}

/// The verdict of a validation that decided its description.
pub(crate) fn decided(valid: bool, description: String) -> utopia_validators::Verdict {
    utopia_validators::Verdict::decided(valid, Some(Cow::Owned(description)))
}

/// Implements [`utopia_validators::Validator`] for a validator with inherent
/// `description()` and `is_valid(&Value) -> bool` (or `-> Result<bool>` with
/// `fallible`). With `constant`, the description does not depend on what
/// was validated, so a verdict leaves it to [`Validator::description`]
/// (computed only when read) instead of computing it after each validation.
///
/// [`Validator::description`]: utopia_validators::Validator::description
macro_rules! validator {
    ($ty:ty, $kind:expr, $array:expr) => {
        $crate::validator::validator!(@impl $ty, $kind, $array, |s: &$ty, v: &$crate::value::Value| Ok::<bool, $crate::Error>(<$ty>::is_valid(s, v)), |s: &$ty, valid: bool| $crate::validator::decided(valid, <$ty>::description(s)));
    };
    ($ty:ty, $kind:expr, $array:expr, constant) => {
        $crate::validator::validator!(@impl $ty, $kind, $array, |s: &$ty, v: &$crate::value::Value| Ok::<bool, $crate::Error>(<$ty>::is_valid(s, v)), |_: &$ty, valid: bool| utopia_validators::Verdict::decided(valid, None));
    };
    ($ty:ty, $kind:expr, $array:expr, fallible) => {
        $crate::validator::validator!(@impl $ty, $kind, $array, |s: &$ty, v: &$crate::value::Value| <$ty>::is_valid(s, v), |s: &$ty, valid: bool| $crate::validator::decided(valid, <$ty>::description(s)));
    };
    (@impl $ty:ty, $kind:expr, $array:expr, $check:expr, $verdict:expr) => {
        impl utopia_validators::Validator for $ty {
            fn description(&self) -> String {
                <$ty>::description(self)
            }

            fn is_valid(&self, value: &serde_json::Value) -> bool {
                utopia_validators::Validator::validate(self, utopia_validators::Input::Json(value)).is_ok_and(|v| v.valid)
            }

            fn validate(
                &self,
                value: utopia_validators::Input<'_>,
            ) -> Result<utopia_validators::Verdict, utopia_validators::Error> {
                let value = $crate::validator::input_value(value);
                let valid = ($check)(self, &value).map_err($crate::validator::engine_error)?;
                Ok(($verdict)(self, valid))
            }

            fn is_array(&self) -> bool {
                $array
            }

            fn kind(&self) -> utopia_validators::Type {
                $kind
            }
        }
    };
}
pub(crate) use validator;
