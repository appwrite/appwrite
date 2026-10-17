//! [`Operator`]: atomic update operators against the attributes they
//! update.
//!
//! PHP: `Utopia\Database\Validator\Operator`.

use std::collections::HashMap;

use php_std::value::Number;

use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::operator::{Operator as DatabaseOperator, OperatorMethod};
use crate::validator::{Message, array_key, index, is_numeric, text, text_key};
use crate::value::{ArrayKey, Ext, Str, Value, as_document};

/// `Operator::MAX_ARRAY_OPERATOR_SIZE`: the most items an array operator
/// may carry.
pub const MAX_ARRAY_OPERATOR_SIZE: usize = 10000;

/// `Operator::ARRAY_FILTER_CONDITIONS`: the conditions of `arrayFilter`.
pub const ARRAY_FILTER_CONDITIONS: [&str; 8] =
    ["equal", "notEqual", "greaterThan", "greaterThanEqual", "lessThan", "lessThanEqual", "isNull", "isNotNull"];

/// Where PHP reports an `Operator::parse()` argument error from.
const PARSE_CALLER: &str = "/usr/src/code/vendor/utopia-php/database/src/Database/Validator/Operator.php on line 108";

const STRING_TYPES: [&str; 5] = ["string", "varchar", "text", "mediumtext", "longtext"];

/// An operator valid for the attribute it updates: an existing attribute
/// of a type the method applies to (numbers for arithmetic, arrays or
/// many-side relationships for array methods, strings, booleans, dates),
/// with well-formed values; with the current document, an integer result
/// within 32-bit range, a string result within the attribute's size, and an
/// insert index within the array.
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::operator::{Operator, OperatorMethod};
/// use utopia_database::validator::operator::Operator as OperatorValidator;
/// use utopia_database::value::{Value, list, string};
///
/// let count = Document::from_pairs([("$id", string("count")), ("key", string("count")), ("type", string("integer"))]).unwrap();
/// let collection = Document::from_pairs([("$id", string("c")), ("attributes", list([count.into()]))]).unwrap();
/// let validator = OperatorValidator::new(&collection, None);
/// assert!(validator.check(&Operator::new(OperatorMethod::Increment, "count", vec![Value::Int(1)])).unwrap());
/// assert!(!validator.check(&Operator::new(OperatorMethod::Toggle, "count", vec![])).unwrap());
/// assert_eq!(validator.description(), "Cannot apply toggle operator to non-boolean field 'count'");
/// ```
///
/// PHP: `Utopia\Database\Validator\Operator` (`new Operator($collection, $currentDocument)`).
#[derive(Clone, Debug)]
pub struct Operator {
    attributes: HashMap<ArrayKey, Document>,
    current_document: Option<Document>,
    message: Message,
}

/// An arithmetic operator of PHP.
#[derive(Clone, Copy)]
pub(crate) enum Arith {
    Add,
    Sub,
    Mul,
    Div,
    Mod,
    Pow,
}

impl Arith {
    fn symbol(self) -> &'static str {
        match self {
            Arith::Add => "+",
            Arith::Sub => "-",
            Arith::Mul => "*",
            Arith::Div => "/",
            Arith::Mod => "%",
            Arith::Pow => "**",
        }
    }
}

/// The number PHP's arithmetic reads from a value: `null` is 0, booleans
/// 0 or 1, strings their (leading) number; `None` where PHP throws.
fn operand(value: &Value) -> Option<Number> {
    Some(match value {
        Value::Null => Number::Int(0),
        Value::Bool(b) => Number::Int(i64::from(*b)),
        Value::Int(i) => Number::Int(*i),
        Value::Float(f) => Number::Float(*f),
        Value::Str(s) => {
            let s = s.as_bytes();
            if let Some((n, _)) = php_std::value::numeric_bytes_ex(s) {
                return Some(n);
            }
            // A leading number ("5abc"): PHP warns and uses it.
            let start = s.iter().take_while(|c| matches!(c, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C)).count();
            let mut end = start;
            if end < s.len() && matches!(s[end], b'+' | b'-') {
                end += 1;
            }
            let digits = |from: usize| s[from..].iter().take_while(|c| c.is_ascii_digit()).count();
            let int_digits = digits(end);
            end += int_digits;
            let mut frac_digits = 0;
            if end < s.len() && s[end] == b'.' {
                frac_digits = digits(end + 1);
                if int_digits + frac_digits > 0 {
                    end += 1 + frac_digits;
                }
            }
            if int_digits + frac_digits == 0 {
                return None;
            }
            if end < s.len() && matches!(s[end], b'e' | b'E') {
                let mut e = end + 1;
                if e < s.len() && matches!(s[e], b'+' | b'-') {
                    e += 1;
                }
                let exp = digits(e);
                if exp > 0 {
                    end = e + exp;
                }
            }
            php_std::value::numeric_bytes_ex(&s[start..end]).map(|(n, _)| n)?
        }
        _ => return None,
    })
}

/// `(int) $float` for `%`: 0 when it does not fit.
fn to_int(n: Number) -> i64 {
    match n {
        Number::Int(i) => i,
        Number::Float(f) if (-9.223_372_036_854_776e18..9.223_372_036_854_776e18).contains(&f) => f as i64,
        Number::Float(_) => 0,
    }
}

/// `$a <op> $b` as PHP computes it on scalars.
pub(crate) fn arith(op: Arith, a: &Value, b: &Value) -> Result<Value> {
    let (Some(x), Some(y)) = (operand(a), operand(b)) else {
        return Err(Error::new(
            Kind::TypeError,
            format!("Unsupported operand types: {} {} {}", a.debug_type(), op.symbol(), b.debug_type()),
        ));
    };
    let float = |f: f64| Ok(Value::Float(f));
    match op {
        Arith::Add | Arith::Sub | Arith::Mul => match (x, y) {
            (Number::Int(i), Number::Int(j)) => {
                let exact = match op {
                    Arith::Add => i.checked_add(j),
                    Arith::Sub => i.checked_sub(j),
                    _ => i.checked_mul(j),
                };
                match exact {
                    Some(r) => Ok(Value::Int(r)),
                    None => float(match op {
                        Arith::Add => i as f64 + j as f64,
                        Arith::Sub => i as f64 - j as f64,
                        _ => i as f64 * j as f64,
                    }),
                }
            }
            (x, y) => float(match op {
                Arith::Add => x.as_f64() + y.as_f64(),
                Arith::Sub => x.as_f64() - y.as_f64(),
                _ => x.as_f64() * y.as_f64(),
            }),
        },
        Arith::Div => {
            if y.as_f64() == 0.0 {
                return Err(Error::new(Kind::DivisionByZero, "Division by zero"));
            }
            match (x, y) {
                (Number::Int(i), Number::Int(j)) if j != -1 && i % j == 0 => Ok(Value::Int(i / j)),
                (Number::Int(i), Number::Int(-1)) if i != i64::MIN => Ok(Value::Int(-i)),
                (x, y) => float(x.as_f64() / y.as_f64()),
            }
        }
        Arith::Mod => {
            let (i, j) = (to_int(x), to_int(y));
            if j == 0 {
                return Err(Error::new(Kind::DivisionByZero, "Modulo by zero"));
            }
            Ok(Value::Int(if j == -1 { 0 } else { i % j }))
        }
        Arith::Pow => match (x, y) {
            (Number::Int(i), Number::Int(j)) if j >= 0 => {
                let exact = u32::try_from(j).ok().and_then(|j| i.checked_pow(j));
                match exact {
                    Some(r) => Ok(Value::Int(r)),
                    None => float((i as f64).powf(j as f64)),
                }
            }
            (x, y) => float(x.as_f64().powf(y.as_f64())),
        },
    }
}

/// `Operator::parse($string)` as the validator calls it; the message after
/// `Invalid operator: `.
fn parse(value: &Value) -> std::result::Result<DatabaseOperator, String> {
    let Value::Str(s) = value else {
        return Err(match value {
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => return parse(&Value::Str(Str::from(text(value)))),
            other => format!(
                "Utopia\\Database\\Operator::parse(): Argument #1 ($operator) must be of type string, {} given, called in {PARSE_CALLER}",
                other.debug_type()
            ),
        });
    };
    let decoded =
        php_std::json::decode(s.as_bytes(), Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
            .map_err(|e| format!("Invalid operator: {}", e.message()))?;
    let decoded = crate::value::from_plain(decoded);
    let Value::Array(operator) = decoded else {
        return Err(format!("Invalid operator. Must be an array, got {}", decoded.type_name()));
    };
    let field = |key: &str, default: Value| match operator.get_str(key) {
        Some(Value::Null) | None => default,
        Some(v) => v.clone(),
    };
    let method = field("method", Value::from(""));
    let attribute = field("attribute", Value::from(""));
    let values = field("values", Value::Array(Default::default()));
    let Value::Str(method) = method else {
        return Err(format!("Invalid operator method. Must be a string, got {}", method.type_name()));
    };
    let method = String::from_utf8_lossy(method.as_bytes()).into_owned();
    let method = OperatorMethod::from_name(&method);
    if let OperatorMethod::Other(m) = &method {
        return Err(format!("Invalid operator method: {m}"));
    }
    let Value::Str(attribute) = attribute else {
        return Err(format!("Invalid operator attribute. Must be a string, got {}", attribute.type_name()));
    };
    let Value::Array(values) = values else {
        return Err(format!("Invalid operator values. Must be an array, got {}", values.type_name()));
    };
    Ok(DatabaseOperator::new(
        method,
        String::from_utf8_lossy(attribute.as_bytes()).into_owned(),
        values.into_iter().map(|(_, v)| v).collect(),
    ))
}

/// Whether a relationship attribute holds many documents on this side
/// (`isRelationshipArray`).
fn is_relationship_array(attribute: &Document) -> bool {
    let options = attribute.get("options").cloned().unwrap_or(Value::Array(Default::default()));
    let relation = index(&options, "relationType");
    let side = index(&options, "side");
    let is = |v: &Value, s: &str| v.strict_eq(&Value::from(s));
    is(relation, Database::RELATION_MANY_TO_MANY)
        || (is(relation, Database::RELATION_ONE_TO_MANY) && is(side, Database::RELATION_SIDE_PARENT))
        || (is(relation, Database::RELATION_MANY_TO_ONE) && is(side, Database::RELATION_SIDE_CHILD))
}

/// A relationship value: a document id or a document.
fn is_relationship_value(item: &Value) -> bool {
    matches!(item, Value::Str(_) | Value::Ext(Ext::Document(_)))
}

fn in_strings(needle: &Value, haystack: &[&str]) -> bool {
    haystack.iter().any(|h| needle.loose_eq(&Value::from(*h)))
}

impl Operator {
    /// `new Operator($collection, $currentDocument)`: the collection's
    /// attributes by `key` (or `$id`).
    pub fn new(collection: &Document, current_document: Option<Document>) -> Self {
        let mut attributes = HashMap::new();
        if let Some(Value::Array(list)) = collection.get("attributes") {
            for attribute in list.values().filter_map(as_document) {
                let key = match attribute.get("key") {
                    Some(k) => k.clone(),
                    None => Value::from(attribute.id()),
                };
                attributes.insert(array_key(&key), attribute.clone());
            }
        }
        Self { attributes, current_document, message: Message::new("Invalid operator") }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    fn fail(&self, message: impl Into<String>) -> Result<bool> {
        self.message.set(message);
        Ok(false)
    }

    /// `isValid($value)`: an operator, or a value `Operator::parse` reads
    /// as one. Fails where PHP's arithmetic throws while predicting a
    /// result (a `TypeError` on a current value that is not a number, a
    /// `DivisionByZeroError` on a modulo by a fraction).
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match value {
            Value::Ext(Ext::Operator(o)) => self.check(o),
            other => match parse(other) {
                Ok(o) => self.check(&o),
                Err(message) => self.fail(format!("Invalid operator: {message}")),
            },
        }
    }

    /// [`Operator::is_valid`] of an operator.
    pub fn check(&self, operator: &DatabaseOperator) -> Result<bool> {
        let method = operator.method();
        if let OperatorMethod::Other(m) = method {
            return self.fail(format!("Invalid operator method: {m}"));
        }
        let Some(attribute) = self.attributes.get(&text_key(operator.attribute())) else {
            return self.fail(format!("Attribute '{}' does not exist in collection", operator.attribute()));
        };
        self.check_for_attribute(operator, attribute)
    }

    fn check_for_attribute(&self, operator: &DatabaseOperator, attribute: &Document) -> Result<bool> {
        let method = operator.method();
        let name = method.as_str();
        let field = operator.attribute();
        let values = operator.values();
        let first = values.first();
        let kind = attribute.get("type").cloned().unwrap_or(Value::Null);
        let is_array = attribute.get("array").is_some_and(Value::truthy);
        let relationship = kind.strict_eq(&Value::from(Database::VAR_RELATIONSHIP));
        let integer = kind.strict_eq(&Value::from(Database::VAR_INTEGER));

        // Operators run on the stored value, which for an encrypted
        // attribute is the ciphertext.
        let encrypt = Value::from("encrypt");
        match attribute.get("filters") {
            None => {}
            Some(Value::Array(filters)) => {
                if filters.values().any(|f| f.strict_eq(&encrypt)) {
                    return self.fail(format!("Cannot apply {name} operator to encrypted attribute '{field}'"));
                }
            }
            Some(other) => {
                return Err(Error::new(
                    Kind::TypeError,
                    format!("in_array(): Argument #2 ($haystack) must be of type array, {} given", other.debug_type()),
                ));
            }
        }

        if matches!(
            method,
            OperatorMethod::ArrayAppend
                | OperatorMethod::ArrayPrepend
                | OperatorMethod::ArrayIntersect
                | OperatorMethod::ArrayDiff
                | OperatorMethod::ArrayRemove
        ) {
            let size = match first {
                Some(Value::Array(a)) => a.len(),
                _ => values.len(),
            };
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
        let int_range = |item: &Value| {
            is_numeric(item)
                && (item.compare(&Value::Int(Database::MAX_INT)) > 0
                    || item.compare(&Value::Int(Database::MIN_INT)) < 0)
        };

        match method {
            OperatorMethod::Increment
            | OperatorMethod::Decrement
            | OperatorMethod::Multiply
            | OperatorMethod::Divide
            | OperatorMethod::Modulo
            | OperatorMethod::Power => {
                if !in_strings(&kind, &[Database::VAR_INTEGER, Database::VAR_FLOAT]) {
                    return self.fail(format!("Cannot apply {name} operator to non-numeric field '{field}'"));
                }
                let Some(value) = first.filter(|v| !matches!(v, Value::Null) && is_numeric(v)) else {
                    return self.fail(format!(
                        "Cannot apply {name} operator: value must be numeric, got {}",
                        first.map_or("NULL", Value::type_name)
                    ));
                };
                if matches!(method, OperatorMethod::Divide | OperatorMethod::Modulo) && value.to_double() == 0.0 {
                    let what = if method == &OperatorMethod::Divide { "division" } else { "modulo" };
                    return self.fail(format!("Cannot apply {name} operator: {what} by zero"));
                }
                if let Some(limit) = values.get(1)
                    && !matches!(limit, Value::Null)
                    && !is_numeric(limit)
                {
                    return self.fail(format!(
                        "Cannot apply {name} operator: max/min limit must be numeric, got {}",
                        limit.type_name()
                    ));
                }
                if let Some(current) = &self.current_document
                    && integer
                    && values.get(1).is_none_or(|v| matches!(v, Value::Null))
                {
                    let current = current.get(field).cloned().unwrap_or(Value::Int(0));
                    let op = match method {
                        OperatorMethod::Increment => Arith::Add,
                        OperatorMethod::Decrement => Arith::Sub,
                        OperatorMethod::Multiply => Arith::Mul,
                        OperatorMethod::Divide => Arith::Div,
                        OperatorMethod::Modulo => Arith::Mod,
                        _ => Arith::Pow,
                    };
                    let predicted = arith(op, &current, value)?;
                    if predicted.compare(&Value::Int(Database::MAX_INT)) > 0 {
                        return self.fail(format!(
                            "Cannot apply {name} operator: would overflow maximum value of {}",
                            Database::MAX_INT
                        ));
                    }
                    if predicted.compare(&Value::Int(Database::MIN_INT)) < 0 {
                        return self.fail(format!(
                            "Cannot apply {name} operator: would underflow minimum value of {}",
                            Database::MIN_INT
                        ));
                    }
                }
            }
            OperatorMethod::ArrayAppend | OperatorMethod::ArrayPrepend => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                    if !values.iter().all(is_relationship_value) {
                        return self.fail(relationship_values());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot apply {name} operator to non-array field '{field}'"));
                }
                if !values.is_empty() && integer {
                    let items: Vec<&Value> = match first {
                        Some(Value::Array(a)) => a.values().collect(),
                        _ => values.iter().collect(),
                    };
                    if items.into_iter().any(int_range) {
                        return self.fail(format!(
                            "Cannot apply {name} operator: array items must be between {} and {}",
                            Database::MIN_INT,
                            Database::MAX_INT
                        ));
                    }
                }
            }
            OperatorMethod::ArrayUnique => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot apply {name} operator to non-array field '{field}'"));
                }
            }
            OperatorMethod::ArrayInsert => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot apply {name} operator to non-array field '{field}'"));
                }
                if values.len() != 2 {
                    return self
                        .fail(format!("Cannot apply {name} operator: requires exactly 2 values (index and value)"));
                }
                let Value::Int(index) = values[0] else {
                    return self.fail(format!("Cannot apply {name} operator: index must be a non-negative integer"));
                };
                if index < 0 {
                    return self.fail(format!("Cannot apply {name} operator: index must be a non-negative integer"));
                }
                let insert = &values[1];
                if relationship && !is_relationship_value(insert) {
                    return self.fail(relationship_values());
                }
                if integer && int_range(insert) {
                    return self.fail(format!(
                        "Cannot apply {name} operator: array items must be between {} and {}",
                        Database::MIN_INT,
                        Database::MAX_INT
                    ));
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
            OperatorMethod::ArrayRemove => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                    let items: Vec<&Value> = match first {
                        Some(Value::Array(a)) => a.values().collect(),
                        _ => values.iter().collect(),
                    };
                    if !items.into_iter().all(is_relationship_value) {
                        return self.fail(relationship_values());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot apply {name} operator to non-array field '{field}'"));
                }
                if values.is_empty() {
                    return self.fail(format!("Cannot apply {name} operator: requires a value to remove"));
                }
            }
            OperatorMethod::ArrayIntersect => {
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
                if relationship && !values.iter().all(is_relationship_value) {
                    return self.fail(relationship_values());
                }
            }
            OperatorMethod::ArrayDiff => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                    if !values.iter().all(is_relationship_value) {
                        return self.fail(relationship_values());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot use {name} operator on non-array attribute '{field}'"));
                }
            }
            OperatorMethod::ArrayFilter => {
                if relationship {
                    if !is_relationship_array(attribute) {
                        return self.fail(single_relationship());
                    }
                } else if !is_array {
                    return self.fail(format!("Cannot apply {name} operator to non-array field '{field}'"));
                }
                if values.is_empty() || values.len() > 2 {
                    return self.fail(format!(
                        "Cannot apply {name} operator: requires 1 or 2 values (condition and optional comparison value)"
                    ));
                }
                let Value::Str(condition) = &values[0] else {
                    return self.fail(format!("Cannot apply {name} operator: condition must be a string"));
                };
                if !ARRAY_FILTER_CONDITIONS.iter().any(|c| c.as_bytes() == condition.as_bytes()) {
                    return self.fail(format!(
                        "Invalid array filter condition '{}'. Must be one of: {}",
                        text(&values[0]),
                        ARRAY_FILTER_CONDITIONS.join(", ")
                    ));
                }
            }
            OperatorMethod::StringConcat => {
                if !in_strings(&kind, &STRING_TYPES) || is_array {
                    return self.fail(format!("Cannot apply {name} operator to non-string field '{field}'"));
                }
                let Some(Value::Str(concat)) = first else {
                    return self.fail(format!("Cannot apply {name} operator: requires a string value"));
                };
                if let Some(current) = &self.current_document {
                    let current = current.get(field).cloned().unwrap_or(Value::from(""));
                    let current_len = match &current {
                        Value::Str(s) => s.as_bytes().len(),
                        Value::Array(_) | Value::Object(_) | Value::Ext(_) => {
                            return Err(Error::new(
                                Kind::TypeError,
                                format!(
                                    "strlen(): Argument #1 ($string) must be of type string, {} given",
                                    current.debug_type()
                                ),
                            ));
                        }
                        other => text(other).len(),
                    };
                    let predicted = current_len + concat.as_bytes().len();
                    let max = attribute.get("size").cloned().unwrap_or(Value::Int(0));
                    if max.compare(&Value::Int(0)) > 0 && Value::Int(predicted as i64).compare(&max) > 0 {
                        return self.fail(format!(
                            "Cannot apply {name} operator: result would exceed maximum length of {} characters",
                            text(&max)
                        ));
                    }
                }
            }
            OperatorMethod::StringReplace => {
                if !in_strings(&kind, &STRING_TYPES) {
                    return self.fail(format!("Cannot apply {name} operator to non-string field '{field}'"));
                }
                if values.len() != 2 || !matches!(values[0], Value::Str(_)) || !matches!(values[1], Value::Str(_)) {
                    return self.fail(format!(
                        "Cannot apply {name} operator: requires exactly 2 string values (search and replace)"
                    ));
                }
            }
            OperatorMethod::Toggle => {
                if !kind.strict_eq(&Value::from(Database::VAR_BOOLEAN)) {
                    return self.fail(format!("Cannot apply {name} operator to non-boolean field '{field}'"));
                }
            }
            OperatorMethod::DateAddDays | OperatorMethod::DateSubDays => {
                if !kind.strict_eq(&Value::from(Database::VAR_DATETIME)) {
                    return self.fail(format!("Cannot apply {name} operator to non-datetime field '{field}'"));
                }
                if !matches!(first, Some(Value::Int(_))) {
                    return self.fail(format!("Cannot apply {name} operator: requires an integer number of days"));
                }
            }
            OperatorMethod::DateSetNow => {
                if !kind.strict_eq(&Value::from(Database::VAR_DATETIME)) {
                    return self.fail(format!("Cannot apply {name} operator to non-datetime field '{field}'"));
                }
            }
            OperatorMethod::Other(_) => {
                return self.fail(format!("Cannot apply {name} operator: unsupported operator method"));
            }
        }
        Ok(true)
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

super::validator!(Operator, utopia_validators::Type::Object, false, fallible);
