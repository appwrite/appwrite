//! `convertQueries()`: each filter converted to what its attribute stores.

use utopia_query::schema::ColumnType;
use utopia_query::{Method, Object, Query as BaseQuery, Value as QValue};

use super::Database;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::error::{Error, Result};
use crate::query::Query;
use crate::value::to_query;

/// Attributes by the key queries name them by.
type AttributeMap = Vec<(String, Attribute)>;

fn find<'a>(map: &'a AttributeMap, key: &str) -> Option<&'a Attribute> {
    map.iter().find(|(k, _)| k == key).map(|(_, a)| a)
}

impl Database {
    /// Each filter converted to what its attribute stores
    /// (`convertQueries()`): the attribute's type and array-ness set on the
    /// query, datetime values in the storage timezone (or as the adapter
    /// casts them). With the collections a query set's joins read
    /// (`joined`, by alias), a filter on `alias.attribute` is converted by
    /// that collection's attribute, and the conditions of each join and of
    /// each `having()` are converted too. Aggregates and selects are left as
    /// they are.
    ///
    /// Fails ([`crate::Kind::Query`]) with the message of a datetime that
    /// does not parse.
    pub fn convert_queries(
        &self,
        collection: &Collection,
        mut queries: Vec<Query>,
        joined: &[(String, Collection)],
    ) -> Result<Vec<Query>> {
        let map = self.attribute_map(collection, joined)?;
        let nested = self.nested_query_attributes();
        let mut having_map: Option<AttributeMap> = None;
        let snapshot: Vec<(Method, String, String)> =
            queries.iter().map(|q| (q.method(), q.attribute().to_owned(), q.alias().to_owned())).collect();
        for query in queries.iter_mut() {
            let method = query.method();
            if method.is_aggregate() || method == Method::Select {
                continue;
            }
            if method.is_join() {
                let values = convert_values(self, query.values().clone(), &map, nested)?;
                query.set_values(values);
                continue;
            }
            if method == Method::Having {
                let map = having_map.get_or_insert_with(|| with_aggregate_aliases(map.clone(), &snapshot));
                let values = convert_values(self, query.values().clone(), map, nested)?;
                query.set_values(values);
                continue;
            }
            convert_base(self, query, &map, nested)?;
        }
        Ok(queries)
    }

    /// One query converted by the collection's attributes (`convertQuery()`).
    pub fn convert_query(&self, collection: &Collection, mut query: Query) -> Result<Query> {
        let map = self.attribute_map(collection, &[])?;
        convert_query_with_map(self, &mut query, &map, self.nested_query_attributes())?;
        Ok(query)
    }

    /// Whether `object.path` attributes are queried as nested object paths.
    fn nested_query_attributes(&self) -> bool {
        self.inner.adapter.supports(Capability::Objects) && self.inner.adapter.supports(Capability::DefinedAttributes)
    }

    /// The collection's attributes and the internal ones by key, and each
    /// joined collection's under `alias.key` (`buildAttributeMap()`).
    fn attribute_map(&self, collection: &Collection, joined: &[(String, Collection)]) -> Result<AttributeMap> {
        let internal = self.internal_attributes();
        let mut map: AttributeMap = Vec::new();
        for attribute in collection.attributes()?.iter().chain(internal) {
            match map.iter_mut().find(|(k, _)| *k == attribute.key) {
                Some(entry) => entry.1 = attribute.clone(),
                None => map.push((attribute.key.clone(), attribute.clone())),
            }
        }
        for (alias, joined) in joined {
            for attribute in joined.attributes()?.iter().chain(internal) {
                let key = format!("{alias}.{}", attribute.key);
                if find(&map, &key).is_none() {
                    map.push((key, attribute.clone()));
                }
            }
        }
        Ok(map)
    }

    /// Whether every value is an object-shaped array (`isCompatibleObjectValue()`):
    /// non-empty, each an array, and none a list.
    pub(crate) fn is_compatible_object_value(values: &utopia_query::Array) -> bool {
        if values.is_empty() {
            return false;
        }
        values.values().all(|value| match value {
            QValue::Array(a) => a.is_empty() || !a.is_list(),
            _ => false,
        })
    }
}

/// The map a having condition is converted by: a `min`/`max` alias names a
/// value of the aggregated attribute's type, any other aggregate alias
/// nothing (`withAggregateAliases()`).
fn with_aggregate_aliases(mut map: AttributeMap, queries: &[(Method, String, String)]) -> AttributeMap {
    for (method, attribute, alias) in queries {
        if !method.is_aggregate() || alias.is_empty() {
            continue;
        }
        let aggregated =
            if matches!(method, Method::Min | Method::Max) { find(&map, attribute).cloned() } else { None };
        map.retain(|(k, _)| k != alias);
        if let Some(aggregated) = aggregated {
            map.push((alias.clone(), aggregated));
        }
    }
    map
}

/// The nested queries among `values` converted (`convertQueriesWithMap()` on
/// the queries a value list holds).
fn convert_values(
    db: &Database,
    values: utopia_query::Array,
    map: &AttributeMap,
    nested: bool,
) -> Result<utopia_query::Array> {
    let mut out = utopia_query::Array::with_capacity(values.len());
    for (k, v) in values {
        let v = match v {
            QValue::Ext(Object::Query(mut q)) => {
                convert_base(db, &mut q, map, nested)?;
                QValue::Ext(Object::Query(q))
            }
            other => other,
        };
        out.set(k, v);
    }
    Ok(out)
}

/// A query and, for a nested one, its children converted.
fn convert_base(db: &Database, query: &mut BaseQuery, map: &AttributeMap, nested: bool) -> Result<()> {
    if query.is_nested() {
        let values = convert_values(db, query.values().clone(), map, nested)?;
        query.set_values(values);
    }
    convert_query_with_map(db, query, map, nested)
}

/// `convertQueryWithMap()`.
fn convert_query_with_map(db: &Database, query: &mut BaseQuery, map: &AttributeMap, nested: bool) -> Result<()> {
    let name = query.attribute().to_owned();
    let is_nested_attribute = nested && name.contains('.');
    let attribute = find(map, &name);
    if attribute.is_none() && is_nested_attribute {
        let base = name.split('.').next().unwrap_or("");
        if find(map, base).is_some_and(|a| a.kind == ColumnType::Object) {
            query.set_attribute_type(ColumnType::Object.as_str());
        }
    }
    let adapter = &db.inner.adapter;
    match attribute {
        Some(attribute) => {
            query.set_on_array(attribute.array);
            query.set_attribute_type(attribute.kind.as_str());
            if attribute.kind == ColumnType::Datetime {
                let mut values = utopia_query::Array::with_capacity(query.values().len());
                for (k, v) in query.values().clone() {
                    let text = php_std::Value::<utopia_query::Object>::to_php_string(&v)
                        .map(|s| s.as_bytes().to_vec())
                        .unwrap_or_default();
                    let converted = match adapter.casting() {
                        Some(casting) => casting.cast_datetime(&String::from_utf8_lossy(&text)).map(|d| to_query(&d)),
                        None => crate::datetime::set_timezone(&text).map(|s| QValue::Str(s.into())),
                    }
                    .map_err(|e| Error::query(e.message()).with_code(e.code()).with_previous(e))?;
                    values.set(k, converted);
                }
                query.set_values(values);
            }
        }
        None if !adapter.supports(Capability::DefinedAttributes) => {
            if adapter.supports(Capability::Objects) && Database::is_compatible_object_value(query.values()) {
                query.set_attribute_type(ColumnType::Object.as_str());
            }
        }
        None => {}
    }
    Ok(())
}
