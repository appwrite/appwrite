//! Operators applied in PHP to a stored value, and the PHP arithmetic and
//! comparisons the adapter uses.
//!
//! PHP: `Memory::applyOperator`, `numericValue`, `stringValue`,
//! `applyNumericLimit`, `preserveNumericType`, `coerceArray`,
//! `matchesArrayFilter`, `shiftDate`, `applyOperators`, and the engine's
//! `+`, `-`, `*`, `/`, `%`, `**`, `<`, `>`, `is_numeric`, `(int)`.

use php_std::value::Number;

use super::documents::json_array;
use crate::error::{Error, Kind, Result};
use crate::operator::{Operator, OperatorType};
use crate::validator::big_int::{BigInt, Operand};
use crate::value::{Array, Str, Value};

/// `(int) $value`.
pub(crate) fn to_long(value: &Value) -> i64 {
    crate::value::to_long(value)
}

/// `is_numeric($value) ? $value + 0 : null`.
pub(crate) fn numeric(value: &Value) -> Option<Value> {
    match value {
        Value::Int(_) | Value::Float(_) => Some(value.clone()),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| of(n)),
        _ => None,
    }
}

/// `is_numeric($value)`.
pub(crate) fn is_numeric(value: &Value) -> bool {
    numeric(value).is_some()
}

fn of(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::Int(i),
        Number::Float(f) => Value::Float(f),
    }
}

fn type_name(v: &Value) -> &'static str {
    match v {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) | Value::Ext(_) => "object",
    }
}

/// The number a value is in arithmetic (`$a + $b`): `null` and booleans as
/// integers, numeric strings parsed; anything else fails as PHP does.
fn number(v: &Value, op: &str, other: &Value) -> Result<Number> {
    Ok(match v {
        Value::Null | Value::Bool(false) => Number::Int(0),
        Value::Bool(true) => Number::Int(1),
        Value::Int(i) => Number::Int(*i),
        Value::Float(f) => Number::Float(*f),
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((n, _)) => n,
            None => {
                return Err(Error::new(
                    Kind::TypeError,
                    format!("Unsupported operand types: string {op} {}", type_name(other)),
                ));
            }
        },
        other_value => {
            return Err(Error::new(
                Kind::TypeError,
                format!("Unsupported operand types: {} {op} {}", type_name(other_value), type_name(other)),
            ));
        }
    })
}

/// `$a + $b`.
pub(crate) fn add(a: &Value, b: &Value) -> Result<Value> {
    Ok(of(php_std::format::add(number(a, "+", b)?, number(b, "+", a)?)))
}

fn sub(a: &Value, b: &Value) -> Result<Value> {
    Ok(of(php_std::format::sub(number(a, "-", b)?, number(b, "-", a)?)))
}

fn mul(a: &Value, b: &Value) -> Result<Value> {
    Ok(of(php_std::format::mul(number(a, "*", b)?, number(b, "*", a)?)))
}

fn div(a: &Value, b: &Value) -> Result<Value> {
    match php_std::format::div(number(a, "/", b)?, number(b, "/", a)?) {
        Some(n) => Ok(of(n)),
        None => Err(Error::new(Kind::DivisionByZero, "Division by zero")),
    }
}

/// `$a ** $b` (`pow_function_base`: an integer power by squaring until it
/// overflows, then a float).
fn pow(a: &Value, b: &Value) -> Result<Value> {
    let (base, exponent) = (number(a, "**", b)?, number(b, "**", a)?);
    Ok(match (base, exponent) {
        (Number::Int(l2), Number::Int(i)) if i >= 0 => {
            if i == 0 {
                return Ok(Value::Int(1));
            }
            if l2 == 0 {
                return Ok(Value::Int(0));
            }
            let (mut l1, mut l2, mut i) = (1i64, l2, i);
            while i >= 1 {
                if i % 2 != 0 {
                    i -= 1;
                    match l1.checked_mul(l2) {
                        Some(r) => l1 = r,
                        None => {
                            let dval = l1 as f64 * l2 as f64;
                            return Ok(Value::Float(dval * php_std::format::pow(l2 as f64, i as f64)));
                        }
                    }
                } else {
                    i /= 2;
                    match l2.checked_mul(l2) {
                        Some(r) => l2 = r,
                        None => {
                            let dval = l2 as f64 * l2 as f64;
                            return Ok(Value::Float(l1 as f64 * php_std::format::pow(dval, i as f64)));
                        }
                    }
                }
            }
            Value::Int(l1)
        }
        (b, e) => Value::Float(php_std::format::pow(b.as_f64(), e.as_f64())),
    })
}

/// `$a < $b`.
pub(crate) fn lt(a: &Value, b: &Value) -> bool {
    a.compare(b) < 0
}

/// `$a <= $b`.
pub(crate) fn le(a: &Value, b: &Value) -> bool {
    a.compare(b) <= 0
}

/// `$a > $b` (the engine's `$b < $a`).
pub(crate) fn gt(a: &Value, b: &Value) -> bool {
    b.compare(a) < 0
}

/// `$a >= $b` (the engine's `$b <= $a`).
pub(crate) fn ge(a: &Value, b: &Value) -> bool {
    b.compare(a) <= 0
}

/// `$values[$i] ?? $default`.
fn nth(values: &Array, i: usize, default: Value) -> Value {
    match values.get(&php_std::ArrayKey::Int(i as i64)) {
        None | Some(Value::Null) => default,
        Some(v) => v.clone(),
    }
}

/// `numericValue($value, $default)`: an integer or float as it is, a
/// numeric string as a number, anything else `$default`.
fn numeric_value(value: &Value, default: Option<Value>) -> Option<Value> {
    match value {
        Value::Int(_) | Value::Float(_) => Some(value.clone()),
        Value::Str(_) => numeric(value).or(default),
        _ => default,
    }
}

/// `stringValue($value)`: a string, a scalar or `null` as `(string)`,
/// anything else `''`.
fn string_value(value: &Value) -> Result<Str> {
    match value {
        Value::Str(s) => Ok(s.clone()),
        Value::Null | Value::Bool(_) | Value::Int(_) | Value::Float(_) => value.to_php_string().map_err(Error::from),
        _ => Ok(Str::EMPTY),
    }
}

/// `preserveNumericType($original, $result)`: an integral float result of
/// an integer stays an integer.
fn preserve(original: &Value, result: Value) -> Value {
    if let (Value::Int(_), Value::Float(f)) = (original, &result)
        && *f == php_std::format::dval_to_lval(*f) as f64
    {
        return Value::Int(php_std::format::dval_to_lval(*f));
    }
    result
}

fn integer(value: &Value) -> Option<Operand<'_>> {
    if BigInt::is_integer_value(value) { Operand::from_value(value) } else { None }
}

/// `applyNumericLimit($original, $candidate, $bound, $isUpper)`: the
/// candidate, or the original when the candidate crosses the bound.
fn apply_numeric_limit(original: &Value, candidate: Value, bound: Option<&Value>, upper: bool) -> Result<Value> {
    let null = Value::Null;
    let bound = bound.unwrap_or(&null);
    if let (Some(o), Some(c), Some(b)) = (integer(original), integer(&candidate), integer(bound)) {
        let ordering = BigInt::compare(c, b)?;
        let crossed = if upper { ordering.is_gt() } else { ordering.is_lt() };
        return Ok(if crossed { BigInt::to_native(o)?.into() } else { candidate });
    }
    let original = numeric(original).unwrap_or(Value::Int(0));
    let candidate = numeric(&candidate).unwrap_or(Value::Int(0));
    if let Some(bound) = numeric(bound)
        && ((upper && gt(&candidate, &bound)) || (!upper && lt(&candidate, &bound)))
    {
        return Ok(original);
    }
    Ok(preserve(&original, candidate))
}

/// `coerceArray($value)`: a list from an array or JSON text.
pub(crate) fn coerce_array(value: &Value) -> Vec<Value> {
    match value {
        Value::Array(a) => a.values().cloned().collect(),
        Value::Str(s) if !s.is_empty() => match json_array(s.as_bytes()) {
            Some(a) => a.into_iter().map(|(_, v)| v).collect(),
            None => Vec::new(),
        },
        _ => Vec::new(),
    }
}

fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

/// `matchesArrayFilter($item, $condition, $compare)`.
fn matches_array_filter(item: &Value, condition: &[u8], compare: &Value) -> bool {
    let both = || match (numeric(item), numeric(compare)) {
        (Some(a), Some(b)) => Some((a, b)),
        _ => None,
    };
    match condition {
        b"equal" => item.loose_eq(compare),
        b"notEqual" => !item.loose_eq(compare),
        b"greaterThan" => both().is_some_and(|(a, b)| gt(&a, &b)),
        b"greaterThanEqual" => both().is_some_and(|(a, b)| ge(&a, &b)),
        b"lessThan" => both().is_some_and(|(a, b)| lt(&a, &b)),
        b"lessThanEqual" => both().is_some_and(|(a, b)| le(&a, &b)),
        b"isNull" => matches!(item, Value::Null),
        b"isNotNull" => !matches!(item, Value::Null),
        _ => true,
    }
}

/// `shiftDate($current, $seconds)`: the date moved by `seconds`, in the
/// database format; an unparsable date as it is (`null` when empty).
fn shift_date(current: &Value, seconds: i64) -> Result<Value> {
    if matches!(current, Value::Null) {
        return Ok(Value::Null);
    }
    let text = string_value(current)?;
    let tz = php_std::datetime::TzInfo::utc();
    let Ok(mut date) = php_std::datetime::DateTime::new(text.as_bytes(), None, &tz) else {
        return Ok(if text.is_empty() { Value::Null } else { Value::Str(text) });
    };
    let modifier = format!("{}{seconds} seconds", if seconds >= 0 { "+" } else { "" });
    date.modify(modifier.as_bytes()).map_err(|e| Error::database(e.to_string()))?;
    Ok(Value::Str(Str::from(crate::datetime::format(&date))))
}

/// The days of a date operator in seconds (`$days * 86400`), refused as
/// PHP refuses the float an overflowing product is for `int $seconds`.
fn days_in_seconds(days: i64, negate: bool) -> Result<i64> {
    let days = if negate { days.checked_neg() } else { Some(days) };
    days.and_then(|d| d.checked_mul(86_400)).ok_or_else(|| {
        Error::new(
            Kind::TypeError,
            "Utopia\\Database\\Adapter\\Memory::shiftDate(): Argument #2 ($seconds) must be of type int, float given",
        )
    })
}

/// `applyOperator($current, $operator)`: the value the operator makes of
/// the stored one.
///
/// Fails ([`Kind::Operator`]) for a bound of an exact big-integer result
/// that is not a whole number, and ([`Kind::Limit`]) for a power out of
/// range without a bound.
pub fn apply_operator(current: &Value, operator: &Operator) -> Result<Value> {
    let values = operator.values();
    let method = operator.method();
    let zero = Value::Int(0);
    let current_or_zero = if matches!(current, Value::Null) { &zero } else { current };
    let operand = nth(&values, 0, Value::Int(1));
    if let Some(exact) = BigInt::calculate_outside_native(method, current_or_zero, &operand) {
        let bound = nth(&values, 1, Value::Null);
        if method == OperatorType::Modulo || !is_numeric(&bound) || matches!(bound, Value::Float(f) if !f.is_finite()) {
            return Ok(exact.into());
        }
        let Some(limit) = Operand::from_value(&bound).and_then(BigInt::integral_value) else {
            let shown = bound.to_php_string().map_err(Error::from)?;
            return Err(Error::operator(format!(
                "Cannot apply {} operator: max/min limit must be a whole number, got {}",
                method.as_str(),
                String::from_utf8_lossy(shown.as_bytes())
            )));
        };
        let upper = matches!(method, OperatorType::Increment | OperatorType::Multiply | OperatorType::Power);
        return apply_numeric_limit(current_or_zero, exact.into(), Some(&Value::from(limit)), upper);
    }

    let base = || numeric(current).unwrap_or(Value::Int(0));
    let by = |default: i64| numeric_value(&nth(&values, 0, Value::Null), Some(Value::Int(default)));
    let bound = || numeric_value(&nth(&values, 1, Value::Null), None);
    Ok(match method {
        OperatorType::Increment => {
            let (by, max, base) = (by(1).unwrap_or(Value::Int(1)), bound(), base());
            let candidate = add(&base, &by)?;
            apply_numeric_limit(&base, candidate, max.as_ref(), true)?
        }
        OperatorType::Decrement => {
            let (by, min, base) = (by(1).unwrap_or(Value::Int(1)), bound(), base());
            let candidate = sub(&base, &by)?;
            apply_numeric_limit(&base, candidate, min.as_ref(), false)?
        }
        OperatorType::Multiply => {
            let (by, max, base) = (by(1).unwrap_or(Value::Int(1)), bound(), base());
            let candidate = mul(&base, &by)?;
            apply_numeric_limit(&base, candidate, max.as_ref(), true)?
        }
        OperatorType::Divide => {
            let (by, min) = (by(1).unwrap_or(Value::Int(1)), bound());
            if by.loose_eq(&Value::Int(0)) {
                return Ok(current.clone());
            }
            let base = base();
            let candidate = div(&base, &by)?;
            apply_numeric_limit(&base, candidate, min.as_ref(), false)?
        }
        OperatorType::Modulo => {
            let divisor = to_long(&by(1).unwrap_or(Value::Int(1)));
            if divisor == 0 {
                return Ok(current.clone());
            }
            let base = if is_numeric(current) { to_long(current) } else { 0 };
            Value::Int(if divisor == -1 { 0 } else { base % divisor })
        }
        OperatorType::Power => {
            let (by, max, base) = (by(1).unwrap_or(Value::Int(1)), bound(), base());
            let by_f = by.to_double();
            if (base.loose_eq(&Value::Int(0)) && lt(&by, &Value::Int(0)))
                || (lt(&base, &Value::Int(0)) && by_f.floor() != by_f)
            {
                if max.is_some() {
                    return Ok(base);
                }
                return Err(Error::limit("Value out of range"));
            }
            let candidate = pow(&base, &by)?;
            if !candidate.to_double().is_finite() {
                if max.is_some() {
                    return Ok(base);
                }
                return Err(Error::limit("Value out of range"));
            }
            apply_numeric_limit(&base, candidate, max.as_ref(), true)?
        }
        OperatorType::StringConcat => {
            let mut out = string_value(current)?.as_bytes().to_vec();
            out.extend_from_slice(string_value(&nth(&values, 0, crate::value::string("")))?.as_bytes());
            Value::Str(Str::from(out))
        }
        OperatorType::StringReplace => {
            let search = string_value(&nth(&values, 0, crate::value::string("")))?;
            let replace = string_value(&nth(&values, 1, crate::value::string("")))?;
            if matches!(current, Value::Null) {
                return Ok(Value::Null);
            }
            let subject = string_value(current)?;
            if search.is_empty() {
                Value::Str(subject)
            } else {
                Value::Str(Str::from(
                    php_std::string::str_replace(search.as_bytes(), replace.as_bytes(), subject.as_bytes())
                        .0
                        .into_owned(),
                ))
            }
        }
        OperatorType::Toggle => Value::Bool(!current.truthy()),
        OperatorType::ArrayAppend => {
            let mut items = coerce_array(current);
            items.extend(values.values().cloned());
            list(items)
        }
        OperatorType::ArrayPrepend => {
            let mut items: Vec<Value> = values.values().cloned().collect();
            items.extend(coerce_array(current));
            list(items)
        }
        OperatorType::ArrayInsert => {
            let mut items = coerce_array(current);
            let index = to_long(&numeric_value(&nth(&values, 0, Value::Null), Some(Value::Int(0))).unwrap_or(zero));
            let index = index.clamp(0, items.len() as i64) as usize;
            items.insert(index, nth(&values, 1, Value::Null));
            list(items)
        }
        OperatorType::ArrayRemove => {
            let needle = nth(&values, 0, Value::Null);
            list(coerce_array(current).into_iter().filter(|item| !item.strict_eq(&needle)).collect())
        }
        OperatorType::ArrayUnique => {
            let items = Array::from_list(coerce_array(current));
            let unique = php_std::sort::array_unique(&items, php_std::sort::SortFlags::Regular).map_err(Error::from)?;
            list(unique.into_iter().map(|(_, v)| v).collect())
        }
        OperatorType::ArrayIntersect | OperatorType::ArrayDiff => {
            let other = Array::from_list(values.values().cloned().collect());
            let keep = method == OperatorType::ArrayIntersect;
            list(
                coerce_array(current)
                    .into_iter()
                    .filter(|item| php_std::sort::in_array(item, &other, false) == keep)
                    .collect(),
            )
        }
        OperatorType::ArrayFilter => {
            let condition = string_value(&nth(&values, 0, crate::value::string("")))?;
            let compare = nth(&values, 1, Value::Null);
            list(
                coerce_array(current)
                    .into_iter()
                    .filter(|item| matches_array_filter(item, condition.as_bytes(), &compare))
                    .collect(),
            )
        }
        OperatorType::DateAddDays => {
            let days = to_long(&numeric_value(&nth(&values, 0, Value::Null), Some(Value::Int(0))).unwrap_or(zero));
            shift_date(current, days_in_seconds(days, false)?)?
        }
        OperatorType::DateSubDays => {
            let days = to_long(&numeric_value(&nth(&values, 0, Value::Null), Some(Value::Int(0))).unwrap_or(zero));
            shift_date(current, days_in_seconds(days, true)?)?
        }
        OperatorType::DateSetNow => Value::Str(Str::from(crate::datetime::now())),
    })
}

/// `applyOperators($attributes, $row)`: each operator applied to the
/// stored value of its attribute (JSON text decoded first), the other
/// values kept.
pub(crate) fn apply_operators(attributes: &Array, row: &Array) -> Result<Array> {
    let mut result = Array::new();
    for (attribute, value) in attributes.iter() {
        if let Some(operator) = crate::value::as_operator(value) {
            let column = super::filter(&super::store::key_text(attribute));
            let mut current = row.get_str(&column).cloned().unwrap_or(Value::Null);
            if let Some(decoded) = super::documents::decode_json_like(&current) {
                current = Value::Array(decoded);
            }
            result.set(attribute.to_owned(), apply_operator(&current, operator)?);
            continue;
        }
        result.set(attribute.to_owned(), value.clone());
    }
    Ok(result)
}
