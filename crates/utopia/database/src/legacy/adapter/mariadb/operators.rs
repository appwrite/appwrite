//! Operators: atomic updates as SQL expressions over MariaDB's (and
//! MySQL's) JSON functions, and their effect on a default value for
//! documents an upsert creates.
//!
//! PHP: `MariaDB::getOperatorSQL`, `MySQL::getOperatorSQL`,
//! `SQL::registerOperatorBind`, `SQL::applyOperatorToValue`.

use php_std::value::Number;

use super::MariaDB;
use super::find::unique;
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

impl MariaDB {
    /// `getOperatorSQL($column, $operator, $binds)`.
    pub(super) fn operator_sql(&self, column: &str, operator: &Operator, binds: &mut Binds) -> Result<String> {
        let q = quote(column);
        let values = operator.values();
        let list = || Value::Array(values.iter().cloned().collect::<Array>());
        Ok(match operator.method() {
            OperatorMethod::Increment => {
                let k = register(binds, nth(values, 0, Value::Int(1)));
                if isset(values, 1) {
                    let m = register(binds, values[1].clone());
                    format!(
                        "{q} = CASE\n                        WHEN COALESCE({q}, 0) > :{m} - :{k} THEN COALESCE({q}, 0)\n                        ELSE COALESCE({q}, 0) + :{k}\n                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) + :{k}")
                }
            }
            OperatorMethod::Decrement => {
                let k = register(binds, nth(values, 0, Value::Int(1)));
                if isset(values, 1) {
                    let m = register(binds, values[1].clone());
                    format!(
                        "{q} = CASE\n                        WHEN COALESCE({q}, 0) < :{m} + :{k} THEN COALESCE({q}, 0)\n                        ELSE COALESCE({q}, 0) - :{k}\n                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) - :{k}")
                }
            }
            OperatorMethod::Multiply => {
                let k = register(binds, nth(values, 0, Value::Int(1)));
                if isset(values, 1) {
                    let m = register(binds, values[1].clone());
                    format!(
                        "{q} = CASE\n                        WHEN :{k} > 0 AND COALESCE({q}, 0) > :{m} / :{k} THEN COALESCE({q}, 0)\n                        WHEN :{k} < 0 AND COALESCE({q}, 0) < :{m} / :{k} THEN COALESCE({q}, 0)\n                        ELSE COALESCE({q}, 0) * :{k}\n                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) * :{k}")
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
                format!("{q} = MOD(COALESCE({q}, 0), :{k})")
            }
            OperatorMethod::Power => {
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
                        whens.push(format!("WHEN LOG(:{m}) < 0 THEN {col}"));
                    } else if odd {
                        whens.push(format!("WHEN {col} > 0 AND :{k} * LOG({col}) > LOG(:{m}) THEN {col}"));
                    } else {
                        whens.push(format!("WHEN {col} <> 0 AND :{k} * LOG(ABS({col})) > LOG(:{m}) THEN {col}"));
                    }
                    format!("{q} = CASE {} ELSE POWER({col}, :{k}) END", whens.join(" "))
                } else {
                    format!("{q} = POWER(COALESCE({q}, 0), :{k})")
                }
            }
            OperatorMethod::StringConcat => {
                let k = register(binds, nth(values, 0, Value::Str(Str::EMPTY)));
                format!("{q} = CONCAT(COALESCE({q}, ''), :{k})")
            }
            OperatorMethod::StringReplace => {
                let s = register(binds, nth(values, 0, Value::Str(Str::EMPTY)));
                let r = register(binds, nth(values, 1, Value::Str(Str::EMPTY)));
                format!("{q} = REPLACE({q}, :{s}, :{r})")
            }
            OperatorMethod::Toggle => format!("{q} = NOT COALESCE({q}, FALSE)"),
            OperatorMethod::ArrayAppend => {
                let k = register(binds, json_encode(&list()));
                format!("{q} = JSON_MERGE_PRESERVE(IFNULL({q}, JSON_ARRAY()), :{k})")
            }
            OperatorMethod::ArrayPrepend => {
                let k = register(binds, json_encode(&list()));
                format!("{q} = JSON_MERGE_PRESERVE(:{k}, IFNULL({q}, JSON_ARRAY()))")
            }
            OperatorMethod::ArrayInsert => {
                let i = register(binds, nth(values, 0, Value::Int(0)));
                let v = register(binds, json_encode(&nth(values, 1, Value::Null)));
                format!(
                    "{q} = JSON_ARRAY_INSERT(\n                    {q},\n                    CONCAT('$[', :{i}, ']'),\n                    JSON_EXTRACT(:{v}, '$')\n                )"
                )
            }
            OperatorMethod::ArrayRemove => {
                // Scalars bind as strings (`(string)$removeValue`), arrays as JSON.
                let remove = match nth(values, 0, Value::Null) {
                    a @ Value::Array(_) => json_encode(&a),
                    other => Value::Str(other.to_php_string().map_err(Error::from)?),
                };
                let k = register(binds, remove);
                format!(
                    "{q} = IFNULL((\n                    SELECT JSON_ARRAYAGG(value)\n                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt\n                    WHERE value != :{k}\n                ), JSON_ARRAY())"
                )
            }
            OperatorMethod::ArrayUnique => {
                if self.mysql() {
                    format!(
                        "{q} = IFNULL((\n                    SELECT JSON_ARRAYAGG(value)\n                    FROM (\n                        SELECT DISTINCT value\n                        FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt\n                    ) AS distinct_values\n                ), JSON_ARRAY())"
                    )
                } else {
                    format!(
                        "{q} = IFNULL((\n                    SELECT JSON_ARRAYAGG(DISTINCT jt.value)\n                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt\n                ), JSON_ARRAY())"
                    )
                }
            }
            OperatorMethod::ArrayIntersect | OperatorMethod::ArrayDiff => {
                let k = register(binds, json_encode(&list()));
                let not = if *operator.method() == OperatorMethod::ArrayDiff { "NOT " } else { "" };
                format!(
                    "{q} = IFNULL((\n                    SELECT JSON_ARRAYAGG(jt1.value)\n                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt1\n                    WHERE jt1.value {not}IN (\n                        SELECT value\n                        FROM JSON_TABLE(:{k}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt2\n                    )\n                ), JSON_ARRAY())"
                )
            }
            OperatorMethod::ArrayFilter => {
                let condition = nth(values, 0, Value::Str("equal".into()));
                let filter_value = nth(values, 1, Value::Null);
                let c = register(binds, condition);
                let v = register(
                    binds,
                    if matches!(filter_value, Value::Null) { Value::Null } else { json_encode(&filter_value) },
                );
                format!(
                    "{q} = IFNULL((\n                    SELECT JSON_ARRAYAGG(value)\n                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt\n                    WHERE CASE :{c}\n                        WHEN 'equal' THEN value = JSON_UNQUOTE(:{v})\n                        WHEN 'notEqual' THEN value != JSON_UNQUOTE(:{v})\n                        WHEN 'greaterThan' THEN CAST(value AS DECIMAL(65,30)) > CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))\n                        WHEN 'greaterThanEqual' THEN CAST(value AS DECIMAL(65,30)) >= CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))\n                        WHEN 'lessThan' THEN CAST(value AS DECIMAL(65,30)) < CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))\n                        WHEN 'lessThanEqual' THEN CAST(value AS DECIMAL(65,30)) <= CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))\n                        WHEN 'isNull' THEN value IS NULL\n                        WHEN 'isNotNull' THEN value IS NOT NULL\n                        ELSE TRUE\n                    END\n                ), JSON_ARRAY())"
                )
            }
            OperatorMethod::DateAddDays => {
                let k = register(binds, nth(values, 0, Value::Int(0)));
                format!("{q} = DATE_ADD({q}, INTERVAL :{k} DAY)")
            }
            OperatorMethod::DateSubDays => {
                let k = register(binds, nth(values, 0, Value::Int(0)));
                format!("{q} = DATE_SUB({q}, INTERVAL :{k} DAY)")
            }
            OperatorMethod::DateSetNow => format!("{q} = NOW()"),
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
                super::sql::value_name(other)
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
                let d = super::pdo::to_long(&divisor);
                if d == 0 {
                    return Err(Error::new(Kind::Exception, "Modulo by zero"));
                }
                Value::Int(super::pdo::to_long(&value).wrapping_rem(d))
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
            let index = super::pdo::to_long(&nth(values, 0, Value::Int(0)));
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
