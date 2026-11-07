//! Operators of the [`Redis`] adapter: applied here to the stored value, as
//! the SQL adapters apply them in SQL.

use super::Redis;
use super::query::{int_of, numeric};
use crate::error::{Error, Result};
use crate::operator::{Operator, OperatorMethod};
use crate::query::Method;
use crate::validator::operator::{Arith, arith};
use crate::value::{Array, Ext, Value, to_text};

/// `$values[$i] ?? $default`.
fn arg(values: &[Value], i: usize, default: Value) -> Value {
    match values.get(i) {
        Some(Value::Null) | None => default,
        Some(v) => v.clone(),
    }
}

/// `is_numeric($v) ? $v + 0 : 0`.
fn number(value: &Value) -> Value {
    numeric(value).unwrap_or(Value::Int(0))
}

/// `$a < $b`.
fn lt(a: &Value, b: &Value) -> bool {
    a.compare(b) < 0
}

/// `preserveNumericType()`: an integral float result of an integer stays an
/// integer.
fn preserve(original: &Value, result: Value) -> Value {
    if let (Value::Int(_), Value::Float(f)) = (original, &result) {
        let int = php_std::format::dval_to_lval(*f);
        if int as f64 == *f {
            return Value::Int(int);
        }
    }
    result
}

/// `coerceArray()`: a list of the array, or of a JSON array string.
fn coerce_array(value: &Value) -> Vec<Value> {
    match value {
        Value::Array(a) => a.values().cloned().collect(),
        Value::Str(s) if !s.as_bytes().is_empty() => {
            match php_std::json::decode(s.as_bytes(), Some(true), 512, php_std::json::Flags::NONE)
                .map(crate::value::from_plain)
            {
                Ok(Value::Array(a)) => a.values().cloned().collect(),
                _ => Vec::new(),
            }
        }
        _ => Vec::new(),
    }
}

/// A list value.
fn list(items: Vec<Value>) -> Value {
    Value::Array(items.into_iter().collect())
}

/// `matchesArrayFilter()`.
fn array_filter_matches(item: &Value, condition: &str, compare: &Value) -> bool {
    let both = || match (numeric(item), numeric(compare)) {
        (Some(a), Some(b)) => Some((a, b)),
        _ => None,
    };
    match Method::from_name(condition) {
        Method::Equal => item.loose_eq(compare),
        Method::NotEqual => !item.loose_eq(compare),
        Method::GreaterThan => both().is_some_and(|(a, b)| lt(&b, &a)),
        Method::GreaterThanEqual => both().is_some_and(|(a, b)| b.compare(&a) <= 0),
        Method::LessThan => both().is_some_and(|(a, b)| lt(&a, &b)),
        Method::LessThanEqual => both().is_some_and(|(a, b)| a.compare(&b) <= 0),
        Method::IsNull => matches!(item, Value::Null),
        Method::IsNotNull => !matches!(item, Value::Null),
        _ => true,
    }
}

/// `shiftDate()`: the date `seconds` later, in the database format;
/// `null` for `null`, and a value that is not a date as it is (`null`
/// for an empty one).
fn shift_date(current: &Value, seconds: i64) -> Value {
    if matches!(current, Value::Null) {
        return Value::Null;
    }
    let text = to_text(current);
    let Ok(mut base) = php_std::datetime::DateTime::new(text.as_bytes(), None, &php_std::datetime::TzInfo::utc())
    else {
        return if text.is_empty() { Value::Null } else { Value::from(text.as_str()) };
    };
    let modifier = format!("{}{seconds} seconds", if seconds >= 0 { "+" } else { "" });
    let _ = base.modify(modifier.as_bytes());
    Value::from(crate::datetime::format(&base).as_str())
}

impl Redis {
    /// Each operator of `attrs` applied to the stored value of its attribute
    /// in `existing`; plain values as they are (`applyOperators()`).
    pub(super) fn apply_operators(&self, attrs: Array, existing: &Array) -> Result<Array> {
        let mut result = Array::with_capacity(attrs.len());
        for (key, value) in attrs.iter() {
            let value = match value {
                Value::Ext(Ext::Operator(operator)) => {
                    let current = existing.get_ref(key).cloned().unwrap_or(Value::Null);
                    Self::apply_operator(current, operator)?
                }
                other => other.clone(),
            };
            result.set(key.to_owned(), value);
        }
        Ok(result)
    }

    /// One operator on a stored value (`applyOperator()`).
    fn apply_operator(current: Value, operator: &Operator) -> Result<Value> {
        let values = operator.values();
        Ok(match operator.method() {
            OperatorMethod::Increment => {
                let by = arg(values, 0, Value::Int(1));
                let base = number(&current);
                if let Some(max) = values.get(1).filter(|v| !matches!(v, Value::Null)) {
                    let headroom = arith(Arith::Sub, max, &base)?;
                    if lt(&headroom, &by) {
                        return Ok(preserve(&base, base.clone()));
                    }
                }
                let result = arith(Arith::Add, &base, &by)?;
                preserve(&base, result)
            }
            OperatorMethod::Decrement => {
                let by = arg(values, 0, Value::Int(1));
                let base = number(&current);
                if let Some(min) = values.get(1).filter(|v| !matches!(v, Value::Null)) {
                    let room = arith(Arith::Sub, &base, min)?;
                    if lt(&room, &by) {
                        return Ok(preserve(&base, base.clone()));
                    }
                }
                let result = arith(Arith::Sub, &base, &by)?;
                preserve(&base, result)
            }
            OperatorMethod::Multiply => {
                let by = arg(values, 0, Value::Int(1));
                let base = number(&current);
                let result = arith(Arith::Mul, &base, &by)?;
                if let Some(max) = values.get(1).filter(|v| !matches!(v, Value::Null))
                    && lt(max, &result)
                {
                    return Ok(preserve(&base, base.clone()));
                }
                preserve(&base, result)
            }
            OperatorMethod::Divide => {
                let by = arg(values, 0, Value::Int(1));
                if by.loose_eq(&Value::Int(0)) {
                    return Ok(current);
                }
                let base = number(&current);
                let result = arith(Arith::Div, &base, &by)?;
                if let Some(min) = values.get(1).filter(|v| !matches!(v, Value::Null))
                    && lt(&result, min)
                {
                    return Ok(preserve(&base, base.clone()));
                }
                preserve(&base, result)
            }
            OperatorMethod::Modulo => {
                let by = arg(values, 0, Value::Int(1));
                if by.loose_eq(&Value::Int(0)) {
                    return Ok(current);
                }
                let base = if numeric(&current).is_some() { int_of(&current) } else { 0 };
                arith(Arith::Mod, &Value::Int(base), &Value::Int(int_of(&by)))?
            }
            OperatorMethod::Power => {
                let by = arg(values, 0, Value::Int(1));
                let base = number(&current);
                let finite = |v: &Value| !matches!(v, Value::Float(f) if !f.is_finite());
                if let Some(max) = values.get(1).filter(|v| !matches!(v, Value::Null)) {
                    let zero = Value::Int(0);
                    let exponent = by.to_double();
                    if (base.loose_eq(&zero) && lt(&by, &zero)) || (lt(&base, &zero) && exponent.floor() != exponent) {
                        return Ok(preserve(&base, base.clone()));
                    }
                    let result = arith(Arith::Pow, &base, &by)?;
                    if !finite(&result) || lt(max, &result) {
                        return Ok(preserve(&base, base.clone()));
                    }
                    return Ok(preserve(&base, result));
                }
                let result = arith(Arith::Pow, &base, &by)?;
                if !finite(&result) {
                    return Err(Error::limit("Value out of range"));
                }
                preserve(&base, result)
            }
            OperatorMethod::StringConcat => {
                let current = if matches!(current, Value::Null) { String::new() } else { to_text(&current) };
                Value::from(format!("{current}{}", to_text(&arg(values, 0, Value::from("")))).as_str())
            }
            OperatorMethod::StringReplace => {
                let search = to_text(&arg(values, 0, Value::from("")));
                let replace = to_text(&arg(values, 1, Value::from("")));
                if matches!(current, Value::Null) {
                    return Ok(Value::Null);
                }
                let subject = to_text(&current);
                let (out, _) = php_std::string::str_replace(search.as_bytes(), replace.as_bytes(), subject.as_bytes());
                Value::Str(crate::value::Str::from(out.into_owned()))
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
                let index = int_of(&arg(values, 0, Value::Int(0))).clamp(0, items.len() as i64) as usize;
                items.insert(index, arg(values, 1, Value::Null));
                list(items)
            }
            OperatorMethod::ArrayRemove => {
                let needle = arg(values, 0, Value::Null);
                list(coerce_array(&current).into_iter().filter(|item| !item.strict_eq(&needle)).collect())
            }
            OperatorMethod::ArrayUnique => {
                let items: Array = coerce_array(&current).into_iter().collect();
                let unique =
                    php_std::sort::array_unique(&items, php_std::sort::SortFlags::Regular).map_err(Error::from)?;
                list(unique.values().cloned().collect())
            }
            OperatorMethod::ArrayIntersect => {
                let other: Vec<Value> = values.to_vec();
                list(coerce_array(&current).into_iter().filter(|item| other.iter().any(|o| item.loose_eq(o))).collect())
            }
            OperatorMethod::ArrayDiff => {
                let other: Vec<Value> = values.to_vec();
                list(
                    coerce_array(&current).into_iter().filter(|item| !other.iter().any(|o| item.loose_eq(o))).collect(),
                )
            }
            OperatorMethod::ArrayFilter => {
                let condition = to_text(&arg(values, 0, Value::from("")));
                let compare = arg(values, 1, Value::Null);
                list(
                    coerce_array(&current)
                        .into_iter()
                        .filter(|item| array_filter_matches(item, &condition, &compare))
                        .collect(),
                )
            }
            OperatorMethod::DateAddDays => {
                let days = int_of(&arg(values, 0, Value::Int(0)));
                shift_date(&current, days.wrapping_mul(86400))
            }
            OperatorMethod::DateSubDays => {
                let days = int_of(&arg(values, 0, Value::Int(0)));
                shift_date(&current, days.wrapping_neg().wrapping_mul(86400))
            }
            OperatorMethod::DateSetNow => Value::from(crate::datetime::now().as_str()),
            other => return Err(Error::operator(format!("Invalid operator: {}", other.as_str()))),
        })
    }
}
