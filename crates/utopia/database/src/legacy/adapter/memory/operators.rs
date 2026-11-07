//! Operators applied in PHP to a stored value, and the PHP arithmetic and
//! comparisons the adapter uses.
//!
//! PHP: `Memory::applyOperator`, `preserveNumericType`, `coerceArray`,
//! `matchesArrayFilter`, `shiftDate`, and the engine's `+`, `<`, `>`,
//! `is_numeric`, `(int)`.

use php_std::value::Number;

use super::documents::json_array;
use super::store::s;
use crate::error::{Error, Kind, Result};
use crate::operator::{Operator, OperatorMethod};
use crate::value::{Array, Str, Value};

/// `zval_get_long()` / `(int) $value`.
pub(super) fn to_long(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// `is_numeric($value) ? $value + 0 : null`.
pub(super) fn numeric(value: &Value) -> Option<Value> {
    match value {
        Value::Int(_) | Value::Float(_) => Some(value.clone()),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| of(n)),
        _ => None,
    }
}

fn of(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::Int(i),
        Number::Float(f) => Value::Float(f),
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

/// `$a + $b`.
pub(super) fn add(a: &Value, b: &Value) -> Result<Value> {
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

/// `$a ** $b`.
fn pow(a: &Value, b: &Value) -> Result<Value> {
    let (base, exponent) = (number(a, "**", b)?, number(b, "**", a)?);
    Ok(match (base, exponent) {
        (Number::Int(b), Number::Int(e)) if e >= 0 => match u32::try_from(e).ok().and_then(|e| b.checked_pow(e)) {
            Some(r) => Value::Int(r),
            None => Value::Float(php_std::format::pow(b as f64, e as f64)),
        },
        (b, e) => Value::Float(php_std::format::pow(b.as_f64(), e.as_f64())),
    })
}

/// `$a < $b`.
pub(super) fn lt(a: &Value, b: &Value) -> bool {
    a.compare(b) < 0
}

/// `$a <= $b`.
pub(super) fn le(a: &Value, b: &Value) -> bool {
    a.compare(b) <= 0
}

/// `$a > $b` (the engine's `$b < $a`).
pub(super) fn gt(a: &Value, b: &Value) -> bool {
    b.compare(a) < 0
}

/// `$a >= $b` (the engine's `$b <= $a`).
pub(super) fn ge(a: &Value, b: &Value) -> bool {
    b.compare(a) <= 0
}

/// `$values[$i] ?? $default`.
fn nth(values: &[Value], i: usize, default: Value) -> Value {
    match values.get(i) {
        None | Some(Value::Null) => default,
        Some(v) => v.clone(),
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

/// `coerceArray($value)`: a list from an array or JSON text.
fn coerce_array(value: &Value) -> Vec<Value> {
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
    Value::Array(items.into_iter().collect())
}

/// `matchesArrayFilter($item, $condition, $compare)`.
fn matches_array_filter(item: &Value, condition: &str, compare: &Value) -> bool {
    let both = || match (numeric(item), numeric(compare)) {
        (Some(a), Some(b)) => Some((a, b)),
        _ => None,
    };
    match condition {
        "equal" => item.loose_eq(compare),
        "notEqual" => !item.loose_eq(compare),
        "greaterThan" => both().is_some_and(|(a, b)| gt(&a, &b)),
        "greaterThanEqual" => both().is_some_and(|(a, b)| ge(&a, &b)),
        "lessThan" => both().is_some_and(|(a, b)| lt(&a, &b)),
        "lessThanEqual" => both().is_some_and(|(a, b)| le(&a, &b)),
        "isNull" => matches!(item, Value::Null),
        "isNotNull" => !matches!(item, Value::Null),
        _ => true,
    }
}

/// `shiftDate($current, $seconds)`: the date moved by `seconds`, as
/// `Y-m-d H:i:s.v`.
fn shift_date(current: &Value, seconds: i64) -> Value {
    if matches!(current, Value::Null) {
        return Value::Null;
    }
    let text = current.to_php_string().unwrap_or(Str::EMPTY);
    let tz = php_std::datetime::TzInfo::utc();
    let Ok(mut date) = php_std::datetime::DateTime::new(text.as_bytes(), None, &tz) else {
        return if text.is_empty() { Value::Null } else { Value::Str(text) };
    };
    let modifier = format!("{}{seconds} seconds", if seconds >= 0 { "+" } else { "" });
    let _ = date.modify(modifier.as_bytes());
    Value::Str(Str::from(date.format(b"Y-m-d H:i:s.v")))
}

/// `applyOperator($current, $operator)`.
pub(super) fn apply(current: Value, operator: &Operator) -> Result<Value> {
    let values = operator.values();
    let base = || numeric(&current).unwrap_or(Value::Int(0));
    Ok(match operator.method() {
        OperatorMethod::Increment => {
            let by = nth(values, 0, Value::Int(1));
            let base = base();
            if let Some(max) = values.get(1).filter(|v| !matches!(v, Value::Null))
                && lt(&sub(max, &base)?, &by)
            {
                return Ok(preserve(&base, base.clone()));
            }
            let result = add(&base, &by)?;
            preserve(&base, result)
        }
        OperatorMethod::Decrement => {
            let by = nth(values, 0, Value::Int(1));
            let base = base();
            if let Some(min) = values.get(1).filter(|v| !matches!(v, Value::Null))
                && lt(&sub(&base, min)?, &by)
            {
                return Ok(preserve(&base, base.clone()));
            }
            let result = sub(&base, &by)?;
            preserve(&base, result)
        }
        OperatorMethod::Multiply => {
            let by = nth(values, 0, Value::Int(1));
            let base = base();
            let result = mul(&base, &by)?;
            if let Some(max) = values.get(1).filter(|v| !matches!(v, Value::Null))
                && gt(&result, max)
            {
                return Ok(preserve(&base, base.clone()));
            }
            preserve(&base, result)
        }
        OperatorMethod::Divide => {
            let by = nth(values, 0, Value::Int(1));
            if by.loose_eq(&Value::Int(0)) {
                return Ok(current);
            }
            let base = base();
            let result = div(&base, &by)?;
            if let Some(min) = values.get(1).filter(|v| !matches!(v, Value::Null))
                && lt(&result, min)
            {
                return Ok(preserve(&base, base.clone()));
            }
            preserve(&base, result)
        }
        OperatorMethod::Modulo => {
            let by = nth(values, 0, Value::Int(1));
            if by.loose_eq(&Value::Int(0)) {
                return Ok(current);
            }
            let base = if numeric(&current).is_some() { to_long(&current) } else { 0 };
            let divisor = to_long(&by);
            if divisor == 0 {
                return Err(Error::new(Kind::DivisionByZero, "Modulo by zero"));
            }
            Value::Int(if divisor == -1 { 0 } else { base % divisor })
        }
        OperatorMethod::Power => {
            let by = nth(values, 0, Value::Int(1));
            let base = base();
            if let Some(max) = values.get(1).filter(|v| !matches!(v, Value::Null)) {
                let by_f = by.to_double();
                if (base.loose_eq(&Value::Int(0)) && lt(&by, &Value::Int(0)))
                    || (lt(&base, &Value::Int(0)) && by_f.floor() != by_f)
                {
                    return Ok(preserve(&base, base.clone()));
                }
                let result = pow(&base, &by)?;
                let finite = !matches!(result, Value::Float(f) if !f.is_finite());
                if !finite || gt(&result, max) {
                    return Ok(preserve(&base, base.clone()));
                }
                return Ok(preserve(&base, result));
            }
            let result = pow(&base, &by)?;
            if matches!(result, Value::Float(f) if !f.is_finite()) {
                return Err(Error::new(Kind::Limit, "Value out of range"));
            }
            preserve(&base, result)
        }
        OperatorMethod::StringConcat => {
            let mut out = if matches!(current, Value::Null) {
                Vec::new()
            } else {
                current.to_php_string().map_err(Error::from)?.as_bytes().to_vec()
            };
            out.extend_from_slice(nth(values, 0, s("")).to_php_string().map_err(Error::from)?.as_bytes());
            Value::Str(Str::from(out))
        }
        OperatorMethod::StringReplace => {
            let search = nth(values, 0, s("")).to_php_string().map_err(Error::from)?;
            let replace = nth(values, 1, s("")).to_php_string().map_err(Error::from)?;
            if matches!(current, Value::Null) {
                return Ok(Value::Null);
            }
            let subject = current.to_php_string().map_err(Error::from)?;
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
        OperatorMethod::Toggle => Value::Bool(!current.truthy()),
        OperatorMethod::ArrayAppend => {
            let mut items = coerce_array(&current);
            items.extend(values.iter().cloned());
            list(items)
        }
        OperatorMethod::ArrayPrepend => {
            let mut items: Vec<Value> = values.to_vec();
            items.extend(coerce_array(&current));
            list(items)
        }
        OperatorMethod::ArrayInsert => {
            let mut items = coerce_array(&current);
            let index = to_long(&nth(values, 0, Value::Int(0))).clamp(0, items.len() as i64) as usize;
            items.insert(index, nth(values, 1, Value::Null));
            list(items)
        }
        OperatorMethod::ArrayRemove => {
            let needle = nth(values, 0, Value::Null);
            list(coerce_array(&current).into_iter().filter(|item| !item.strict_eq(&needle)).collect())
        }
        OperatorMethod::ArrayUnique => {
            let items: Array = coerce_array(&current).into_iter().collect();
            let unique = php_std::sort::array_unique(&items, php_std::sort::SortFlags::Regular).map_err(Error::from)?;
            list(unique.into_iter().map(|(_, v)| v).collect())
        }
        OperatorMethod::ArrayIntersect | OperatorMethod::ArrayDiff => {
            let other: Array = values.iter().cloned().collect();
            let keep = *operator.method() == OperatorMethod::ArrayIntersect;
            list(
                coerce_array(&current)
                    .into_iter()
                    .filter(|item| php_std::sort::in_array(item, &other, false) == keep)
                    .collect(),
            )
        }
        OperatorMethod::ArrayFilter => {
            let condition = crate::value::to_text(&nth(values, 0, s("")));
            let compare = nth(values, 1, Value::Null);
            list(
                coerce_array(&current)
                    .into_iter()
                    .filter(|item| matches_array_filter(item, &condition, &compare))
                    .collect(),
            )
        }
        OperatorMethod::DateAddDays => {
            shift_date(&current, to_long(&nth(values, 0, Value::Int(0))).wrapping_mul(86400))
        }
        OperatorMethod::DateSubDays => {
            shift_date(&current, to_long(&nth(values, 0, Value::Int(0))).wrapping_neg().wrapping_mul(86400))
        }
        OperatorMethod::DateSetNow => Value::Str(Str::from(crate::datetime::now())),
        OperatorMethod::Other(m) => return Err(Error::operator(format!("Invalid operator: {m}"))),
    })
}
