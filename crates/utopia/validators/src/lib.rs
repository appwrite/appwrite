//! Validate request parameters, and describe the rule each one follows.
//!
//! A [`Validator`] answers two questions: whether a value is valid, and what
//! rule it follows. The rule is a human-readable description that becomes
//! the error message a client sees (``Invalid `x` param: <description>``), so
//! it is part of the API. The crate validates scalars ([`Boolean`],
//! [`Integer`], [`Float`], [`Numeric`], [`Range`]), strings ([`Text`],
//! [`Identifier`], [`Contains`], [`Globstar`], [`HexColor`], [`Phone`]),
//! lists and maps ([`ArrayList`], [`Assoc`], [`WhiteList`], [`Nullable`]),
//! network values ([`Domain`], [`Hostname`], [`Host`], [`Ip`], [`Url`]) and
//! JSON ([`Json`] and the [`json`] module), and combines validators
//! ([`AllOf`], [`AnyOf`], [`NoneOf`], [`Multiple`]). [`Wildcard`] accepts
//! everything.
//!
//! PHP: ports `utopia-php/validators`. Every validator reproduces the
//! acceptance rules *and* the human-readable descriptions of its PHP class.
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
//! Validators read values through [`Input`], which borrows either of two
//! models: a request value ([`Value`], from `serde_json`, the form a decoded
//! request body or query string takes) or an exact value
//! ([`php_std::Value`], which also holds binary strings, non-finite floats
//! and objects with properties). Both are validated the same way, without
//! converting one into the other.
//!
//! PHP: request values are decoded as `Utopia\Http\Request` decodes bodies
//! and query strings; objects are `stdClass` instances.
//!
//! # State
//!
//! Validators hold no state, so one validator can serve concurrent requests.
//! [`Validator::validate`] returns a [`Verdict`]: whether the value is
//! valid, and the description to report for it; for a composite, the
//! description of the rule that decided ([`Verdict::rule`]).
//! [`Validator::check`] returns that description as the error of a failed
//! validation.
//!
//! PHP: PHP's `AllOf`, `AnyOf`, `NoneOf` and `JSON\FCM` remember their last
//! deciding validation (the rule that decided, the field that was wrong),
//! and `getDescription()` reads it. A [`Verdict`] carries that decision as
//! data ([`Verdict::decided`]); a caller replaying a PHP object's sequence
//! of calls keeps the latest decision
//! (`crates/tools/compat/src/libs/validators.rs` does).
//!
//! # Errors
//!
//! Some values cannot be validated at all, such as an object compared as a
//! string by a [`WhiteList`]: [`Validator::validate`] returns the
//! [`Error`], and [`Validator::is_valid`] and [`Validator::check`] treat the
//! value as invalid. [`Validator::try_description`] returns the error where
//! a validator cannot be described, such as a composite without rules.
//!
//! PHP: these are the cases where PHP throws inside `isValid()` or
//! `getDescription()`.

#![warn(missing_docs)]

/// The value semantics validators follow (truthiness, numeric strings,
/// number comparisons), re-exported from `php-std` for existing callers.
///
/// PHP: the semantics of PHP values.
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

/// The type of value a validator expects.
///
/// PHP: the `Validator::TYPE_*` constants.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Type {
    /// A boolean.
    Boolean,
    /// An integer.
    Integer,
    /// A float.
    ///
    /// PHP: `TYPE_FLOAT`, whose value is `"double"` (what `gettype()` says).
    Float,
    /// A string.
    String,
    /// An array: a list or a map.
    Array,
    /// An object: named properties.
    Object,
    /// Any type.
    Mixed,
}

impl Type {
    /// The type's name: `boolean`, `integer`, `double`, `string`, `array`,
    /// `object` or `mixed`.
    ///
    /// PHP: the value of the matching `TYPE_*` constant.
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

    /// The type a name from [`Type::as_str`] names, or `None`.
    ///
    /// PHP: reads the value of a `TYPE_*` constant.
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
/// Besides validity, a verdict carries the description to report for the
/// value and, for a composite, which rule decided.
///
/// PHP: PHP's `AllOf`, `AnyOf`, `NoneOf` and `JSON\FCM` remember what decided
/// their last `isValid()` (the failing rule, the missing field), and
/// `getDescription()` reports it until another validation decides. A verdict
/// carries that decision as data: whether this validation decided, which rule
/// of a composite did, and the description PHP reports afterwards.
#[derive(Debug, Clone, PartialEq, Eq)]
#[must_use]
pub struct Verdict {
    /// Whether the value is valid.
    pub valid: bool,
    /// The description to report after this validation, or `None` for
    /// [`Validator::description`].
    ///
    /// For a validation that did not decide, it is what a validator that had not
    /// decided before reports: an [`AllOf`] describes its first rule as that
    /// rule's validation left it.
    ///
    /// A composite's verdict that one of its rules decided without a
    /// description of its own leaves this `None` and names the rule in
    /// [`Verdict::rule`]: [`Verdict::describe`] describes that rule only
    /// when asked, so validating builds no description.
    ///
    /// PHP: what `getDescription()` returns after this validation.
    pub description: Option<Cow<'static, str>>,
    /// Whether this validation decided the description to report. One that did
    /// not leaves an earlier decision in place.
    pub decided: bool,
    /// The index of the rule of an [`AllOf`], [`AnyOf`] or [`NoneOf`] that
    /// decided. Without a [`Verdict::description`], that rule's description
    /// is the one to report ([`Validator::rule_description`]).
    ///
    /// PHP: `failedRule`.
    pub rule: Option<usize>,
}

impl Verdict {
    /// A valid verdict that decides nothing.
    pub const VALID: Verdict = Verdict::of(true);
    /// An invalid verdict that decides nothing.
    pub const INVALID: Verdict = Verdict::of(false);

    /// A verdict that decides nothing: the description stays as it was.
    pub const fn of(valid: bool) -> Self {
        Verdict { valid, description: None, decided: false, rule: None }
    }

    /// A verdict that decides the description (`None`: [`Validator::description`]).
    pub fn decided(valid: bool, description: Option<Cow<'static, str>>) -> Self {
        Verdict { valid, description, decided: true, rule: None }
    }

    /// The description this verdict of `validator` carries: its own, or that
    /// of the rule that decided it; `None` for [`Validator::description`].
    ///
    /// A composite or wrapper reads its inner verdicts through this.
    pub fn into_description(self, validator: &(impl Validator + ?Sized)) -> Option<Cow<'static, str>> {
        self.description.or_else(|| Some(Cow::Owned(validator.rule_description(self.rule?)?)))
    }

    /// The description to report after this validation, for a validator that had
    /// not decided before.
    ///
    /// PHP: what `getDescription()` reports.
    pub fn describe(&self, validator: &(impl Validator + ?Sized)) -> String {
        match &self.description {
            Some(d) => d.clone().into_owned(),
            None => self.rule.and_then(|i| validator.rule_description(i)).unwrap_or_else(|| validator.description()),
        }
    }

    /// [`Verdict::describe`], or the error where the validator cannot be
    /// described.
    ///
    /// PHP: the error PHP's `getDescription()` throws.
    pub fn try_describe(&self, validator: &(impl Validator + ?Sized)) -> Result<String, Error> {
        match &self.description {
            Some(d) => Ok(d.clone().into_owned()),
            None => match self.rule.and_then(|i| validator.rule_description(i)) {
                Some(d) => Ok(d),
                None => validator.try_description(),
            },
        }
    }
}

/// A parameter validator: decides whether a value is valid, and describes the
/// rule it follows.
///
/// Implement [`Validator::description`] and [`Validator::is_valid`] for a
/// validator of request values ([`Value`]); the provided
/// [`Validator::validate`] then accepts exact values too, through their
/// request-model form. The validators of this crate implement
/// [`Validator::validate`] for both models.
///
/// PHP: `Utopia\Validator\Validator`.
pub trait Validator: Send + Sync {
    /// The human-readable rule, used verbatim in error messages.
    ///
    /// A composite can report another description after a validation; see
    /// [`Verdict`].
    ///
    /// PHP: `getDescription()` of a validator that has not validated anything.
    fn description(&self) -> String;

    /// Whether a request value is valid; `false` where the value cannot be
    /// validated (see [`Validator::validate`] for the error).
    ///
    /// PHP: `isValid($value)`.
    fn is_valid(&self, value: &Value) -> bool;

    /// Validates a request or exact value: the [`Verdict`], with the description
    /// to report afterwards, or the error that prevents validation.
    ///
    /// The provided implementation calls [`Validator::is_valid`]; exact values the
    /// request model cannot hold (binary strings, non-finite floats) are invalid
    /// there.
    ///
    /// PHP: `isValid($value)`, with what `getDescription()` reports afterwards, or
    /// the error PHP throws.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value {
            Input::Json(v) => self.is_valid(v),
            Input::Value(z) => z.try_to_json().is_some_and(|v| self.is_valid(&v)),
        }))
    }

    /// Validates a request value; on failure returns the description to report
    /// (that of the inner rule that failed, for composites).
    fn check(&self, value: &Value) -> Result<(), String> {
        match self.validate(Input::Json(value)) {
            Ok(v) if v.valid => Ok(()),
            Ok(v) => Err(v.describe(self)),
            Err(_) => Err(self.description()),
        }
    }

    /// Whether the validator expects an array value; `false` by default.
    ///
    /// PHP: `isArray()`.
    fn is_array(&self) -> bool {
        false
    }

    /// The type of value the validator expects; [`Type::Mixed`] by default.
    ///
    /// PHP: `getType()`.
    fn kind(&self) -> Type {
        Type::Mixed
    }

    /// Whether `null` is an acceptable value (used for "null means omitted" handling).
    fn accepts_null(&self) -> bool {
        self.is_valid(&Value::Null)
    }

    /// The description, or the error where the validator cannot be described (a
    /// composite without rules, a strict [`WhiteList`] holding an object).
    /// [`Validator::description`] describes those as best it can.
    ///
    /// PHP: `getDescription()`, with the error PHP throws.
    fn try_description(&self) -> Result<String, Error> {
        Ok(self.description())
    }

    /// The description of rule `index` of a composite, which a [`Verdict`]
    /// of this validator names as the rule that decided; `None` for a
    /// validator without rules.
    ///
    /// PHP: `$this->failedRule->getDescription()`.
    fn rule_description(&self, index: usize) -> Option<String> {
        let _ = index;
        None
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
            fn rule_description(&self, index: usize) -> Option<String> {
                (**self).rule_description(index)
            }
        }
    )*};
}

forward_validator!(&V, Box<V>, Arc<V>);

/// Formats an integer with commas between thousands and no decimals: `3600`
/// is `3,600`.
///
/// PHP: `number_format($n)`.
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
