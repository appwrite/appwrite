//! What a read selects, and the dotted attributes it names.
//!
//! PHP: `SQL::applySelectionProjection()`, `mapSelectionsToColumns()`,
//! `applyJoinProjection()`, `joinSelections()`, `joinWildcardSelections()`,
//! `remapDottedQueryAttributes()`, `remapDottedQuery()`,
//! `qualifyDottedAttribute()`, `qualifiedGroupNames()`.

use utopia_query::Method;
use utopia_query::Object;

use super::{COLUMN_LIST_METHODS, JOIN_ATTRIBUTES, JOINED_ROW_INTERNALS, JoinAlias, aliases_of, assoc_set};
use crate::builder::SqlBuilder;
use crate::collection::Collection;
use crate::document;
use crate::error::Result;
use crate::query::{DEFAULT_ALIAS, Query};
use crate::storage;
use crate::value::Value;

use super::super::Sql;

/// The selections `alias.*` makes under each join alias, in order.
pub(crate) type JoinSelections = Vec<(String, Vec<String>)>;

impl Sql {
    /// `applySelectionProjection()`: the selections as columns, internal
    /// attributes by their columns, each `alias.*` expanded to the columns
    /// `join_selections` lists under the alias.
    pub(crate) fn apply_selection_projection(
        &self,
        builder: &mut SqlBuilder,
        selections: &[String],
        include_internal: bool,
        join_aliases: &[String],
        join_selections: &JoinSelections,
    ) {
        let mut expanded: Vec<String> = Vec::new();
        for selection in selections {
            let joined = selection
                .strip_suffix(".*")
                .and_then(|prefix| join_selections.iter().find(|(alias, _)| alias == prefix));
            match joined {
                Some((_, columns)) => expanded.extend(columns.iter().cloned()),
                None => expanded.push(selection.clone()),
            }
        }
        let mut unique: Vec<String> = Vec::new();
        for selection in expanded {
            if !unique.contains(&selection) {
                unique.push(selection);
            }
        }

        let mut simple = Vec::new();
        for column in self.map_selections_to_columns(&unique, include_internal, join_aliases) {
            if column.contains(" AS ") {
                builder.select_raw(&column, Vec::new());
            } else {
                simple.push(column);
            }
        }
        if !simple.is_empty() {
            builder.select(simple);
        }
    }

    /// `mapSelectionsToColumns()`: user-facing attribute names as columns,
    /// the internal columns added (all of them, or those selected), joined
    /// columns aliased `alias.column`.
    pub(crate) fn map_selections_to_columns(
        &self,
        selections: &[String],
        include_internal: bool,
        join_aliases: &[String],
    ) -> Vec<String> {
        let internal_keys =
            [document::ID, document::SEQUENCE, document::PERMISSIONS, document::CREATED_AT, document::UPDATED_AT];

        let mut explicit: Vec<&str> = Vec::new();
        for selection in selections {
            if let Some(internal) = internal_keys.iter().find(|k| **k == selection) {
                if !explicit.contains(internal) {
                    explicit.push(internal);
                }
            }
        }

        let mut list: Vec<String> = selections
            .iter()
            .filter(|s| !internal_keys.contains(&s.as_str()) && s.as_str() != document::COLLECTION)
            .cloned()
            .collect();

        if include_internal {
            list.extend(internal_keys.iter().map(|k| storage::column(k).to_owned()));
        } else {
            list.extend(explicit.iter().map(|k| storage::column(k).to_owned()));
        }

        let quote = self.identifier_quote();
        let mut columns = Vec::with_capacity(list.len());
        for selection in list {
            if let Some((prefix, name)) = selection.split_once('.')
                && join_aliases.iter().any(|a| a == prefix)
            {
                let internal = self.filter(storage::column(name));
                let qualified = format!("{quote}{}{quote}.{quote}{internal}{quote}", self.filter(prefix));
                columns.push(format!("{qualified} AS {quote}{prefix}.{internal}{quote}"));
                continue;
            }
            columns.push(self.filter(&selection));
        }
        columns
    }

    /// `applyJoinProjection()`: every column of the main table, and under
    /// each join alias what [`Sql::join_selections`] lists and the
    /// additions naming it.
    pub(crate) fn apply_join_projection(
        &self,
        builder: &mut SqlBuilder,
        collection: &Collection,
        joins: &[JoinAlias],
        alias: &str,
        additions: &[String],
    ) {
        builder.select(vec![format!("{}.*", self.filter(alias))]);

        let join_aliases = aliases_of(joins);
        let mut selections: Vec<String> =
            self.join_selections(collection, joins).into_iter().flat_map(|(_, columns)| columns).collect();
        for addition in additions {
            if let Some((prefix, _)) = addition.split_once('.')
                && join_aliases.iter().any(|a| a == prefix)
            {
                selections.push(addition.clone());
            }
        }

        self.apply_selection_projection(
            builder,
            &selections,
            false,
            &join_aliases,
            &self.join_wildcard_selections(collection, joins),
        );
    }

    /// `joinSelections()`: under each join alias, the joined `$id` and the
    /// attributes the Database layer handed over for its collection.
    pub(crate) fn join_selections(&self, collection: &Collection, joins: &[JoinAlias]) -> JoinSelections {
        let join_attributes = collection.get(JOIN_ATTRIBUTES);
        let mut selections: JoinSelections = Vec::new();
        for join in joins {
            let mut columns =
                selections.iter().find(|(alias, _)| *alias == join.alias).map(|(_, c)| c.clone()).unwrap_or_default();
            columns.push(format!("{}.{}", join.alias, document::ID));
            if let Some(Value::Array(map)) = join_attributes
                && let Some(Value::Array(attributes)) = map.get_str(&join.table)
            {
                for attribute in attributes.values() {
                    if let Value::Str(s) = attribute
                        && !s.is_empty()
                    {
                        columns.push(format!("{}.{}", join.alias, String::from_utf8_lossy(s.as_bytes())));
                    }
                }
            }
            assoc_set(&mut selections, join.alias.clone(), columns);
        }
        selections
    }

    /// `joinWildcardSelections()`: what `alias.*` selects under each join
    /// alias, the joined internal attributes included.
    pub(crate) fn join_wildcard_selections(&self, collection: &Collection, joins: &[JoinAlias]) -> JoinSelections {
        let mut selections = self.join_selections(collection, joins);
        for (alias, columns) in &mut selections {
            for internal in JOINED_ROW_INTERNALS {
                columns.push(format!("{alias}.{internal}"));
            }
        }
        selections
    }

    /// `remapDottedQueryAttributes()`: each dotted attribute named by its
    /// column, `alias.attribute` by the joined column.
    pub(crate) fn remap_dotted_query_attributes(
        &self,
        queries: &mut [Query],
        joins: &[JoinAlias],
        collection: &Collection,
    ) -> Result<()> {
        let mut alias_set = aliases_of(joins);
        alias_set.push(DEFAULT_ALIAS.to_owned());
        let main_attributes: Vec<String> = collection.attributes()?.iter().map(|a| a.key.clone()).collect();
        for query in queries.iter_mut() {
            self.remap_dotted_query(query, &alias_set, &main_attributes);
        }
        Ok(())
    }

    /// `remapDottedQuery()`.
    fn remap_dotted_query(&self, query: &mut utopia_query::Query, alias_set: &[String], main: &[String]) {
        let method = query.method();
        if method == Method::Select {
            return;
        }

        if method.is_join() {
            if query.is_nested_join() {
                let mut values = query.values().clone();
                for_each_mut(&mut values, |value| {
                    if let utopia_query::Value::Ext(Object::Query(on)) = value
                        && on.method() != Method::On
                    {
                        self.remap_dotted_query(on, alias_set, main);
                    }
                });
                query.set_values(values);
            }
            return;
        }

        if query.is_nested() {
            let mut values = query.values().clone();
            for_each_mut(&mut values, |value| {
                if let utopia_query::Value::Ext(Object::Query(child)) = value {
                    self.remap_dotted_query(child, alias_set, main);
                }
            });
            query.set_values(values);
            return;
        }

        if COLUMN_LIST_METHODS.contains(&method) {
            let mut values = query.values().clone();
            let mut changed = false;
            for_each_mut(&mut values, |value| {
                if let utopia_query::Value::Str(column) = value
                    && column.as_bytes().contains(&b'.')
                {
                    let column = String::from_utf8_lossy(column.as_bytes()).into_owned();
                    *value = utopia_query::Value::Str(
                        self.qualify_dotted_attribute(&column, alias_set, main).as_str().into(),
                    );
                    changed = true;
                }
            });
            if changed {
                query.set_values(values);
            }
            return;
        }

        let attribute = query.attribute();
        if attribute.is_empty() || attribute == "*" || !attribute.contains('.') {
            return;
        }
        let qualified = self.qualify_dotted_attribute(attribute, alias_set, main);
        query.set_attribute(qualified);
    }

    /// `qualifyDottedAttribute()`: a main attribute (even dotted) or a bare
    /// name by its column, `alias.attribute` of a known alias by the
    /// joined column, anything else as it is.
    pub(crate) fn qualify_dotted_attribute(&self, attribute: &str, alias_set: &[String], main: &[String]) -> String {
        if main.iter().any(|m| m == attribute) {
            return self.filter(storage::column(attribute));
        }
        let Some((prefix, name)) = attribute.split_once('.') else {
            return self.filter(storage::column(attribute));
        };
        if alias_set.iter().any(|a| a == prefix) {
            return format!("{}.{}", self.filter(prefix), self.filter(storage::column(name)));
        }
        attribute.to_owned()
    }

    /// `qualifiedGroupNames()`: by position, the joined groups returned
    /// under their qualified name, because another group of the query
    /// shares their column name.
    pub(crate) fn qualified_group_names(&self, groups: &[String]) -> Vec<(usize, String)> {
        let names: Vec<String> = groups
            .iter()
            .map(|group| {
                let bare = group.rfind('.').map_or(group.as_str(), |d| &group[d + 1..]);
                self.filter(storage::column(bare))
            })
            .collect();
        let mut qualified = Vec::new();
        for (index, group) in groups.iter().enumerate() {
            let count = names.iter().filter(|n| **n == names[index]).count();
            if count > 1 && group.contains('.') {
                qualified.push((index, group.clone()));
            }
        }
        qualified
    }
}

/// Runs `f` on every value of a query's values, in order.
pub(crate) fn for_each_mut(values: &mut utopia_query::Array, mut f: impl FnMut(&mut utopia_query::Value)) {
    let keys: Vec<php_std::types::ArrayKey> = values.keys().map(php_std::types::KeyRef::to_owned).collect();
    for k in keys {
        if let Some(value) = values.get_mut(&k) {
            f(value);
        }
    }
}
