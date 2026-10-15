//! [`QueryValidator`]: the validator of one kind of query that a query list
//! validator ([`crate::validator::queries::base::Queries`]) dispatches to, by
//! [`MethodType`].
//!
//! PHP: `Utopia\Database\Validator\Query\Base`.

use std::any::Any;

use utopia_query::{Object, Value as QValue};

use crate::value::{Ext, Value};

/// The kind of query a [`QueryValidator`] validates.
///
/// PHP: `Base::METHOD_TYPE_*`.
#[derive(Clone, Copy, PartialEq, Eq, Hash, Debug)]
pub enum MethodType {
    /// `limit`
    Limit,
    /// `offset`
    Offset,
    /// `cursor`
    Cursor,
    /// `order`
    Order,
    /// `filter`
    Filter,
    /// `select`
    Select,
    /// `join`
    Join,
    /// `aggregate`
    Aggregate,
    /// `groupBy`
    GroupBy,
    /// `having`
    Having,
    /// `distinct`
    Distinct,
}

impl MethodType {
    /// The type as PHP spells it (`METHOD_TYPE_*`'s value).
    pub fn as_str(self) -> &'static str {
        match self {
            MethodType::Limit => "limit",
            MethodType::Offset => "offset",
            MethodType::Cursor => "cursor",
            MethodType::Order => "order",
            MethodType::Filter => "filter",
            MethodType::Select => "select",
            MethodType::Join => "join",
            MethodType::Aggregate => "aggregate",
            MethodType::GroupBy => "groupBy",
            MethodType::Having => "having",
            MethodType::Distinct => "distinct",
        }
    }
}

/// A validator of one kind of query. Implement it to add a query validator
/// to a query list validator.
///
/// PHP: `Utopia\Database\Validator\Query\Base` (an abstract
/// `Utopia\Validator` with `getMethodType()`). Validators keep the message
/// of their last failure, and the query list validators hand them the
/// joins, aliases and aggregates of the list they validate, so their
/// methods take `&self` and keep that state behind locks.
pub trait QueryValidator: Any + Send + Sync {
    /// The kind of query this validator validates (`getMethodType()`).
    fn method_type(&self) -> MethodType;

    /// Whether the query is valid (`isValid($query)`); a failure updates
    /// [`QueryValidator::description`].
    fn check(&self, query: &utopia_query::Query) -> bool;

    /// The last failure, or `Invalid query` (`getDescription()`).
    fn description(&self) -> String;

    /// The validator, to find which of this module's validators it is
    /// (PHP's `instanceof`).
    fn as_any(&self) -> &dyn Any;
}

impl std::fmt::Debug for dyn QueryValidator {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "QueryValidator({})", self.method_type().as_str())
    }
}

/// The description a query validator starts with (`Base::$message`).
pub(crate) const INVALID_QUERY: &str = "Invalid query";

/// The query a value holds, if it is one (`$value instanceof Query`).
pub(crate) fn query_of(value: &Value) -> Option<&utopia_query::Query> {
    match value {
        Value::Ext(Ext::Query(q)) => Some(q),
        _ => None,
    }
}

/// The query a query value holds, if it is one.
pub(crate) fn nested(value: &QValue) -> Option<&utopia_query::Query> {
    match value {
        QValue::Ext(Object::Query(q)) => Some(q),
        _ => None,
    }
}

/// A query value as a plain PHP value, as a utopia-php/validators
/// validator reads it (rows and nested queries as their arrays).
pub(crate) fn plain(value: &QValue) -> php_std::Value {
    match value {
        QValue::Null => php_std::Value::Null,
        QValue::Bool(b) => php_std::Value::Bool(*b),
        QValue::Int(i) => php_std::Value::Int(*i),
        QValue::Float(f) => php_std::Value::Float(*f),
        QValue::Str(s) => php_std::Value::Str(s.clone()),
        QValue::Array(a) => php_std::Value::Array(a.iter().map(|(k, v)| (k.to_owned(), plain(v))).collect()),
        QValue::Object(a) => php_std::Value::Object(a.iter().map(|(k, v)| (k.to_owned(), plain(v))).collect()),
        QValue::Ext(_) => crate::value::to_plain(&crate::value::from_query(value)),
    }
}

/// Whether a utopia-php/validators validator accepts a query value.
pub(crate) fn accepts(validator: &(impl utopia_validators::Validator + ?Sized), value: &QValue) -> bool {
    let plain = plain(value);
    validator.validate(utopia_validators::Input::Value(&plain)).is_ok_and(|v| v.valid)
}

/// What a utopia-php/validators validator reports after rejecting a query
/// value (`None` when it accepts it).
pub(crate) fn rejection(validator: &(impl utopia_validators::Validator + ?Sized), value: &QValue) -> Option<String> {
    let plain = plain(value);
    match validator.validate(utopia_validators::Input::Value(&plain)) {
        Ok(v) if v.valid => None,
        Ok(v) => Some(v.describe(validator)),
        Err(_) => Some(validator.description()),
    }
}

/// `ucfirst($method->value)`.
pub(crate) fn ucfirst(s: &str) -> String {
    let mut c = s.chars();
    match c.next() {
        Some(f) => f.to_ascii_uppercase().to_string() + c.as_str(),
        None => String::new(),
    }
}

/// Whether `s` matches a PCRE pattern `/^[first][rest]*$/` (without `D`, so
/// `$` also matches before a final newline).
pub(crate) fn matches_identifier(s: &[u8], first: impl Fn(u8) -> bool, rest: impl Fn(u8) -> bool) -> bool {
    let s = s.strip_suffix(b"\n").unwrap_or(s);
    match s.split_first() {
        Some((f, tail)) => first(*f) && tail.iter().all(|c| rest(*c)),
        None => false,
    }
}

/// PHP's `/^[A-Za-z_][A-Za-z0-9_]*$/` (an alias).
pub(crate) fn is_alias_identifier(s: &str) -> bool {
    matches_identifier(
        s.as_bytes(),
        |c| c.is_ascii_alphabetic() || c == b'_',
        |c| c.is_ascii_alphanumeric() || c == b'_',
    )
}

/// Whether the column half of an `alias.column` reference is a single plain
/// identifier (`isAllowedJoinColumn()`: `/^[A-Za-z_$][A-Za-z0-9_$]*$/`), so
/// a dotted path cannot smuggle extra segments past the alias check.
pub(crate) fn is_allowed_join_column(column: &str) -> bool {
    !column.is_empty()
        && matches_identifier(
            column.as_bytes(),
            |c| c.is_ascii_alphabetic() || c == b'_' || c == b'$',
            |c| c.is_ascii_alphanumeric() || c == b'_' || c == b'$',
        )
}

/// `strpos($attribute, '.')` split: the part before the first dot and the
/// part after it.
pub(crate) fn split_dot(attribute: &str) -> Option<(&str, &str)> {
    attribute.split_once('.')
}

/// Implements [`QueryValidator`] and [`utopia_validators::Validator`] for a
/// query validator with inherent `check(&utopia_query::Query) -> bool`,
/// `description()` and `is_valid(&Value) -> bool`; `getType()` is `object`
/// and `isArray()` false.
macro_rules! query_validator {
    ($ty:ty, $method_type:expr) => {
        impl $crate::validator::query::base::QueryValidator for $ty {
            fn method_type(&self) -> $crate::validator::query::base::MethodType {
                $method_type
            }

            fn check(&self, query: &utopia_query::Query) -> bool {
                <$ty>::check(self, query)
            }

            fn description(&self) -> String {
                <$ty>::description(self)
            }

            fn as_any(&self) -> &dyn std::any::Any {
                self
            }
        }

        impl $ty {
            /// `getMethodType()`.
            pub fn method_type(&self) -> $crate::validator::query::base::MethodType {
                $method_type
            }

            /// `getType()`: `object`.
            pub fn type_name(&self) -> &'static str {
                "object"
            }

            /// `isArray()`: false.
            pub fn is_array(&self) -> bool {
                false
            }
        }

        $crate::validator::validator!($ty, utopia_validators::Type::Object, false);
    };
    ($ty:ty, $method_type:expr, fallible) => {
        impl $crate::validator::query::base::QueryValidator for $ty {
            fn method_type(&self) -> $crate::validator::query::base::MethodType {
                $method_type
            }

            fn check(&self, query: &utopia_query::Query) -> bool {
                <$ty>::check(self, query)
            }

            fn description(&self) -> String {
                <$ty>::description(self)
            }

            fn as_any(&self) -> &dyn std::any::Any {
                self
            }
        }

        impl $ty {
            /// `getMethodType()`.
            pub fn method_type(&self) -> $crate::validator::query::base::MethodType {
                $method_type
            }

            /// `getType()`: `object`.
            pub fn type_name(&self) -> &'static str {
                "object"
            }

            /// `isArray()`: false.
            pub fn is_array(&self) -> bool {
                false
            }
        }

        $crate::validator::validator!($ty, utopia_validators::Type::Object, false, fallible);
    };
}
pub(crate) use query_validator;

/// `getDescription()` and `isValid()` of a validator that keeps PHP's
/// `Base::isValid()`: anything but a query is refused with `Value must be a
/// Query`.
macro_rules! base_is_valid {
    ($ty:ty) => {
        impl $ty {
            /// `isValid($value)`: a query this validator accepts; any other
            /// value is refused (`Value must be a Query`).
            pub fn is_valid(&self, value: &$crate::value::Value) -> bool {
                match $crate::validator::query::base::query_of(value) {
                    Some(q) => self.check(q),
                    None => {
                        self.message.set("Value must be a Query");
                        false
                    }
                }
            }

            /// `getDescription()`.
            pub fn description(&self) -> String {
                self.message.get()
            }
        }
    };
}
pub(crate) use base_is_valid;
