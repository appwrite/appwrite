//! [`Operator`]: an atomic update of one attribute (increment, array
//! append, string concat, ...), given as an attribute value of an update.
//!
//! PHP: `Utopia\Database\Operator`.

use crate::value::{Array, ArrayKey, Str, Value};

/// An operator method (`Operator::TYPE_*`).
#[derive(Clone, PartialEq, Eq, Hash, Debug)]
pub enum OperatorMethod {
    /// `increment`
    Increment,
    /// `decrement`
    Decrement,
    /// `modulo`
    Modulo,
    /// `power`
    Power,
    /// `multiply`
    Multiply,
    /// `divide`
    Divide,
    /// `arrayAppend`
    ArrayAppend,
    /// `arrayPrepend`
    ArrayPrepend,
    /// `arrayInsert`
    ArrayInsert,
    /// `arrayRemove`
    ArrayRemove,
    /// `arrayUnique`
    ArrayUnique,
    /// `arrayIntersect`
    ArrayIntersect,
    /// `arrayDiff`
    ArrayDiff,
    /// `arrayFilter`
    ArrayFilter,
    /// `stringConcat`
    StringConcat,
    /// `stringReplace`
    StringReplace,
    /// `toggle`
    Toggle,
    /// `dateAddDays`
    DateAddDays,
    /// `dateSubDays`
    DateSubDays,
    /// `dateSetNow`
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
}

/// One operator.
#[derive(Clone, PartialEq, Debug)]
pub struct Operator {
    method: OperatorMethod,
    attribute: String,
    values: Vec<Value>,
}

impl Operator {
    /// `new Operator($method, $attribute, $values)`.
    pub fn new(method: OperatorMethod, attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self { method, attribute: attribute.into(), values }
    }

    /// The method (`getMethod()`).
    pub fn method(&self) -> &OperatorMethod {
        &self.method
    }

    /// The attribute (`getAttribute()`).
    pub fn attribute(&self) -> &str {
        &self.attribute
    }

    /// The values (`getValues()`).
    pub fn values(&self) -> &[Value] {
        &self.values
    }

    /// The first value (`getValue()`).
    pub fn value(&self) -> Option<&Value> {
        self.values.first()
    }

    /// Sets the method (`setMethod()`).
    pub fn set_method(&mut self, method: OperatorMethod) -> &mut Self {
        self.method = method;
        self
    }

    /// Sets the attribute (`setAttribute()`).
    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    /// Sets the values (`setValues()`).
    pub fn set_values(&mut self, values: Vec<Value>) -> &mut Self {
        self.values = values;
        self
    }

    /// Sets the only value (`setValue()`).
    pub fn set_value(&mut self, value: Value) -> &mut Self {
        self.values = vec![value];
        self
    }

    /// `toArray()`: `method`, `attribute` and `values`.
    pub fn to_array(&self) -> Array {
        let mut out = Array::new();
        out.set(ArrayKey::from("method"), Value::Str(Str::from(self.method.as_str())));
        out.set(ArrayKey::from("attribute"), Value::Str(Str::from(self.attribute.as_str())));
        out.set(ArrayKey::from("values"), Value::Array(self.values.iter().cloned().collect()));
        out
    }
}
