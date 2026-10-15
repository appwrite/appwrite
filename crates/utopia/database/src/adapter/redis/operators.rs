//! Operators of the [`Redis`] adapter, applied here to the stored value
//! (PHP's Redis adapter keeps its own copy of `applyOperator()`, ported as it
//! is): exact for integers beyond PHP's native range through
//! [`BigInt`], PHP's arithmetic otherwise.

use php_std::datetime::{DateTime, TzInfo};
use php_std::value::Number;
use redis::aio::ConnectionLike;

use super::Redis;
use crate::error::{Error, Kind, Result};
use crate::operator::{Operator, OperatorType};
use crate::validator::big_int::{BigInt, Operand};
use crate::value::{Array, ArrayKey, Ext, Str, Value, to_long, to_text};

/// `is_numeric($value) ? $value + 0 : null`.
pub(super) fn numeric(value: &Value) -> Option<Number> {
    match value {
        Value::Int(i) => Some(Number::Int(*i)),
        Value::Float(f) => Some(Number::Float(*f)),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| n),
        _ => None,
    }
}

/// `numericOr($value, 0)`.
pub(super) fn numeric_or_zero(value: &Value) -> Number {
    numeric(value).unwrap_or(Number::Int(0))
}

/// A number as a value.
pub(super) fn number_value(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::Int(i),
        Number::Float(f) => Value::Float(f),
    }
}

/// PHP `$a < $b`.
pub(super) fn lt(a: &Value, b: &Value) -> bool {
    a.compare(b) < 0
}

/// PHP `$a <= $b`.
pub(super) fn le(a: &Value, b: &Value) -> bool {
    a.compare(b) <= 0
}

/// PHP `$a > $b` (`$b < $a`, as the engine compiles it).
pub(super) fn gt(a: &Value, b: &Value) -> bool {
    lt(b, a)
}

/// PHP `$a >= $b`.
pub(super) fn ge(a: &Value, b: &Value) -> bool {
    le(b, a)
}

/// PHP `$a + $b` of two numbers.
pub(super) fn add_values(a: &Value, b: &Value) -> Value {
    number_value(php_std::format::add(numeric_or_zero(a), numeric_or_zero(b)))
}

/// `$values[$i] ?? $default`.
fn arg(values: &Array, i: i64, default: Value) -> Value {
    match values.get(&ArrayKey::Int(i)) {
        Some(Value::Null) | None => default,
        Some(v) => v.clone(),
    }
}

/// `numericOr($value, $default)`.
fn numeric_or(value: &Value, default: Number) -> Number {
    numeric(value).unwrap_or(default)
}

/// `intOr($value, $default)`: `(int)` of a numeric value.
fn int_or(value: &Value, default: i64) -> i64 {
    if numeric(value).is_some() { to_long(value) } else { default }
}

/// `stringOrEmpty()`.
fn string_or_empty(value: &Value) -> Vec<u8> {
    super::string_or_empty(value)
}

/// PHP `$base ** $exponent` of two numbers.
fn pow(base: Number, exponent: Number) -> Number {
    match (base, exponent) {
        (Number::Int(b), Number::Int(e)) if e >= 0 => int_pow(b, e),
        _ => Number::Float(php_std::format::pow(base.as_f64(), exponent.as_f64())),
    }
}

/// `int ** int` with a non-negative exponent, as the engine computes it: by
/// squaring, switching to a float at the first overflow.
fn int_pow(base: i64, exponent: i64) -> Number {
    let (mut l1, mut l2, mut i) = (1i64, base, exponent);
    if i == 0 {
        return Number::Int(1);
    }
    if l2 == 0 {
        return Number::Int(0);
    }
    while i >= 1 {
        if i % 2 != 0 {
            i -= 1;
            match l1.checked_mul(l2) {
                Some(v) => l1 = v,
                None => {
                    let dval = l1 as f64 * l2 as f64;
                    return Number::Float(dval * php_std::format::pow(l2 as f64, i as f64));
                }
            }
        } else {
            i /= 2;
            match l2.checked_mul(l2) {
                Some(v) => l2 = v,
                None => {
                    let dval = l2 as f64 * l2 as f64;
                    return Number::Float(l1 as f64 * php_std::format::pow(dval, i as f64));
                }
            }
        }
    }
    Number::Int(l1)
}

/// `preserveNumericType()`: an integral float result of an integer stays an
/// integer.
fn preserve(original: Number, result: Number) -> Number {
    if let (Number::Int(_), Number::Float(f)) = (original, result) {
        let int = php_std::format::dval_to_lval(f);
        if int as f64 == f {
            return Number::Int(int);
        }
    }
    result
}

/// `applyNumericLimit()`: `candidate`, or `original` where it crosses
/// `bound` (above it when `upper`, below it otherwise); exact when all three
/// are integers.
fn apply_numeric_limit(original: &Value, candidate: &Value, bound: &Value, upper: bool) -> Result<Value> {
    if BigInt::is_integer_value(original) && BigInt::is_integer_value(candidate) && BigInt::is_integer_value(bound) {
        fn operand(v: &Value) -> Operand<'_> {
            Operand::from_value(v).unwrap_or(Operand::Int(0))
        }
        let ordering = BigInt::compare(operand(candidate), operand(bound))?;
        let crossed = if upper { ordering.is_gt() } else { ordering.is_lt() };
        let kept = if crossed { original } else { candidate };
        return Ok(BigInt::to_native(operand(kept))?.into());
    }
    let original = numeric_or_zero(original);
    let candidate = numeric_or_zero(candidate);
    if let Some(bound) = numeric(bound) {
        let (c, b) = (number_value(candidate), number_value(bound));
        if (upper && gt(&c, &b)) || (!upper && lt(&c, &b)) {
            return Ok(number_value(original));
        }
    }
    Ok(number_value(preserve(original, candidate)))
}

/// `coerceArray()`: the values of an array, or of a JSON array string.
fn coerce_array(value: &Value) -> Vec<Value> {
    match value {
        Value::Array(a) => a.values().cloned().collect(),
        Value::Str(s) if !s.is_empty() => match super::json_decode_lenient(s.as_bytes()) {
            Some(Value::Array(a)) => a.values().cloned().collect(),
            _ => Vec::new(),
        },
        _ => Vec::new(),
    }
}

/// A list value.
fn list(items: Vec<Value>) -> Value {
    crate::value::list(items)
}

/// `matchesArrayFilter()`.
fn array_filter_matches(item: &Value, condition: &[u8], compare: &Value) -> bool {
    let both = || match (numeric(item), numeric(compare)) {
        (Some(a), Some(b)) => Some((number_value(a), number_value(b))),
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

/// `shiftDate()`: the date `seconds` later in the database format; `null`
/// for `null`, and a value that is not a date as it is (`null` when empty).
fn shift_date(current: &Value, seconds: i64) -> Value {
    if matches!(current, Value::Null) {
        return Value::Null;
    }
    let text = string_or_empty(current);
    let Ok(mut base) = DateTime::new(&text, None, &TzInfo::utc()) else {
        return if text.is_empty() { Value::Null } else { Value::Str(Str::from(text)) };
    };
    let modifier = format!("{}{seconds} seconds", if seconds >= 0 { "+" } else { "" });
    let _ = base.modify(modifier.as_bytes());
    Value::Str(Str::from(crate::datetime::format(&base)))
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Redis<C> {
    /// `applyOperators()`: each operator of `attributes` applied to the
    /// stored value of its attribute in `existing`; other values as they are.
    pub(super) fn apply_operators(&self, attributes: Array, existing: &Array) -> Result<Array> {
        let mut result = Array::with_capacity(attributes.len());
        for (key, value) in attributes {
            let value = match value {
                Value::Ext(Ext::Operator(operator)) => {
                    let current = existing.get(&key).cloned().unwrap_or(Value::Null);
                    self.apply_operator(&current, &operator)?
                }
                other => other,
            };
            result.set(key, value);
        }
        Ok(result)
    }

    /// One operator applied to a stored value (`applyOperator()`).
    ///
    /// Public for tests of the operator arithmetic (PHP reaches the
    /// protected method through a subclass).
    #[doc(hidden)]
    pub fn apply_operator(&self, current: &Value, operator: &Operator) -> Result<Value> {
        let values = operator.values();
        let method = operator.method();
        let current_or_zero = if matches!(current, Value::Null) { Value::Int(0) } else { current.clone() };
        if let Some(exact) = BigInt::calculate_outside_native(method, &current_or_zero, &arg(&values, 0, Value::Int(1)))
        {
            let bound = arg(&values, 1, Value::Null);
            let not_finite = matches!(bound, Value::Float(f) if !f.is_finite());
            if method == OperatorType::Modulo || numeric(&bound).is_none() || not_finite {
                return Ok(exact.into());
            }
            let Some(limit) = Operand::from_value(&bound).and_then(BigInt::integral_value) else {
                return Err(Error::operator(format!(
                    "Cannot apply {} operator: max/min limit must be a whole number, got {}",
                    method.as_str(),
                    to_text(&bound)
                )));
            };
            let upper = matches!(method, OperatorType::Increment | OperatorType::Multiply | OperatorType::Power);
            return apply_numeric_limit(&current_or_zero, &Value::from(exact), &Value::from(limit), upper);
        }
        let base = || numeric_or_zero(current);
        Ok(match method {
            OperatorType::Increment | OperatorType::Decrement | OperatorType::Multiply => {
                let by = numeric_or(&arg(&values, 0, Value::Int(1)), Number::Int(1));
                let limit = arg(&values, 1, Value::Null);
                let base = base();
                let candidate = match method {
                    OperatorType::Increment => php_std::format::add(base, by),
                    OperatorType::Decrement => php_std::format::sub(base, by),
                    _ => php_std::format::mul(base, by),
                };
                let upper = method != OperatorType::Decrement;
                apply_numeric_limit(&number_value(base), &number_value(candidate), &limit, upper)?
            }
            OperatorType::Divide => {
                let by = arg(&values, 0, Value::Int(1));
                let min = arg(&values, 1, Value::Null);
                let Some(divisor) = numeric(&by).filter(|_| !by.loose_eq(&Value::Int(0))) else {
                    return Ok(current.clone());
                };
                let base = base();
                let candidate = php_std::format::div(base, divisor)
                    .ok_or_else(|| Error::new(Kind::DivisionByZero, "Division by zero"))?;
                apply_numeric_limit(&number_value(base), &number_value(candidate), &min, false)?
            }
            OperatorType::Modulo => {
                let by = arg(&values, 0, Value::Int(1));
                if numeric(&by).is_none() || by.loose_eq(&Value::Int(0)) {
                    return Ok(current.clone());
                }
                let base = if numeric(current).is_some() { to_long(current) } else { 0 };
                let divisor = to_long(&by);
                if divisor == 0 {
                    return Err(Error::new(Kind::DivisionByZero, "Modulo by zero"));
                }
                Value::Int(if divisor == -1 { 0 } else { base % divisor })
            }
            OperatorType::Power => {
                let by = numeric_or(&arg(&values, 0, Value::Int(1)), Number::Int(1));
                let max = arg(&values, 1, Value::Null);
                let base = base();
                let (b, e) = (number_value(base), number_value(by));
                let zero = Value::Int(0);
                let exponent = by.as_f64();
                if (b.loose_eq(&zero) && lt(&e, &zero)) || (lt(&b, &zero) && exponent.floor() != exponent) {
                    if numeric(&max).is_some() {
                        return Ok(b);
                    }
                    return Err(Error::limit("Value out of range"));
                }
                let candidate = pow(base, by);
                if !candidate.as_f64().is_finite() {
                    if numeric(&max).is_some() {
                        return Ok(b);
                    }
                    return Err(Error::limit("Value out of range"));
                }
                apply_numeric_limit(&b, &number_value(candidate), &max, true)?
            }
            OperatorType::StringConcat => {
                let mut text = string_or_empty(current);
                text.extend(string_or_empty(&arg(&values, 0, Value::Str(Str::EMPTY))));
                Value::Str(Str::from(text))
            }
            OperatorType::StringReplace => {
                let search = string_or_empty(&arg(&values, 0, Value::Str(Str::EMPTY)));
                let replace = string_or_empty(&arg(&values, 1, Value::Str(Str::EMPTY)));
                if matches!(current, Value::Null) {
                    return Ok(Value::Null);
                }
                let subject = string_or_empty(current);
                let (out, _) = php_std::string::str_replace(&search, &replace, &subject);
                Value::Str(Str::from(out.into_owned()))
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
                let index = int_or(&arg(&values, 0, Value::Int(0)), 0).clamp(0, items.len() as i64) as usize;
                items.insert(index, arg(&values, 1, Value::Null));
                list(items)
            }
            OperatorType::ArrayRemove => {
                let needle = arg(&values, 0, Value::Null);
                list(coerce_array(current).into_iter().filter(|item| !item.strict_eq(&needle)).collect())
            }
            OperatorType::ArrayUnique => {
                let items: Array = coerce_array(current).into_iter().collect();
                let unique = php_std::sort::array_unique(&items, php_std::sort::SortFlags::Regular)
                    .map_err(Error::from)?;
                list(unique.values().cloned().collect())
            }
            OperatorType::ArrayIntersect | OperatorType::ArrayDiff => {
                let other: Array = values.values().cloned().collect();
                let keep = method == OperatorType::ArrayIntersect;
                list(
                    coerce_array(current)
                        .into_iter()
                        .filter(|item| php_std::sort::in_array(item, &other, false) == keep)
                        .collect(),
                )
            }
            OperatorType::ArrayFilter => {
                let condition = string_or_empty(&arg(&values, 0, Value::Str(Str::EMPTY)));
                let compare = arg(&values, 1, Value::Null);
                list(
                    coerce_array(current)
                        .into_iter()
                        .filter(|item| array_filter_matches(item, &condition, &compare))
                        .collect(),
                )
            }
            OperatorType::DateAddDays => {
                let days = int_or(&arg(&values, 0, Value::Int(0)), 0);
                shift_date(current, days.wrapping_mul(86400))
            }
            OperatorType::DateSubDays => {
                let days = int_or(&arg(&values, 0, Value::Int(0)), 0);
                shift_date(current, days.wrapping_neg().wrapping_mul(86400))
            }
            OperatorType::DateSetNow => Value::Str(Str::from(crate::datetime::now())),
        })
    }
}

