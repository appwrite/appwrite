//! [`Filter`]: filter queries (`equal`, `contains`, `between`, `exists`,
//! spatial, vector, logical, ...).
//!
//! PHP: `Utopia\Database\Validator\Query\Filter`.

use std::collections::HashMap;
use std::sync::{Arc, Mutex, MutexGuard};

use php_std::ArrayKey;
use php_std::datetime::DateTime;
use utopia_query::schema::ColumnType;
use utopia_query::{Array as QArray, Method, Value as QValue};
use utopia_validators::{Boolean, Float, Integer, Text};

use crate::attribute::Attribute;
use crate::document;
use crate::error::{Error, Kind, Result};
use crate::validator::Message;
use crate::validator::big_int::BigInt;
use crate::validator::datetime::{Datetime, default_max, default_min};
use crate::validator::object_path::ObjectPath;
use crate::validator::query::base::{
    INVALID_QUERY, MethodType, accepts, nested, query_of, query_validator, split_dot, ucfirst,
};
use crate::validator::query::joined::attributes::Joins;
use crate::validator::query::joined::collection::{Collection, stores_column};
use crate::validator::sequence::Sequence;
use crate::value::{self as dbvalue, Value};

const ENCRYPT: &str = "encrypt";

/// The options of [`Filter::with_options`] past the attributes and the id
/// type, with PHP's defaults.
#[derive(Clone, Debug)]
pub struct FilterOptions {
    /// `$maxValuesCount` (5000).
    pub max_values_count: i64,
    /// `$minAllowedDate` (`0000-01-01`).
    pub min_allowed_date: DateTime,
    /// `$maxAllowedDate` (`9999-12-31`).
    pub max_allowed_date: DateTime,
    /// `$supportForAttributes` (true).
    pub support_for_attributes: bool,
    /// `$supportUnsignedBigInt` (true).
    pub support_unsigned_big_int: bool,
}

impl Default for FilterOptions {
    fn default() -> Self {
        Self {
            max_values_count: 5000,
            min_allowed_date: default_min(),
            max_allowed_date: default_max(),
            support_for_attributes: true,
            support_unsigned_big_int: true,
        }
    }
}

/// The rule every value of a query on an attribute follows, by the
/// attribute's type.
enum Rule {
    Sequence(Sequence),
    Text(Text),
    Integer(Option<Integer>),
    BigInt(BigInt),
    Float,
    Boolean,
    Datetime(Box<Datetime>),
}

impl Rule {
    fn accepts(&self, value: &QValue) -> bool {
        match self {
            Rule::Sequence(v) => v.is_valid(&dbvalue::from_query(value)),
            Rule::Text(v) => accepts(v, value),
            Rule::Integer(v) => v.as_ref().is_some_and(|v| accepts(v, value)),
            Rule::BigInt(v) => v.is_valid(&dbvalue::from_query(value)),
            Rule::Float => accepts(&Float::default(), value),
            Rule::Boolean => accepts(&Boolean::STRICT, value),
            Rule::Datetime(v) => v.is_valid(&dbvalue::from_query(value)),
        }
    }
}

const STRING_TYPES: [ColumnType; 5] =
    [ColumnType::String, ColumnType::Varchar, ColumnType::Text, ColumnType::MediumText, ColumnType::LongText];

const SPATIAL_TYPES: [ColumnType; 3] = [ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon];

/// A filter query on an attribute of the collection (or of a join, or a
/// path into an object attribute) whose values suit the attribute's type:
/// at most `max_values_count` values, strings for strings, integers in range
/// for integers, dates between the allowed dates, ... Logical queries
/// (`and`, `or`, `elemMatch`) must nest filters, and are valid when each
/// nested filter is.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::attribute::Attribute;
/// use utopia_database::validator::query::filter::Filter;
///
/// let filter = Filter::new(&[Attribute::integer("age")], "integer");
/// assert!(filter.check(&Query::greater_than("age", 18)));
/// assert!(!filter.check(&Query::greater_than("age", "18")));
/// assert_eq!(filter.description(), "Query value is invalid for attribute \"age\"");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Filter` (`new Filter($attributes,
/// $idAttributeType, $maxValuesCount, $minAllowedDate, $maxAllowedDate,
/// $supportForAttributes, $supportUnsignedBigInt)`).
#[derive(Debug)]
pub struct Filter {
    schema: HashMap<String, Attribute>,
    id_attribute_type: String,
    options: FilterOptions,
    joins: Mutex<Joins>,
    message: Message,
}

impl Filter {
    /// `new Filter($attributes, $idAttributeType)` with PHP's defaults.
    pub fn new(attributes: &[Attribute], id_attribute_type: impl Into<String>) -> Self {
        Filter::with_options(attributes, id_attribute_type, FilterOptions::default())
    }

    /// `new Filter(...)` with every option.
    pub fn with_options(
        attributes: &[Attribute],
        id_attribute_type: impl Into<String>,
        options: FilterOptions,
    ) -> Self {
        Filter {
            schema: attributes.iter().map(|a| (a.key.clone(), a.clone())).collect(),
            id_attribute_type: id_attribute_type.into(),
            options,
            joins: Mutex::new(Joins::default()),
            message: Message::new(INVALID_QUERY),
        }
    }

    fn joins(&self) -> MutexGuard<'_, Joins> {
        self.joins.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// The most values a filter query may hold (`getMaxValuesCount()`).
    pub fn max_values_count(&self) -> i64 {
        self.options.max_values_count
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    fn too_many(&self, values: &QArray, attribute: &str) -> bool {
        if values.len() as i64 > self.options.max_values_count {
            return !self.fail(format!(
                "Query on attribute has greater than {} values: {attribute}",
                self.options.max_values_count
            ));
        }
        false
    }

    /// `acceptsMainAttribute()`.
    fn accepts_main(&self, attribute: &str) -> bool {
        self.schema.contains_key(attribute)
    }

    /// `isValid($value)`. PHP calls `getMethod()` on whatever it is given,
    /// so anything but a query fails ([`Kind::Error`], `Call to a member
    /// function getMethod() on <type>`).
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match query_of(value) {
            Some(q) => Ok(self.check(q)),
            None => {
                Err(Error::new(Kind::Error, format!("Call to a member function getMethod() on {}", value.debug_type())))
            }
        }
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        let joins = self.joins();
        self.check_with(&joins, query)
    }

    fn check_with(&self, joins: &Joins, query: &utopia_query::Query) -> bool {
        let method = query.method();
        let attribute = query.attribute();
        let values = query.values();
        match method {
            Method::Equal | Method::Contains | Method::ContainsAny | Method::NotContains | Method::ContainsAll => {
                if is_empty(values) {
                    return self.fail(format!("{} queries require at least one value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(joins, attribute, values, method)
            }
            Method::Exists | Method::NotExists => {
                if is_empty(values) {
                    return self.fail(format!("{} queries require at least one value.", ucfirst(method.as_str())));
                }
                self.is_valid_exists(joins, query)
            }
            Method::DistanceEqual
            | Method::DistanceNotEqual
            | Method::DistanceGreaterThan
            | Method::DistanceLessThan => {
                let first = values.get(&ArrayKey::Int(0));
                let Some(QValue::Array(parameters)) = first.filter(|_| values.len() == 1) else {
                    return self.fail("Distance query requires [[geometry, distance]] parameters");
                };
                if parameters.len() != 3 {
                    return self.fail("Distance query requires [[geometry, distance]] parameters");
                }
                let distance = parameters.get(&ArrayKey::Int(1)).unwrap_or(&QValue::Null);
                if !is_numeric(distance) || !distance.to_double().is_finite() {
                    return self.fail("Distance query requires a finite distance");
                }
                self.is_valid_attribute_and_values(joins, attribute, values, method)
            }
            Method::NotEqual
            | Method::LessThan
            | Method::LessThanEqual
            | Method::GreaterThan
            | Method::GreaterThanEqual
            | Method::Search
            | Method::NotSearch
            | Method::StartsWith
            | Method::NotStartsWith
            | Method::EndsWith
            | Method::NotEndsWith
            | Method::Regex => {
                if values.len() != 1 {
                    return self.fail(format!("{} queries require exactly one value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(joins, attribute, values, method)
            }
            Method::Between | Method::NotBetween => {
                if values.len() != 2 {
                    return self.fail(format!("{} queries require exactly two values.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(joins, attribute, values, method)
            }
            Method::IsNull | Method::IsNotNull => self.is_valid_attribute_and_values(joins, attribute, values, method),
            Method::VectorDot | Method::VectorCosine | Method::VectorEuclidean => {
                if !self.is_valid_attribute(joins, attribute) {
                    return false;
                }
                let mut key = attribute;
                if let Some((alias, column)) = split_dot(attribute)
                    && !self.schema.contains_key(attribute)
                {
                    if joins.is_join_column_reference(alias, column) {
                        if values.len() != 1 {
                            return self.fail(format!(
                                "{} queries require exactly one vector value.",
                                ucfirst(method.as_str())
                            ));
                        }
                        return self.is_valid_attribute_and_values(joins, attribute, values, method);
                    }
                    key = alias;
                }
                if self.schema.get(key).map(|a| a.kind) != Some(ColumnType::Vector) {
                    return self.fail("Vector queries can only be used on vector attributes");
                }
                if values.len() != 1 {
                    return self
                        .fail(format!("{} queries require exactly one vector value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(joins, attribute, values, method)
            }
            Method::Or | Method::And => {
                let message = format!("{} queries can only contain filter queries", ucfirst(method.as_str()));
                let Some(children) = self.children(values, &message) else { return false };
                if children.len() < 2 {
                    return self.fail(format!("{} queries require at least two queries", ucfirst(method.as_str())));
                }
                children.into_iter().all(|child| self.check_with(joins, child))
            }
            Method::ElemMatch => {
                if self.options.support_for_attributes {
                    return self.fail("elemMatch is not supported by the database");
                }
                if !self.is_valid_attribute(joins, attribute) {
                    return false;
                }
                let Some(children) = self.children(values, "elemMatch queries can only contain filter queries") else {
                    return false;
                };
                if children.is_empty() {
                    return self.fail("elemMatch queries require at least one query");
                }
                children.into_iter().all(|child| self.check_with(joins, child))
            }
            _ if method.is_spatial() => {
                if is_empty(values) {
                    return self.fail(format!("{} queries require at least one value.", ucfirst(method.as_str())));
                }
                self.is_valid_attribute_and_values(joins, attribute, values, method)
            }
            _ => false,
        }
    }

    /// The nested queries of a logical query, when every value is a query
    /// `Query::groupByType()` puts under its filters; else `message`.
    fn children<'a>(&self, values: &'a QArray, message: &str) -> Option<Vec<&'a utopia_query::Query>> {
        let mut children = Vec::with_capacity(values.len());
        for value in values.values() {
            match nested(value) {
                Some(child) if grouped_as_filter(child.method()) => children.push(child),
                _ => {
                    self.fail(message);
                    return None;
                }
            }
        }
        Some(children)
    }

    fn is_valid_attribute(&self, joins: &Joins, attribute: &str) -> bool {
        if self.schema.get(attribute).is_some_and(|a| a.filters.iter().any(|f| f == ENCRYPT)) {
            return self.fail(format!("Cannot query encrypted attribute: {attribute}"));
        }
        let mut base = attribute;
        if let Some((alias, column)) = split_dot(attribute) {
            if self.schema.contains_key(attribute) {
                return true;
            }
            if joins.is_join_column_reference(alias, column) {
                if joins.by_alias(alias).is_some_and(|j| j.encrypted.contains(column)) {
                    return self.fail(format!("Cannot query encrypted attribute: {attribute}"));
                }
                if !self.options.support_for_attributes {
                    return true;
                }
                return match joins.is_joined_column(alias, column, |a| self.accepts_main(a)) {
                    Ok(()) => true,
                    Err(message) => self.fail(message),
                };
            }
            if !self.is_valid_object_path(alias, attribute) {
                return false;
            }
            base = alias;
        }
        if self.options.support_for_attributes && !self.schema.contains_key(base) {
            return self.fail(format!("Attribute not found in schema: {base}"));
        }
        true
    }

    fn is_valid_object_path(&self, base: &str, path: &str) -> bool {
        if self.schema.get(base).map(|a| a.kind) != Some(ColumnType::Object) {
            return true;
        }
        if !ObjectPath::accepts(path.as_bytes()) {
            return self.fail(format!("Invalid object path \"{path}\": {}", ObjectPath.description()));
        }
        true
    }

    /// The definition a joined column is checked against: the joined
    /// collection's own, or the main collection's for an internal attribute
    /// (`joinedSchema()`).
    fn joined_schema<'a>(&'a self, joins: &'a Joins, alias: &str, column: &str) -> Option<&'a Attribute> {
        let schema = joins.by_alias(alias).and_then(|j| j.schema.get(column));
        match schema {
            None if column.starts_with('$') => self.schema.get(column),
            other => other,
        }
    }

    fn is_valid_attribute_and_values(&self, joins: &Joins, attribute: &str, values: &QArray, method: Method) -> bool {
        if !self.is_valid_attribute(joins, attribute) {
            return false;
        }
        let original = attribute;
        let mut attribute = attribute;
        let mut schema: Option<&Attribute> = None;
        let mut joined = false;
        if let Some((alias, column)) = split_dot(attribute)
            && !self.schema.contains_key(attribute)
        {
            if joins.is_join_column_reference(alias, column) {
                if method.is_vector() {
                    return self.fail(format!("Vector queries cannot be used on a joined attribute: {attribute}"));
                }
                joined = true;
                schema = self.joined_schema(joins, alias, column);
                if schema.is_none() {
                    return !self.too_many(values, attribute);
                }
            } else {
                attribute = alias;
            }
        }
        let schema = match schema {
            Some(schema) => schema,
            None => {
                if !self.options.support_for_attributes && !self.schema.contains_key(attribute) {
                    return !self.too_many(values, attribute);
                }
                match self.schema.get(attribute) {
                    Some(schema) => schema,
                    None => return self.fail(format!("Attribute not found in schema: {attribute}")),
                }
            }
        };
        let kind = schema.kind;
        if kind == ColumnType::Relationship && original != attribute {
            return true;
        }
        if self.too_many(values, attribute) {
            return false;
        }
        let dotted_on_object = !joined && original.contains('.') && kind == ColumnType::Object;
        if method.is_spatial() && !SPATIAL_TYPES.contains(&kind) {
            return self.fail(format!(
                "Spatial query \"{}\" cannot be applied on non-spatial attribute: {attribute}",
                method.as_str()
            ));
        }

        let mut rule = None;
        if !values.is_empty() {
            rule = match kind {
                ColumnType::Id => {
                    Some(Rule::Sequence(Sequence::new(self.id_attribute_type.clone(), attribute == document::SEQUENCE)))
                }
                k if STRING_TYPES.contains(&k) => Some(Rule::Text(Text::with_min(0, 0))),
                ColumnType::Integer => {
                    let bits = if schema.size.unwrap_or(0) >= 8 { 64 } else { 32 };
                    let unsigned = !schema.signed && bits < 64;
                    Some(Rule::Integer(Integer::new(false, bits, unsigned).ok()))
                }
                ColumnType::BigInteger => {
                    Some(Rule::BigInt(BigInt::new(schema.signed, self.options.support_unsigned_big_int)))
                }
                ColumnType::Float | ColumnType::Double => Some(Rule::Float),
                ColumnType::Boolean => Some(Rule::Boolean),
                ColumnType::Datetime => Some(Rule::Datetime(Box::new(Datetime::between(
                    self.options.min_allowed_date.clone(),
                    self.options.max_allowed_date.clone(),
                )))),
                ColumnType::Relationship => Some(Rule::Text(Text::with_min(255, 0))),
                ColumnType::Object => dotted_on_object.then(|| Rule::Text(Text::with_min(0, 0))),
                ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon | ColumnType::Vector => None,
                _ => return self.fail("Unknown Data type"),
            };
        }

        for value in values.values() {
            match kind {
                ColumnType::Object if !dotted_on_object => {
                    if matches!(
                        method,
                        Method::Equal
                            | Method::NotEqual
                            | Method::Contains
                            | Method::ContainsAny
                            | Method::ContainsAll
                            | Method::NotContains
                    ) && !valid_object_query_values(value)
                    {
                        return self.fail(format!("Invalid object query structure for attribute \"{attribute}\""));
                    }
                    continue;
                }
                ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon => {
                    if !matches!(value, QValue::Array(_)) {
                        return self.fail("Spatial data must be an array");
                    }
                    continue;
                }
                ColumnType::Vector => {
                    let QValue::Array(components) = value else {
                        return self.fail("Vector query value must be an array");
                    };
                    if !components.values().all(is_numeric) {
                        return self.fail("Vector query value must contain only numeric values");
                    }
                    let expected = schema.size.unwrap_or(0);
                    if components.len() as i64 != expected {
                        return self.fail(format!("Vector query value must have {expected} elements"));
                    }
                    continue;
                }
                _ => {}
            }
            if let Some(rule) = &rule
                && !rule.accepts(value)
            {
                return self.fail(format!("Query value is invalid for attribute \"{attribute}\""));
            }
        }

        if kind == ColumnType::Relationship && !stores_column(schema) {
            return self.fail("Cannot query on virtual relationship attribute");
        }
        let array = schema.array;
        let contains =
            matches!(method, Method::Contains | Method::ContainsAny | Method::ContainsAll | Method::NotContains);
        if !array
            && contains
            && !STRING_TYPES.contains(&kind)
            && kind != ColumnType::Object
            && !SPATIAL_TYPES.contains(&kind)
        {
            let query_type = if method == Method::NotContains { "notContains" } else { "contains" };
            return self.fail(format!(
                "Cannot query {query_type} on attribute \"{attribute}\" because it is not an array, string, or object."
            ));
        }
        if array
            && !contains
            && !matches!(method, Method::IsNull | Method::IsNotNull | Method::Exists | Method::NotExists)
        {
            return self.fail(format!(
                "Cannot query {} on attribute \"{attribute}\" because it is an array.",
                method.as_str()
            ));
        }
        if method.is_vector() {
            if kind != ColumnType::Vector {
                return self.fail("Vector queries can only be used on vector attributes");
            }
            if array {
                return self.fail("Vector queries cannot be used on array attributes");
            }
        }
        true
    }

    /// `exists()` and `notExists()` name attributes in their values
    /// (`isValidExists()`).
    fn is_valid_exists(&self, joins: &Joins, query: &utopia_query::Query) -> bool {
        let method = query.method();
        let attribute = query.attribute();
        if !attribute.is_empty() && !self.is_valid_attribute(joins, attribute) {
            return false;
        }
        for value in query.values().values() {
            let name = match value {
                QValue::Str(s) if !s.is_empty() => String::from_utf8_lossy(s.as_bytes()),
                _ => return self.fail(format!("{} queries take attribute names", ucfirst(method.as_str()))),
            };
            if !self.is_valid_attribute(joins, &name)
                || (self.options.support_for_attributes && !self.is_exists_column(joins, &name, method))
            {
                return false;
            }
        }
        true
    }

    fn is_exists_column(&self, joins: &Joins, attribute: &str, method: Method) -> bool {
        if let Some((alias, column)) = split_dot(attribute)
            && !self.schema.contains_key(attribute)
        {
            if joins.is_join_column_reference(alias, column) {
                return true;
            }
            return self.fail(format!(
                "{} queries take attributes of the collection or of a join alias: {attribute}",
                ucfirst(method.as_str())
            ));
        }
        if self.schema.get(attribute).is_some_and(|a| !stores_column(a)) {
            return self.fail("Cannot query on virtual relationship attribute");
        }
        true
    }

    /// `allowJoins()`.
    pub fn allow_joins(&self, joins: &[Arc<Collection>]) {
        self.joins().allow_joins(joins);
    }

    /// `allowJoinAliases()`.
    pub fn allow_join_aliases<S: AsRef<str>>(&self, aliases: &[S]) {
        self.joins().allow_join_aliases(aliases);
    }

    /// `resetJoinAliases()`.
    pub fn reset_join_aliases(&self) {
        self.joins().reset();
    }
}

/// Whether `Query::groupByType()` puts a query of this method under its
/// filters.
pub(crate) fn grouped_as_filter(method: Method) -> bool {
    !(matches!(
        method,
        Method::OrderAsc
            | Method::OrderDesc
            | Method::OrderRandom
            | Method::Limit
            | Method::Offset
            | Method::CursorAfter
            | Method::CursorBefore
            | Method::Select
            | Method::GroupBy
            | Method::GroupByTimeBucket
            | Method::Having
            | Method::Distinct
            | Method::Union
            | Method::UnionAll
    ) || method.is_aggregate()
        || method.is_join())
}

/// `isEmpty($values)`: no values, or a first value that is an empty array.
fn is_empty(values: &QArray) -> bool {
    if values.is_empty() {
        return true;
    }
    matches!(values.get(&ArrayKey::Int(0)), Some(QValue::Array(a)) if a.is_empty())
}

/// `is_numeric($value)`.
fn is_numeric(value: &QValue) -> bool {
    match value {
        QValue::Int(_) | QValue::Float(_) => true,
        QValue::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `isValidObjectQueryValues($values)`: no array mixes integer and string
/// keys, at any depth.
fn valid_object_query_values(value: &QValue) -> bool {
    let QValue::Array(a) = value else { return true };
    let has_int = a.keys().any(|k| matches!(k, php_std::types::KeyRef::Int(_)));
    let has_string = a.keys().any(|k| matches!(k, php_std::types::KeyRef::Str(_)));
    if has_int && has_string {
        return false;
    }
    a.values().all(valid_object_query_values)
}

query_validator!(Filter, MethodType::Filter, fallible);
