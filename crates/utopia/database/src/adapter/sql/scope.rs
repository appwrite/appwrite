//! The scopes a SQL adapter's builders read collections through.
//!
//! PHP: `Utopia\Database\Adapter\SQL\Scope\Raw`, `Scope\Filter`.

use std::sync::Arc;

use utopia_query::builder::{Scope, ScopeBinding};
use utopia_query::hook::{Hook, Map};

use super::hook::tenant;
use crate::builder::query_error;
use crate::database;
use crate::error::Error;
use crate::storage;
use crate::value::Value;

/// The name a collection's table is stored under.
pub type Tables = Arc<dyn Fn(&str) -> String + Send + Sync>;

/// The attribute map every scope adds (`Storage::attributeMap()`).
pub fn attributes() -> Map {
    Map::new(storage::ATTRIBUTE_MAP)
}

/// The scope of a builder `SQL::builder()` hands out: it maps document
/// attributes to columns and, under shared tables, keeps the statement and
/// every table joined through the builder's join methods to the tenant
/// (`Tenant\Raw`), which learns the joins as the builder compiles them.
///
/// Its state is taken when the builder is handed out.
///
/// PHP: `Utopia\Database\Adapter\SQL\Scope\Raw`.
pub struct Raw {
    table: Tables,
    shared_tables: bool,
    tenant: Value,
    quote: String,
}

impl Raw {
    /// The scope (`new Raw(...)`).
    pub fn new(table: Tables, shared_tables: bool, tenant: Value, quote: impl Into<String>) -> Self {
        Raw { table, shared_tables, tenant, quote: quote.into() }
    }
}

impl Scope for Raw {
    fn table(&self, collection: &str) -> String {
        (self.table)(collection)
    }

    fn join_table(&self, table: &str) -> String {
        self.table(table)
    }

    fn bind(&self, _collection: &str, table: &str, alias: &str) -> utopia_query::Result<ScopeBinding> {
        let mut binding = ScopeBinding { hooks: vec![attributes().into()], before_each_build: Vec::new() };
        if !self.shared_tables {
            return Ok(binding);
        }
        let metadata = table == self.table(database::METADATA)
            || table == self.table(&storage::permissions_table(database::METADATA));
        let tenants = Arc::new(tenant::Raw::new(
            crate::value::to_query(&self.tenant),
            if alias.is_empty() { table } else { alias },
            metadata,
            self.quote.as_str(),
        ));
        binding.hooks.push(tenants.hook());
        binding.hooks.push(tenant::RawOuterJoin::new(tenants.clone()).into());
        binding.before_each_build.push(Arc::new(move || tenants.reset()));
        Ok(binding)
    }

    fn error(&self, message: String) -> utopia_query::Error {
        query_error(Error::query(message))
    }
}

/// The scope of the builders the adapter reads and writes documents
/// through: it maps document attributes to columns and, under shared
/// tables, keeps the main table to the tenants with `Tenant\Filter`, the
/// read naming its joins up front.
///
/// PHP: `Utopia\Database\Adapter\SQL\Scope\Filter`.
pub struct Filter {
    table: Tables,
    shared_tables: bool,
    tenants: Vec<Value>,
    quote: String,
    allow_null_tenant: bool,
    unindexed: Vec<String>,
}

impl Filter {
    /// The scope (`new Filter(...)`): `tenants` is the selected tenant, or
    /// the tenants a read spans.
    pub fn new(
        table: Tables,
        shared_tables: bool,
        tenants: Vec<Value>,
        quote: impl Into<String>,
        allow_null_tenant: bool,
        unindexed: Vec<String>,
    ) -> Self {
        Filter { table, shared_tables, tenants, quote: quote.into(), allow_null_tenant, unindexed }
    }
}

impl Scope for Filter {
    fn table(&self, collection: &str) -> String {
        (self.table)(collection)
    }

    /// The adapter's reads join tables by the names they are stored under.
    fn join_table(&self, table: &str) -> String {
        table.to_owned()
    }

    fn bind(&self, collection: &str, _table: &str, alias: &str) -> utopia_query::Result<ScopeBinding> {
        let mut binding = ScopeBinding { hooks: vec![attributes().into()], before_each_build: Vec::new() };
        if !self.shared_tables {
            return Ok(binding);
        }
        let source = if alias.is_empty() { collection } else { alias };
        let tenants: Vec<utopia_query::Value> = self.tenants.iter().map(crate::value::to_query).collect();
        let mut filter = tenant::Filter::new(tenants)
            .with_metadata_collection(database::METADATA)
            .with_collection(collection)
            .with_quote(self.quote.as_str())
            .with_unindexed(self.unindexed.clone());
        if self.allow_null_tenant {
            filter = filter.with_allow_null_column(format!("{source}.{}", storage::UID));
        }
        binding.hooks.push(filter.clone().into());
        binding.hooks.push(Hook::join_filter(tenant::OuterJoin::new(filter, source)));
        Ok(binding)
    }

    fn error(&self, message: String) -> utopia_query::Error {
        query_error(Error::query(message))
    }
}
