//! Operators as aggregation pipeline expressions.
//!
//! PHP: `Mongo::buildOperatorPipeline`, `getOperatorExpression`,
//! `getNumericOperand`, `getIntegerOperand`, `getStringOperand`,
//! `getArrayFilterCondition`.

use super::Mongo;
use super::filters::{assoc, key_text};
use crate::error::{Error, Result};
use crate::operator::{Operator, OperatorType};
use crate::value::{Array, ArrayKey, Ext, Value};

/// `[$a, $b, ...]`.
fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

/// A string value.
fn s(value: &str) -> Value {
    super::text(value)
}

/// `['$ifNull' => [$reference, $default]]`.
fn if_null(reference: &str, default: Value) -> Value {
    assoc([("$ifNull", list(vec![s(reference), default]))])
}

/// `['$literal' => $value]`.
fn literal(value: Value) -> Value {
    assoc([("$literal", value)])
}

/// `\array_values($values)`.
fn array_values(values: &Array) -> Value {
    list(values.values().cloned().collect())
}

/// `$values[$offset] ?? null`.
fn at(values: &Array, offset: i64) -> Option<Value> {
    values.get(&ArrayKey::Int(offset)).filter(|v| !matches!(v, Value::Null)).cloned()
}

/// `getNumericOperand($values, $offset, $default, $method)`.
fn numeric(values: &Array, offset: i64, default: Value, method: OperatorType) -> Result<Value> {
    let value = at(values, offset).unwrap_or(default);
    if !matches!(value, Value::Int(_) | Value::Float(_)) {
        return Err(Error::database(format!("Invalid numeric operand for operator {}", method.as_str())));
    }
    Ok(value)
}

/// `getIntegerOperand($values, $offset, $default, $method)`.
fn integer(values: &Array, offset: i64, default: i64, method: OperatorType) -> Result<i64> {
    match at(values, offset).unwrap_or(Value::Int(default)) {
        Value::Int(i) => Ok(i),
        _ => Err(Error::database(format!("Invalid integer operand for operator {}", method.as_str()))),
    }
}

/// `getStringOperand($values, $offset, $default, $method)`.
fn string(values: &Array, offset: i64, default: &str, method: OperatorType) -> Result<Value> {
    match at(values, offset).unwrap_or_else(|| s(default)) {
        v @ Value::Str(_) => Ok(v),
        _ => Err(Error::database(format!("Invalid string operand for operator {}", method.as_str()))),
    }
}

/// `getArrayFilterCondition($condition, $compare)`.
fn array_filter_condition(condition: &[u8], compare: Value) -> Value {
    let value = literal(compare);
    let this = || s("$$this");
    match condition {
        b"equal" => assoc([("$eq", list(vec![this(), value]))]),
        b"notEqual" => assoc([("$ne", list(vec![this(), value]))]),
        b"greaterThan" => assoc([("$gt", list(vec![this(), value]))]),
        b"greaterThanEqual" => assoc([("$gte", list(vec![this(), value]))]),
        b"lessThan" => assoc([("$lt", list(vec![this(), value]))]),
        b"lessThanEqual" => assoc([("$lte", list(vec![this(), value]))]),
        b"isNull" => assoc([("$eq", list(vec![this(), Value::Null]))]),
        b"isNotNull" => assoc([("$ne", list(vec![this(), Value::Null]))]),
        _ => literal(Value::Bool(true)),
    }
}

/// `$expression` bounded by `$values[1]` when set: the expression when it
/// compares with the bound by `comparison`, else the stored value.
fn bounded(expression: Value, values: &Array, comparison: &str, reference: &str, default: Value) -> Value {
    match at(values, 1) {
        Some(limit) => assoc([(
            "$cond",
            list(vec![
                assoc([(comparison, list(vec![expression.clone(), limit]))]),
                expression,
                if_null(reference, default),
            ]),
        )]),
        None => expression,
    }
}

impl Mongo {
    /// `buildOperatorPipeline($record)`: a record holding operators as a
    /// pipeline update (`[['$set' => ...]]`), each operator its expression
    /// and every other value a literal; `None` without operators.
    pub(crate) fn operator_pipeline(record: &Array) -> Result<Option<Array>> {
        if !record.values().any(|v| matches!(v, Value::Ext(Ext::Operator(_)))) {
            return Ok(None);
        }
        let mut set = Array::new();
        for (k, value) in record.iter() {
            let expression = match value {
                Value::Ext(Ext::Operator(operator)) => Self::operator_expression(operator, &key_text(k))?,
                other => literal(other.clone()),
            };
            set.set(k.to_owned(), expression);
        }
        Ok(Some(Array::from_list(vec![assoc([("$set", Value::Array(set))])])))
    }

    /// `getOperatorExpression($operator, $field)`.
    ///
    /// Fails ([`crate::Kind::Database`]) for an operand of the wrong type.
    pub(crate) fn operator_expression(operator: &Operator, field: &str) -> Result<Value> {
        let reference = format!("${field}");
        let reference = reference.as_str();
        let method = operator.method();
        let values = operator.values();
        let values = values.as_ref();
        let first = || at(values, 0);
        Ok(match method {
            OperatorType::Increment => {
                let expression =
                    assoc([("$add", list(vec![if_null(reference, Value::Int(0)), first().unwrap_or(Value::Int(1))]))]);
                bounded(expression, values, "$lte", reference, Value::Int(0))
            }
            OperatorType::Decrement => {
                let expression = assoc([(
                    "$subtract",
                    list(vec![if_null(reference, Value::Int(0)), first().unwrap_or(Value::Int(1))]),
                )]);
                bounded(expression, values, "$gte", reference, Value::Int(0))
            }
            OperatorType::Multiply => {
                let expression = assoc([(
                    "$multiply",
                    list(vec![if_null(reference, Value::Int(0)), first().unwrap_or(Value::Int(1))]),
                )]);
                bounded(expression, values, "$lte", reference, Value::Int(0))
            }
            OperatorType::Divide => {
                let expression =
                    assoc([("$divide", list(vec![if_null(reference, Value::Int(0)), first().unwrap_or(Value::Null)]))]);
                bounded(expression, values, "$gte", reference, Value::Int(0))
            }
            OperatorType::Modulo => {
                assoc([("$mod", list(vec![if_null(reference, Value::Int(0)), first().unwrap_or(Value::Null)]))])
            }
            OperatorType::Power => {
                let base = if_null(reference, Value::Int(0));
                let exponent = numeric(values, 0, Value::Int(1), method)?;
                let mut expression = assoc([("$pow", list(vec![base.clone(), exponent.clone()]))]);
                if let Some(limit) = at(values, 1) {
                    expression = assoc([(
                        "$cond",
                        list(vec![assoc([("$lte", list(vec![expression.clone(), limit]))]), expression, base.clone()]),
                    )]);
                    let x = exponent.to_double();
                    let mut guards: Vec<Value> = Vec::new();
                    if x < 0.0 {
                        guards.push(assoc([("$eq", list(vec![base.clone(), Value::Int(0)]))]));
                    }
                    if x.floor() != x {
                        guards.push(assoc([("$lt", list(vec![base.clone(), Value::Int(0)]))]));
                    }
                    if !guards.is_empty() {
                        let undefined =
                            if guards.len() == 1 { guards.remove(0) } else { assoc([("$or", list(guards))]) };
                        expression = assoc([("$cond", list(vec![undefined, base, expression]))]);
                    }
                }
                expression
            }
            OperatorType::StringConcat => {
                assoc([("$concat", list(vec![if_null(reference, s("")), literal(first().unwrap_or_else(|| s("")))]))])
            }
            OperatorType::StringReplace => {
                let search = first().unwrap_or_else(|| s(""));
                if matches!(&search, Value::Str(x) if x.is_empty()) {
                    if_null(reference, s(""))
                } else {
                    assoc([(
                        "$replaceAll",
                        assoc([
                            ("input", if_null(reference, s(""))),
                            ("find", literal(search)),
                            ("replacement", literal(at(values, 1).unwrap_or_else(|| s("")))),
                        ]),
                    )])
                }
            }
            OperatorType::Toggle => assoc([("$not", list(vec![if_null(reference, Value::Bool(false))]))]),
            OperatorType::ArrayAppend => {
                assoc([("$concatArrays", list(vec![if_null(reference, list(vec![])), literal(array_values(values))]))])
            }
            OperatorType::ArrayPrepend => {
                assoc([("$concatArrays", list(vec![literal(array_values(values)), if_null(reference, list(vec![]))]))])
            }
            OperatorType::ArrayInsert => {
                let index = integer(values, 0, 0, method)?;
                let value = at(values, 1).unwrap_or(Value::Null);
                let size = assoc([("$size", s("$$array"))]);
                let before = assoc([(
                    "$cond",
                    list(vec![
                        assoc([("$lte", list(vec![Value::Int(index), Value::Int(0)]))]),
                        list(vec![]),
                        assoc([("$slice", list(vec![s("$$array"), Value::Int(index)]))]),
                    ]),
                )]);
                let after = assoc([(
                    "$cond",
                    list(vec![
                        assoc([("$gte", list(vec![Value::Int(index), size.clone()]))]),
                        list(vec![]),
                        assoc([(
                            "$slice",
                            list(vec![s("$$array"), assoc([("$subtract", list(vec![Value::Int(index), size]))])]),
                        )]),
                    ]),
                )]);
                assoc([(
                    "$let",
                    assoc([
                        ("vars", assoc([("array", if_null(reference, list(vec![])))])),
                        ("in", assoc([("$concatArrays", list(vec![before, literal(list(vec![value])), after]))])),
                    ]),
                )])
            }
            OperatorType::ArrayRemove => assoc([(
                "$filter",
                assoc([
                    ("input", if_null(reference, list(vec![]))),
                    ("cond", assoc([("$ne", list(vec![s("$$this"), literal(first().unwrap_or(Value::Null))]))])),
                ]),
            )]),
            OperatorType::ArrayUnique => assoc([(
                "$reduce",
                assoc([
                    ("input", if_null(reference, list(vec![]))),
                    ("initialValue", list(vec![])),
                    (
                        "in",
                        assoc([(
                            "$cond",
                            list(vec![
                                assoc([("$in", list(vec![s("$$this"), s("$$value")]))]),
                                s("$$value"),
                                assoc([("$concatArrays", list(vec![s("$$value"), list(vec![s("$$this")])]))]),
                            ]),
                        )]),
                    ),
                ]),
            )]),
            OperatorType::ArrayIntersect => assoc([(
                "$filter",
                assoc([
                    ("input", if_null(reference, list(vec![]))),
                    ("cond", assoc([("$in", list(vec![s("$$this"), literal(array_values(values))]))])),
                ]),
            )]),
            OperatorType::ArrayDiff => assoc([(
                "$filter",
                assoc([
                    ("input", if_null(reference, list(vec![]))),
                    (
                        "cond",
                        assoc([(
                            "$not",
                            list(vec![assoc([("$in", list(vec![s("$$this"), literal(array_values(values))]))])]),
                        )]),
                    ),
                ]),
            )]),
            OperatorType::ArrayFilter => {
                let condition = string(values, 0, "", method)?;
                let condition = match &condition {
                    Value::Str(c) => c.as_bytes().to_vec(),
                    _ => Vec::new(),
                };
                assoc([(
                    "$filter",
                    assoc([
                        ("input", if_null(reference, list(vec![]))),
                        ("cond", array_filter_condition(&condition, at(values, 1).unwrap_or(Value::Null))),
                    ]),
                )])
            }
            OperatorType::DateAddDays => assoc([(
                "$dateAdd",
                assoc([
                    ("startDate", if_null(reference, s("$$NOW"))),
                    ("unit", s("day")),
                    ("amount", Value::Int(integer(values, 0, 0, method)?)),
                ]),
            )]),
            OperatorType::DateSubDays => assoc([(
                "$dateSubtract",
                assoc([
                    ("startDate", if_null(reference, s("$$NOW"))),
                    ("unit", s("day")),
                    ("amount", Value::Int(integer(values, 0, 0, method)?)),
                ]),
            )]),
            OperatorType::DateSetNow => s("$$NOW"),
        })
    }
}
