//! Validators with the semantics of `utopia-php/validators`.
//!
//! Every validator reproduces the acceptance rules *and* the human-readable
//! descriptions of its PHP class, because descriptions are part of the API
//! contract (they end up in `Invalid `x` param: <description>` errors).
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Validator\Validator` (abstract class) | [`Validator`] (trait) |
//! | `getDescription()`, `isValid()`, `getType()`, `isArray()` | [`Validator::description`], [`Validator::validate`] / [`Validator::is_valid`], [`Validator::kind`], [`Validator::is_array`] |
//! | `Validator::TYPE_*` | [`Type`] |
//! | `AllOf`, `AnyOf`, `NoneOf`, `Multiple` | [`AllOf`], [`AnyOf`], [`NoneOf`], [`Multiple`] |
//! | `ArrayList`, `Assoc`, `Nullable`, `WhiteList` | [`ArrayList`], [`Assoc`], [`Nullable`], [`WhiteList`] |
//! | `Boolean`, `Integer`, `FloatValidator`, `Numeric`, `Range` | [`Boolean`], [`Integer`], [`Float`], [`Numeric`], [`Range`] |
//! | `Text`, `Identifier` | [`Text`], [`Identifier`] |
//! | `Contains`, `Globstar`, `HexColor`, `Phone`, `Wildcard` | [`Contains`], [`Globstar`], [`HexColor`], [`Phone`], [`Wildcard`] |
//! | `Domain` (+ `createRestriction`), `Host`, `Hostname`, `IP`, `URL` | [`Domain`] ([`Restriction`]), [`Host`], [`Hostname`], [`Ip`], [`Url`] |
//! | `JSON`, `JSON\ArrayValidator`, `JSON\ObjectValidator`, `JSON\FCM` | [`Json`], [`json::Array`], [`json::Object`], [`json::Fcm`] |
//! | `\InvalidArgumentException`, `\Exception`, `\Error`, `\TypeError` | [`Error`] |
//!
//! # Values
//!
//! Validators read PHP values through [`Input`], which borrows either model
//! `php-std` defines: the request model (`serde_json::Value`, as
//! `Utopia\Http\Request` decodes bodies and query strings) or the exact model
//! ([`php_std::zval::Zval`], which also holds binary strings, non-finite
//! floats and `stdClass` objects with properties). Both are validated the
//! same way, without converting one into the other.
//!
//! # State
//!
//! PHP's `AllOf`, `AnyOf`, `NoneOf` and `JSON\FCM` remember their last
//! deciding validation (the rule that decided, the field that was wrong),
//! and `getDescription()` reads it. Rust validators are shared by concurrent
//! requests, so they hold no state: [`Validator::validate`] returns a
//! [`Verdict`] carrying the decision as data ([`Verdict::decided`],
//! [`Verdict::rule`]) and the description PHP's `getDescription()` reports
//! after that validation. [`Validator::check`] reports it for one
//! validation; a caller replaying a PHP object's sequence of calls keeps the
//! latest decision (`crates/tools/compat/src/libs/validators.rs` does).
//!
//! # Errors
//!
//! Where PHP throws inside `isValid()` (a `WhiteList` casting a `stdClass`
//! to a string), [`Validator::validate`] returns the [`Error`];
//! [`Validator::is_valid`] and [`Validator::check`] treat it as invalid.
//! [`Validator::try_description`] is `getDescription()` with the errors PHP
//! throws there (a composite without rules).

/// PHP value semantics, re-exported from `php-std` for existing callers.
pub use php_std::value as php;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

mod compose;
mod error;
mod hostname;
mod input;
pub mod json;
mod list;
mod network;
mod scalar;
mod string;
mod text;

use std::borrow::Cow;
use std::sync::Arc;

pub use compose::{AllOf, AnyOf, Multiple, NoneOf};
pub use error::Error;
pub use hostname::{Host, Hostname};
pub use input::{Entries, Input, View};
pub use json::Json;
pub use list::{ArrayList, Assoc, Nullable, WhiteList};
pub use network::{Domain, Ip, IpVersion, Restriction, Url};
pub use scalar::{Boolean, Float, Integer, Numeric, Range};
pub use string::{Contains, Globstar, HexColor, Phone, Wildcard};
pub use text::{Identifier, Text};

pub use serde_json::Value;

/// `Validator::TYPE_*`: the type of value a validator expects.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Type {
    Boolean,
    Integer,
    /// PHP's `TYPE_FLOAT`, whose value is `"double"` (what `gettype()` says).
    Float,
    String,
    Array,
    Object,
    Mixed,
}

impl Type {
    /// The PHP constant's value.
    pub const fn as_str(self) -> &'static str {
        match self {
            Type::Boolean => "boolean",
            Type::Integer => "integer",
            Type::Float => "double",
            Type::String => "string",
            Type::Array => "array",
            Type::Object => "object",
            Type::Mixed => "mixed",
        }
    }

    /// The type a PHP `TYPE_*` value names.
    pub fn from_php(value: &str) -> Option<Self> {
        Some(match value {
            "boolean" => Type::Boolean,
            "integer" => Type::Integer,
            "double" => Type::Float,
            "string" => Type::String,
            "array" => Type::Array,
            "object" => Type::Object,
            "mixed" => Type::Mixed,
            _ => return None,
        })
    }
}

/// The outcome of one validation.
///
/// PHP's `AllOf`, `AnyOf`, `NoneOf` and `JSON\FCM` remember what decided
/// their last `isValid()` (the failing rule, the missing field), and
/// `getDescription()` reports it until another validation decides. A verdict
/// carries that decision as data: whether this validation decided, which rule
/// of a composite did, and the description PHP reports afterwards.
#[derive(Debug, Clone, PartialEq, Eq)]
#[must_use]
pub struct Verdict {
    /// Whether the value is valid.
    pub valid: bool,
    /// What `getDescription()` returns after this validation when it is not
    /// [`Validator::description`]: the decided description, or, for a
    /// validation that did not decide, the description of a validator that
    /// had not decided before (an `AllOf` describes its first rule as that
    /// rule's validation left it).
    pub description: Option<Cow<'static, str>>,
    /// Whether this validation decided the description PHP keeps. One that
    /// did not leaves an earlier decision in place.
    pub decided: bool,
    /// The rule of an `AllOf`, `AnyOf` or `NoneOf` that decided (PHP's `failedRule`).
    pub rule: Option<usize>,
}

impl Verdict {
    pub const VALID: Verdict = Verdict::of(true);
    pub const INVALID: Verdict = Verdict::of(false);

    /// A verdict that decides nothing: the description stays as it was.
    pub const fn of(valid: bool) -> Self {
        Verdict { valid, description: None, decided: false, rule: None }
    }

    /// A verdict that decides the description (`None`: [`Validator::description`]).
    pub fn decided(valid: bool, description: Option<Cow<'static, str>>) -> Self {
        Verdict { valid, description, decided: true, rule: None }
    }

    /// The description `getDescription()` reports after this validation, for
    /// a validator that had not decided before.
    pub fn describe(&self, validator: &(impl Validator + ?Sized)) -> String {
        match &self.description {
            Some(d) => d.clone().into_owned(),
            None => validator.description(),
        }
    }

    /// [`Verdict::describe`] with the error PHP's `getDescription()` throws.
    pub fn try_describe(&self, validator: &(impl Validator + ?Sized)) -> Result<String, Error> {
        match &self.description {
            Some(d) => Ok(d.clone().into_owned()),
            None => validator.try_description(),
        }
    }
}

/// A parameter validator (`Utopia\Validator\Validator`).
///
/// Implement [`Validator::is_valid`] for a validator of request values
/// (`serde_json::Value`); the provided [`Validator::validate`] then accepts
/// exact PHP values too, through their request-model form. The validators of
/// this crate implement [`Validator::validate`] for both models.
pub trait Validator: Send + Sync {
    /// `getDescription()` of a validator that has not validated anything:
    /// the human-readable rule, used verbatim in error messages.
    fn description(&self) -> String;

    /// `isValid($value)` for a request value. `false` where PHP throws
    /// (see [`Validator::validate`] for the error).
    fn is_valid(&self, value: &Value) -> bool;

    /// `isValid($value)`, with what `getDescription()` reports afterwards,
    /// or the error PHP throws.
    ///
    /// The provided implementation calls [`Validator::is_valid`]; exact
    /// values the request model cannot hold (binary strings, non-finite
    /// floats) are invalid there.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value {
            Input::Json(v) => self.is_valid(v),
            Input::Zval(z) => z.to_json().is_ok_and(|v| self.is_valid(&v)),
        }))
    }

    /// Validates a request value; on failure returns the description PHP
    /// reports (that of the inner rule that failed, for composites).
    fn check(&self, value: &Value) -> Result<(), String> {
        match self.validate(Input::Json(value)) {
            Ok(v) if v.valid => Ok(()),
            Ok(v) => Err(v.describe(self)),
            Err(_) => Err(self.description()),
        }
    }

    /// `isArray()`: whether the validator expects an array value.
    fn is_array(&self) -> bool {
        false
    }

    /// `getType()`.
    fn kind(&self) -> Type {
        Type::Mixed
    }

    /// Whether `null` is an acceptable value (used for "null means omitted" handling).
    fn accepts_null(&self) -> bool {
        self.is_valid(&Value::Null)
    }

    /// `getDescription()` with the error PHP throws where it cannot describe
    /// (an `AllOf` without rules, a strict `WhiteList` holding a `stdClass`).
    /// [`Validator::description`] describes those as best it can.
    fn try_description(&self) -> Result<String, Error> {
        Ok(self.description())
    }
}

/// `is_valid` of a validator implementing `validate`.
macro_rules! is_valid_via_validate {
    () => {
        fn is_valid(&self, value: &serde_json::Value) -> bool {
            self.validate($crate::Input::Json(value)).is_ok_and(|v| v.valid)
        }
    };
}
pub(crate) use is_valid_via_validate;

macro_rules! forward_validator {
    ($($ty:ty),*) => {$(
        impl<V: Validator + ?Sized> Validator for $ty {
            fn description(&self) -> String {
                (**self).description()
            }
            fn is_valid(&self, value: &Value) -> bool {
                (**self).is_valid(value)
            }
            fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
                (**self).validate(value)
            }
            fn check(&self, value: &Value) -> Result<(), String> {
                (**self).check(value)
            }
            fn is_array(&self) -> bool {
                (**self).is_array()
            }
            fn kind(&self) -> Type {
                (**self).kind()
            }
            fn accepts_null(&self) -> bool {
                (**self).accepts_null()
            }
            fn try_description(&self) -> Result<String, Error> {
                (**self).try_description()
            }
        }
    )*};
}

forward_validator!(&V, Box<V>, Arc<V>);

/// PHP `number_format($n)`: thousands separated by commas, no decimals.
pub fn number_format(n: i128) -> String {
    let negative = n < 0;
    let digits = n.unsigned_abs().to_string();
    let mut out = String::with_capacity(digits.len() + digits.len() / 3 + 1);
    if negative {
        out.push('-');
    }
    let first = digits.len() % 3;
    for (i, ch) in digits.chars().enumerate() {
        if i != 0 && (i + 3 - first).is_multiple_of(3) {
            out.push(',');
        }
        out.push(ch);
    }
    out
}

/// PHP `number_format($number)` of an `int|float`.
pub(crate) fn format_number(n: php::Number) -> String {
    String::from_utf8_lossy(&php_std::format::number_format(n, 0, b".", b",")).into_owned()
}

#[cfg(test)]
mod tests;
