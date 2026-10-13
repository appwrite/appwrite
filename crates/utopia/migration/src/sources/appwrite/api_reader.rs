//! Reading databases through the Appwrite API.

use async_trait::async_trait;
use php_std::{Array, Value};

use super::reader::{Cursor, Reader, ReaderQuery};
use crate::error::Result;
use crate::resource::{ResourceRef, is_supported, types};
use crate::resources::{cast_bool, cast_int};
use crate::sdk::{Client, Model, query};
use crate::source::ResourceIds;
use crate::transfer::Report;

/// Reads databases through the API's TablesDB service; rows and the rest as
/// the models' arrays.
///
/// PHP: `Utopia\Migration\Sources\Appwrite\Reader\API`.
#[derive(Debug, Clone)]
pub struct ApiReader {
    client: Client,
}

fn queries(list: Vec<ReaderQuery>) -> Value {
    query::list(
        list.into_iter()
            .filter_map(|q| match q {
                ReaderQuery::Api(s) => Some(s),
                ReaderQuery::Database(_) => None,
            })
            .collect(),
    )
}

fn v(s: &str) -> Value {
    Value::from(s)
}

fn arrays(m: &Model, prop: &str) -> Vec<Array> {
    m.models(prop).into_iter().map(Model::to_array).collect()
}

fn add(report: &mut Report, key: &str, n: i64) {
    let e = report.entry(key.to_owned()).or_insert(Value::Int(0));
    *e = Value::Int(cast_int(e) + n);
}

impl ApiReader {
    /// A reader on `client`.
    ///
    /// PHP: `new Reader\API(new TablesDB($client))`.
    pub fn new(client: Client) -> Self {
        Self { client }
    }

    async fn tables_db(&self, method: &str, args: &[(&str, Value)]) -> Result<Model> {
        self.client.invoke("TablesDB", method, args).await
    }

    fn table_ids(table: &ResourceRef) -> (String, String) {
        (table.database().map(|d| d.id()).unwrap_or_default(), table.id())
    }
}

#[async_trait]
impl Reader for ApiReader {
    async fn report(&self, resources: &[String], report: &mut Report, resource_ids: &ResourceIds) -> Result<()> {
        let relevant = [types::DATABASE, types::TABLE, types::ROW, types::COLUMN, types::INDEX];
        if !is_supported(&relevant, resources) {
            return Ok(());
        }
        for t in relevant {
            if is_supported(&[t], resources) {
                report.insert(t.to_owned(), Value::Int(0));
            }
        }
        let mut database_queries = Vec::new();
        if let Some(ids) = resource_ids.get(types::DATABASE)
            && cast_bool(ids)
        {
            database_queries.push(query::equal("$id", ids.clone()));
        }
        let response = self.tables_db("list", &[("queries", query::list(database_queries))]).await?;
        let databases = arrays(&response, "databases");
        if resources.iter().any(|r| r == types::DATABASE) {
            report.insert(types::DATABASE.to_owned(), response.value("total"));
        }
        let selected = resources.iter().filter(|r| relevant.contains(&r.as_str())).count();
        if selected == 1 && resources.iter().any(|r| r == types::DATABASE) {
            return Ok(());
        }
        for database in &databases {
            let database_id = crate::resources::cast_string(database.get_str("$id").unwrap_or(&Value::Null));
            let mut tables: Vec<Array> = Vec::new();
            let page = 25;
            let mut last: Option<String> = None;
            loop {
                let q = match &last {
                    Some(id) => vec![query::cursor_after(id)],
                    None => vec![query::limit(page)],
                };
                let response = self
                    .tables_db("listTables", &[("databaseId", v(&database_id)), ("queries", query::list(q))])
                    .await?;
                let current = arrays(&response, "tables");
                let count = current.len() as i64;
                tables.extend(current);
                last = tables
                    .last()
                    .and_then(|t| t.get_str("$id"))
                    .filter(|v| !matches!(v, Value::Null))
                    .map(crate::resources::cast_string);
                if count < page {
                    break;
                }
            }
            if is_supported(&[types::TABLE], resources) {
                add(report, types::TABLE, tables.len() as i64);
            }
            if is_supported(&[types::ROW, types::COLUMN, types::INDEX], resources) {
                for table in &tables {
                    let table_id = crate::resources::cast_string(table.get_str("$id").unwrap_or(&Value::Null));
                    if is_supported(&[types::COLUMN], resources) {
                        let columns = match table.get_str("columns").filter(|v| !matches!(v, Value::Null)) {
                            Some(c) => c.clone(),
                            None => table.get_str("attributes").cloned().unwrap_or(Value::Array(Array::new())),
                        };
                        add(report, types::COLUMN, php_std::value::array_len(&columns.to_json()) as i64);
                    }
                    if resources.iter().any(|r| r == types::INDEX) {
                        let indexes = table.get_str("indexes").cloned().unwrap_or(Value::Array(Array::new()));
                        add(report, types::INDEX, php_std::value::array_len(&indexes.to_json()) as i64);
                    }
                    if is_supported(&[types::ROW], resources) {
                        let rows = self
                            .tables_db(
                                "listRows",
                                &[
                                    ("databaseId", v(&database_id)),
                                    ("tableId", v(&table_id)),
                                    ("queries", query::list(vec![query::limit(1)])),
                                ],
                            )
                            .await?;
                        add(report, types::ROW, cast_int(&rows.value("total")));
                    }
                }
            }
        }
        Ok(())
    }

    async fn list_databases(&self, q: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        Ok(arrays(&self.tables_db("list", &[("queries", queries(q))]).await?, "databases"))
    }

    async fn list_tables(&self, database: &ResourceRef, q: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let response =
            self.tables_db("listTables", &[("databaseId", v(&database.id())), ("queries", queries(q))]).await?;
        Ok(arrays(&response, "tables"))
    }

    async fn list_columns(&self, table: &ResourceRef, q: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let (database_id, table_id) = Self::table_ids(table);
        let response = self
            .tables_db(
                "listColumns",
                &[("databaseId", v(&database_id)), ("tableId", v(&table_id)), ("queries", queries(q))],
            )
            .await?;
        Ok(match response.to_array().get_str("columns") {
            Some(Value::Array(list)) => list
                .values()
                .map(|c| match c {
                    Value::Array(a) => a.clone(),
                    _ => Array::new(),
                })
                .collect(),
            _ => Vec::new(),
        })
    }

    async fn list_indexes(&self, table: &ResourceRef, q: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let (database_id, table_id) = Self::table_ids(table);
        let response = self
            .tables_db(
                "listIndexes",
                &[("databaseId", v(&database_id)), ("tableId", v(&table_id)), ("queries", queries(q))],
            )
            .await?;
        Ok(arrays(&response, "indexes"))
    }

    async fn list_rows(&self, table: &ResourceRef, q: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let (database_id, table_id) = Self::table_ids(table);
        let response = self
            .tables_db(
                "listRows",
                &[("databaseId", v(&database_id)), ("tableId", v(&table_id)), ("queries", queries(q))],
            )
            .await?;
        Ok(arrays(&response, "rows"))
    }

    async fn get_row(&self, table: &ResourceRef, row_id: &str, q: Vec<ReaderQuery>) -> Result<Array> {
        let (database_id, table_id) = Self::table_ids(table);
        let response = self
            .tables_db(
                "getRow",
                &[
                    ("databaseId", v(&database_id)),
                    ("tableId", v(&table_id)),
                    ("rowId", v(row_id)),
                    ("queries", queries(q)),
                ],
            )
            .await?;
        Ok(response.to_array())
    }

    fn query_select(&self, columns: Vec<String>) -> ReaderQuery {
        ReaderQuery::Api(query::select(columns))
    }

    fn query_equal(&self, column: &str, values: Vec<Value>) -> ReaderQuery {
        ReaderQuery::Api(query::equal(column, Value::Array(Array::from_list(values))))
    }

    async fn query_cursor_after(&self, cursor: Cursor) -> Result<ReaderQuery> {
        let id = match cursor {
            Cursor::Resource(r) => r.id(),
            Cursor::Id(id) => id,
            Cursor::Other(a) => crate::resources::cast_string(a.get_str("$id").unwrap_or(&Value::Null)),
        };
        Ok(ReaderQuery::Api(query::cursor_after(&id)))
    }

    fn query_limit(&self, limit: i64) -> ReaderQuery {
        ReaderQuery::Api(query::limit(limit))
    }

    fn support_for_attributes(&self) -> bool {
        true
    }
}
