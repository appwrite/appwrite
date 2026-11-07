//! Operators: atomic updates as SQL expressions (SQLite's JSON functions
//! for arrays), and their effect on a default value for documents an upsert
//! creates.
//!
//! PHP: `SQLite::getOperatorSQL`, `MariaDB::getOperatorSQL` (its fallback),
//! `SQL::registerOperatorBind`, `SQL::applyOperatorToValue`.

use php_std::value::Number;

use super::Sqlite;
use super::find::unique;
use super::pdo::to_long;
use super::sql::{Binds, json_encode, quote};
use crate::error::{Error, Kind, Result};
use crate::operator::{Operator, OperatorMethod};
use crate::value::{Array, Str, Value};

/// `registerOperatorBind($binds, $value)`: binds `value` under a fresh name.
fn register(binds: &mut Binds, value: Value) -> String {
    let key = unique();
    binds.set(format!(":{key}"), value);
    key
}

/// `$values[$i] ?? $default`.
fn nth(values: &[Value], i: usize, default: Value) -> Value {
    match values.get(i) {
        None | Some(Value::Null) => default,
        Some(v) => v.clone(),
    }
}

fn isset(values: &[Value], i: usize) -> bool {
    values.get(i).is_some_and(|v| !matches!(v, Value::Null))
}

impl Sqlite {
    /// `getOperatorSQL($column, $operator, $binds)` (SQLite).
    pub(super) fn operator_sql(&self, column: &str, operator: &Operator, binds: &mut Binds) -> Result<String> {
        let q = quote(column);
        let values = operator.values();
        let list = || Value::Array(values.iter().cloned().collect::<Array>());
        Ok(match operator.method() {
            OperatorMethod::Increment | OperatorMethod::Decrement | OperatorMethod::Multiply => {
                let (sign, cmp) = match operator.method() {
                    OperatorMethod::Increment => ("+", ">"),
                    OperatorMethod::Decrement => ("-", "<"),
                    _ => ("*", ">"),
                };
                let k = register(binds, nth(values, 0, Value::Int(1)));
                if isset(values, 1) {
                    let m = register(binds, values[1].clone());
                    format!(
                        "{q} = CASE\n                        WHEN COALESCE({q}, 0) {sign} :{k} {cmp} :{m} THEN COALESCE({q}, 0)\n                        ELSE COALESCE({q}, 0) {sign} :{k}\n                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) {sign} :{k}")
                }
            }
            OperatorMethod::Divide => {
                let k = register(binds, nth(values, 0, Value::Int(1)));
                if isset(values, 1) {
                    let m = register(binds, values[1].clone());
                    format!(
                        "{q} = CASE\n                        WHEN :{k} != 0 AND COALESCE({q}, 0) / :{k} < :{m} THEN COALESCE({q}, 0)\n                        ELSE COALESCE({q}, 0) / :{k}\n                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) / :{k}")
                }
            }
            OperatorMethod::Modulo => {
                let k = register(binds, nth(values, 0, Value::Int(1)));
                format!("{q} = COALESCE({q}, 0) % :{k}")
            }
            OperatorMethod::Power => {
                if !self.support_for_math_functions() {
                    return Err(Error::database(
                        "SQLite POWER operator requires math functions. Compile SQLite with -DSQLITE_ENABLE_MATH_FUNCTIONS or use multiply operators instead.",
                    ));
                }
                let exponent = nth(values, 0, Value::Int(1));
                let k = register(binds, exponent.clone());
                if isset(values, 1) {
                    let m = register(binds, values[1].clone());
                    let col = format!("COALESCE({q}, 0)");
                    let e = exponent.to_double();
                    let integral = e.floor() == e;
                    let odd = integral && php_std::format::dval_to_lval(e) % 2 != 0;
                    let mut whens = Vec::new();
                    if e < 0.0 {
                        whens.push(format!("WHEN {col} = 0 THEN {col}"));
                    }
                    if !integral {
                        whens.push(format!("WHEN {col} < 0 THEN {col}"));
                    }
                    if e == 0.0 {
                        whens.push(format!("WHEN LN(:{m}) < 0 THEN {col}"));
                    } else if odd {
                        whens.push(format!("WHEN {col} > 0 AND :{k} * LN({col}) > LN(:{m}) THEN {col}"));
                    } else {
                        whens.push(format!("WHEN {col} <> 0 AND :{k} * LN(ABS({col})) > LN(:{m}) THEN {col}"));
                    }
                    format!("{q} = CASE {} ELSE POWER({col}, :{k}) END", whens.join(" "))
                } else {
                    format!("{q} = POWER(COALESCE({q}, 0), :{k})")
                }
            }
            OperatorMethod::StringConcat => {
                let k = register(binds, nth(values, 0, Value::Str(Str::EMPTY)));
                format!("{q} = IFNULL({q}, '') || :{k}")
            }
            OperatorMethod::StringReplace => {
                let s = register(binds, nth(values, 0, Value::Str(Str::EMPTY)));
                let r = register(binds, nth(values, 1, Value::Str(Str::EMPTY)));
                format!("{q} = REPLACE({q}, :{s}, :{r})")
            }
            OperatorMethod::Toggle => format!("{q} = CASE WHEN COALESCE({q}, 0) = 0 THEN 1 ELSE 0 END"),
            OperatorMethod::ArrayAppend => {
                let k = register(binds, json_encode(&list()));
                format!(
                    "{q} = (\n                    SELECT json_group_array(value)\n                    FROM (\n                        SELECT value FROM json_each(IFNULL({q}, '[]'))\n                        UNION ALL\n                        SELECT value FROM json_each(:{k})\n                    )\n                )"
                )
            }
            OperatorMethod::ArrayPrepend => {
                let k = register(binds, json_encode(&list()));
                format!(
                    "{q} = (\n                    SELECT json_group_array(value)\n                    FROM (\n                        SELECT value FROM json_each(:{k})\n                        UNION ALL\n                        SELECT value FROM json_each(IFNULL({q}, '[]'))\n                    )\n                )"
                )
            }
            OperatorMethod::ArrayUnique => format!(
                "{q} = (\n                    SELECT json_group_array(DISTINCT value)\n                    FROM json_each(IFNULL({q}, '[]'))\n                )"
            ),
            OperatorMethod::ArrayRemove => {
                let remove = nth(values, 0, Value::Null);
                let remove = match &remove {
                    Value::Array(_) => json_encode(&remove),
                    other => Value::Str(other.to_php_string().map_err(Error::from)?),
                };
                let k = register(binds, remove);
                format!(
                    "{q} = (\n                    SELECT json_group_array(value)\n                    FROM json_each(IFNULL({q}, '[]'))\n                    WHERE value != :{k}\n                )"
                )
            }
            OperatorMethod::ArrayInsert => {
                let i = register(binds, nth(values, 0, Value::Int(0)));
                let v = register(binds, json_encode(&nth(values, 1, Value::Null)));
                format!(
                    "{q} = (\n                    SELECT json_group_array(value)\n                    FROM (\n                        SELECT value, rownum\n                        FROM (\n                            SELECT value, (ROW_NUMBER() OVER ()) - 1 as rownum\n                            FROM json_each(IFNULL({q}, '[]'))\n                        )\n                        WHERE rownum < :{i}\n                        UNION ALL\n                        SELECT value, :{i} as rownum\n                        FROM json_each(json_array(json(:{v})))\n                        UNION ALL\n                        SELECT value, rownum + 1 as rownum\n                        FROM (\n                            SELECT value, (ROW_NUMBER() OVER ()) - 1 as rownum\n                            FROM json_each(IFNULL({q}, '[]'))\n                        )\n                        WHERE rownum >= :{i}\n                        ORDER BY rownum\n                    )\n                )"
                )
            }
            OperatorMethod::ArrayIntersect | OperatorMethod::ArrayDiff => {
                let k = register(binds, json_encode(&list()));
                let not = if *operator.method() == OperatorMethod::ArrayDiff { "NOT " } else { "" };
                format!(
                    "{q} = (\n                    SELECT json_group_array(value)\n                    FROM json_each(IFNULL({q}, '[]'))\n                    WHERE value {not}IN (SELECT value FROM json_each(:{k}))\n                )"
                )
            }
            OperatorMethod::ArrayFilter => {
                if values.is_empty() {
                    return Ok(format!("{q} = {q}"));
                }
                let filter_type = values[0].clone();
                let is = |name: &str| filter_type.loose_eq(&Value::Str(name.into()));
                if is("isNull") {
                    format!(
                        "{q} = (\n                            SELECT json_group_array(value)\n                            FROM json_each(IFNULL({q}, '[]'))\n                            WHERE value IS NULL\n                        )"
                    )
                } else if is("isNotNull") {
                    format!(
                        "{q} = (\n                            SELECT json_group_array(value)\n                            FROM json_each(IFNULL({q}, '[]'))\n                            WHERE value IS NOT NULL\n                        )"
                    )
                } else if ["equal", "notEqual", "greaterThan", "greaterThanEqual", "lessThan", "lessThanEqual"]
                    .iter()
                    .any(|n| is(n))
                {
                    if values.len() < 2 {
                        return Ok(format!("{q} = {q}"));
                    }
                    let filter_value = match &values[1] {
                        Value::Bool(b) => Value::Int(i64::from(*b)),
                        other => other.clone(),
                    };
                    let k = register(binds, filter_value);
                    let exact = |name: &str| filter_type.strict_eq(&Value::Str(name.into()));
                    let op = if exact("equal") {
                        "="
                    } else if exact("notEqual") {
                        "!="
                    } else if exact("greaterThan") {
                        ">"
                    } else if exact("greaterThanEqual") {
                        ">="
                    } else if exact("lessThan") {
                        "<"
                    } else if exact("lessThanEqual") {
                        "<="
                    } else {
                        return Err(Error::operator(format!(
                            "Unsupported filter type: {}",
                            crate::value::to_text(&filter_type)
                        )));
                    };
                    let numeric =
                        ["greaterThan", "greaterThanEqual", "lessThan", "lessThanEqual"].iter().any(|n| is(n));
                    if numeric {
                        format!(
                            "{q} = (\n                                SELECT json_group_array(value)\n                                FROM json_each(IFNULL({q}, '[]'))\n                                WHERE CAST(value AS REAL) {op} CAST(:{k} AS REAL)\n                            )"
                        )
                    } else {
                        format!(
                            "{q} = (\n                                SELECT json_group_array(value)\n                                FROM json_each(IFNULL({q}, '[]'))\n                                WHERE value {op} :{k}\n                            )"
                        )
                    }
                } else {
                    format!("{q} = {q}")
                }
            }
            OperatorMethod::DateAddDays => {
                let k = register(binds, nth(values, 0, Value::Int(0)));
                format!("{q} = datetime({q}, :{k} || ' days')")
            }
            OperatorMethod::DateSubDays => {
                let k = register(binds, nth(values, 0, Value::Int(0)));
                format!("{q} = datetime({q}, '-' || abs(:{k}) || ' days')")
            }
            OperatorMethod::DateSetNow => format!("{q} = datetime('now')"),
            OperatorMethod::Other(m) => return Err(Error::operator(format!("Invalid operator: {m}"))),
        })
    }
}

fn number(v: &Value) -> Result<Number> {
    Ok(match v {
        Value::Null | Value::Bool(false) => Number::Int(0),
        Value::Bool(true) => Number::Int(1),
        Value::Int(i) => Number::Int(*i),
        Value::Float(f) => Number::Float(*f),
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((n, _)) => n,
            None => return Err(Error::new(Kind::TypeError, format!("Unsupported operand types: string + {}", "int"))),
        },
        other => {
            return Err(Error::new(
                Kind::TypeError,
                format!("Unsupported operand types: {} + int", other.debug_type()),
            ));
        }
    })
}

fn of(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::Int(i),
        Number::Float(f) => Value::Float(f),
    }
}

fn or_null(v: Value, default: Value) -> Value {
    if matches!(v, Value::Null) { default } else { v }
}

fn array_of(v: &Value, function: &str) -> Result<Array> {
    match v {
        Value::Null => Ok(Array::new()),
        Value::Array(a) => Ok(a.clone()),
        other => Err(Error::new(
            Kind::TypeError,
            format!(
                "{function}(): Argument #1 ($array) must be of type array, {} given",
                super::documents::value_name(other)
            ),
        )),
    }
}

fn values_list(a: Array) -> Value {
    Value::Array(a.into_iter().map(|(_, v)| v).collect())
}

/// `applyOperatorToValue($operator, $value)`: what the operator makes of
/// `value` (the attribute's default) for a document an upsert creates.
pub(super) fn apply(operator: &Operator, value: Value) -> Result<Value> {
    let values = operator.values();
    Ok(match operator.method() {
        OperatorMethod::Increment => of(php_std::format::add(number(&value)?, number(&nth(values, 0, Value::Int(1)))?)),
        OperatorMethod::Decrement => of(php_std::format::sub(number(&value)?, number(&nth(values, 0, Value::Int(1)))?)),
        OperatorMethod::Multiply => of(php_std::format::mul(number(&value)?, number(&nth(values, 0, Value::Int(1)))?)),
        OperatorMethod::Divide => {
            let divisor = nth(values, 0, Value::Int(1));
            if divisor.to_double() != 0.0 {
                match php_std::format::div(number(&value)?, number(&divisor)?) {
                    Some(n) => of(n),
                    None => return Err(Error::new(Kind::Exception, "Division by zero")),
                }
            } else {
                or_null(value, Value::Int(0))
            }
        }
        OperatorMethod::Modulo => {
            let divisor = nth(values, 0, Value::Int(1));
            if divisor.to_double() != 0.0 {
                let d = to_long(&divisor);
                if d == 0 {
                    return Err(Error::new(Kind::Exception, "Modulo by zero"));
                }
                Value::Int(to_long(&value).wrapping_rem(d))
            } else {
                or_null(value, Value::Int(0))
            }
        }
        OperatorMethod::Power => {
            let base = number(&value)?;
            let exponent = number(&nth(values, 0, Value::Int(1)))?;
            match (base, exponent) {
                (Number::Int(b), Number::Int(e)) if e >= 0 => {
                    match u32::try_from(e).ok().and_then(|e| b.checked_pow(e)) {
                        Some(r) => Value::Int(r),
                        None => Value::Float(php_std::format::pow(b as f64, e as f64)),
                    }
                }
                (b, e) => Value::Float(php_std::format::pow(b.as_f64(), e.as_f64())),
            }
        }
        OperatorMethod::ArrayAppend => {
            let a = array_of(&value, "array_merge")?;
            let mut out = Array::new();
            for (k, v) in a.iter().chain(values.iter().cloned().collect::<Array>().iter()) {
                match k {
                    crate::value::KeyRef::Int(_) => {
                        let _ = out.push(v.clone());
                    }
                    crate::value::KeyRef::Str(_) => {
                        out.set(k.to_owned(), v.clone());
                    }
                }
            }
            Value::Array(out)
        }
        OperatorMethod::ArrayPrepend => {
            let a = array_of(&value, "array_merge")?;
            let mut out: Array = values.iter().cloned().collect();
            for (k, v) in a.iter() {
                match k {
                    crate::value::KeyRef::Int(_) => {
                        let _ = out.push(v.clone());
                    }
                    crate::value::KeyRef::Str(_) => {
                        out.set(k.to_owned(), v.clone());
                    }
                }
            }
            Value::Array(out)
        }
        OperatorMethod::ArrayInsert => {
            let a = array_of(&value, "array_splice")?;
            let index = to_long(&nth(values, 0, Value::Int(0)));
            let item = nth(values, 1, Value::Null);
            let mut items: Vec<(Option<crate::value::ArrayKey>, Value)> = a
                .iter()
                .map(|(k, v)| (matches!(k, crate::value::KeyRef::Str(_)).then(|| k.to_owned()), v.clone()))
                .collect();
            let len = items.len() as i64;
            let at = if index < 0 { (len + index).max(0) } else { index.min(len) } as usize;
            items.insert(at, (None, item));
            let mut out = Array::new();
            for (k, v) in items {
                match k {
                    Some(k) => {
                        out.set(k, v);
                    }
                    None => {
                        let _ = out.push(v);
                    }
                }
            }
            Value::Array(out)
        }
        OperatorMethod::ArrayRemove => {
            let a = array_of(&value, "array_diff")?;
            let remove = match nth(values, 0, Value::Null) {
                Value::Array(r) => r,
                other => [other].into_iter().collect(),
            };
            values_list(php_std::sort::array_diff(&a, &[&remove]).map_err(Error::from)?)
        }
        OperatorMethod::ArrayUnique => {
            let a = array_of(&value, "array_unique")?;
            values_list(php_std::sort::array_unique(&a, php_std::sort::SortFlags::String).map_err(Error::from)?)
        }
        OperatorMethod::ArrayIntersect => {
            let a = array_of(&value, "array_intersect")?;
            let others: Array = values.iter().cloned().collect();
            values_list(php_std::sort::array_intersect(&a, &[&others]).map_err(Error::from)?)
        }
        OperatorMethod::ArrayDiff => {
            let a = array_of(&value, "array_diff")?;
            let others: Array = values.iter().cloned().collect();
            values_list(php_std::sort::array_diff(&a, &[&others]).map_err(Error::from)?)
        }
        OperatorMethod::ArrayFilter => or_null(value, Value::Array(Array::new())),
        OperatorMethod::StringConcat => {
            let mut s =
                or_null(value, Value::Str(Str::EMPTY)).to_php_string().map_err(Error::from)?.as_bytes().to_vec();
            s.extend_from_slice(
                nth(values, 0, Value::Str(Str::EMPTY)).to_php_string().map_err(Error::from)?.as_bytes(),
            );
            Value::Str(Str::from(s))
        }
        OperatorMethod::StringReplace => {
            let search = nth(values, 0, Value::Str(Str::EMPTY)).to_php_string().map_err(Error::from)?;
            let replace = nth(values, 1, Value::Str(Str::EMPTY)).to_php_string().map_err(Error::from)?;
            let subject = or_null(value, Value::Str(Str::EMPTY)).to_php_string().map_err(Error::from)?;
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
        OperatorMethod::Toggle => Value::Bool(!or_null(value, Value::Bool(false)).truthy()),
        OperatorMethod::DateAddDays | OperatorMethod::DateSubDays => value,
        OperatorMethod::DateSetNow => {
            let now = php_std::datetime::DateTime::new(b"now", None, &php_std::datetime::TzInfo::utc())
                .map_err(|e| Error::database(e.message().into_owned()))?;
            Value::Str(Str::from(now.format(b"Y-m-d H:i:s.v")))
        }
        OperatorMethod::Other(_) => value,
    })
}
