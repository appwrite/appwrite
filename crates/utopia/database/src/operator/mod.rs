//! [`Operator`]: an atomic update of one attribute (increment, array
//! append, string concat, ...), given as an attribute value of an update,
//! and [`OperatorType`], its methods.
//!
//! ```
//! use utopia_database::{Operator, Value};
//! use utopia_database::operator::OperatorType;
//!
//! let mut op = Operator::increment(5, Some(100.into()));
//! op.set_attribute("score");
//! assert_eq!(op.to_string().unwrap(), r#"{"method":"increment","attribute":"score","values":[5,100]}"#);
//! assert_eq!(Operator::parse(r#"{"method":"toggle"}"#).unwrap().method(), OperatorType::Toggle);
//! assert!(Operator::divide(0, None).is_err());
//! assert!(Operator::is_operator(&Value::from(Operator::toggle())));
//! ```
//!
//! PHP: `Utopia\Database\Operator`, `Utopia\Database\OperatorType`.
//! Checking an operator against an attribute is
//! [`crate::validator::operator`]'s (`Validator\Operator`). PHP's `__clone`
//! (a deep copy of nested operators) is Rust's `Clone`.

use std::borrow::Cow;

use crate::error::{Error, Kind, Result};
use crate::macros::backed_enum;
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value, json_value};

backed_enum! {
    /// The method of an [`Operator`].
    ///
    /// PHP: `Utopia\Database\OperatorType`.
    pub enum OperatorType: "Utopia\\Database\\OperatorType" {
        /// Adds a number.
        Increment = "increment",
        /// Subtracts a number.
        Decrement = "decrement",
        /// The remainder of a division.
        Modulo = "modulo",
        /// Raises to a power.
        Power = "power",
        /// Multiplies.
        Multiply = "multiply",
        /// Divides.
        Divide = "divide",
        /// Appends to an array.
        ArrayAppend = "arrayAppend",
        /// Prepends to an array.
        ArrayPrepend = "arrayPrepend",
        /// Inserts into an array at an index.
        ArrayInsert = "arrayInsert",
        /// Removes a value from an array.
        ArrayRemove = "arrayRemove",
        /// Removes the duplicates of an array.
        ArrayUnique = "arrayUnique",
        /// Keeps the elements also in the given values.
        ArrayIntersect = "arrayIntersect",
        /// Removes the elements in the given values.
        ArrayDiff = "arrayDiff",
        /// Keeps the elements meeting a condition.
        ArrayFilter = "arrayFilter",
        /// Appends to a string.
        StringConcat = "stringConcat",
        /// Replaces in a string.
        StringReplace = "stringReplace",
        /// Negates a boolean.
        Toggle = "toggle",
        /// Adds days to a date.
        DateAddDays = "dateAddDays",
        /// Subtracts days from a date.
        DateSubDays = "dateSubDays",
        /// Sets a date to now.
        DateSetNow = "dateSetNow",
    }
}

impl OperatorType {
    /// `increment`, `decrement`, `multiply`, `divide`, `modulo`, `power`
    /// (`isNumeric()`).
    pub fn is_numeric(self) -> bool {
        use OperatorType::*;
        matches!(self, Increment | Decrement | Multiply | Divide | Modulo | Power)
    }

    /// The `array*` methods (`isArray()`).
    pub fn is_array(self) -> bool {
        use OperatorType::*;
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

    /// `stringConcat`, `stringReplace` (`isString()`).
    pub fn is_string(self) -> bool {
        matches!(self, OperatorType::StringConcat | OperatorType::StringReplace)
    }

    /// `toggle` (`isBoolean()`).
    pub fn is_boolean(self) -> bool {
        matches!(self, OperatorType::Toggle)
    }

    /// `dateAddDays`, `dateSubDays`, `dateSetNow` (`isDate()`).
    pub fn is_date(self) -> bool {
        matches!(self, OperatorType::DateAddDays | OperatorType::DateSubDays | OperatorType::DateSetNow)
    }
}

/// An `int|float|string` argument of the numeric helpers.
#[derive(Clone, PartialEq, Debug)]
pub enum Number {
    /// An `int`.
    Int(i64),
    /// A `float`.
    Float(f64),
    /// A `string` (numeric text, such as an unsigned 64-bit integer).
    Str(Str),
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

impl From<&str> for Number {
    fn from(s: &str) -> Self {
        Number::Str(Str::from(s))
    }
}

impl From<String> for Number {
    fn from(s: String) -> Self {
        Number::Str(Str::from(s))
    }
}

impl From<Number> for Value {
    fn from(n: Number) -> Self {
        match n {
            Number::Int(i) => Value::Int(i),
            Number::Float(f) => Value::Float(f),
            Number::Str(s) => Value::Str(s),
        }
    }
}

/// The most values one array operator (append, prepend, intersect, diff,
/// remove) may carry.
///
/// PHP: `Operator::MAX_ARRAY_OPERATOR_SIZE`.
pub const MAX_ARRAY_OPERATOR_SIZE: usize = 10_000;

/// The conditions `arrayFilter` accepts.
///
/// PHP: `Operator::ARRAY_FILTER_CONDITIONS`.
pub const ARRAY_FILTER_CONDITIONS: [&str; 8] =
    ["equal", "notEqual", "greaterThan", "greaterThanEqual", "lessThan", "lessThanEqual", "isNull", "isNotNull"];

/// One operator: a method, the attribute it updates (`""` until the
/// update's key sets it) and its values (a PHP array, keys kept).
///
/// PHP: `Utopia\Database\Operator`.
#[derive(Clone, PartialEq, Debug)]
pub struct Operator {
    method: OperatorType,
    attribute: String,
    values: Array,
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
    /// An operator of `method` on `attribute` with a list of values.
    ///
    /// PHP: `new Operator($method, $attribute, $values)` with a list.
    pub fn new(method: OperatorType, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self::from_array(method, attribute, Array::from_list(values))
    }

    /// [`Operator::new`] with any PHP array of values, keys kept.
    ///
    /// PHP: `new Operator($method, $attribute, $values)`.
    pub fn from_array(method: OperatorType, attribute: impl Into<String>, values: Array) -> Self {
        Self { method, attribute: attribute.into(), values }
    }

    /// The method (`getMethod()`).
    pub fn method(&self) -> OperatorType {
        self.method
    }

    /// The attribute (`getAttribute()`).
    pub fn attribute(&self) -> &str {
        &self.attribute
    }

    /// The values (`getValues()`): as stored, except that the numeric
    /// string exponent of a `power` reads as its number (`'2'` is `2`).
    pub fn values(&self) -> Cow<'_, Array> {
        let key = ArrayKey::Int(0);
        if self.method != OperatorType::Power {
            return Cow::Borrowed(&self.values);
        }
        let Some(Value::Str(exponent)) = self.values.get(&key) else {
            return Cow::Borrowed(&self.values);
        };
        let Some((number, _)) = php_std::value::numeric_bytes_ex(exponent.as_bytes()) else {
            return Cow::Borrowed(&self.values);
        };
        let mut values = self.values.clone();
        values.set(
            key,
            match number {
                php_std::value::Number::Int(i) => Value::Int(i),
                php_std::value::Number::Float(f) => Value::Float(f),
            },
        );
        Cow::Owned(values)
    }

    /// The values as stored, without [`Operator::values`]' reading of a
    /// `power` exponent.
    pub fn raw_values(&self) -> &Array {
        &self.values
    }

    /// The value at key `0` of [`Operator::values`]; `None` when there is
    /// none or it is `null` (`getValue($default)` is
    /// `value().unwrap_or(default)`).
    pub fn value(&self) -> Option<Value> {
        self.values().get(&ArrayKey::Int(0)).filter(|v| !matches!(v, Value::Null)).cloned()
    }

    /// Sets the method (`setMethod()`).
    pub fn set_method(&mut self, method: OperatorType) -> &mut Self {
        self.method = method;
        self
    }

    /// Sets the attribute (`setAttribute()`).
    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    /// Sets the values to a list (`setValues()`).
    pub fn set_values(&mut self, values: Vec<Value>) -> &mut Self {
        self.values = Array::from_list(values);
        self
    }

    /// Sets the values to any PHP array, keys kept (`setValues()`).
    pub fn set_values_array(&mut self, values: Array) -> &mut Self {
        self.values = values;
        self
    }

    /// Sets the only value (`setValue()`).
    pub fn set_value(&mut self, value: Value) -> &mut Self {
        self.values = Array::from_list(vec![value]);
        self
    }

    /// Whether `value` is the backing value of an [`OperatorType`]
    /// (`Operator::isMethod()`; a case itself always is).
    pub fn is_method(value: &str) -> bool {
        OperatorType::try_from_value(value).is_some()
    }

    /// `isNumericOperation()`.
    pub fn is_numeric_operation(&self) -> bool {
        self.method.is_numeric()
    }

    /// `isArrayOperation()`.
    pub fn is_array_operation(&self) -> bool {
        self.method.is_array()
    }

    /// `isStringOperation()`.
    pub fn is_string_operation(&self) -> bool {
        self.method.is_string()
    }

    /// `isBooleanOperation()`.
    pub fn is_boolean_operation(&self) -> bool {
        self.method.is_boolean()
    }

    /// `isDateOperation()`.
    pub fn is_date_operation(&self) -> bool {
        self.method.is_date()
    }

    /// Parses an operator from its JSON (what [`Operator::to_string`] writes).
    ///
    /// Fails with a [`Kind::Operator`] error: `Invalid operator: <JSON
    /// error>`, `Invalid operator. Must be an array, got <type>`, or one of
    /// [`Operator::parse_operator`]'s.
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
    /// `values`; a missing or `null` entry is `''`, `''` and `[]`).
    ///
    /// Fails with a [`Kind::Operator`] error, in PHP's order: `Invalid
    /// operator method. Must be a string, got <type>`, `Invalid operator
    /// method: <method>`, `Invalid operator attribute. Must be a string,
    /// got <type>`, `Invalid operator values. Must be an array, got <type>`.
    ///
    /// PHP: `Operator::parseOperator()`.
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
        let Some(method) = method.as_str().and_then(OperatorType::try_from_value) else {
            let mut message = b"Invalid operator method: ".to_vec();
            message.extend_from_slice(method.as_bytes());
            return Err(Error::from_bytes(Kind::Operator, message));
        };
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

    /// Parses each operator of a list with [`Operator::parse`]
    /// (`Operator::parseOperators()`).
    pub fn parse_operators<S: AsRef<[u8]>>(operators: &[S]) -> Result<Vec<Operator>> {
        operators.iter().map(Operator::parse).collect()
    }

    /// The array form: `method`, `attribute` and `values` (as stored, keys
    /// kept) (`toArray()`).
    pub fn to_array(&self) -> Array {
        let mut out = Array::with_capacity(3);
        out.set(ArrayKey::from("method"), Value::Str(Str::from_static(self.method.as_str())));
        out.set(ArrayKey::from("attribute"), Value::Str(Str::from(self.attribute.as_str())));
        out.set(ArrayKey::from("values"), Value::Array(self.values.clone()));
        out
    }

    /// The JSON of [`Operator::to_array`], as PHP's `json_encode` writes it.
    ///
    /// Fails with a [`Kind::Operator`] error `Invalid Json: <JSON error>`
    /// when a value cannot be encoded.
    ///
    /// PHP: `Operator::toString()`.
    #[allow(clippy::inherent_to_string_shadow_display)]
    pub fn to_string(&self) -> Result<String> {
        php_std::json::encode(
            &json_value(&Value::Array(self.to_array())),
            php_std::json::Flags::NONE,
            php_std::json::DEFAULT_DEPTH,
        )
        .map_err(|e| Error::operator(format!("Invalid Json: {}", e.message())))
    }

    /// Adds `value`, up to `max` (`Operator::increment()`; PHP's default
    /// `value` is 1).
    pub fn increment(value: impl Into<Number>, max: Option<Number>) -> Self {
        Self::bounded(OperatorType::Increment, value.into(), max)
    }

    /// Subtracts `value`, down to `min` (`Operator::decrement()`; PHP's
    /// default `value` is 1).
    pub fn decrement(value: impl Into<Number>, min: Option<Number>) -> Self {
        Self::bounded(OperatorType::Decrement, value.into(), min)
    }

    /// Multiplies by `factor`, up to `max` (`Operator::multiply()`).
    pub fn multiply(factor: impl Into<Number>, max: Option<Number>) -> Self {
        Self::bounded(OperatorType::Multiply, factor.into(), max)
    }

    /// Divides by `divisor`, down to `min` (`Operator::divide()`).
    ///
    /// Fails with a [`Kind::Operator`] error `Division by zero is not
    /// allowed` when `divisor == 0`.
    pub fn divide(divisor: impl Into<Number>, min: Option<Number>) -> Result<Self> {
        let divisor = divisor.into();
        if is_zero(&divisor) {
            return Err(Error::operator("Division by zero is not allowed"));
        }
        Ok(Self::bounded(OperatorType::Divide, divisor, min))
    }

    /// The remainder of a division by `divisor` (`Operator::modulo()`).
    ///
    /// Fails with a [`Kind::Operator`] error `Modulo by zero is not
    /// allowed` when `divisor == 0`.
    pub fn modulo(divisor: impl Into<Number>) -> Result<Self> {
        let divisor = divisor.into();
        if is_zero(&divisor) {
            return Err(Error::operator("Modulo by zero is not allowed"));
        }
        Ok(Self::new(OperatorType::Modulo, "", vec![divisor.into()]))
    }

    /// Raises to `exponent`, up to `max` (`Operator::power()`).
    pub fn power(exponent: impl Into<Number>, max: Option<Number>) -> Self {
        Self::bounded(OperatorType::Power, exponent.into(), max)
    }

    fn bounded(method: OperatorType, value: Number, bound: Option<Number>) -> Self {
        let mut values = vec![value.into()];
        values.extend(bound.map(Value::from));
        Self::new(method, "", values)
    }

    /// Appends `values` to the array (`Operator::arrayAppend()`).
    pub fn array_append<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorType::ArrayAppend, "", values.into_iter().map(Into::into).collect())
    }

    /// Prepends `values` to the array (`Operator::arrayPrepend()`).
    pub fn array_prepend<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorType::ArrayPrepend, "", values.into_iter().map(Into::into).collect())
    }

    /// Inserts `value` at `index` (`Operator::arrayInsert()`).
    pub fn array_insert(index: i64, value: impl Into<Value>) -> Self {
        Self::new(OperatorType::ArrayInsert, "", vec![Value::Int(index), value.into()])
    }

    /// Removes every element equal to `value` (`Operator::arrayRemove()`).
    pub fn array_remove(value: impl Into<Value>) -> Self {
        Self::new(OperatorType::ArrayRemove, "", vec![value.into()])
    }

    /// Removes duplicates from the array (`Operator::arrayUnique()`).
    pub fn array_unique() -> Self {
        Self::new(OperatorType::ArrayUnique, "", Vec::new())
    }

    /// Keeps the elements also in `values` (`Operator::arrayIntersect()`).
    pub fn array_intersect<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorType::ArrayIntersect, "", values.into_iter().map(Into::into).collect())
    }

    /// Removes the elements in `values` (`Operator::arrayDiff()`).
    pub fn array_diff<V: Into<Value>>(values: impl IntoIterator<Item = V>) -> Self {
        Self::new(OperatorType::ArrayDiff, "", values.into_iter().map(Into::into).collect())
    }

    /// Keeps the elements meeting `condition` (one of
    /// [`ARRAY_FILTER_CONDITIONS`], checked by the validator) against
    /// `value` (`null` for the null checks; PHP's default)
    /// (`Operator::arrayFilter()`).
    pub fn array_filter(condition: &str, value: impl Into<Value>) -> Self {
        Self::new(OperatorType::ArrayFilter, "", vec![Value::Str(Str::from(condition)), value.into()])
    }

    /// Appends `value` to the string (`Operator::stringConcat()`).
    pub fn string_concat(value: impl Into<Value>) -> Self {
        Self::new(OperatorType::StringConcat, "", vec![value.into()])
    }

    /// Replaces `search` with `replace` in the string
    /// (`Operator::stringReplace()`).
    pub fn string_replace(search: &str, replace: &str) -> Self {
        Self::new(OperatorType::StringReplace, "", vec![Value::Str(Str::from(search)), Value::Str(Str::from(replace))])
    }

    /// Negates the boolean (`Operator::toggle()`).
    pub fn toggle() -> Self {
        Self::new(OperatorType::Toggle, "", Vec::new())
    }

    /// Adds `days` days to the date, negative subtracting
    /// (`Operator::dateAddDays()`).
    pub fn date_add_days(days: i64) -> Self {
        Self::new(OperatorType::DateAddDays, "", vec![Value::Int(days)])
    }

    /// Subtracts `days` days from the date (`Operator::dateSubDays()`).
    pub fn date_sub_days(days: i64) -> Self {
        Self::new(OperatorType::DateSubDays, "", vec![Value::Int(days)])
    }

    /// Sets the date to now (`Operator::dateSetNow()`).
    pub fn date_set_now() -> Self {
        Self::new(OperatorType::DateSetNow, "", Vec::new())
    }

    /// Whether `value` is an operator (`Operator::isOperator()`).
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
        Self::extract(data)
    }

    /// The attribute `extractOperators()` sets on each operator `data`
    /// holds without one: its key. PHP's operators are objects the data
    /// shares, so the caller's document carries them named after the call.
    pub fn name_operators(data: &mut Array) {
        let keys: Vec<ArrayKey> = data.keys().map(KeyRef::to_owned).collect();
        for key in keys {
            if let Some(Value::Ext(Ext::Operator(operator))) = data.get_mut(&key)
                && (operator.attribute.is_empty() || operator.attribute == "0")
            {
                operator.attribute = match key.as_ref() {
                    KeyRef::Int(i) => i.to_string(),
                    KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                };
            }
        }
    }

    fn extract(data: Array) -> Extracted {
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

/// PHP `$n == 0`.
fn is_zero(n: &Number) -> bool {
    Value::from(n.clone()).loose_eq(&Value::Int(0))
}
