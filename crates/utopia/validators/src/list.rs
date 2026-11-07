//! Lists, maps, allowed values and optional values.

use std::borrow::Cow;

use php_std::string::strtolower;
use php_std::{Str, Value};

use crate::input::{View, loose_str_eq};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// A value that is one of a list of allowed values.
///
/// Loose (the default): the list and the value are compared as lower-case
/// strings, numeric strings by value, so `"Admin"` matches `admin` and `1`
/// matches `"1"`. Strict: the value must be identical to an entry, type
/// included. An array is never allowed.
///
/// PHP: `Utopia\Validator\WhiteList`; loose compares with `==`, strict with
/// `===`.
#[derive(Debug, Clone)]
pub struct WhiteList {
    list: Vec<Value>,
    strict: bool,
    kind: Type,
}

impl WhiteList {
    /// A loose (case-insensitive) list of allowed strings.
    pub fn new(list: &[&str]) -> Self {
        let list = list.iter().map(|s| Value::Str(Str::from(strtolower(s.as_bytes()).into_owned()))).collect();
        Self { list, strict: false, kind: Type::String }
    }

    /// A strict list of allowed strings.
    pub fn strict(list: &[&str]) -> Self {
        Self { list: list.iter().map(|s| Value::Str(Str::from(*s))).collect(), strict: true, kind: Type::String }
    }

    /// A list of any allowed values, strict or loose, expecting values of type
    /// `kind`.
    ///
    /// A loose list is converted to lower-case strings, which fails with
    /// [`Error::Engine`] for an object entry.
    ///
    /// PHP: `new WhiteList($list, $strict, $type)`.
    pub fn with(list: Vec<Value>, strict: bool, kind: Type) -> Result<Self, Error> {
        let list = if strict {
            list
        } else {
            list.iter()
                .map(|v| Ok(Value::Str(Str::from(strtolower(&Input::Value(v).to_bytes()?).into_owned()))))
                .collect::<Result<_, Error>>()?
        };
        Ok(Self { list, strict, kind })
    }

    /// The allowed values; lower-case strings for a loose list.
    ///
    /// PHP: `getList()`.
    pub fn list(&self) -> &[Value] {
        &self.list
    }
}

/// `$a === $b`. Objects are never identical: the list owns its own.
fn identical(a: Input<'_>, b: Input<'_>) -> bool {
    match (a.view(), b.view()) {
        (View::Null, View::Null) => true,
        (View::Bool(x), View::Bool(y)) => x == y,
        (View::Int(x), View::Int(y)) => x == y,
        (View::Float(x), View::Float(y)) => x == y,
        (View::Str(x), View::Str(y)) => x == y,
        (View::Array(x), View::Array(y)) => {
            x.len() == y.len() && x.zip(y).all(|((kx, vx), (ky, vy))| kx == ky && identical(vx, vy))
        }
        _ => false,
    }
}

impl Validator for WhiteList {
    /// Lists the allowed values: `Value must be one of (a, b, c)`.
    fn description(&self) -> String {
        let items: Vec<String> = self
            .list
            .iter()
            .map(|v| String::from_utf8_lossy(&Input::Value(v).to_bytes().unwrap_or_default()).into_owned())
            .collect();
        format!("Value must be one of ({})", items.join(", "))
    }

    /// The description, or [`Error::Engine`] for a strict list holding an object, which cannot be described.
    fn try_description(&self) -> Result<String, Error> {
        for v in &self.list {
            Input::Value(v).to_bytes()?;
        }
        Ok(self.description())
    }

    is_valid_via_validate!();

    /// Valid for a value in the list; an array is never valid. A loose comparison
    /// fails with [`Error::Engine`] for an object value.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if value.is_array() {
            return Ok(Verdict::INVALID);
        }
        if self.strict {
            return Ok(Verdict::of(self.list.iter().any(|item| identical(value, Input::Value(item)))));
        }
        let value = value.to_bytes()?;
        let value = strtolower(&value);
        Ok(Verdict::of(self.list.iter().any(|item| matches!(item, Value::Str(s) if loose_str_eq(&value, s)))))
    }

    /// The type given to [`WhiteList::with`]; [`Type::String`] otherwise.
    fn kind(&self) -> Type {
        self.kind
    }
}

/// An array of at most `length` elements (0 for any number), each valid for
/// `inner`.
///
/// PHP: `Utopia\Validator\ArrayList`.
#[derive(Debug, Clone)]
pub struct ArrayList<V> {
    /// The validator every element must pass.
    pub inner: V,
    /// The most elements allowed; 0 for any number.
    pub length: usize,
}

impl<V: Validator> ArrayList<V> {
    /// A list of at most `length` elements (0 for any number), each valid for `inner`.
    pub fn new(inner: V, length: usize) -> Self {
        Self { inner, length }
    }

    /// The validator every element must pass.
    ///
    /// PHP: `getValidator()`.
    pub fn validator(&self) -> &V {
        &self.inner
    }

    fn describe(&self, inner: &str) -> String {
        let mut msg = String::from("Value must a valid array");
        if self.length > 0 {
            msg.push_str(&format!(" no longer than {} items", self.length));
        }
        if !inner.is_empty() && inner != "0" {
            msg.push_str(" and ");
            msg.push_str(inner);
        }
        msg
    }

    /// The verdict after the inner validator's: the description wraps the inner one.
    fn verdict(&self, valid: bool, inner: Option<Verdict>) -> Verdict {
        match inner {
            None => Verdict::of(valid),
            Some(inner) => Verdict {
                valid,
                decided: inner.decided,
                description: inner.into_description(&self.inner).map(|d| Cow::Owned(self.describe(&d))),
                rule: None,
            },
        }
    }
}

impl<V: Validator> Validator for ArrayList<V> {
    /// `Value must a valid array`, followed by the length limit and the element validator's description.
    fn description(&self) -> String {
        self.describe(&self.inner.description())
    }

    /// The description, or the error the element validator returns describing itself.
    fn try_description(&self) -> Result<String, Error> {
        Ok(self.describe(&self.inner.try_description()?))
    }

    is_valid_via_validate!();

    /// Valid for an array (a list or a map) within the length limit whose
    /// elements all pass. It stops at the first invalid element, and the
    /// description wraps the element validator's.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let View::Array(entries) = value.view() else {
            return Ok(Verdict::INVALID);
        };
        let count = entries.len();
        // The inner validator's state is what its last deciding validation
        // left or, when none decided, what its last validation describes.
        let mut decided: Option<Verdict> = None;
        let mut last: Option<Verdict> = None;
        for (_, element) in entries {
            let v = self.inner.validate(element)?;
            let valid = v.valid;
            if v.decided {
                decided = Some(v.clone());
            }
            last = Some(v);
            if !valid {
                break;
            }
        }
        let valid = last.as_ref().is_none_or(|v| v.valid) && (self.length == 0 || count <= self.length);
        Ok(self.verdict(valid, decided.or(last)))
    }

    /// Always `true`: the validator expects an array.
    fn is_array(&self) -> bool {
        true
    }

    /// The element validator's type.
    fn kind(&self) -> Type {
        self.inner.kind()
    }
}

/// A map: an array that is not a non-empty list, of at most `length` bytes
/// when encoded as JSON.
///
/// PHP: `Utopia\Validator\Assoc`.
#[derive(Debug, Clone, Copy)]
pub struct Assoc {
    /// The most bytes the value may take encoded as JSON.
    pub length: usize,
}

impl Default for Assoc {
    /// A limit of 65535 bytes.
    fn default() -> Self {
        Self { length: 65535 }
    }
}

impl Validator for Assoc {
    /// Returns "Value must be a valid object.".
    fn description(&self) -> String {
        "Value must be a valid object.".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for an array that is empty or not a list, within the size limit. A
    /// value that cannot be encoded counts as 0 bytes.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if !value.is_array() {
            return Ok(Verdict::INVALID);
        }
        // `strlen(json_encode($value))`: a failed encoding (`false`) is 0 bytes.
        let size = value.json_encode().map_or(0, |json| json.len());
        if size > self.length {
            return Ok(Verdict::INVALID);
        }
        // `array_keys($value) !== range(0, count($value) - 1)`; `range(0, -1)` is `[0, -1]`.
        let empty = matches!(value.view(), View::Array(e) if e.len() == 0);
        Ok(Verdict::of(empty || !value.is_list()))
    }

    /// Expects an object ([`Type::Object`]).
    fn kind(&self) -> Type {
        Type::Object
    }
}

/// Null, or a value the inner validator accepts.
///
/// PHP: `Utopia\Validator\Nullable`.
#[derive(Debug, Clone)]
pub struct Nullable<V>(
    /// The inner validator.
    pub V,
);

impl<V: Validator> Nullable<V> {
    /// The inner validator.
    ///
    /// PHP: `getValidator()`.
    pub fn validator(&self) -> &V {
        &self.0
    }
}

impl<V: Validator> Validator for Nullable<V> {
    /// The inner validator's description, followed by ` or null`.
    fn description(&self) -> String {
        format!("{} or null", self.0.description())
    }

    /// The description, or the error the inner validator returns describing itself.
    fn try_description(&self) -> Result<String, Error> {
        Ok(format!("{} or null", self.0.try_description()?))
    }

    is_valid_via_validate!();

    /// Valid for null; anything else is decided by the inner validator, whose description gets ` or null` appended.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if value.is_null() {
            return Ok(Verdict::VALID);
        }
        let v = self.0.validate(value)?;
        let (valid, decided, rule) = (v.valid, v.decided, v.rule);
        let description = v.into_description(&self.0).map(|d| Cow::Owned(format!("{d} or null")));
        Ok(Verdict { valid, description, decided, rule })
    }

    /// The inner validator's type.
    fn kind(&self) -> Type {
        self.0.kind()
    }
}
