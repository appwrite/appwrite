//! [`Operator`]: an atomic update of one attribute (increment, array
//! append, string concat, ...), given as an attribute value of an update.
//!
//! ```
//! use utopia_database::{Operator, Value};
//! use utopia_database::operator::OperatorMethod;
//!
//! let mut op = Operator::increment(5, Some(100.into()));
//! op.set_attribute("score");
//! assert_eq!(op.to_string().unwrap(), r#"{"method":"increment","attribute":"score","values":[5,100]}"#);
//! assert_eq!(*Operator::parse(r#"{"method":"toggle"}"#).unwrap().method(), OperatorMethod::Toggle);
//! assert!(Operator::divide(0, None).is_err());
//! assert!(Operator::is_operator(&Value::from(Operator::toggle())));
//! ```
//!
//! PHP: `Utopia\Database\Operator`. Checking an operator against an
//! attribute is [`crate::validator`]'s (`Validator\Operator`). PHP's
//! `__clone` (deep copy of nested operators) is Rust's `Clone`.

use crate::error::{Error, Result};
use crate::query::{Values, json_value};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

/// An integer or a float (a PHP `int|float` parameter).
#[derive(Clone, Copy, PartialEq, Debug)]
pub enum Number {
    /// An `int`.
    Int(i64),
    /// A `float`.
    Float(f64),
}

impl From<i64> for Number {
    fn from(i: i64) -> Self {
        Number::Int(i)
    }
}

impl From<i32> for Number {
    fn from(i: i32) -> Self {
        Number::Int(i.into())
    }
}

impl From<f64> for Number {
    fn from(f: f64) -> Self {
        Number::Float(f)
    }
}

impl From<Number> for Value {
    fn from(n: Number) -> Self {
        match n {
            Number::Int(i) => Value::Int(i),
            Number::Float(f) => Value::Float(f),
        }
    }
}

impl Number {
    /// PHP `$n == 0` (`0`, `0.0` and `-0.0`; not NaN).
    fn is_zero(self) -> bool {
        match self {
            Number::Int(i) => i == 0,
            Number::Float(f) => f == 0.0,
        }
    }
}

/// An operator method (`Operator::TYPE_*`).
#[derive(Clone, PartialEq, Eq, Hash, Debug)]
pub enum OperatorMethod {
    /// `increment` (`TYPE_INCREMENT`)
    Increment,
    /// `decrement` (`TYPE_DECREMENT`)
    Decrement,
    /// `modulo` (`TYPE_MODULO`)
    Modulo,
    /// `power` (`TYPE_POWER`)
    Power,
    /// `multiply` (`TYPE_MULTIPLY`)
    Multiply,
    /// `divide` (`TYPE_DIVIDE`)
    Divide,
    /// `arrayAppend` (`TYPE_ARRAY_APPEND`)
    ArrayAppend,
    /// `arrayPrepend` (`TYPE_ARRAY_PREPEND`)
    ArrayPrepend,
    /// `arrayInsert` (`TYPE_ARRAY_INSERT`)
    ArrayInsert,
    /// `arrayRemove` (`TYPE_ARRAY_REMOVE`)
    ArrayRemove,
    /// `arrayUnique` (`TYPE_ARRAY_UNIQUE`)
    ArrayUnique,
    /// `arrayIntersect` (`TYPE_ARRAY_INTERSECT`)
    ArrayIntersect,
    /// `arrayDiff` (`TYPE_ARRAY_DIFF`)
    ArrayDiff,
    /// `arrayFilter` (`TYPE_ARRAY_FILTER`)
    ArrayFilter,
    /// `stringConcat` (`TYPE_STRING_CONCAT`)
    StringConcat,
    /// `stringReplace` (`TYPE_STRING_REPLACE`)
    StringReplace,
    /// `toggle` (`TYPE_TOGGLE`)
    Toggle,
    /// `dateAddDays` (`TYPE_DATE_ADD_DAYS`)
    DateAddDays,
    /// `dateSubDays` (`TYPE_DATE_SUB_DAYS`)
    DateSubDays,
    /// `dateSetNow` (`TYPE_DATE_SET_NOW`)
    DateSetNow,
    /// A method string PHP holds without knowing it.
    Other(Box<str>),
}

/// Every known method, in the order of PHP's `Operator::TYPES`.
pub const TYPES: [OperatorMethod; 20] = [
    OperatorMethod::Increment,
    OperatorMethod::Decrement,
    OperatorMethod::Multiply,
    OperatorMethod::Divide,
    OperatorMethod::Modulo,
    OperatorMethod::Power,
    OperatorMethod::StringConcat,
    OperatorMethod::StringReplace,
    OperatorMethod::ArrayAppend,
    OperatorMethod::ArrayPrepend,
    OperatorMethod::ArrayInsert,
    OperatorMethod::ArrayRemove,
    OperatorMethod::ArrayUnique,
    OperatorMethod::ArrayIntersect,
    OperatorMethod::ArrayDiff,
    OperatorMethod::ArrayFilter,
    OperatorMethod::Toggle,
    OperatorMethod::DateAddDays,
    OperatorMethod::DateSubDays,
    OperatorMethod::DateSetNow,
];

/// The most values one array operator (append, prepend, intersect, diff)
/// may carry.
///
/// PHP: `Operator::MAX_ARRAY_OPERATOR_SIZE`.
pub const MAX_ARRAY_OPERATOR_SIZE: usize = 10000;

/// The conditions `arrayFilter` accepts.
///
/// PHP: `Operator::ARRAY_FILTER_CONDITIONS`.
pub const ARRAY_FILTER_CONDITIONS: [&str; 8] =
    ["equal", "notEqual", "greaterThan", "greaterThanEqual", "lessThan", "lessThanEqual", "isNull", "isNotNull"];

impl OperatorMethod {
    /// The method string (`TYPE_*`'s value).
    pub fn as_str(&self) -> &str {
        match self {
            OperatorMethod::Increment => "increment",
            OperatorMethod::Decrement => "decrement",
            OperatorMethod::Modulo => "modulo",
            OperatorMethod::Power => "power",
            OperatorMethod::Multiply => "multiply",
            OperatorMethod::Divide => "divide",
            OperatorMethod::ArrayAppend => "arrayAppend",
            OperatorMethod::ArrayPrepend => "arrayPrepend",
            OperatorMethod::ArrayInsert => "arrayInsert",
            OperatorMethod::ArrayRemove => "arrayRemove",
            OperatorMethod::ArrayUnique => "arrayUnique",
            OperatorMethod::ArrayIntersect => "arrayIntersect",
            OperatorMethod::ArrayDiff => "arrayDiff",
            OperatorMethod::ArrayFilter => "arrayFilter",
            OperatorMethod::StringConcat => "stringConcat",
            OperatorMethod::StringReplace => "stringReplace",
            OperatorMethod::Toggle => "toggle",
            OperatorMethod::DateAddDays => "dateAddDays",
            OperatorMethod::DateSubDays => "dateSubDays",
            OperatorMethod::DateSetNow => "dateSetNow",
            OperatorMethod::Other(s) => s,
        }
    }

    /// The method a string names; unknown strings are [`OperatorMethod::Other`].
    pub fn from_name(name: &str) -> OperatorMethod {
        TYPES.iter().find(|m| m.as_str() == name).cloned().unwrap_or_else(|| OperatorMethod::Other(name.into()))
    }

    /// Whether the method is known (not [`OperatorMethod::Other`]).
    ///
    /// PHP: `Operator::isMethod()`.
    pub fn is_known(&self) -> bool {
        !matches!(self, OperatorMethod::Other(_))
    }

    /// `increment`, `decrement`, `multiply`, `divide`, `modulo`, `power`.
    ///
    /// PHP: `in_array($method, Operator::NUMERIC_TYPES)`.
    pub fn is_numeric(&self) -> bool {
        use OperatorMethod::*;
        matches!(self, Increment | Decrement | Multiply | Divide | Modulo | Power)
    }

    /// The `array*` methods.
    ///
    /// PHP: `in_array($method, Operator::ARRAY_TYPES)`.
    pub fn is_array(&self) -> bool {
        use OperatorMethod::*;
        matches!(
            self,
            ArrayAppend
                | ArrayPrepend
                | ArrayInsert
                | ArrayRemove
                | ArrayUnique
                | ArrayIntersect
                | ArrayDiff
                | ArrayFilter
        )
    }

    /// `stringConcat`, `stringReplace`.
    ///
    /// PHP: `in_array($method, Operator::STRING_TYPES)`.
    pub fn is_string(&self) -> bool {
        matches!(self, OperatorMethod::StringConcat | OperatorMethod::StringReplace)
    }

    /// `toggle`.
    ///
    /// PHP: `in_array($method, Operator::BOOLEAN_TYPES)`.
    pub fn is_boolean(&self) -> bool {
        matches!(self, OperatorMethod::Toggle)
    }

    /// `dateAddDays`, `dateSubDays`, `dateSetNow`.
    ///
    /// PHP: `in_array($method, Operator::DATE_TYPES)`.
    pub fn is_date(&self) -> bool {
        matches!(self, OperatorMethod::DateAddDays | OperatorMethod::DateSubDays | OperatorMethod::DateSetNow)
    }
}

impl std::fmt::Display for OperatorMethod {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.as_str())
    }
}

/// One operator.
///
/// PHP: `Utopia\Database\Operator`.
#[derive(Clone, PartialEq, Debug)]
pub struct Operator {
    method: OperatorMethod,
    attribute: String,
    values: Values,
}

/// The operators and plain values of an update's data.
///
/// PHP: the array `Operator::extractOperators()` returns.
#[derive(Clone, PartialEq, Debug, Default)]
pub struct Extracted {
    /// The operators by key, in order (`operators`).
    pub operators: Vec<(ArrayKey, Operator)>,
    /// Every other value by key, in order (`updates`).
    pub updates: Array,
}

impl Operator {
    /// An operator of `method` on `attribute` with `values`.
    ///
    /// PHP: `new Operator($method, $attribute, $values)` with a list.
    pub fn new(method: OperatorMethod, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self { method, attribute: attribute.into(), values: Values::list(values) }
    }

    /// [`Operator::new`] with any PHP array of values, keys kept.
    ///
    /// PHP: `new Operator($method, $attribute, $values)`.
    pub fn from_array(method: OperatorMethod, attribute: impl Into<String>, values: Array) -> Self {
        Self { method, attribute: attribute.into(), values: Values::array(values) }
    }

    /// The method.
    ///
    /// PHP: `Operator::getMethod()`.
    pub fn method(&self) -> &OperatorMethod {
        &self.method
    }

    /// The attribute (`""` until the update's key sets it).
    ///
    /// PHP: `Operator::getAttribute()`.
    pub fn attribute(&self) -> &str {
        &self.attribute
    }

    /// The values, in order (keys dropped; see [`Operator::values_array`]).
    ///
    /// PHP: `array_values($operator->getValues())`.
    pub fn values(&self) -> &[Value] {
        self.values.as_slice()
    }

    /// The values as the PHP array they are, keys included.
    ///
    /// PHP: `Operator::getValues()`.
    pub fn values_array(&self) -> Array {
        self.values.to_array()
    }

    /// The value at key `0`; `None` when there is none or it is `null`.
    ///
    /// PHP: `Operator::getValue($default)`, `$this->values[0] ?? $default`.
    pub fn value(&self) -> Option<&Value> {
        self.values.first()
    }

    /// Sets the method.
    ///
    /// PHP: `Operator::setMethod()`.
    pub fn set_method(&mut self, method: OperatorMethod) -> &mut Self {
        self.method = method;
        self
    }

    /// Sets the attribute.
    ///
    /// PHP: `Operator::setAttribute()`.
    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    /// Sets the values to a list.
    ///
    /// PHP: `Operator::setValues()`.
    pub fn set_values(&mut self, values: Vec<Value>) -> &mut Self {
        self.values = Values::list(values);
        self
    }

    /// Sets the values to any PHP array, keys kept.
    ///
    /// PHP: `Operator::setValues()`.
    pub fn set_values_array(&mut self, values: Array) -> &mut Self {
        self.values = Values::array(values);
        self
    }

    /// Sets the only value.
    ///
    /// PHP: `Operator::setValue()`.
    pub fn set_value(&mut self, value: Value) -> &mut Self {
        self.values = Values::list(vec![value]);
        self
    }

    /// Whether `value` names a known method.
    ///
    /// PHP: `Operator::isMethod()`.
    pub fn is_method(value: &str) -> bool {
        OperatorMethod::from_name(value).is_known()
    }

    /// PHP: `Operator::isNumericOperation()`.
    pub fn is_numeric_operation(&self) -> bool {
        self.method.is_numeric()
    }

    /// PHP: `Operator::isArrayOperation()`.
    pub fn is_array_operation(&self) -> bool {
        self.method.is_array()
    }

    /// PHP: `Operator::isStringOperation()`.
    pub fn is_string_operation(&self) -> bool {
        self.method.is_string()
    }

    /// PHP: `Operator::isBooleanOperation()`.
    pub fn is_boolean_operation(&self) -> bool {
        self.method.is_boolean()
    }

    /// PHP: `Operator::isDateOperation()`.
    pub fn is_date_operation(&self) -> bool {
        self.method.is_date()
    }

    /// Parses an operator from its JSON (what [`Operator::to_string`] writes).
    ///
    /// Fails with a [`crate::Kind::Operator`] error: `Invalid operator:
    /// <JSON error>`, `Invalid operator. Must be an array, got <type>`, or
    /// one of [`Operator::parse_operator`]'s.
    ///
    /// PHP: `Operator::parse()`.
    pub fn parse(operator: impl AsRef<[u8]>) -> Result<Operator> {
        let value = php_std::json::decode(
            operator.as_ref(),
            Some(true),
            php_std::json::DEFAULT_DEPTH,
            php_std::json::Flags::NONE,
        )
        .map_err(|e| Error::operator(format!("Invalid operator: {}", e.message())))?;
        match crate::value::from_plain(value) {
            Value::Array(a) => Self::parse_operator(&a),
            other => Err(Error::operator(format!("Invalid operator. Must be an array, got {}", other.type_name()))),
        }
    }

    /// Builds an operator from its array form (`method`, `attribute`,
    /// `values`).
    ///
    /// PHP: `Operator::parseOperator()`; errors ([`crate::Kind::Operator`])
    /// in PHP's order: the method must be a string, then a known method,
    /// the attribute a string, the values an array.
    pub fn parse_operator(operator: &Array) -> Result<Operator> {
        let present = |key: &str| operator.get_str(key).filter(|v| !matches!(v, Value::Null));
        let method = match present("method") {
            None => Str::from(""),
            Some(Value::Str(s)) => s.clone(),
            Some(other) => {
                return Err(Error::operator(format!(
                    "Invalid operator method. Must be a string, got {}",
                    other.type_name()
                )));
            }
        };
        let method_text = String::from_utf8_lossy(method.as_bytes());
        let method = match method.as_str() {
            Some(name) => OperatorMethod::from_name(name),
            None => OperatorMethod::Other(method_text.as_ref().into()),
        };
        if !method.is_known() {
            return Err(Error::operator(format!("Invalid operator method: {method_text}")));
        }
        let attribute = match present("attribute") {
            None => String::new(),
            Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Some(other) => {
                return Err(Error::operator(format!(
                    "Invalid operator attribute. Must be a string, got {}",
                    other.type_name()
                )));
            }
        };
        let values = match present("values") {
            None => Array::new(),
            Some(Value::Array(a)) => a.clone(),
            Some(other) => {
                return Err(Error::operator(format!(
                    "Invalid operator values. Must be an array, got {}",
                    other.type_name()
                )));
            }
        };
        Ok(Operator::from_array(method, attribute, values))
    }

    /// Parses each operator of a list with [`Operator::parse`].
    ///
    /// PHP: `Operator::parseOperators()`.
    pub fn parse_operators<S: AsRef<[u8]>>(operators: &[S]) -> Result<Vec<Operator>> {
        operators.iter().map(Operator::parse).collect()
    }

    /// The array form: `method`, `attribute` and `values` (keys kept).
    ///
    /// PHP: `Operator::toArray()`.
    pub fn to_array(&self) -> Array {
        let mut out = Array::new();
        out.set(ArrayKey::from("method"), Value::Str(Str::from(self.method.as_str())));
        out.set(ArrayKey::from("attribute"), Value::Str(Str::from(self.attribute.as_str())));
        out.set(ArrayKey::from("values"), Value::Array(self.values.to_array()));
        out
    }

    /// The JSON of [`Operator::to_array`], as PHP's `json_encode` writes it.
    ///
    /// Fails with a [`crate::Kind::Operator`] error `Invalid Json: <JSON
    /// error>` when a value cannot be encoded.
    ///
    /// PHP: `Operator::toString()`.
    pub fn to_string(&self) -> Result<String> {
        php_std::json::encode(
            &json_value(&Value::Array(self.to_array())),
            php_std::json::Flags::NONE,
            php_std::json::DEFAULT_DEPTH,
        )
        .map_err(|e| Error::operator(format!("Invalid Json: {}", e.message())))
    }

    /// Adds `value`, up to `max`.
    ///
    /// PHP: `Operator::increment()` (`increment()` there is `increment(1)`).
    pub fn increment(value: impl Into<Number>, max: Option<Number>) -> Self {
        Self::bounded(OperatorMethod::Increment, value.into(), max)
    }

    /// Subtracts `value`, down to `min`.
    ///
    /// PHP: `Operator::decrement()` (`decrement()` there is `decrement(1)`).
    pub fn decrement(value: impl Into<Number>, min: Option<Number>) -> Self {
        Self::bounded(OperatorMethod::Decrement, value.into(), min)
    }

    /// Multiplies by `factor`, up to `max`.
    ///
    /// PHP: `Operator::multiply()`.
    pub fn multiply(factor: impl Into<Number>, max: Option<Number>) -> Self {
        Self::bounded(OperatorMethod::Multiply, factor.into(), max)
    }

    /// Divides by `divisor`, down to `min`. A zero divisor fails with a
    /// [`crate::Kind::Operator`] error `Division by zero is not allowed`.
    ///
    /// PHP: `Operator::divide()`.
    pub fn divide(divisor: impl Into<Number>, min: Option<Number>) -> Result<Self> {
        let divisor = divisor.into();
        if divisor.is_zero() {
            return Err(Error::operator("Division by zero is not allowed"));
        }
        Ok(Self::bounded(OperatorMethod::Divide, divisor, min))
    }

    /// The remainder of a division by `divisor`. A zero divisor fails with
    /// a [`crate::Kind::Operator`] error `Modulo by zero is not allowed`.
    ///
    /// PHP: `Operator::modulo()`.
    pub fn modulo(divisor: impl Into<Number>) -> Result<Self> {
        let divisor = divisor.into();
        if divisor.is_zero() {
            return Err(Error::operator("Modulo by zero is not allowed"));
        }
        Ok(Self::new(OperatorMethod::Modulo, "", vec![divisor.into()]))
    }

    /// Raises to `exponent`, up to `max`.
    ///
    /// PHP: `Operator::power()`.
    pub fn power(exponent: impl Into<Number>, max: Option<Number>) -> Self {
        Self::bounded(OperatorMethod::Power, exponent.into(), max)
    }

    fn bounded(method: OperatorMethod, value: Number, bound: Option<Number>) -> Self {
        let mut values = vec![value.into()];
        values.extend(bound.map(Value::from));
        Self::new(method, "", values)
    }

    /// Appends `values` to the array.
    ///
    /// PHP: `Operator::arrayAppend()`.
    pub fn array_append<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorMethod::ArrayAppend, "", values.into_iter().map(Into::into).collect())
    }

    /// Prepends `values` to the array.
    ///
    /// PHP: `Operator::arrayPrepend()`.
    pub fn array_prepend<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorMethod::ArrayPrepend, "", values.into_iter().map(Into::into).collect())
    }

    /// Inserts `value` at `index`.
    ///
    /// PHP: `Operator::arrayInsert()`.
    pub fn array_insert(index: i64, value: impl Into<Value>) -> Self {
        Self::new(OperatorMethod::ArrayInsert, "", vec![Value::Int(index), value.into()])
    }

    /// Removes every element equal to `value`.
    ///
    /// PHP: `Operator::arrayRemove()`.
    pub fn array_remove(value: impl Into<Value>) -> Self {
        Self::new(OperatorMethod::ArrayRemove, "", vec![value.into()])
    }

    /// Removes duplicates from the array.
    ///
    /// PHP: `Operator::arrayUnique()`.
    pub fn array_unique() -> Self {
        Self::new(OperatorMethod::ArrayUnique, "", Vec::new())
    }

    /// Keeps the elements also in `values`.
    ///
    /// PHP: `Operator::arrayIntersect()`.
    pub fn array_intersect<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorMethod::ArrayIntersect, "", values.into_iter().map(Into::into).collect())
    }

    /// Removes the elements in `values`.
    ///
    /// PHP: `Operator::arrayDiff()`.
    pub fn array_diff<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorMethod::ArrayDiff, "", values.into_iter().map(Into::into).collect())
    }

    /// Keeps the elements meeting `condition` (one of
    /// [`ARRAY_FILTER_CONDITIONS`], checked by the validator) against
    /// `value` (`null` for the null checks).
    ///
    /// PHP: `Operator::arrayFilter()`.
    pub fn array_filter(condition: &str, value: impl Into<Value>) -> Self {
        Self::new(OperatorMethod::ArrayFilter, "", vec![Value::from(condition), value.into()])
    }

    /// Appends `value` to the string.
    ///
    /// PHP: `Operator::stringConcat()`.
    pub fn string_concat(value: impl Into<Value>) -> Self {
        Self::new(OperatorMethod::StringConcat, "", vec![value.into()])
    }

    /// Replaces `search` with `replace` in the string.
    ///
    /// PHP: `Operator::stringReplace()`.
    pub fn string_replace(search: &str, replace: &str) -> Self {
        Self::new(OperatorMethod::StringReplace, "", vec![Value::from(search), Value::from(replace)])
    }

    /// Negates the boolean.
    ///
    /// PHP: `Operator::toggle()`.
    pub fn toggle() -> Self {
        Self::new(OperatorMethod::Toggle, "", Vec::new())
    }

    /// Adds `days` days to the date (negative subtracts).
    ///
    /// PHP: `Operator::dateAddDays()`.
    pub fn date_add_days(days: i64) -> Self {
        Self::new(OperatorMethod::DateAddDays, "", vec![Value::Int(days)])
    }

    /// Subtracts `days` days from the date.
    ///
    /// PHP: `Operator::dateSubDays()`.
    pub fn date_sub_days(days: i64) -> Self {
        Self::new(OperatorMethod::DateSubDays, "", vec![Value::Int(days)])
    }

    /// Sets the date to now.
    ///
    /// PHP: `Operator::dateSetNow()`.
    pub fn date_set_now() -> Self {
        Self::new(OperatorMethod::DateSetNow, "", Vec::new())
    }

    /// Whether `value` is an operator.
    ///
    /// PHP: `Operator::isOperator()`.
    pub fn is_operator(value: &Value) -> bool {
        matches!(value, Value::Ext(Ext::Operator(_)))
    }

    /// Splits an update's data into operators and plain values. An
    /// operator without an attribute (`""`, or `"0"`, empty to PHP) takes
    /// its key as attribute.
    ///
    /// PHP: `Operator::extractOperators()`. PHP sets the attribute on the
    /// operator object the data holds too; here the data is consumed.
    pub fn extract_operators(data: Array) -> Extracted {
        let mut extracted = Extracted::default();
        for (key, value) in data {
            match value {
                Value::Ext(Ext::Operator(mut operator)) => {
                    if operator.attribute.is_empty() || operator.attribute == "0" {
                        operator.attribute = match key.as_ref() {
                            KeyRef::Int(i) => i.to_string(),
                            KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                        };
                    }
                    extracted.operators.push((key, *operator));
                }
                other => {
                    extracted.updates.set(key, other);
                }
            }
        }
        extracted
    }
}
