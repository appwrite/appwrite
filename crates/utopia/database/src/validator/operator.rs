//! [`Operator`]: atomic update operators against the attributes they
//! update.
//!
//! PHP: `Utopia\Database\Validator\Operator`.

use std::cmp::Ordering;
use std::collections::HashMap;

use utopia_query::schema::ColumnType;

use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::operator::{ARRAY_FILTER_CONDITIONS, MAX_ARRAY_OPERATOR_SIZE, Operator as DatabaseOperator, OperatorType};
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::validator::big_int::{BigInt, Integer, Operand};
use crate::validator::{Message, is_numeric, text};
use crate::value::{Array, ArrayKey, Ext, Str, Value};

/// Where PHP reports an `Operator::parse()` argument error from.
const PARSE_CALLER: &str = "/usr/src/code/vendor/utopia-php/database/src/Database/Validator/Operator.php on line 201";

const STRING_TYPES: [ColumnType; 5] =
    [ColumnType::String, ColumnType::Varchar, ColumnType::Text, ColumnType::MediumText, ColumnType::LongText];

/// An operator valid for the attribute it updates: an existing attribute
/// that is not encrypted, of a type the method applies to (numbers for
/// arithmetic, arrays or many-side relationships for array methods,
/// strings, booleans, dates), with well-formed values within the
/// attribute's range; with the current document, an integer result within
/// the attribute's range, a string result within its size, and an insert
/// index within the array.
///
/// ```
/// use utopia_database::{Attribute, Collection, Document};
/// use utopia_database::operator::{Operator, OperatorType};
/// use utopia_database::validator::operator::Operator as OperatorValidator;
/// use utopia_database::value::{Array, Value};
///
/// let collection =
///     Collection::create("c", "", vec![Attribute::integer("count")], vec![], None, true, Array::new()).unwrap();
/// let validator = OperatorValidator::new(&collection, None, true).unwrap();
/// assert!(validator.check(&Operator::new(OperatorType::Increment, "count", vec![Value::Int(1)])).unwrap());
/// assert!(!validator.check(&Operator::new(OperatorType::Toggle, "count", vec![])).unwrap());
/// assert_eq!(validator.description(), "Cannot apply toggle operator to non-boolean field 'count'");
/// ```
///
/// PHP: `Utopia\Database\Validator\Operator` (`new Operator($collection,
/// $currentDocument, $supportUnsignedBigInt)`).
#[derive(Clone, Debug)]
pub struct Operator {
    attributes: HashMap<String, Attribute>,
    current_document: Option<Document>,
    support_unsigned_big_int: bool,
    message: Message,
}

/// The integer `getIntegerValue()` reads from a value: an integer, an
/// integer string, or a whole float within PHP's integer range.
fn integer_value(value: &Value) -> Option<Integer> {
    match value {
        Value::Int(i) => Some(Integer::Int(*i)),
        Value::Str(s) if BigInt::is_integer_string(s.as_bytes(), true) => {
            BigInt::to_native(Operand::Str(s.as_bytes())).ok()
        }
        Value::Float(f)
            if f.is_finite()
                && f.floor() == *f
                && *f >= -9_223_372_036_854_775_808.0
                && *f <= 9_223_372_036_854_775_808.0 =>
        {
            Some(Integer::Int(php_std::format::dval_to_lval(*f)))
        }
        _ => None,
    }
}

/// The number `getNumericValue()` reads from a value: an integer or float,
/// or a numeric string (an integer when it is an integer string within
/// PHP's range, a float otherwise).
fn numeric_value(value: &Value) -> Option<Value> {
    match value {
        Value::Int(_) | Value::Float(_) => Some(value.clone()),
        Value::Str(s) if is_numeric(value) => Some(if BigInt::fits_php_int(s.as_bytes(), true) {
            Value::Int(crate::value::to_long(value))
        } else {
            Value::Float(value.to_double())
        }),
        _ => None,
    }
}

/// `BigInt::compare()` of an integer and a bound (an integer or integer
/// string).
fn compare(integer: &Integer, bound: &Value) -> Ordering {
    Operand::from_value(bound).and_then(|b| BigInt::compare(integer, b).ok()).unwrap_or(Ordering::Greater)
}

/// A relationship value: a document id or a document.
fn is_relationship_value(item: &Value) -> bool {
    matches!(item, Value::Str(_) | Value::Ext(Ext::Document(_)))
}

/// Whether a relationship attribute holds many documents on this side
/// (`isRelationshipArray()`).
fn is_relationship_array(attribute: &Attribute) -> bool {
    match attribute.relationship.as_ref().map(|r| r.kind) {
        Some(RelationshipType::ManyToMany) => true,
        Some(RelationshipType::OneToMany) => attribute.side == Some(RelationshipSide::Parent),
        Some(RelationshipType::ManyToOne) => attribute.side == Some(RelationshipSide::Child),
        _ => false,
    }
}

/// `$values[$i]`, `null` when missing.
fn nth(values: &Array, i: i64) -> &Value {
    const NULL: &Value = &Value::Null;
    values.get(&ArrayKey::Int(i)).unwrap_or(NULL)
}

/// `is_array($values[0]) ? $values[0] : $values`.
fn payload(values: &Array) -> &Array {
    match nth(values, 0) {
        Value::Array(a) => a,
        _ => values,
    }
}

impl Operator {
    /// The validator of operators on `collection`'s attributes (by `key`),
    /// predicting results against `current_document` when given; without
    /// `support_unsigned_big_int`, arithmetic on an unsigned big integer
    /// is refused.
    ///
    /// Fails as [`Collection::attributes`] does.
    pub fn new(
        collection: &Collection,
        current_document: Option<Document>,
        support_unsigned_big_int: bool,
    ) -> Result<Self> {
        let attributes =
            collection.attributes()?.iter().map(|attribute| (attribute.key.clone(), attribute.clone())).collect();
        Ok(Self { attributes, current_document, support_unsigned_big_int, message: Message::new("Invalid operator") })
    }

    /// [`Operator::new`] of a collection document (PHP's constructor reads
    /// it with `Collection::fromDocument()`).
    ///
    /// Fails as [`Collection::from_document`] and [`Collection::attributes`] do.
    pub fn from_document(
        collection: &Document,
        current_document: Option<Document>,
        support_unsigned_big_int: bool,
    ) -> Result<Self> {
        Self::new(&Collection::from_document(collection)?, current_document, support_unsigned_big_int)
    }

    /// `getDescription()`: the reason of the last failure.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }

    fn fail(&self, message: impl Into<String>) -> Result<bool> {
        self.message.set(message);
        Ok(false)
    }

    /// `isValid($value)`: an operator, or a value `Operator::parse()` reads
    /// as one (an integer, float or boolean is read as its string).
    ///
    /// Fails with a [`Kind::TypeError`] where PHP's `strlen()` throws on the
    /// current value of a `stringConcat` (an array or an object).
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        let parsed;
        let operator = match value {
            Value::Ext(Ext::Operator(o)) => &**o,
            Value::Null | Value::Array(_) | Value::Object(_) | Value::Ext(_) => {
                return self.fail(format!(
                    "Invalid operator: Utopia\\Database\\Operator::parse(): Argument #1 ($operator) must be of type string, {} given, called in {PARSE_CALLER}",
                    value.debug_type()
                ));
            }
            other => {
                let source = match other {
                    Value::Str(s) => s.clone(),
                    other => other.to_php_string().unwrap_or(Str::EMPTY),
                };
                match DatabaseOperator::parse(source.as_bytes()) {
                    Ok(o) => {
                        parsed = o;
                        &parsed
                    }
                    Err(e) => {
                        let mut message = b"Invalid operator: ".to_vec();
                        message.extend_from_slice(e.message_bytes());
                        return self.fail(String::from_utf8_lossy(&message).into_owned());
                    }
                }
            }
        };
        self.check(operator)
    }

    /// [`Operator::is_valid`] of an operator.
    pub fn check(&self, operator: &DatabaseOperator) -> Result<bool> {
        let Some(attribute) = self.attributes.get(operator.attribute()) else {
            return self.fail(format!("Attribute '{}' does not exist in collection", operator.attribute()));
        };
        self.check_for_attribute(operator, attribute)
    }

    fn in_bounds(&self, value: &Value, attribute: &Attribute) -> bool {
        let Some(bounds) = attribute.bounds() else {
            return false;
        };
        if attribute.is_integer() {
            return integer_value(value).is_some_and(|i| {
                compare(&i, &bounds.min) != Ordering::Less && compare(&i, &bounds.max) != Ordering::Greater
            });
        }
        let Some(numeric) = numeric_value(value) else {
            return false;
        };
        if let Value::Float(f) = numeric
            && !f.is_finite()
        {
            return false;
        }
        numeric.compare(&bounds.min) >= 0 && numeric.compare(&bounds.max) <= 0
    }

    fn valid_limit(&self, limit: &Value, attribute: &Attribute, operator: &DatabaseOperator) -> bool {
        let name = operator.method().as_str();
        let finite = match limit {
            Value::Int(_) => true,
            Value::Float(f) => f.is_finite(),
            Value::Str(_) => is_numeric(limit),
            _ => false,
        };
        if !attribute.is_integer() || !finite {
            if self.in_bounds(limit, attribute) {
                return true;
            }
            self.message
                .set(format!("Cannot apply {name} operator: max/min limit must be numeric, got {}", limit.type_name()));
            return false;
        }
        let integral = Operand::from_value(limit).and_then(BigInt::integral_value);
        let Some(integral) = integral else {
            self.message.set(format!(
                "Cannot apply {name} operator: max/min limit must be a whole number for integer attribute '{}', got {}",
                operator.attribute(),
                text(limit)
            ));
            return false;
        };
        let Some(bounds) = attribute.bounds() else {
            self.message
                .set(format!("Cannot apply {name} operator: max/min limit must be numeric, got {}", limit.type_name()));
            return false;
        };
        if compare(&integral, &bounds.min) == Ordering::Less || compare(&integral, &bounds.max) == Ordering::Greater {
            self.message.set(format!(
                "Cannot apply {name} operator: max/min limit must be between {} and {}",
                text(&bounds.min),
                text(&bounds.max)
            ));
            return false;
        }
        true
    }

    fn range_message(&self, method: OperatorType, attribute: &Attribute, result: &Integer) -> bool {
        let Some(bounds) = attribute.bounds() else {
            return false;
        };
        let result_value = Value::from(result.clone());
        let above = if attribute.is_integer() {
            compare(result, &bounds.max) == Ordering::Greater
        } else {
            result_value.compare(&bounds.max) > 0
        };
        if above {
            self.message.set(format!(
                "Cannot apply {} operator: would overflow maximum value of {}",
                method.as_str(),
                text(&bounds.max)
            ));
            return false;
        }
        let below = if attribute.is_integer() {
            compare(result, &bounds.min) == Ordering::Less
        } else {
            result_value.compare(&bounds.min) < 0
        };
        if below {
            self.message.set(format!(
                "Cannot apply {} operator: would underflow minimum value of {}",
                method.as_str(),
                text(&bounds.min)
            ));
            return false;
        }
        true
    }

    fn items_message(&self, name: &str, attribute: &Attribute) -> Result<bool> {
        let Some(bounds) = attribute.bounds() else {
            return Ok(false);
        };
        self.fail(format!(
            "Cannot apply {name} operator: array items must be between {} and {}",
            text(&bounds.min),
            text(&bounds.max)
        ))
    }

    fn check_for_attribute(&self, operator: &DatabaseOperator, attribute: &Attribute) -> Result<bool> {
        let method = operator.method();
        let name = method.as_str();
        let field = operator.attribute();
        let values = operator.values();
        let values: &Array = &values;
        let first = nth(values, 0);
        let kind = attribute.kind;
        let is_array = attribute.array;
        let relationship = kind == ColumnType::Relationship;

        // Operators run on the stored value, which for an encrypted
        // attribute is the ciphertext.
        if attribute.filters.iter().any(|f| f == "encrypt") {
            return self.fail(format!("Cannot apply {name} operator to encrypted attribute '{field}'"));
        }

        if matches!(
            method,
            OperatorType::ArrayAppend
                | OperatorType::ArrayPrepend
                | OperatorType::ArrayIntersect
                | OperatorType::ArrayDiff
                | OperatorType::ArrayRemove
        ) {
            let size = payload(values).len();
            if size > MAX_ARRAY_OPERATOR_SIZE {
                return self.fail(format!(
                    "Array size {size} exceeds maximum allowed size of {MAX_ARRAY_OPERATOR_SIZE} for array operations"
                ));
            }
        }

        let single_relationship = || format!("Cannot apply {name} operator to single-value relationship '{field}'");
        let relationship_values = || {
            format!(
                "Cannot apply {name} operator: relationship values must be document IDs (strings) or Document objects"
            )
        };
        let non_array = || format!("Cannot apply {name} operator to non-array field '{field}'");

        match method {
            OperatorType::Increment
            | OperatorType::Decrement
            | OperatorType::Multiply
            | OperatorType::Divide
            | OperatorType::Modulo
            | OperatorType::Power => {
                if !attribute.is_numeric() {
                    return self.fail(format!("Cannot apply {name} operator to non-numeric field '{field}'"));
                }
                if !attribute.signed && kind == ColumnType::BigInteger && !self.support_unsigned_big_int {
                    return self.fail(format!(
                        "Cannot apply {name} operator: unsigned 64-bit arithmetic is not supported by this adapter"
                    ));
                }
                if matches!(first, Value::Null) || !self.in_bounds(first, attribute) {
                    return self.fail(format!(
                        "Cannot apply {name} operator: value must be numeric, got {}",
                        first.type_name()
                    ));
                }
                let integer_type = attribute.is_integer();
                let operand = if integer_type { integer_value(first).map(Value::from) } else { numeric_value(first) };
                let Some(operand) = operand else {
                    return Ok(false);
                };
                if matches!(method, OperatorType::Divide | OperatorType::Modulo)
                    && matches!(operand, Value::Int(0) | Value::Float(0.0))
                {
                    let what = if method == OperatorType::Divide { "division" } else { "modulo" };
                    return self.fail(format!("Cannot apply {name} operator: {what} by zero"));
                }
                let limit = nth(values, 1);
                if values.len() > 1 && !matches!(limit, Value::Null) && !self.valid_limit(limit, attribute, operator) {
                    return Ok(false);
                }
                if let Some(current) = &self.current_document
                    && integer_type
                    && matches!(limit, Value::Null)
                {
                    let stored = current.get(field).cloned().unwrap_or(Value::Int(0));
                    let current = integer_value(&stored);
                    let Some(current) = current.filter(|c| self.in_bounds(&Value::from(c.clone()), attribute)) else {
                        return self.fail(format!(
                            "Cannot apply {name} operator: current value is outside the attribute range"
                        ));
                    };
                    let Some(operand) = Operand::from_value(&operand) else {
                        return Ok(false);
                    };
                    let predicted = match BigInt::calculate(method, &current, operand) {
                        Ok(predicted) => predicted,
                        Err(e) if e.kind() == Kind::InvalidArgument => {
                            return self
                                .fail(format!("Cannot apply {name} operator: result is outside the attribute range"));
                        }
                        Err(e) => return Err(e),
                    };
                    if !self.range_message(method, attribute, &predicted) {
                        return Ok(false);
                    }
                }
            }
            OperatorType::ArrayAppend | OperatorType::ArrayPrepend => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                    if !values.values().all(is_relationship_value) {
                        return self.fail(relationship_values());
                    }
                } else if !is_array {
                    return self.fail(non_array());
                }
                if !values.is_empty() && attribute.is_integer() {
                    for item in payload(values).values() {
                        if is_numeric(item) && !self.in_bounds(item, attribute) {
                            return self.items_message(name, attribute);
                        }
                    }
                }
            }
            OperatorType::ArrayUnique => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                } else if !is_array {
                    return self.fail(non_array());
                }
            }
            OperatorType::ArrayInsert => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                } else if !is_array {
                    return self.fail(non_array());
                }
                if values.len() != 2 {
                    return self
                        .fail(format!("Cannot apply {name} operator: requires exactly 2 values (index and value)"));
                }
                let index = match first {
                    Value::Int(i) if *i >= 0 => *i,
                    _ => {
                        return self
                            .fail(format!("Cannot apply {name} operator: index must be a non-negative integer"));
                    }
                };
                let insert = nth(values, 1);
                if relationship && !is_relationship_value(insert) {
                    return self.fail(relationship_values());
                }
                if attribute.is_integer() && is_numeric(insert) && !self.in_bounds(insert, attribute) {
                    return self.items_message(name, attribute);
                }
                if let Some(current) = &self.current_document
                    && let Some(Value::Array(current)) = current.get(field)
                    && index > current.len() as i64
                {
                    return self.fail(format!(
                        "Cannot apply {name} operator: index {index} is out of bounds for array of length {}",
                        current.len()
                    ));
                }
            }
            OperatorType::ArrayRemove => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                    if !payload(values).values().all(is_relationship_value) {
                        return self.fail(relationship_values());
                    }
                } else if !is_array {
                    return self.fail(non_array());
                }
                if values.is_empty() {
                    return self.fail(format!("Cannot apply {name} operator: requires a value to remove"));
                }
            }
            OperatorType::ArrayIntersect => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot use {name} operator on non-array attribute '{field}'"));
                }
                if values.is_empty() {
                    return self.fail(format!("{name} operator requires a non-empty array value"));
                }
                if relationship && !values.values().all(is_relationship_value) {
                    return self.fail(relationship_values());
                }
            }
            OperatorType::ArrayDiff => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                    if !values.values().all(is_relationship_value) {
                        return self.fail(relationship_values());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot use {name} operator on non-array attribute '{field}'"));
                }
            }
            OperatorType::ArrayFilter => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                } else if !is_array {
                    return self.fail(non_array());
                }
                if values.is_empty() || values.len() > 2 {
                    return self.fail(format!(
                        "Cannot apply {name} operator: requires 1 or 2 values (condition and optional comparison value)"
                    ));
                }
                let Value::Str(condition) = first else {
                    return self.fail(format!("Cannot apply {name} operator: condition must be a string"));
                };
                if !ARRAY_FILTER_CONDITIONS.iter().any(|c| c.as_bytes() == condition.as_bytes()) {
                    return self.fail(format!(
                        "Invalid array filter condition '{}'. Must be one of: {}",
                        text(first),
                        ARRAY_FILTER_CONDITIONS.join(", ")
                    ));
                }
            }
            OperatorType::StringConcat => {
                if !STRING_TYPES.contains(&kind) || is_array {
                    return self.fail(format!("Cannot apply {name} operator to non-string field '{field}'"));
                }
                let Value::Str(concat) = first else {
                    return self.fail(format!("Cannot apply {name} operator: requires a string value"));
                };
                if let Some(current) = &self.current_document {
                    let current = current.get(field).cloned().unwrap_or(Value::Str(Str::EMPTY));
                    let current_len = match &current {
                        Value::Array(_) | Value::Object(_) | Value::Ext(_) => {
                            return Err(Error::new(
                                Kind::TypeError,
                                format!(
                                    "strlen(): Argument #1 ($string) must be of type string, {} given",
                                    current.debug_type()
                                ),
                            ));
                        }
                        other => other.to_php_string().map_or(0, |s| s.len()),
                    };
                    let predicted = current_len + concat.len();
                    let max = attribute.size.unwrap_or(0);
                    if max > 0 && predicted as i64 > max {
                        return self.fail(format!(
                            "Cannot apply {name} operator: result would exceed maximum length of {max} characters"
                        ));
                    }
                }
            }
            OperatorType::StringReplace => {
                if !STRING_TYPES.contains(&kind) {
                    return self.fail(format!("Cannot apply {name} operator to non-string field '{field}'"));
                }
                if values.len() != 2 || !matches!(first, Value::Str(_)) || !matches!(nth(values, 1), Value::Str(_)) {
                    return self.fail(format!(
                        "Cannot apply {name} operator: requires exactly 2 string values (search and replace)"
                    ));
                }
            }
            OperatorType::Toggle => {
                if kind != ColumnType::Boolean {
                    return self.fail(format!("Cannot apply {name} operator to non-boolean field '{field}'"));
                }
            }
            OperatorType::DateAddDays | OperatorType::DateSubDays => {
                if kind != ColumnType::Datetime {
                    return self.fail(format!("Cannot apply {name} operator to non-datetime field '{field}'"));
                }
                if !matches!(first, Value::Int(_)) {
                    return self.fail(format!("Cannot apply {name} operator: requires an integer number of days"));
                }
            }
            OperatorType::DateSetNow => {
                if kind != ColumnType::Datetime {
                    return self.fail(format!("Cannot apply {name} operator to non-datetime field '{field}'"));
                }
            }
        }
        Ok(true)
    }
}

super::validator!(Operator, utopia_validators::Type::Object, false, fallible);
