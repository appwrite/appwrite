//! `increaseDocumentAttribute()` and `decreaseDocumentAttribute()`, and the
//! numeric helpers the writes share (`getNumericResult()`,
//! `getNativeNumber()`, `declaredAttribute()`, `numericAttribute()`,
//! `isDeclaredInteger()`, `assertIntegerChange()`, `integerBound()`,
//! `assertPositiveChange()`, `roundOperators()`).

use std::cmp::Ordering;

use super::super::{Database, METADATA};
use crate::attribute::Attribute;
use crate::cache::Target;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::operator::{Operator, OperatorType};
use crate::permission::PermissionType;
use crate::rounding::Rounding;
use crate::validator::authorization::Input;
use crate::validator::big_int::{BigInt, Operand};
use crate::value::{ArrayKey, Ext, Str, Value};

/// PHP's `is_numeric()`.
pub(crate) fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// PHP's string conversion of a scalar, for messages (`'...'.$value`).
pub(crate) fn text(value: &Value) -> String {
    value.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default()
}

/// A value rounded by `rounding`, or the value as given when it does not
/// round (PHP's `Rounding::round()` returns such a value unchanged).
pub(crate) fn round(rounding: Rounding, value: &Value) -> Value {
    rounding.round(value).map(Value::from).unwrap_or_else(|| value.clone())
}

/// The operand of an integer value (`int|string`), for [`BigInt`].
fn operand(value: &Value) -> Result<Operand<'_>> {
    Operand::from_value(value).ok_or_else(|| Error::type_error("Value must be numeric."))
}

/// `$a + $b` of PHP numbers: integers overflow to a float.
fn add(a: &Value, b: &Value) -> Value {
    match (a, b) {
        (Value::Int(x), Value::Int(y)) => x.checked_add(*y).map_or(Value::Float(*x as f64 + *y as f64), Value::Int),
        _ => Value::Float(a.to_double() + b.to_double()),
    }
}

/// `$a - $b` of PHP numbers: integers overflow to a float.
fn sub(a: &Value, b: &Value) -> Value {
    match (a, b) {
        (Value::Int(x), Value::Int(y)) => x.checked_sub(*y).map_or(Value::Float(*x as f64 - *y as f64), Value::Int),
        _ => Value::Float(a.to_double() - b.to_double()),
    }
}

/// `$value * -1` of a PHP number.
fn negate(value: &Value) -> Value {
    match value {
        Value::Int(x) => x.checked_neg().map_or(Value::Float(-(*x as f64)), Value::Int),
        other => Value::Float(-other.to_double()),
    }
}

/// Whether a value is a float that is not finite.
fn infinite(value: &Value) -> bool {
    matches!(value, Value::Float(f) if !f.is_finite())
}

/// `BigInt::compare($a, $b)`.
fn compare(a: &Value, b: &Value) -> Result<Ordering> {
    BigInt::compare(operand(a)?, operand(b)?)
}

impl Database {
    /// The value a numeric attribute holds after adding (`increase`) or
    /// subtracting `value` (`getNumericResult()`): exact for integers,
    /// within the attribute's bounds.
    ///
    /// Fails ([`crate::Kind::Type`]) for a value that is not an integer on
    /// an integer attribute, or not finite; ([`crate::Kind::Limit`]) beyond
    /// the attribute's bounds.
    pub(crate) fn numeric_result(
        &self,
        attribute: &Attribute,
        current: Option<&Value>,
        value: &Value,
        increase: bool,
    ) -> Result<Value> {
        let current = current.cloned().unwrap_or(Value::Int(0));

        if attribute.is_integer() {
            if !attribute.signed
                && attribute.kind == utopia_query::schema::ColumnType::BigInteger
                && !self.inner.adapter.supports(Capability::UnsignedBigInt)
            {
                return Err(Error::type_error("Unsigned 64-bit arithmetic is not supported by this adapter."));
            }
            if !BigInt::is_integer_value(&current) {
                return Err(Error::type_error("Attribute value must be an integer."));
            }
            if !BigInt::is_integer_value(value) {
                return Err(Error::type_error("Change value must be an integer."));
            }
            let result = if increase {
                BigInt::add(operand(&current)?, operand(value)?)?
            } else {
                BigInt::subtract(operand(&current)?, operand(value)?)?
            };
            let Some(bounds) = attribute.bounds() else {
                return Err(Error::type_error("Attribute value must be numeric."));
            };
            let result = Value::from(result);
            if compare(&result, &bounds.max)? == Ordering::Greater {
                return Err(Error::limit(format!("Attribute value exceeds maximum limit: {}", text(&bounds.max))));
            }
            if compare(&result, &bounds.min)? == Ordering::Less {
                return Err(Error::limit(format!("Attribute value exceeds minimum limit: {}", text(&bounds.min))));
            }
            return Ok(result);
        }

        if !is_numeric(&current) {
            return Err(Error::type_error("Attribute value must be numeric."));
        }
        let current = native_number(&current)?;
        let value = native_number(value)?;
        let bounds = attribute.bounds();
        let Some(bounds) = bounds.filter(|_| !infinite(&current)) else {
            return Err(Error::type_error("Attribute value must be a finite numeric value."));
        };
        let maximum = native_number(&bounds.max)?;
        let minimum = native_number(&bounds.min)?;
        // PHP evaluates `$a > $b` as `$b < $a`, which matters for NaN.
        let gt = |a: &Value, b: &Value| b.compare(a) < 0;
        let lt = |a: &Value, b: &Value| a.compare(b) < 0;
        let zero = Value::Int(0);

        if gt(&current, &maximum) {
            return Err(Error::limit(format!("Attribute value exceeds maximum limit: {}", text(&maximum))));
        }
        if lt(&current, &minimum) {
            return Err(Error::limit(format!("Attribute value exceeds minimum limit: {}", text(&minimum))));
        }
        let overflows = if increase {
            gt(&value, &zero) && gt(&current, &sub(&maximum, &value))
        } else {
            lt(&value, &zero) && gt(&current, &add(&maximum, &value))
        };
        if overflows {
            return Err(Error::limit(format!("Attribute value exceeds maximum limit: {}", text(&maximum))));
        }
        let underflows = if increase {
            lt(&value, &zero) && lt(&current, &sub(&minimum, &value))
        } else {
            gt(&value, &zero) && lt(&current, &add(&minimum, &value))
        };
        if underflows {
            return Err(Error::limit(format!("Attribute value exceeds minimum limit: {}", text(&minimum))));
        }
        let result = if increase { add(&current, &value) } else { sub(&current, &value) };
        if infinite(&result) {
            return Err(Error::type_error("Attribute value must be a finite numeric value."));
        }
        Ok(result)
    }

    /// The single-valued numeric attribute an increase or decrease changes
    /// (`numericAttribute()`); `None` when the adapter does not define
    /// attributes.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for an undeclared attribute,
    /// ([`crate::Kind::Type`]) for one that is not numeric or is an array.
    pub(crate) fn numeric_attribute(&self, collection: &Collection, key: &str) -> Result<Option<Attribute>> {
        if !self.inner.adapter.supports(Capability::DefinedAttributes) {
            return Ok(None);
        }
        let Some(attribute) = declared_attribute(collection, key)? else {
            return Err(Error::not_found("Attribute not found"));
        };
        if !attribute.is_numeric() || attribute.array {
            return Err(Error::type_error("Attribute must be an integer or float and can not be an array."));
        }
        Ok(Some(attribute))
    }

    /// Adds `value` (an integer, a float or a numeric string) to a numeric
    /// attribute, up to `max` (`increaseDocumentAttribute()`); returns the
    /// document as updated. On an integer attribute `value` is rounded half
    /// away from zero (below a half changes nothing) and `max` floored.
    ///
    /// Fails ([`crate::Kind::Type`]) for a value that is not a number
    /// greater than 0, ([`crate::Kind::Limit`]) when the result would exceed
    /// `max`, ([`crate::Kind::NotFound`]) for a missing document or
    /// attribute, ([`crate::Kind::Type`]) for an attribute that is not
    /// numeric, ([`crate::Kind::Authorization`]) without update permission.
    pub async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        max: Option<Value>,
    ) -> Result<Document> {
        self.step_document_attribute(collection, id, attribute, value, max, true).await
    }

    /// Subtracts `value` from a numeric attribute, down to `min`
    /// (`decreaseDocumentAttribute()`); on an integer attribute `value` is
    /// rounded half away from zero and `min` ceiled.
    ///
    /// Fails as [`Database::increase_document_attribute`] does.
    pub async fn decrease_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        min: Option<Value>,
    ) -> Result<Document> {
        self.step_document_attribute(collection, id, attribute, value, min, false).await
    }

    /// The body `increaseDocumentAttribute()` and
    /// `decreaseDocumentAttribute()` share; `bound` is the max of an
    /// increase or the min of a decrease.
    async fn step_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        bound: Option<Value>,
        increase: bool,
    ) -> Result<Document> {
        assert_positive_change(&value)?;

        let collection = self.silent(self.get_collection(collection)).await?;
        let numeric_attribute = self.numeric_attribute(&collection, attribute)?;
        let declared = match &numeric_attribute {
            Some(a) => Some(a.clone()),
            None => declared_attribute(&collection, attribute)?,
        };

        let mut value = value;
        let mut bound = bound;
        if is_declared_integer(declared.as_ref()) {
            value = round(Rounding::HalfAwayFromZero, &value);
            assert_integer_change(&value)?;
            if let Some(b) = &bound {
                let (rounding, name) = if increase { (Rounding::Floor, "Max") } else { (Rounding::Ceil, "Min") };
                bound = Some(integer_bound(&round(rounding, b), name)?);
            }
        }
        let integer = numeric_attribute.as_ref().is_some_and(Attribute::is_integer);
        let zero = matches!(value, Value::Int(0));

        let target = metadata_target(&collection, id);
        let event = if increase { Event::DocumentIncrease } else { Event::DocumentDecrease };
        let document = self
            .with_mutation(event, &target, || {
                let (collection, value, bound, numeric_attribute) = (&collection, &value, &bound, &numeric_attribute);
                async move {
                    let authorization = self.authorization();
                    // Skip ensures the caller does not need read permission for this.
                    let mut document = authorization
                        .skip(self.silent(self.skip_query_hooks(self.read_document(collection, id, Vec::new(), true))))
                        .await?
                        .0;
                    if document.is_empty() {
                        return Err(Error::not_found("Document not found"));
                    }
                    if collection.id() != METADATA {
                        let mut permissions = collection.permissions_by_type(PermissionType::Update)?;
                        if document_security(collection) {
                            permissions.extend(document.permissions_by_type(PermissionType::Update)?);
                        }
                        if !authorization.is_valid(&Input::new(PermissionType::Update, permissions)) {
                            return Err(Error::authorization(authorization.description()));
                        }
                    }
                    if zero {
                        return Ok(document);
                    }

                    let exists = document.has(attribute);
                    let current = document.raw(attribute).cloned().unwrap_or(Value::Null);
                    let result = match numeric_attribute {
                        Some(a) => self.numeric_result(
                            a,
                            Some(&current).filter(|c| !matches!(c, Value::Null)),
                            value,
                            increase,
                        )?,
                        None => {
                            let current = if exists { current } else { Value::Int(0) };
                            if !matches!(current, Value::Int(_) | Value::Float(_)) {
                                return Err(Error::type_error("Attribute value must be numeric."));
                            }
                            let change = native_number(value)?;
                            if increase { add(&current, &change) } else { sub(&current, &change) }
                        }
                    };
                    if let Some(b) = bound {
                        let crossed = if integer {
                            let ordering = compare(&result, b)?;
                            if increase { ordering == Ordering::Greater } else { ordering == Ordering::Less }
                        } else {
                            // `$result > $max` is `$max < $result`.
                            if increase { b.compare(&result) < 0 } else { result.compare(b) < 0 }
                        };
                        if crossed {
                            let which = if increase { "maximum" } else { "minimum" };
                            return Err(Error::limit(format!("Attribute value exceeds {which} limit: {}", text(b))));
                        }
                    }

                    let time = crate::datetime::now_after(document.updated_at())?;
                    let updated_at = match document.updated_at() {
                        Some(u) if !super::read::empty(u) && self.is_preserving_dates() => {
                            crate::datetime::set_timezone(u)?
                        }
                        _ => time,
                    };
                    let limit = match bound {
                        Some(b) if integer => Some(Value::from(if increase {
                            BigInt::subtract(operand(b)?, operand(value)?)?
                        } else {
                            BigInt::add(operand(b)?, operand(value)?)?
                        })),
                        Some(b) => {
                            let (b, v) = (native_number(b)?, native_number(value)?);
                            Some(if increase { sub(&b, &v) } else { add(&b, &v) })
                        }
                        None => None,
                    };
                    let change = if integer {
                        let native = BigInt::to_native(operand(value)?)?;
                        Value::from(if increase { native } else { BigInt::negate(&native)? })
                    } else {
                        let native = native_number(value)?;
                        if increase { native } else { negate(&native) }
                    };
                    let (min, max) = if increase { (None, limit) } else { (limit, None) };
                    self.inner
                        .adapter
                        .increase_document_attribute(collection, id, attribute, change, &updated_at, min, max)
                        .await?;

                    self.purge_cached_document_internal(collection.id(), Some(id)).await?;
                    self.queue_document_purge(collection.id(), id).await?;

                    document.put(attribute, result);
                    Ok(document)
                }
            })
            .await?;

        if !zero {
            let listeners = self.listens(event);
            if !listeners.is_empty() {
                let event = if increase {
                    Domain::DocumentIncreased {
                        collection: collection.id().to_owned(),
                        document: document.clone(),
                        attribute: attribute.to_owned(),
                    }
                } else {
                    Domain::DocumentDecreased {
                        collection: collection.id().to_owned(),
                        document: document.clone(),
                        attribute: attribute.to_owned(),
                    }
                };
                self.dispatch(&event, &listeners).await?;
            }
        }

        Ok(document)
    }

    /// Rounds the integer operators of `document` (`roundOperators()`): an
    /// increment's or decrement's change half away from zero, its limit
    /// floored (increment) or ceiled (decrement). Returns the attributes
    /// whose fractional change rounds to nothing.
    pub(crate) fn round_operators(&self, collection: &Collection, document: &mut Document) -> Result<Vec<String>> {
        let mut unchanged = Vec::new();
        for attribute in collection.attributes()? {
            let Some(Value::Ext(Ext::Operator(operator))) = document.raw(&attribute.key) else { continue };
            if !is_declared_integer(Some(attribute)) {
                continue;
            }
            let limit_rounding = match operator.method() {
                OperatorType::Increment => Rounding::Floor,
                OperatorType::Decrement => Rounding::Ceil,
                _ => continue,
            };
            let mut values = operator.values().into_owned();
            if let Some(first) = values.get(&ArrayKey::Int(0)).cloned() {
                let amount = round(Rounding::HalfAwayFromZero, &first);
                if matches!(amount, Value::Int(0)) && !amount.strict_eq(&first) {
                    unchanged.push(attribute.key.clone());
                }
                values.set(ArrayKey::Int(0), amount);
            }
            if let Some(limit) = values.get(&ArrayKey::Int(1)).filter(|v| !matches!(v, Value::Null)).cloned() {
                values.set(ArrayKey::Int(1), round(limit_rounding, &limit));
            }
            let mut rounded: Operator = (**operator).clone();
            rounded.set_values_array(values);
            document.put(attribute.key.as_str(), Value::from(rounded));
        }
        Ok(unchanged)
    }
}

/// The attribute of `collection` declared as `key` (`declaredAttribute()`).
pub(crate) fn declared_attribute(collection: &Collection, key: &str) -> Result<Option<Attribute>> {
    Ok(collection.attributes()?.iter().find(|a| a.key == key).cloned())
}

/// Whether `attribute` is a single-valued integer (`isDeclaredInteger()`).
pub(crate) fn is_declared_integer(attribute: Option<&Attribute>) -> bool {
    attribute.is_some_and(|a| !a.array && a.is_integer())
}

/// `assertIntegerChange()`.
///
/// Fails ([`crate::Kind::Type`]) for a change that is not an integer
/// (`Change value must be an integer.`).
pub(crate) fn assert_integer_change(value: &Value) -> Result<()> {
    if !BigInt::is_integer_value(value) {
        return Err(Error::type_error("Change value must be an integer."));
    }
    Ok(())
}

/// A bound as the exact integer it stands for (`integerBound()`).
///
/// Fails ([`crate::Kind::Type`]) for a fractional bound (`<name> must be
/// an integer.`).
pub(crate) fn integer_bound(bound: &Value, name: &str) -> Result<Value> {
    Operand::from_value(bound)
        .and_then(BigInt::integral_value)
        .map(Value::from)
        .ok_or_else(|| Error::type_error(format!("{name} must be an integer.")))
}

/// `assertPositiveChange()`.
///
/// Fails ([`crate::Kind::Type`]) for a value that is not a number greater
/// than 0 (`Value must be numeric and greater than 0`).
pub(crate) fn assert_positive_change(value: &Value) -> Result<()> {
    let positive = is_numeric(value)
        && match value {
            Value::Str(s) if BigInt::is_integer_string(s.as_bytes(), true) => {
                BigInt::compare(Operand::Str(s.as_bytes()), 0)? == Ordering::Greater
            }
            // `(float) $value <= 0` refuses; NaN passes, as in PHP.
            other => !matches!(other.to_double().partial_cmp(&0.0), Some(Ordering::Less | Ordering::Equal)),
        };
    if !positive {
        return Err(Error::type_error("Value must be numeric and greater than 0"));
    }
    Ok(())
}

/// A number as PHP's native type (`getNativeNumber()`): a numeric string
/// with a `.` or an exponent as a float, any other as an integer.
///
/// Fails ([`crate::Kind::Type`]) for a value that is not numeric (`Value
/// must be numeric.`).
pub(crate) fn native_number(value: &Value) -> Result<Value> {
    match value {
        Value::Int(_) | Value::Float(_) => Ok(value.clone()),
        Value::Str(s) if is_numeric(value) => {
            let bytes = s.as_bytes();
            Ok(if bytes.iter().any(|b| matches!(b, b'.' | b'e' | b'E')) {
                Value::Float(php_std::format::str_to_float(bytes))
            } else {
                Value::Int(php_std::format::str_to_int(bytes))
            })
        }
        _ => Err(Error::type_error("Value must be numeric.")),
    }
}

/// `$collection->getAttribute('documentSecurity', false)`: unset is off.
pub(crate) fn document_security(collection: &Collection) -> bool {
    collection.get(crate::collection::DOCUMENT_SECURITY).is_some_and(Value::truthy)
}

/// What a write of document `id` invalidates in the query cache
/// (`$cacheTarget`): the definition written for the metadata collection,
/// the collection otherwise.
pub(crate) fn metadata_target(collection: &Collection, id: &str) -> Vec<Target> {
    if collection.id() == METADATA {
        let definition = Document::from_pairs([
            (document::ID, Value::Str(Str::from(id))),
            (document::COLLECTION, Value::Str(Str::from(METADATA))),
        ])
        .unwrap_or_default();
        vec![Target::Document(definition)]
    } else {
        vec![Target::Collection(collection.id().to_owned())]
    }
}

/// What a batch write invalidates (`$cacheTarget`): the definitions
/// written for the metadata collection, the collection otherwise.
pub(crate) fn batch_target(collection: &Collection, batch: &[Document]) -> Vec<Target> {
    if collection.id() == METADATA {
        batch.iter().cloned().map(Target::Document).collect()
    } else {
        vec![Target::Collection(collection.id().to_owned())]
    }
}
