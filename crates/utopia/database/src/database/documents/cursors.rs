//! `resolveCursor()`.
//!
//! PHP: `Trait\Cursors`.

use utopia_query::{CursorDirection, DEFAULT_ALIAS, Method, Object, OrderDirection, Value as QValue};

use super::super::Database;
use super::find::{add_tie_breaks, sequence_text};
use super::joins::{Joined, assert_join_count, joined, joined_by_alias};
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::Event;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::value::{self as dbvalue, Str, Value};

/// The operator a join condition takes with its sides swapped
/// (`MIRRORED_OPERATORS`).
fn mirrored(operator: &str) -> &str {
    match operator {
        "<" => ">",
        "<=" => ">=",
        ">" => "<",
        ">=" => "<=",
        other => other,
    }
}

impl Database {
    /// The cursor a list pages from when it names a document by its id
    /// (`resolveCursor()`): the document's values of the list's orders,
    /// which `cursorAfter()` or `cursorBefore()` in the same list reads as
    /// the row that id stands for.
    ///
    /// The document is read with authorization skipped, so a list can page
    /// past a document its caller cannot read; the cursor carries only
    /// `$id`, `$sequence`, `$collection` and the values the list orders by.
    /// An order on a joined attribute takes its value from the joined rows
    /// the caller can read that the list pairs with the document: a page
    /// after the document starts behind the last of them in the list's
    /// order, a page before it ends ahead of the first, and without such a
    /// row the value is `null`. Pass the cursor only to a cursor query;
    /// never return it, or any of its values, to an end user.
    ///
    /// `queries`' cursor queries are left out. Empty when the collection
    /// holds no document with the id.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection and
    /// ([`crate::Kind::Query`]) for invalid queries.
    pub async fn resolve_cursor(
        &self,
        collection: &str,
        id: &str,
        direction: CursorDirection,
        queries: Vec<Query>,
    ) -> Result<Document> {
        let definition = self.silent(self.get_collection(collection)).await?;
        let queries = self.rewrite_queries(Event::DocumentFind, &definition, queries).await?;
        let queries = Database::resolve_one_to_many_joins(
            &definition,
            queries.into_iter().filter(|q| !matches!(q.method(), Method::CursorAfter | Method::CursorBefore)).collect(),
        )?;

        let joined_by_id = self.resolve_joined_collections(&queries).await?;
        if self.is_validating() {
            self.validate_documents_queries(&definition, &queries, Some(&joined_by_id)).await?;
        }

        let parsed = Query::group_by_type(&queries)?;
        let joins = &parsed.joins;
        if !joins.is_empty() && !self.inner.adapter.supports(Capability::Joins) {
            return Err(Error::query("Join queries are not supported by this adapter"));
        }
        assert_join_count(joins.len())?;

        let (document, _) = self
            .authorization()
            .skip(self.skip_relationships(self.read_document(&definition, id, Vec::new(), false)))
            .await?;
        if document.is_empty() {
            return Ok(Document::empty());
        }

        let sequence = document.sequence().map_or(Value::Null, |s| Value::Str(Str::from(sequence_text(s))));
        let mut cursor = Document::from_pairs([
            (document::ID, Value::Str(Str::from(document.id()))),
            (document::SEQUENCE, sequence),
            (document::COLLECTION, Value::Str(Str::from(definition.id()))),
        ])?;

        if parsed.order_types.contains(&OrderDirection::Random) {
            return Ok(cursor);
        }

        let joined_collections = joined_by_alias(joins, &joined_by_id);
        let mut order_attributes = parsed.order_attributes.clone();
        let mut order_types = parsed.order_types.clone();
        if !joined_collections.is_empty() {
            order_attributes =
                self.qualify_joined_orders(&definition, order_attributes, cursor.clone(), &joined_collections)?.0;
        }
        if !parsed.distinct {
            (order_attributes, order_types) = add_tie_breaks(
                order_attributes,
                order_types,
                &parsed.filters,
                joins,
                &joined_collections,
                true,
                &parsed.selections,
            );
        }

        let mut joined_orders: Vec<(String, Vec<Query>)> = Vec::new();
        for (index, attribute) in order_attributes.iter().enumerate() {
            let (alias, name) = self.order_owner(&definition, attribute, &joined_collections)?;
            if alias == DEFAULT_ALIAS {
                cursor.set(attribute.as_str(), document.get(attribute).cloned().unwrap_or(Value::Null))?;
                continue;
            }
            let descending = order_types.get(index).copied().unwrap_or(OrderDirection::Asc) == OrderDirection::Desc;
            let order = if descending != (direction == CursorDirection::After) {
                Query::order_desc(name, None)
            } else {
                Query::order_asc(name, None)
            };
            match joined_orders.iter_mut().find(|(a, _)| *a == alias) {
                Some(entry) => entry.1.push(order),
                None => joined_orders.push((alias, vec![order])),
            }
        }

        if joined_orders.is_empty() {
            return Ok(cursor);
        }

        let mut joins_by_alias: Vec<(String, Query)> = Vec::new();
        for join in joins {
            match joins_by_alias.iter_mut().find(|(a, _)| a == join.alias()) {
                Some(entry) => entry.1 = join.clone(),
                None => joins_by_alias.push((join.alias().to_owned(), join.clone())),
            }
        }

        let mut rows: Vec<(String, Document)> = Vec::new();
        for (alias, join) in paired_joins(&joins_by_alias, &joined_orders) {
            let orders = joined_orders.iter().find(|(a, _)| *a == alias).map(|(_, o)| o.clone()).unwrap_or_default();
            let row = self.paired_row(&join, &alias, &document, &rows, &parsed.filters, orders).await?;
            match rows.iter_mut().find(|(a, _)| *a == alias) {
                Some(entry) => entry.1 = row,
                None => rows.push((alias, row)),
            }
        }

        for (alias, orders) in &joined_orders {
            let row = rows.iter().find(|(a, _)| a == alias).map(|(_, r)| r.clone()).unwrap_or_default();
            for order in orders {
                let name = order.attribute();
                let value = if row.is_empty() { Value::Null } else { row.get(name).cloned().unwrap_or(Value::Null) };
                cursor.set(format!("{alias}.{name}").as_str(), value)?;
            }
        }

        Ok(cursor)
    }

    /// The join alias an order attribute belongs to, with its name there;
    /// the main alias for an attribute of the main collection
    /// (`orderOwner()`).
    fn order_owner(
        &self,
        collection: &Collection,
        attribute: &str,
        joined_collections: &Joined,
    ) -> Result<(String, String)> {
        if let Some(dot) = attribute.find('.')
            && joined(joined_collections, &attribute[..dot]).is_some()
            && !self.declares_sum_attribute(collection, attribute)?
        {
            return Ok((attribute[..dot].to_owned(), attribute[dot + 1..].to_owned()));
        }
        Ok((DEFAULT_ALIAS.to_owned(), attribute.to_owned()))
    }

    /// The joined row the caller's list pairs with the document, read with
    /// the caller's permissions: the first in `orders` of the rows the join
    /// conditions, the filters on the alias and the join's own conditions
    /// match (`pairedRow()`); empty when none does.
    async fn paired_row(
        &self,
        join: &Query,
        alias: &str,
        document: &Document,
        rows: &[(String, Document)],
        filters: &[Query],
        orders: Vec<Query>,
    ) -> Result<Document> {
        let mut conditions: Vec<Query> = Vec::new();
        for (left, operator, right) in join_conditions(join) {
            let (left_alias, left_attribute) = column_owner(&left, DEFAULT_ALIAS);
            let (right_alias, right_attribute) = column_owner(&right, alias);
            let condition = if right_alias == alias && left_alias != alias {
                comparison(
                    &right_attribute,
                    mirrored(&operator),
                    paired_value(&left_alias, &left_attribute, document, rows),
                )?
            } else if left_alias == alias && right_alias != alias {
                comparison(&left_attribute, &operator, paired_value(&right_alias, &right_attribute, document, rows))?
            } else {
                continue;
            };
            let Some(condition) = condition else {
                return Ok(Document::empty());
            };
            conditions.push(condition);
        }

        for filter in filters.iter().map(|f| &**f).chain(join.join_on_queries()) {
            if let Some(condition) = alias_filter(filter, alias) {
                conditions.push(condition);
            }
        }

        conditions.extend(orders);
        conditions.push(Query::limit(1));
        let found = self.skip_relationships(self.find(join.attribute(), conditions, PermissionType::Read)).await?;
        Ok(found.into_iter().next().unwrap_or_default())
    }
}

/// The joins the paired rows read, in the list's join order: those with a
/// joined order, and those their conditions reference (`pairedJoins()`).
fn paired_joins(joins: &[(String, Query)], orders: &[(String, Vec<Query>)]) -> Vec<(String, Query)> {
    let mut required: Vec<String> = orders.iter().map(|(a, _)| a.clone()).collect();
    for (alias, join) in joins.iter().rev() {
        if !required.contains(alias) {
            continue;
        }
        for (left, _, right) in join_conditions(join) {
            for referenced in [column_owner(&left, DEFAULT_ALIAS).0, column_owner(&right, alias).0] {
                if referenced != *alias
                    && joins.iter().any(|(a, _)| *a == referenced)
                    && !required.contains(&referenced)
                {
                    required.push(referenced);
                }
            }
        }
    }
    joins.iter().filter(|(a, _)| required.contains(a)).cloned().collect()
}

/// The `on()` conditions of a join with string sides and operator
/// (`joinConditions()`).
fn join_conditions(join: &Query) -> Vec<(String, String, String)> {
    let mut conditions = Vec::new();
    for on in join.join_on_queries() {
        if on.method() != Method::On {
            continue;
        }
        let values: Vec<&QValue> = on.values().values().collect();
        let text = |i: usize| match values.get(i) {
            Some(QValue::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => None,
        };
        if let (Some(left), Some(operator), Some(right)) = (text(0), text(1), text(2)) {
            conditions.push((left, operator, right));
        }
    }
    conditions
}

/// The alias a column belongs to, `alias` when it names none, and its name
/// (`columnOwner()`).
fn column_owner(column: &str, alias: &str) -> (String, String) {
    match column.find('.') {
        None => (alias.to_owned(), column.to_owned()),
        Some(dot) => (column[..dot].to_owned(), column[dot + 1..].to_owned()),
    }
}

/// The value a join condition compares with: the document's, or a paired
/// row's; a related document as its id (`pairedValue()`).
fn paired_value(alias: &str, attribute: &str, document: &Document, rows: &[(String, Document)]) -> Value {
    let value = if alias == DEFAULT_ALIAS {
        document.get(attribute).cloned()
    } else {
        rows.iter().find(|(a, _)| a == alias).and_then(|(_, r)| r.get(attribute).cloned())
    };
    match value {
        Some(Value::Ext(crate::value::Ext::Document(d))) => Value::Str(Str::from(d.id())),
        Some(v) => v,
        None => Value::Null,
    }
}

/// The filter `attribute <operator> value`; `None` when no row can satisfy
/// it (a value that is not scalar) (`comparison()`).
///
/// Fails ([`crate::Kind::Query`]) for an unknown operator (`Invalid join
/// operator: <op>`).
fn comparison(attribute: &str, operator: &str, value: Value) -> Result<Option<Query>> {
    if !matches!(value, Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_)) {
        return Ok(None);
    }
    let value = dbvalue::to_query(&value);
    Ok(Some(match operator {
        "=" => Query::equal(attribute, vec![value]),
        "!=" | "<>" => Query::not_equal(attribute, value),
        "<" => Query::less_than(attribute, value),
        "<=" => Query::less_than_equal(attribute, value),
        ">" => Query::greater_than(attribute, value),
        ">=" => Query::greater_than_equal(attribute, value),
        other => return Err(Error::query(format!("Invalid join operator: {other}"))),
    }))
}

/// The filter on the joined collection's own attributes; `None` unless it
/// constrains only this alias (`aliasFilter()`).
fn alias_filter(filter: &utopia_query::Query, alias: &str) -> Option<Query> {
    let method = filter.method();
    if method == Method::And || method == Method::Or {
        let mut children = Vec::new();
        for child in filter.values().values() {
            let QValue::Ext(Object::Query(child)) = child else { return None };
            children.push(QValue::from(alias_filter(child, alias)?.into_inner()));
        }
        if children.is_empty() {
            return None;
        }
        return Some(Query::new(method, "", utopia_query::list(children), ""));
    }

    if !method.is_filter()
        && !method.is_spatial()
        && !method.is_json()
        && method != Method::ElemMatch
        && method != Method::ContainsAll
    {
        return None;
    }

    let (owner, attribute) = column_owner(filter.attribute(), DEFAULT_ALIAS);
    if owner != alias {
        return None;
    }
    Some(Query::new(method, attribute, filter.values().clone(), ""))
}
