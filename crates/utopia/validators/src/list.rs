use std::borrow::Cow;

use php_std::string::strtolower;
use php_std::zval::Zval;

use crate::input::{View, loose_str_eq};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// `Utopia\Validator\WhiteList`: the value is one of `list`.
///
/// Loose (the default): the list and the value are compared as lower-case
/// strings, with `==`. Strict: the value must be identical (`===`) to an
/// entry.
#[derive(Debug, Clone)]
pub struct WhiteList {
    list: Vec<Zval>,
    strict: bool,
    kind: Type,
}

impl WhiteList {
    /// Loose (case-insensitive) white list of strings.
    pub fn new(list: &[&str]) -> Self {
        let list = list.iter().map(|s| Zval::String(strtolower(s.as_bytes()).into_owned())).collect();
        Self { list, strict: false, kind: Type::String }
    }

    /// Strict white list of strings.
    pub fn strict(list: &[&str]) -> Self {
        Self {
            list: list.iter().map(|s| Zval::String(s.as_bytes().to_vec())).collect(),
            strict: true,
            kind: Type::String,
        }
    }

    /// `new WhiteList($list, $strict, $type)`. A loose list is converted to
    /// lower-case strings, which fails for a `stdClass` entry.
    pub fn with(list: Vec<Zval>, strict: bool, kind: Type) -> Result<Self, Error> {
        let list = if strict {
            list
        } else {
            list.iter()
                .map(|v| Ok(Zval::String(strtolower(&Input::Zval(v).to_bytes()?).into_owned())))
                .collect::<Result<_, Error>>()?
        };
        Ok(Self { list, strict, kind })
    }

    /// `getList()`.
    pub fn list(&self) -> &[Zval] {
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
    fn description(&self) -> String {
        let items: Vec<String> = self
            .list
            .iter()
            .map(|v| String::from_utf8_lossy(&Input::Zval(v).to_bytes().unwrap_or_default()).into_owned())
            .collect();
        format!("Value must be one of ({})", items.join(", "))
    }

    /// A strict list holding a `stdClass` cannot be described.
    fn try_description(&self) -> Result<String, Error> {
        for v in &self.list {
            Input::Zval(v).to_bytes()?;
        }
        Ok(self.description())
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if value.is_array() {
            return Ok(Verdict::INVALID);
        }
        if self.strict {
            return Ok(Verdict::of(self.list.iter().any(|item| identical(value, Input::Zval(item)))));
        }
        let value = value.to_bytes()?;
        let value = strtolower(&value);
        Ok(Verdict::of(self.list.iter().any(|item| matches!(item, Zval::String(s) if loose_str_eq(&value, s)))))
    }

    fn kind(&self) -> Type {
        self.kind
    }
}

/// `Utopia\Validator\ArrayList`: an array of at most `length` elements
/// (0 = any number), each valid for `inner`.
#[derive(Debug, Clone)]
pub struct ArrayList<V> {
    pub inner: V,
    pub length: usize,
}

impl<V: Validator> ArrayList<V> {
    pub fn new(inner: V, length: usize) -> Self {
        Self { inner, length }
    }

    /// `getValidator()`.
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
                description: inner.description.map(|d| Cow::Owned(self.describe(&d))),
                decided: inner.decided,
                rule: None,
            },
        }
    }
}

impl<V: Validator> Validator for ArrayList<V> {
    fn description(&self) -> String {
        self.describe(&self.inner.description())
    }

    fn try_description(&self) -> Result<String, Error> {
        Ok(self.describe(&self.inner.try_description()?))
    }

    is_valid_via_validate!();

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

    fn is_array(&self) -> bool {
        true
    }

    fn kind(&self) -> Type {
        self.inner.kind()
    }
}

/// `Utopia\Validator\Assoc`: an array that is not a non-empty list, whose
/// `json_encode()` fits `length` bytes.
#[derive(Debug, Clone, Copy)]
pub struct Assoc {
    pub length: usize,
}

impl Default for Assoc {
    fn default() -> Self {
        Self { length: 65535 }
    }
}

impl Validator for Assoc {
    fn description(&self) -> String {
        "Value must be a valid object.".to_owned()
    }

    is_valid_via_validate!();

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

    fn kind(&self) -> Type {
        Type::Object
    }
}

/// `Utopia\Validator\Nullable`: `null`, or a value valid for the inner validator.
#[derive(Debug, Clone)]
pub struct Nullable<V>(pub V);

impl<V: Validator> Nullable<V> {
    /// `getValidator()`.
    pub fn validator(&self) -> &V {
        &self.0
    }
}

impl<V: Validator> Validator for Nullable<V> {
    fn description(&self) -> String {
        format!("{} or null", self.0.description())
    }

    fn try_description(&self) -> Result<String, Error> {
        Ok(format!("{} or null", self.0.try_description()?))
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        if value.is_null() {
            return Ok(Verdict::VALID);
        }
        let v = self.0.validate(value)?;
        Ok(Verdict { description: v.description.map(|d| Cow::Owned(format!("{d} or null"))), ..v })
    }

    fn kind(&self) -> Type {
        self.0.kind()
    }
}
