//! Operators: the SQL of an atomic update and its bindings.
//!
//! PHP: `getOperatorSql()`, `bindOperatorParameters()`, `getOperatorBuilderExpression()`, `getOperatorUpsertExpression()`, `applyOperatorToValue()` of `SQL` and its subclasses.

use std::cmp::Ordering;
use std::sync::OnceLock;

use php_std::value::Number;

use super::pdo::ParamType;
use super::{Engine, Prepared, Sql};
use crate::error::{Error, Kind, Result};
use crate::operator::{MAX_ARRAY_OPERATOR_SIZE, Operator, OperatorType};
use crate::validator::big_int::{BigInt, Integer, Operand};
use crate::value::{Array, ArrayKey, Str, Value};

/// SQL with positional (`?`) bindings, for a builder's `setRaw()` or
/// `conflictSetRaw()`.
///
/// PHP: `Utopia\Database\Adapter\SQL\Expression`.
#[derive(Debug, Clone, PartialEq)]
pub(crate) struct Expression {
    /// The SQL (`sql`).
    pub sql: String,
    /// The values of its placeholders, in order (`bindings`).
    pub bindings: Vec<Value>,
}

/// Whether SQLite has its math functions (`SQLite::getSupportForMathFunctions()`'s
/// process-wide cache).
static SQLITE_MATH_FUNCTIONS: OnceLock<bool> = OnceLock::new();

/// `$values[$i] ?? null`: the value at key `i` unless unset or `null`.
fn at(values: &Array, i: i64) -> Option<&Value> {
    values.get(&ArrayKey::Int(i)).filter(|v| !matches!(v, Value::Null))
}

/// `$values[$i] ?? $default`.
fn or(values: &Array, i: i64, default: Value) -> Value {
    at(values, i).cloned().unwrap_or(default)
}

/// `json_encode($value)` (`false` when it fails).
pub(crate) fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(value, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH) {
        Ok(json) => Value::Str(Str::from(json)),
        Err(_) => Value::Bool(false),
    }
}

/// `json_encode($values)` of an operator's values.
fn json_values(values: &Array) -> Value {
    json_encode(&Value::Array(values.clone()))
}

/// `SQL::exactLimit()`: a finite float bound as the exact integer it is,
/// when it is a whole number.
pub(crate) fn exact_limit(limit: Value) -> Value {
    match limit {
        Value::Float(f) if f.is_finite() => match BigInt::integral_value(Operand::Float(f)) {
            Some(integer) => integer_value(integer),
            None => Value::Float(f),
        },
        other => other,
    }
}

/// An exact integer as PHP's `int|string`.
fn integer_value(integer: Integer) -> Value {
    match integer {
        Integer::Int(i) => Value::Int(i),
        Integer::Str(s) => Value::Str(Str::from(s)),
    }
}

/// The exponent of a `power` (`$values[0] ?? 1`), refused unless it is an
/// integer or a float.
fn power_exponent(values: &Array) -> Result<f64> {
    match or(values, 0, Value::Int(1)) {
        Value::Int(i) => Ok(i as f64),
        Value::Float(f) => Ok(f),
        _ => Err(Error::operator("Power exponent must be numeric")),
    }
}

/// The guarded `POWER()` of a `power` with a maximum, as MariaDB (`LOG`),
/// PostgreSQL and SQLite (`LN`) write it.
fn power_case(quoted: &str, column: &str, exponent: f64, k: &str, m: &str, log: &str) -> String {
    let value = format!("COALESCE({column}, 0)");
    let odd = exponent.floor() == exponent && php_std::format::dval_to_lval(exponent) % 2 != 0;
    let mut guards = Vec::new();
    if exponent < 0.0 {
        guards.push(format!("WHEN {value} = 0 THEN {value}"));
    }
    if exponent.floor() != exponent {
        guards.push(format!("WHEN {value} < 0 THEN {value}"));
    }
    if exponent == 0.0 {
        guards.push(format!("WHEN {log}(:{m}) < 0 THEN {value}"));
    } else if odd {
        guards.push(format!("WHEN {value} > 0 AND :{k} * {log}({value}) > {log}(:{m}) THEN {value}"));
    } else {
        guards.push(format!("WHEN {value} <> 0 AND :{k} * {log}(ABS({value})) > {log}(:{m}) THEN {value}"));
    }
    format!("{quoted} = CASE {} ELSE POWER({value}, :{k}) END", guards.join(" "))
}

/// The next `op_N` key.
fn next(bind_index: &mut usize) -> String {
    let key = format!("op_{bind_index}");
    *bind_index += 1;
    key
}

/// The named bindings `getOperatorBuilderExpression()` (and PostgreSQL's
/// `getOperatorUpsertExpression()`, which JSON-encodes a removed scalar)
/// pair with an operator's `op_N` placeholders.
fn named_bindings(operator: &Operator, encode_removed_scalar: bool) -> Vec<(String, Value)> {
    let values = operator.values();
    let mut named: Vec<(String, Value)> = Vec::new();
    let mut idx = 0usize;
    let mut push = |value: Value| {
        named.push((format!("op_{idx}"), value));
        idx += 1;
    };
    match operator.method() {
        OperatorType::Increment
        | OperatorType::Decrement
        | OperatorType::Multiply
        | OperatorType::Divide
        | OperatorType::Power => {
            push(or(&values, 0, Value::Int(1)));
            if let Some(limit) = at(&values, 1) {
                push(exact_limit(limit.clone()));
            }
        }
        OperatorType::Modulo => push(or(&values, 0, Value::Int(1))),
        OperatorType::StringConcat => push(or(&values, 0, Value::Str(Str::EMPTY))),
        OperatorType::StringReplace => {
            push(or(&values, 0, Value::Str(Str::EMPTY)));
            push(or(&values, 1, Value::Str(Str::EMPTY)));
        }
        OperatorType::Toggle | OperatorType::DateSetNow | OperatorType::ArrayUnique => {}
        OperatorType::DateAddDays | OperatorType::DateSubDays => push(or(&values, 0, Value::Int(0))),
        OperatorType::ArrayAppend
        | OperatorType::ArrayPrepend
        | OperatorType::ArrayIntersect
        | OperatorType::ArrayDiff => push(json_values(&values)),
        OperatorType::ArrayRemove => {
            let value = or(&values, 0, Value::Null);
            push(if encode_removed_scalar || matches!(value, Value::Array(_)) { json_encode(&value) } else { value });
        }
        OperatorType::ArrayInsert => {
            push(or(&values, 0, Value::Int(0)));
            push(json_encode(&or(&values, 1, Value::Null)));
        }
        OperatorType::ArrayFilter => {
            push(or(&values, 0, Value::Str(Str::from_static("equal"))));
            let filter = or(&values, 1, Value::Null);
            push(if matches!(filter, Value::Null) { Value::Null } else { json_encode(&filter) });
        }
    }
    named
}

/// The SQL of a full `col = expression` with its column prefix stripped
/// and its `:op_N` placeholders made positional, in the order they appear
/// (each key searched longest first, as PHP replaces them).
fn positional(full: &str, quoted: &str, named: &[(String, Value)], sort_keys: bool) -> Expression {
    let prefix = format!("{quoted} = ");
    let expression = full.strip_prefix(prefix.as_str()).unwrap_or(full);
    let mut keys: Vec<&(String, Value)> = named.iter().collect();
    if sort_keys {
        keys.sort_by(|a, b| b.0.len().cmp(&a.0.len()));
    }
    let mut replacements: Vec<(usize, usize, &Value)> = Vec::new();
    for (key, value) in keys {
        let search = format!(":{key}");
        let mut offset = 0;
        while let Some(pos) = expression[offset..].find(&search) {
            let pos = offset + pos;
            replacements.push((pos, search.len(), value));
            offset = pos + search.len();
        }
    }
    replacements.sort_by_key(|r| r.0);
    let mut sql = expression.to_owned();
    for (pos, len, _) in replacements.iter().rev() {
        sql.replace_range(*pos..*pos + *len, "?");
    }
    Expression { sql, bindings: replacements.into_iter().map(|(_, _, v)| v.clone()).collect() }
}

/// A number as `$value + 0` reads it, when `is_numeric($value)`.
fn numeric(value: &Value) -> Option<Number> {
    match value {
        Value::Int(i) => Some(Number::Int(*i)),
        Value::Float(f) => Some(Number::Float(*f)),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| n),
        _ => None,
    }
}

fn number_value(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::Int(i),
        Number::Float(f) => Value::Float(f),
    }
}

/// `SQL::stringify()`.
fn stringify(value: &Value) -> Str {
    match value {
        Value::Str(s) => s.clone(),
        Value::Null | Value::Bool(_) | Value::Int(_) | Value::Float(_) => value.to_php_string().unwrap_or(Str::EMPTY),
        Value::Array(_) => Str::from_static("array"),
        other => Str::from(other.debug_type().to_owned()),
    }
}

/// `SQL::stringifyList()`.
fn stringify_list(values: &Array) -> Vec<Str> {
    values.values().map(stringify).collect()
}

fn str_list(values: Vec<Str>) -> Value {
    Value::Array(Array::from_list(values.into_iter().map(Value::Str).collect()))
}

/// `array_merge($a, $b)`: integer keys renumbered, string keys kept (a
/// later one overwriting).
fn array_merge(a: &Array, b: &Array) -> Array {
    let mut out = Array::new();
    for (k, v) in a.iter().chain(b.iter()) {
        match k {
            crate::value::KeyRef::Int(_) => {
                let _ = out.push(v.clone());
            }
            crate::value::KeyRef::Str(_) => {
                out.set(k.to_owned(), v.clone());
            }
        }
    }
    out
}

/// `array_splice($array, $offset, 0, [$value])`.
fn array_insert(array: &Array, offset: i64, value: Value) -> Array {
    let mut items: Vec<(Option<ArrayKey>, Value)> = array
        .iter()
        .map(|(k, v)| (matches!(k, crate::value::KeyRef::Str(_)).then(|| k.to_owned()), v.clone()))
        .collect();
    let len = items.len() as i64;
    let at = if offset < 0 { (len + offset).max(0) } else { offset.min(len) } as usize;
    items.insert(at, (None, value));
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
    out
}

/// PHP's `$a <=> $b` of two numbers, `NAN` comparing as greater.
fn threeway(a: Number, b: Number) -> Ordering {
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => x.cmp(&y),
        _ => {
            let (x, y) = (a.as_f64(), b.as_f64());
            if x == y {
                Ordering::Equal
            } else if x < y {
                Ordering::Less
            } else {
                Ordering::Greater
            }
        }
    }
}

/// PHP 8 `$a < $b` for two numbers (false with `NAN`).
fn lt_numbers(a: Number, b: Number) -> bool {
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => x < y,
        _ => a.as_f64() < b.as_f64(),
    }
}

/// `SQL::filterArray()`.
fn filter_array(items: &Array, condition: &Value, compare: &Value) -> Value {
    let condition = match condition {
        Value::Str(s) => s.as_str().unwrap_or(""),
        _ => "\0",
    };
    let numbers = |item: &Value| Some((numeric(item)?, numeric(compare)?));
    let kept = items.values().filter(|item| match condition {
        "equal" => item.loose_eq(compare),
        "notEqual" => !item.loose_eq(compare),
        "greaterThan" => numbers(item).is_some_and(|(a, b)| lt_numbers(b, a)),
        "greaterThanEqual" => numbers(item).is_some_and(|(a, b)| php_std::value::le_numbers(b, a)),
        "lessThan" => numbers(item).is_some_and(|(a, b)| lt_numbers(a, b)),
        "lessThanEqual" => numbers(item).is_some_and(|(a, b)| php_std::value::le_numbers(a, b)),
        "isNull" => matches!(item, Value::Null),
        "isNotNull" => !matches!(item, Value::Null),
        _ => true,
    });
    Value::Array(Array::from_list(kept.cloned().collect()))
}

/// `SQL::shiftDays()`.
fn shift_days(value: Value, days: i64) -> Value {
    let Value::Str(text) = &value else { return value };
    if text.is_empty() {
        return value;
    }
    let tz = php_std::datetime::TzInfo::utc();
    let Ok(mut date) = php_std::datetime::DateTime::new(text.as_bytes(), None, &tz) else {
        return value;
    };
    date.set_timezone(&php_std::datetime::TimeZone::utc());
    let modifier = if days >= 0 { format!("+{days} days") } else { format!("{days} days") };
    if date.modify(modifier.as_bytes()).is_err() {
        return value;
    }
    Value::Str(Str::from(crate::datetime::format(&date)))
}

/// `SQL::keepWithinBound()`.
fn keep_within_bound(method: OperatorType, current: Number, result: Value, bound: Option<&Value>) -> Value {
    let Some(bound) = bound else { return result };
    if numeric(bound).is_none() || !matches!(result, Value::Int(_) | Value::Float(_)) {
        return result;
    }
    let limit = exact_limit(bound.clone());
    let comparison = match (&result, BigInt::is_integer_value(&limit)) {
        (Value::Int(r), true) => {
            BigInt::compare(Operand::Int(*r), Operand::from_value(&limit).unwrap_or(Operand::Int(0)))
                .unwrap_or(Ordering::Equal)
        }
        _ => {
            let limit = match &limit {
                Value::Str(_) => Number::Float(limit.to_double()),
                other => numeric(other).unwrap_or(Number::Int(0)),
            };
            threeway(numeric(&result).unwrap_or(Number::Int(0)), limit)
        }
    };
    let crossed = match method {
        OperatorType::Increment | OperatorType::Multiply | OperatorType::Power => {
            result.to_double().is_nan() || comparison == Ordering::Greater
        }
        OperatorType::Decrement | OperatorType::Divide => comparison == Ordering::Less,
        _ => false,
    };
    if crossed { number_value(current) } else { result }
}

impl Sql {
    /// `getOperatorSql()`: `col = expression` with `:op_N` placeholders
    /// numbered from `bind_index`, which advances past them; `None` where
    /// SQLite cannot express the operator. PostgreSQL reads the stored row
    /// through `target.` with `target` (`$useTargetPrefix`). `math` is
    /// whether SQLite has its math functions (see
    /// [`Sql::operator_math_functions`]).
    pub(crate) fn operator_sql(
        &self,
        column: &str,
        operator: &Operator,
        bind_index: &mut usize,
        target: bool,
        math: bool,
    ) -> Result<Option<String>> {
        match self.engine {
            Engine::Postgres => self.postgres_operator_sql(column, operator, bind_index, target).map(Some),
            Engine::Sqlite => self.sqlite_operator_sql(column, operator, bind_index, math),
            Engine::MySql => {
                let q = self.quote(column);
                match operator.method() {
                    OperatorType::ArrayAppend => {
                        let k = next(bind_index);
                        Ok(Some(format!("{q} = JSON_MERGE_PRESERVE(IFNULL({q}, JSON_ARRAY()), :{k})")))
                    }
                    OperatorType::ArrayPrepend => {
                        let k = next(bind_index);
                        Ok(Some(format!("{q} = JSON_MERGE_PRESERVE(:{k}, IFNULL({q}, JSON_ARRAY()))")))
                    }
                    OperatorType::ArrayUnique => Ok(Some(format!(
                        "{q} = IFNULL((
                    SELECT JSON_ARRAYAGG(value)
                    FROM (
                        SELECT DISTINCT value
                        FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt
                    ) AS distinct_values
                ), JSON_ARRAY())"
                    ))),
                    _ => self.mariadb_operator_sql(column, operator, bind_index).map(Some),
                }
            }
            Engine::MariaDb => self.mariadb_operator_sql(column, operator, bind_index).map(Some),
        }
    }

    /// `MariaDB::getOperatorSql()`.
    fn mariadb_operator_sql(&self, column: &str, operator: &Operator, bind_index: &mut usize) -> Result<String> {
        let q = self.quote(column);
        let values = operator.values();
        let has_limit = at(&values, 1).is_some();
        Ok(match operator.method() {
            OperatorType::Increment => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN COALESCE({q}, 0) > :{m} - :{k} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) + :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) + :{k}")
                }
            }
            OperatorType::Decrement => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN COALESCE({q}, 0) < :{m} + :{k} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) - :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) - :{k}")
                }
            }
            OperatorType::Multiply => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN :{k} > 0 AND COALESCE({q}, 0) > :{m} / :{k} THEN COALESCE({q}, 0)
                        WHEN :{k} < 0 AND COALESCE({q}, 0) < :{m} / :{k} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) * :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) * :{k}")
                }
            }
            OperatorType::Divide => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN :{k} != 0 AND COALESCE({q}, 0) / :{k} < :{m} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) / :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) / :{k}")
                }
            }
            OperatorType::Modulo => {
                let k = next(bind_index);
                format!("{q} = MOD(COALESCE({q}, 0), :{k})")
            }
            OperatorType::Power => {
                let exponent = power_exponent(&values)?;
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    power_case(&q, &q, exponent, &k, &m, "LOG")
                } else {
                    format!("{q} = POWER(COALESCE({q}, 0), :{k})")
                }
            }
            OperatorType::StringConcat => {
                let k = next(bind_index);
                format!("{q} = CONCAT(COALESCE({q}, ''), :{k})")
            }
            OperatorType::StringReplace => {
                let s = next(bind_index);
                let r = next(bind_index);
                format!("{q} = REPLACE({q}, :{s}, :{r})")
            }
            OperatorType::Toggle => format!("{q} = NOT COALESCE({q}, FALSE)"),
            OperatorType::ArrayAppend => {
                let k = next(bind_index);
                format!("{q} = JSON_MERGE_PRESERVE(IFNULL({q}, JSON_ARRAY()), :{k})")
            }
            OperatorType::ArrayPrepend => {
                let k = next(bind_index);
                format!("{q} = JSON_MERGE_PRESERVE(:{k}, IFNULL({q}, JSON_ARRAY()))")
            }
            OperatorType::ArrayInsert => {
                let i = next(bind_index);
                let v = next(bind_index);
                format!(
                    "{q} = JSON_ARRAY_INSERT(
                    {q},
                    CONCAT('$[', :{i}, ']'),
                    JSON_EXTRACT(:{v}, '$')
                )"
                )
            }
            OperatorType::ArrayRemove => {
                let k = next(bind_index);
                format!(
                    "{q} = IFNULL((
                    SELECT JSON_ARRAYAGG(value)
                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt
                    WHERE value != :{k}
                ), JSON_ARRAY())"
                )
            }
            OperatorType::ArrayUnique => format!(
                "{q} = IFNULL((
                    SELECT JSON_ARRAYAGG(DISTINCT jt.value)
                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt
                ), JSON_ARRAY())"
            ),
            OperatorType::ArrayIntersect | OperatorType::ArrayDiff => {
                let k = next(bind_index);
                let not = if operator.method() == OperatorType::ArrayDiff { "NOT " } else { "" };
                format!(
                    "{q} = IFNULL((
                    SELECT JSON_ARRAYAGG(jt1.value)
                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt1
                    WHERE jt1.value {not}IN (
                        SELECT value
                        FROM JSON_TABLE(:{k}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt2
                    )
                ), JSON_ARRAY())"
                )
            }
            OperatorType::ArrayFilter => {
                let c = next(bind_index);
                let v = next(bind_index);
                format!(
                    "{q} = IFNULL((
                    SELECT JSON_ARRAYAGG(value)
                    FROM JSON_TABLE({q}, '$[*]' COLUMNS(value TEXT PATH '$')) AS jt
                    WHERE CASE :{c}
                        WHEN 'equal' THEN value = JSON_UNQUOTE(:{v})
                        WHEN 'notEqual' THEN value != JSON_UNQUOTE(:{v})
                        WHEN 'greaterThan' THEN CAST(value AS DECIMAL(65,30)) > CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))
                        WHEN 'greaterThanEqual' THEN CAST(value AS DECIMAL(65,30)) >= CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))
                        WHEN 'lessThan' THEN CAST(value AS DECIMAL(65,30)) < CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))
                        WHEN 'lessThanEqual' THEN CAST(value AS DECIMAL(65,30)) <= CAST(JSON_UNQUOTE(:{v}) AS DECIMAL(65,30))
                        WHEN 'isNull' THEN value IS NULL
                        WHEN 'isNotNull' THEN value IS NOT NULL
                        ELSE TRUE
                    END
                ), JSON_ARRAY())"
                )
            }
            OperatorType::DateAddDays => {
                let k = next(bind_index);
                format!("{q} = DATE_ADD({q}, INTERVAL :{k} DAY)")
            }
            OperatorType::DateSubDays => {
                let k = next(bind_index);
                format!("{q} = DATE_SUB({q}, INTERVAL :{k} DAY)")
            }
            OperatorType::DateSetNow => format!("{q} = NOW()"),
        })
    }

    /// `Postgres::getOperatorSql()`.
    fn postgres_operator_sql(
        &self,
        column: &str,
        operator: &Operator,
        bind_index: &mut usize,
        target: bool,
    ) -> Result<String> {
        let q = self.quote(column);
        let c = if target { format!("target.{q}") } else { q.clone() };
        let values = operator.values();
        let has_limit = at(&values, 1).is_some();
        Ok(match operator.method() {
            OperatorType::Increment => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN COALESCE({c}, 0) + CAST(:{k} AS NUMERIC) > CAST(:{m} AS NUMERIC) THEN COALESCE({c}, 0)
                        ELSE COALESCE({c}, 0) + CAST(:{k} AS NUMERIC)
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({c}, 0) + :{k}")
                }
            }
            OperatorType::Decrement => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN COALESCE({c}, 0) - CAST(:{k} AS NUMERIC) < CAST(:{m} AS NUMERIC) THEN COALESCE({c}, 0)
                        ELSE COALESCE({c}, 0) - CAST(:{k} AS NUMERIC)
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({c}, 0) - :{k}")
                }
            }
            OperatorType::Multiply => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN COALESCE({c}, 0) * CAST(:{k} AS NUMERIC) > CAST(:{m} AS NUMERIC) THEN COALESCE({c}, 0)
                        ELSE COALESCE({c}, 0) * CAST(:{k} AS NUMERIC)
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({c}, 0) * :{k}")
                }
            }
            OperatorType::Divide => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN CAST(:{k} AS NUMERIC) != 0 AND COALESCE({c}, 0) / CAST(:{k} AS NUMERIC) < CAST(:{m} AS NUMERIC) THEN COALESCE({c}, 0)
                        ELSE COALESCE({c}, 0) / CAST(:{k} AS NUMERIC)
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({c}, 0) / :{k}")
                }
            }
            OperatorType::Modulo => {
                let k = next(bind_index);
                format!("{q} = MOD(COALESCE({c}::numeric, 0), :{k}::numeric)")
            }
            OperatorType::Power => {
                let exponent = power_exponent(&values)?;
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    power_case(&q, &c, exponent, &k, &m, "LN")
                } else {
                    format!("{q} = POWER(COALESCE({c}, 0), :{k})")
                }
            }
            OperatorType::StringConcat => {
                let k = next(bind_index);
                format!("{q} = CONCAT(COALESCE({c}, ''), :{k})")
            }
            OperatorType::StringReplace => {
                let s = next(bind_index);
                let r = next(bind_index);
                format!("{q} = REPLACE(COALESCE({c}, ''), :{s}, :{r})")
            }
            OperatorType::Toggle => format!("{q} = NOT COALESCE({c}, FALSE)"),
            OperatorType::ArrayAppend => {
                let k = next(bind_index);
                format!("{q} = COALESCE({c}, '[]'::jsonb) || :{k}::jsonb")
            }
            OperatorType::ArrayPrepend => {
                let k = next(bind_index);
                format!("{q} = :{k}::jsonb || COALESCE({c}, '[]'::jsonb)")
            }
            OperatorType::ArrayUnique => format!(
                "{q} = COALESCE((
                    SELECT jsonb_agg(DISTINCT value)
                    FROM jsonb_array_elements({c}) AS value
                ), '[]'::jsonb)"
            ),
            OperatorType::ArrayRemove => {
                let k = next(bind_index);
                format!(
                    "{q} = COALESCE((
                    SELECT jsonb_agg(value)
                    FROM jsonb_array_elements({c}) AS value
                    WHERE value != :{k}::jsonb
                ), '[]'::jsonb)"
                )
            }
            OperatorType::ArrayInsert => {
                let i = next(bind_index);
                let v = next(bind_index);
                format!(
                    "{q} = (
                    SELECT jsonb_agg(value ORDER BY idx)
                    FROM (
                        SELECT value, idx
                        FROM jsonb_array_elements({c}) WITH ORDINALITY AS t(value, idx)
                        WHERE idx - 1 < :{i}
                        UNION ALL
                        SELECT :{v}::jsonb AS value, :{i} + 1 AS idx
                        UNION ALL
                        SELECT value, idx + 1
                        FROM jsonb_array_elements({c}) WITH ORDINALITY AS t(value, idx)
                        WHERE idx - 1 >= :{i}
                    ) AS combined
                )"
                )
            }
            OperatorType::ArrayIntersect | OperatorType::ArrayDiff => {
                let k = next(bind_index);
                let not = if operator.method() == OperatorType::ArrayDiff { "NOT " } else { "" };
                format!(
                    "{q} = COALESCE((
                    SELECT jsonb_agg(value)
                    FROM jsonb_array_elements({c}) AS value
                    WHERE value {not}IN (SELECT jsonb_array_elements(:{k}::jsonb))
                ), '[]'::jsonb)"
                )
            }
            OperatorType::ArrayFilter => {
                let n = next(bind_index);
                let v = next(bind_index);
                format!(
                    "{q} = COALESCE((
                    SELECT jsonb_agg(value)
                    FROM jsonb_array_elements({c}) AS value
                    WHERE CASE :{n}
                        WHEN 'equal' THEN value = :{v}::jsonb
                        WHEN 'notEqual' THEN value != :{v}::jsonb
                        WHEN 'greaterThan' THEN (value::text)::numeric > trim(both '\"' from :{v}::text)::numeric
                        WHEN 'greaterThanEqual' THEN (value::text)::numeric >= trim(both '\"' from :{v}::text)::numeric
                        WHEN 'lessThan' THEN (value::text)::numeric < trim(both '\"' from :{v}::text)::numeric
                        WHEN 'lessThanEqual' THEN (value::text)::numeric <= trim(both '\"' from :{v}::text)::numeric
                        WHEN 'isNull' THEN value = 'null'::jsonb
                        WHEN 'isNotNull' THEN value != 'null'::jsonb
                        ELSE TRUE
                    END
                ), '[]'::jsonb)"
                )
            }
            OperatorType::DateAddDays => {
                let k = next(bind_index);
                format!("{q} = {c} + (:{k} || ' days')::INTERVAL")
            }
            OperatorType::DateSubDays => {
                let k = next(bind_index);
                format!("{q} = {c} - (:{k} || ' days')::INTERVAL")
            }
            OperatorType::DateSetNow => format!("{q} = NOW()"),
        })
    }

    /// `SQLite::getOperatorSql()`.
    fn sqlite_operator_sql(
        &self,
        column: &str,
        operator: &Operator,
        bind_index: &mut usize,
        math: bool,
    ) -> Result<Option<String>> {
        let q = self.quote(column);
        let values = operator.values();
        let has_limit = at(&values, 1).is_some();
        Ok(Some(match operator.method() {
            OperatorType::Increment => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN COALESCE({q}, 0) > :{m} - :{k} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) + :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) + :{k}")
                }
            }
            OperatorType::Decrement => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN COALESCE({q}, 0) < :{m} + :{k} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) - :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) - :{k}")
                }
            }
            OperatorType::Multiply => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN :{k} > 0 AND COALESCE({q}, 0) > :{m} / :{k} THEN COALESCE({q}, 0)
                        WHEN :{k} < 0 AND COALESCE({q}, 0) < :{m} / :{k} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) * :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) * :{k}")
                }
            }
            OperatorType::Divide => {
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    format!(
                        "{q} = CASE
                        WHEN :{k} != 0 AND COALESCE({q}, 0) / :{k} < :{m} THEN COALESCE({q}, 0)
                        ELSE COALESCE({q}, 0) / :{k}
                    END"
                    )
                } else {
                    format!("{q} = COALESCE({q}, 0) / :{k}")
                }
            }
            OperatorType::Modulo => {
                let k = next(bind_index);
                format!("{q} = COALESCE({q}, 0) % :{k}")
            }
            OperatorType::Power => {
                if !math {
                    return Err(Error::database(
                        "SQLite POWER operator requires math functions. Compile SQLite with -DSQLITE_ENABLE_MATH_FUNCTIONS or use multiply operators instead.",
                    ));
                }
                let exponent = power_exponent(&values)?;
                let k = next(bind_index);
                if has_limit {
                    let m = next(bind_index);
                    power_case(&q, &q, exponent, &k, &m, "LN")
                } else {
                    format!("{q} = POWER(COALESCE({q}, 0), :{k})")
                }
            }
            OperatorType::StringConcat => {
                let k = next(bind_index);
                format!("{q} = IFNULL({q}, '') || :{k}")
            }
            OperatorType::StringReplace => {
                let s = next(bind_index);
                let r = next(bind_index);
                format!("{q} = REPLACE({q}, :{s}, :{r})")
            }
            OperatorType::Toggle => format!("{q} = CASE WHEN COALESCE({q}, 0) = 0 THEN 1 ELSE 0 END"),
            OperatorType::ArrayAppend => {
                let k = next(bind_index);
                format!(
                    "{q} = (
                    SELECT json_group_array(value)
                    FROM (
                        SELECT value FROM json_each(IFNULL({q}, '[]'))
                        UNION ALL
                        SELECT value FROM json_each(:{k})
                    )
                )"
                )
            }
            OperatorType::ArrayPrepend => {
                let k = next(bind_index);
                format!(
                    "{q} = (
                    SELECT json_group_array(value)
                    FROM (
                        SELECT value FROM json_each(:{k})
                        UNION ALL
                        SELECT value FROM json_each(IFNULL({q}, '[]'))
                    )
                )"
                )
            }
            OperatorType::ArrayUnique => format!(
                "{q} = (
                    SELECT json_group_array(DISTINCT value)
                    FROM json_each(IFNULL({q}, '[]'))
                )"
            ),
            OperatorType::ArrayRemove => {
                let k = next(bind_index);
                let removed =
                    if matches!(at(&values, 0), Some(Value::Float(_))) { format!("CAST(:{k} AS REAL)") } else { format!(":{k}") };
                format!(
                    "{q} = (
                    SELECT json_group_array(value)
                    FROM json_each(IFNULL({q}, '[]'))
                    WHERE value != {removed}
                )"
                )
            }
            OperatorType::ArrayInsert => {
                let i = next(bind_index);
                let v = next(bind_index);
                format!(
                    "{q} = (
                    SELECT json_group_array(value)
                    FROM (
                        SELECT value, rownum
                        FROM (
                            SELECT value, (ROW_NUMBER() OVER ()) - 1 as rownum
                            FROM json_each(IFNULL({q}, '[]'))
                        )
                        WHERE rownum < :{i}
                        UNION ALL
                        SELECT value, :{i} as rownum
                        FROM json_each(json_array(json(:{v})))
                        UNION ALL
                        SELECT value, rownum + 1 as rownum
                        FROM (
                            SELECT value, (ROW_NUMBER() OVER ()) - 1 as rownum
                            FROM json_each(IFNULL({q}, '[]'))
                        )
                        WHERE rownum >= :{i}
                        ORDER BY rownum
                    )
                )"
                )
            }
            OperatorType::ArrayIntersect | OperatorType::ArrayDiff => {
                let k = next(bind_index);
                let not = if operator.method() == OperatorType::ArrayDiff { "NOT " } else { "" };
                format!(
                    "{q} = (
                    SELECT json_group_array(value)
                    FROM json_each(IFNULL({q}, '[]'))
                    WHERE value {not}IN (SELECT value FROM json_each(:{k}))
                )"
                )
            }
            OperatorType::ArrayFilter => {
                if values.is_empty() {
                    return Ok(Some(format!("{q} = {q}")));
                }
                let filter_type = values.get(&ArrayKey::Int(0)).and_then(crate::value::as_str).unwrap_or("\0");
                match filter_type {
                    "isNull" => format!(
                        "{q} = (
                            SELECT json_group_array(value)
                            FROM json_each(IFNULL({q}, '[]'))
                            WHERE value IS NULL
                        )"
                    ),
                    "isNotNull" => format!(
                        "{q} = (
                            SELECT json_group_array(value)
                            FROM json_each(IFNULL({q}, '[]'))
                            WHERE value IS NOT NULL
                        )"
                    ),
                    "equal" | "notEqual" | "greaterThan" | "greaterThanEqual" | "lessThan" | "lessThanEqual" => {
                        if values.len() < 2 {
                            return Ok(Some(format!("{q} = {q}")));
                        }
                        let k = next(bind_index);
                        let op = match filter_type {
                            "equal" => "=",
                            "notEqual" => "!=",
                            "greaterThan" => ">",
                            "greaterThanEqual" => ">=",
                            "lessThan" => "<",
                            _ => "<=",
                        };
                        if matches!(filter_type, "equal" | "notEqual") {
                            format!(
                                "{q} = (
                                SELECT json_group_array(value)
                                FROM json_each(IFNULL({q}, '[]'))
                                WHERE value {op} :{k}
                            )"
                            )
                        } else {
                            format!(
                                "{q} = (
                                SELECT json_group_array(value)
                                FROM json_each(IFNULL({q}, '[]'))
                                WHERE CAST(value AS REAL) {op} CAST(:{k} AS REAL)
                            )"
                            )
                        }
                    }
                    _ => format!("{q} = {q}"),
                }
            }
            OperatorType::DateAddDays => {
                let k = next(bind_index);
                format!("{q} = datetime({q}, :{k} || ' days')")
            }
            OperatorType::DateSubDays => {
                let k = next(bind_index);
                format!("{q} = datetime({q}, '-' || abs(:{k}) || ' days')")
            }
            OperatorType::DateSetNow => format!("{q} = datetime('now')"),
        }))
    }

    /// `SQLite::getSupportForMathFunctions()` for an operator that needs it
    /// (a `power` on SQLite); `true` for every other operator and engine.
    pub(crate) async fn operator_math_functions(&self, operator: &Operator) -> bool {
        if self.engine != Engine::Sqlite || operator.method() != OperatorType::Power {
            return true;
        }
        if let Some(available) = SQLITE_MATH_FUNCTIONS.get() {
            return *available;
        }
        let available = {
            let mut pdo = self.connection.lock().await;
            match pdo.query("SELECT POWER(2, 3) as test").await {
                Ok(mut statement) => {
                    let row = statement.fetch_assoc();
                    statement.close_cursor();
                    row.and_then(|r| r.get_str("test").cloned()).is_some_and(|v| v.loose_eq(&Value::Int(8)))
                }
                Err(_) => false,
            }
        };
        *SQLITE_MATH_FUNCTIONS.get_or_init(|| available)
    }

    /// `bindOperatorParameters()` (and SQLite's): binds an operator's
    /// values to the `:op_N` placeholders [`Sql::operator_sql`] wrote,
    /// from `bind_index`, which advances past them.
    pub(crate) fn bind_operator_parameters(
        &self,
        statement: &mut Prepared,
        operator: &Operator,
        bind_index: &mut usize,
    ) -> Result<()> {
        let method = operator.method();
        let values = operator.values();
        if self.engine == Engine::Sqlite {
            if matches!(method, OperatorType::Toggle | OperatorType::DateSetNow | OperatorType::ArrayUnique) {
                return Ok(());
            }
            if method == OperatorType::ArrayFilter {
                if values.len() >= 2 {
                    let filter_type = values.get(&ArrayKey::Int(0)).cloned().unwrap_or(Value::Null);
                    let filter_value = values.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null);
                    let comparisons = ["equal", "notEqual", "greaterThan", "greaterThanEqual", "lessThan", "lessThanEqual"];
                    if comparisons.iter().any(|c| filter_type.loose_eq(&Value::Str(Str::from_static(c)))) {
                        let key = next(bind_index);
                        let value = match filter_value {
                            Value::Bool(b) => Value::Int(i64::from(b)),
                            other => other,
                        };
                        let ty = self.pdo_type(&value)?;
                        statement.bind_value(format!(":{key}"), value, ty)?;
                    }
                }
                return Ok(());
            }
        }
        let mut bind = |statement: &mut Prepared, value: Value, ty: Option<ParamType>| -> Result<()> {
            let key = next(bind_index);
            let ty = match ty {
                Some(ty) => ty,
                None => self.pdo_type(&value)?,
            };
            statement.bind_value(format!(":{key}"), value, ty)
        };
        let too_many = |values: &Array| -> Result<()> {
            if values.len() > MAX_ARRAY_OPERATOR_SIZE {
                return Err(Error::database(format!(
                    "Array size {} exceeds maximum allowed size of {MAX_ARRAY_OPERATOR_SIZE} for array operations",
                    values.len()
                )));
            }
            Ok(())
        };
        match method {
            OperatorType::Increment
            | OperatorType::Decrement
            | OperatorType::Multiply
            | OperatorType::Divide
            | OperatorType::Power => {
                bind(statement, or(&values, 0, Value::Int(1)), None)?;
                if let Some(limit) = at(&values, 1) {
                    bind(statement, exact_limit(limit.clone()), None)?;
                }
            }
            OperatorType::Modulo => bind(statement, or(&values, 0, Value::Int(1)), None)?,
            OperatorType::StringConcat => {
                bind(statement, or(&values, 0, Value::Str(Str::EMPTY)), Some(ParamType::Str))?;
            }
            OperatorType::StringReplace => {
                bind(statement, or(&values, 0, Value::Str(Str::EMPTY)), Some(ParamType::Str))?;
                bind(statement, or(&values, 1, Value::Str(Str::EMPTY)), Some(ParamType::Str))?;
            }
            OperatorType::DateAddDays | OperatorType::DateSubDays => {
                bind(statement, or(&values, 0, Value::Int(0)), Some(ParamType::Int))?;
            }
            OperatorType::ArrayAppend
            | OperatorType::ArrayPrepend
            | OperatorType::ArrayIntersect
            | OperatorType::ArrayDiff => {
                too_many(&values)?;
                bind(statement, json_values(&values), Some(ParamType::Str))?;
            }
            OperatorType::ArrayRemove => {
                let value = match or(&values, 0, Value::Null) {
                    a @ Value::Array(_) => json_encode(&a),
                    other => other,
                };
                bind(statement, value, None)?;
            }
            OperatorType::ArrayInsert => {
                bind(statement, or(&values, 0, Value::Int(0)), Some(ParamType::Int))?;
                bind(statement, json_encode(&or(&values, 1, Value::Null)), Some(ParamType::Str))?;
            }
            OperatorType::Toggle | OperatorType::DateSetNow | OperatorType::ArrayUnique | OperatorType::ArrayFilter => {}
        }
        Ok(())
    }

    /// `getOperatorBuilderExpression()` (and PostgreSQL's and SQLite's):
    /// the operator as an expression with positional bindings, for an
    /// update's `setRaw()`.
    pub(crate) async fn operator_builder_expression(&self, column: &str, operator: &Operator) -> Result<Expression> {
        let math = self.operator_math_functions(operator).await;
        let mut bind_index = 0;
        let full = self
            .operator_sql(column, operator, &mut bind_index, false, math)?
            .ok_or_else(|| cannot_express(operator))?;
        let quoted = self.quote(column);
        if self.engine == Engine::Sqlite && operator.method() == OperatorType::ArrayFilter {
            let values = operator.values();
            let mut named = Vec::new();
            if values.len() >= 2 {
                let filter_type = values.get(&ArrayKey::Int(0)).cloned().unwrap_or(Value::Null);
                let comparisons = ["equal", "notEqual", "greaterThan", "greaterThanEqual", "lessThan", "lessThanEqual"];
                if comparisons.iter().any(|c| filter_type.loose_eq(&Value::Str(Str::from_static(c)))) {
                    named.push(("op_0".to_owned(), values.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null)));
                }
            }
            return Ok(positional(&full, &quoted, &named, false));
        }
        let expression = positional(&full, &quoted, &named_bindings(operator, false), true);
        if self.engine == Engine::Postgres && operator.method() == OperatorType::ArrayRemove {
            let value = or(&operator.values(), 0, Value::Null);
            if !matches!(value, Value::Array(_)) {
                return Ok(Expression { sql: expression.sql, bindings: vec![json_encode(&value)] });
            }
        }
        Ok(expression)
    }

    /// `getOperatorUpsertExpression()` (and PostgreSQL's, which reads the
    /// stored row through `target.`): the operator for an upsert's
    /// conflict update.
    pub(crate) async fn operator_upsert_expression(&self, column: &str, operator: &Operator) -> Result<Expression> {
        if self.engine != Engine::Postgres {
            return self.operator_builder_expression(column, operator).await;
        }
        let mut bind_index = 0;
        let full = self
            .operator_sql(column, operator, &mut bind_index, true, true)?
            .ok_or_else(|| cannot_express(operator))?;
        Ok(positional(&full, &self.quote(column), &named_bindings(operator, true), true))
    }

    /// `applyOperatorToValue()`: what the operator makes of `value` (the
    /// attribute's default) for a document an upsert creates.
    pub(crate) fn apply_operator_to_value(&self, operator: &Operator, value: Value) -> Result<Value> {
        apply_operator_to_value(operator, value)
    }
}

/// `Operator cannot be expressed in SQL: <method>`.
fn cannot_express(operator: &Operator) -> Error {
    Error::database(format!("Operator cannot be expressed in SQL: {}", operator.method().as_str()))
}

/// `SQL::applyOperatorToValue()`.
pub(crate) fn apply_operator_to_value(operator: &Operator, value: Value) -> Result<Value> {
    let method = operator.method();
    let values = operator.values();
    let current = if matches!(value, Value::Null) { Value::Int(0) } else { value.clone() };
    let operand = or(&values, 0, Value::Int(1));
    if let Some(exact) = BigInt::calculate_outside_native(method, &current, &operand) {
        let bound = exact_limit(or(&values, 1, Value::Null));
        if BigInt::is_integer_value(&bound) {
            let upper = matches!(method, OperatorType::Increment | OperatorType::Multiply | OperatorType::Power);
            let bound_operand = Operand::from_value(&bound).unwrap_or(Operand::Int(0));
            let cmp = BigInt::compare(exact.as_operand(), bound_operand).unwrap_or(Ordering::Equal);
            if (upper && cmp == Ordering::Greater)
                || (!upper && method != OperatorType::Modulo && cmp == Ordering::Less)
            {
                let native = BigInt::to_native(Operand::from_value(&current).unwrap_or(Operand::Int(0)))?;
                return Ok(integer_value(native));
            }
        }
        return Ok(integer_value(exact));
    }

    let num_val = numeric(&value).unwrap_or(Number::Int(0));
    let first = values.get(&ArrayKey::Int(0)).cloned().filter(|_| !values.is_empty());
    let num_op = first.as_ref().and_then(numeric).unwrap_or(Number::Int(1));
    let arr_val = match &value {
        Value::Array(a) => a.clone(),
        _ => Array::new(),
    };
    let is_zero = |n: Number| n.as_f64() == 0.0;
    let to_int = |n: Number| match n {
        Number::Int(i) => i,
        Number::Float(f) => php_std::format::dval_to_lval(f),
    };
    let scalar_string = |v: Option<&Value>| match v {
        Some(v @ (Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_))) => {
            v.to_php_string().unwrap_or(Str::EMPTY).as_bytes().to_vec()
        }
        _ => Vec::new(),
    };

    let result = match method {
        OperatorType::Increment => number_value(php_std::format::add(num_val, num_op)),
        OperatorType::Decrement => number_value(php_std::format::sub(num_val, num_op)),
        OperatorType::Multiply => number_value(php_std::format::mul(num_val, num_op)),
        OperatorType::Divide => {
            if is_zero(num_op) {
                number_value(num_val)
            } else {
                match php_std::format::div(num_val, num_op) {
                    Some(n) => number_value(n),
                    None => return Err(Error::new(Kind::DivisionByZero, "Division by zero")),
                }
            }
        }
        OperatorType::Modulo => {
            if is_zero(num_op) {
                Value::Int(to_int(num_val))
            } else {
                let divisor = to_int(num_op);
                if divisor == 0 {
                    return Err(Error::new(Kind::DivisionByZero, "Modulo by zero"));
                }
                Value::Int(to_int(num_val).wrapping_rem(divisor))
            }
        }
        OperatorType::Power => match (num_val, num_op) {
            (Number::Int(b), Number::Int(e)) if e >= 0 => match u32::try_from(e).ok().and_then(|e| b.checked_pow(e)) {
                Some(r) => Value::Int(r),
                None => Value::Float(php_std::format::pow(b as f64, e as f64)),
            },
            (b, e) => Value::Float(php_std::format::pow(b.as_f64(), e.as_f64())),
        },
        OperatorType::ArrayAppend => Value::Array(array_merge(&arr_val, &values)),
        OperatorType::ArrayPrepend => Value::Array(array_merge(&values, &arr_val)),
        OperatorType::ArrayInsert => {
            let index = match &first {
                Some(raw) => numeric(raw).map_or(0, to_int),
                None => 0,
            };
            let item = if values.len() > 1 { values.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null) } else { Value::Null };
            Value::Array(array_insert(&arr_val, index, item))
        }
        OperatorType::ArrayRemove => {
            let arr = stringify_list(&arr_val);
            let remove = match or(&values, 0, Value::Null) {
                Value::Array(a) => stringify_list(&a),
                other => vec![stringify(&other)],
            };
            str_list(arr.into_iter().filter(|s| !remove.contains(s)).collect())
        }
        OperatorType::ArrayUnique => {
            let mut out: Vec<Str> = Vec::new();
            for s in stringify_list(&arr_val) {
                if !out.contains(&s) {
                    out.push(s);
                }
            }
            str_list(out)
        }
        OperatorType::ArrayIntersect | OperatorType::ArrayDiff => {
            let others = stringify_list(&values);
            let keep = method == OperatorType::ArrayIntersect;
            str_list(stringify_list(&arr_val).into_iter().filter(|s| others.contains(s) == keep).collect())
        }
        OperatorType::ArrayFilter => filter_array(&arr_val, &or(&values, 0, Value::Null), &or(&values, 1, Value::Null)),
        OperatorType::StringConcat => {
            let mut s = scalar_string(Some(&value));
            s.extend(scalar_string(first.as_ref()));
            Value::Str(Str::from(s))
        }
        OperatorType::StringReplace => {
            let search = scalar_string(first.as_ref());
            let replace = scalar_string(if values.len() > 1 { values.get(&ArrayKey::Int(1)) } else { None });
            let subject = scalar_string(Some(&value));
            Value::Str(Str::from(php_std::string::str_replace(&search, &replace, &subject).0.into_owned()))
        }
        OperatorType::Toggle => Value::Bool(!value.truthy()),
        OperatorType::DateAddDays | OperatorType::DateSubDays => {
            let days = first.as_ref().and_then(numeric).map_or(0, to_int);
            let days = if method == OperatorType::DateSubDays { days.wrapping_neg() } else { days };
            shift_days(value, days)
        }
        OperatorType::DateSetNow => Value::Str(Str::from(crate::datetime::now())),
    };
    Ok(keep_within_bound(method, num_val, result, at(&values, 1)))
}
