//! [`Narrow`]: plain query lists checked with only the validators and
//! attributes they need.
//!
//! PHP: `Utopia\Database\Validator\Queries\Narrow`.

use std::collections::{HashMap, HashSet};
use std::ops::Deref;
use std::sync::Arc;

use utopia_query::Method;

use super::base::{Entry, Queries, QueryEntry};
use super::documents::{filter_options, internal_attributes};
use crate::adapter::Profile;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::validator::query::base::{MethodType, QueryValidator};
use crate::validator::query::cursor::Cursor;
use crate::validator::query::filter::Filter;
use crate::validator::query::limit::Limit;
use crate::validator::query::offset::Offset;
use crate::validator::query::order::Order;
use crate::value::Value;

/// The method type of each method a narrow list may hold
/// (`METHOD_TYPES`).
fn narrow_type(method: Method) -> Option<MethodType> {
    use Method::*;
    Some(match method {
        Equal | NotEqual | LessThan | LessThanEqual | GreaterThan | GreaterThanEqual | Between | NotBetween
        | StartsWith | NotStartsWith | EndsWith | NotEndsWith | Contains | ContainsAny | ContainsAll | NotContains
        | IsNull | IsNotNull | Regex => MethodType::Filter,
        Limit => MethodType::Limit,
        Offset => MethodType::Offset,
        CursorAfter | CursorBefore => MethodType::Cursor,
        OrderAsc | OrderDesc | OrderRandom => MethodType::Order,
        _ => return None,
    })
}

/// The method type of a query of a narrow list, `None` when the entry
/// makes the list another one (`methodType()`).
fn entry_type(entry: Entry<'_>) -> Option<(MethodType, &utopia_query::Query)> {
    let Entry::Query(query) = entry else { return None };
    let method = query.method();
    let kind = narrow_type(method)?;
    if (kind == MethodType::Filter || matches!(method, Method::OrderAsc | Method::OrderDesc))
        && query.attribute().contains('.')
    {
        return None;
    }
    Some((kind, query))
}

/// A list of plain filters, limits, offsets, cursors and orders on the
/// collection's own top-level attributes, checked by the validators the
/// documents validator dispatches those queries to, built from only the
/// attributes the list names: valid exactly when the documents validator
/// of the whole collection accepts the list, refused with the same message
/// when it does not.
///
/// PHP: `Utopia\Database\Validator\Queries\Narrow` (`Narrow::of()`); it
/// derefs to its [`Queries`].
#[derive(Debug)]
pub struct Narrow {
    base: Queries,
    by_type: HashMap<MethodType, Arc<dyn QueryValidator>>,
}

impl Deref for Narrow {
    type Target = Queries;

    fn deref(&self) -> &Queries {
        &self.base
    }
}

impl Narrow {
    fn with(validators: Vec<Arc<dyn QueryValidator>>) -> Self {
        let mut by_type = HashMap::new();
        for validator in &validators {
            by_type.insert(validator.method_type(), validator.clone());
        }
        Narrow { base: Queries::new(validators, 0), by_type }
    }

    /// Whether the list is narrow: every entry a query of a method listed
    /// here, every filter and attribute order on an attribute without a
    /// dot (`accepts()`).
    pub fn accepts<T: QueryEntry>(queries: &[T]) -> bool {
        queries.iter().all(|q| entry_type(q.entry()).is_some())
    }

    /// The validator of a narrow query list, or `None` when the list is not
    /// narrow (`of()`).
    pub fn of<T: QueryEntry>(
        queries: &[T],
        attributes: &[Attribute],
        profile: &Profile,
        max_values_count: i64,
    ) -> Option<Self> {
        let mut types = HashSet::new();
        let mut filtered = HashSet::new();
        let mut ordered = HashSet::new();
        for query in queries {
            let (kind, query) = entry_type(query.entry())?;
            types.insert(kind);
            if kind == MethodType::Filter {
                filtered.insert(query.attribute());
            } else if kind == MethodType::Order && query.method() != Method::OrderRandom {
                ordered.insert(query.attribute());
            }
        }

        let mut filter_attributes = Vec::new();
        let mut order_attributes = Vec::new();
        if !filtered.is_empty() || !ordered.is_empty() {
            for attribute in attributes.iter().chain(internal_attributes()) {
                if filtered.contains(attribute.key.as_str()) {
                    filter_attributes.push(attribute.clone());
                }
                if ordered.contains(attribute.key.as_str()) {
                    order_attributes.push(attribute.clone());
                }
            }
        }

        let support_for_attributes = profile.supports(Capability::DefinedAttributes);
        let limits = &profile.limits;
        let mut validators: Vec<Arc<dyn QueryValidator>> = Vec::new();
        if types.contains(&MethodType::Limit) {
            validators.push(Arc::new(Limit::default()));
        }
        if types.contains(&MethodType::Offset) {
            validators.push(Arc::new(Offset::default()));
        }
        if types.contains(&MethodType::Cursor) {
            validators.push(Arc::new(Cursor::new(limits.uid_length)));
        }
        if types.contains(&MethodType::Filter) {
            validators.push(Arc::new(Filter::with_options(
                &filter_attributes,
                limits.id_type.as_str(),
                filter_options(profile, max_values_count),
            )));
        }
        if types.contains(&MethodType::Order) {
            validators.push(Arc::new(Order::new(
                &order_attributes,
                support_for_attributes,
                profile.supports(Capability::OrderRandom),
            )));
        }
        Some(Narrow::with(validators))
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.base.description()
    }

    /// `isValid($value)`: a narrow list goes to the validator of each
    /// query's method type, in order; any other value is checked as by any
    /// query list validator.
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Array(queries) => self.check(&queries.values().collect::<Vec<_>>()),
            _ => self.base.is_valid(value),
        }
    }

    /// [`Narrow::is_valid`] of a list.
    pub fn check<T: QueryEntry>(&self, queries: &[T]) -> bool {
        if !Narrow::accepts(queries) {
            return self.base.check(queries);
        }
        if let Some(order) = self.by_type.get(&MethodType::Order).and_then(|v| v.as_any().downcast_ref::<Order>()) {
            order.reset_aggregation_aliases();
            order.reset_join_aliases();
        }
        for entry in queries {
            let Some((kind, query)) = entry_type(entry.entry()) else { return false };
            let Some(validator) = self.by_type.get(&kind) else {
                self.base.message.set(format!("Invalid query method: {}", query.method().as_str()));
                return false;
            };
            if !validator.check(query) {
                self.base.message.set(format!("Invalid query: {}", validator.description()));
                return false;
            }
        }
        true
    }
}

crate::validator::validator!(Narrow, utopia_validators::Type::Object, true);
