//! Collections: create, delete, analyze, sizes.
//!
//! PHP: `createCollection()`, `deleteCollection()`, `analyzeCollection()`,
//! `getSizeOfCollection()`, `getSizeOfCollectionOnDisk()` of `SQL` and its
//! subclasses, `discardCreatedCollection()`, `dropCreatedCollection()`.

use utopia_query::schema::{ColumnType, IndexOptions, IndexType};

use super::attribute::{each_table, interpolate};
use super::fts::FTS_TABLE_SUFFIX;
use super::index::escape_like_pattern;
use super::pdo::ParamType;
use super::{Engine, Sql};
use crate::adapter::Adapter;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::Event;
use crate::index::Index;
use crate::query::query_error;
use crate::storage;
use crate::value::{Array, ArrayKey, Str, Value, string};

/// SQLite's first permissions index (`SQLite::INDEX_1`).
const SQLITE_INDEX_1: &str = "_index_1";
/// SQLite's second permissions index (`SQLite::INDEX_2`).
const SQLITE_INDEX_2: &str = "_index_2";

/// `(int) (is_numeric($value) ? $value : 0)`.
fn numeric(value: Option<Value>) -> i64 {
    match value {
        Some(Value::Int(i)) => i,
        Some(Value::Float(f)) => f as i64,
        Some(Value::Str(s)) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some(_) => crate::value::to_long(&Value::Str(s)),
            None => 0,
        },
        _ => 0,
    }
}

impl Sql {
    /// `new Document([Document::ID => Database::METADATA])`: the metadata
    /// collection, as `getDocument()` takes it.
    pub(crate) fn metadata_collection() -> Result<Collection> {
        let mut data = Array::new();
        data.set(ArrayKey::Str(Str::from_static(crate::document::ID)), Value::Str(Str::from(database::METADATA)));
        Collection::from_array(data)
    }

    /// `getDocument(new Document(['$id' => METADATA]), $id)`: the stored
    /// definition of a collection, empty when there is none.
    pub(crate) async fn metadata_document(&self, id: &str) -> Result<Document> {
        self.get_document(&Self::metadata_collection()?, id, Vec::new(), false).await
    }

    /// `createCollection()`
    pub(crate) async fn create_collection(
        &self,
        collection: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        match self.engine {
            Engine::Postgres => self.create_collection_postgres(collection, attributes, indexes).await,
            Engine::Sqlite => self.create_collection_sqlite(collection, attributes, indexes).await,
            Engine::MariaDb | Engine::MySql => self.create_collection_mariadb(collection, attributes, indexes).await,
        }
    }

    /// Postgres `createCollection()`.
    async fn create_collection_postgres(
        &self,
        collection: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        let namespace = self.namespace();
        let id = self.filter(collection);
        let table_raw = self.table_raw(&id);
        let permissions_raw = self.table_raw(&storage::permissions_table(&id));
        let schema = self.schema();
        let shared = self.shared_tables();
        let tenant = interpolate(&self.current_tenant());
        let null = || utopia_query::Value::Null;

        let mut table = schema.table(&table_raw);
        each_table!(&mut table, t => {
            t.id(storage::SEQUENCE);
            t.string(storage::UID, 255);
            if shared {
                t.integer(storage::TENANT).nullable().default(null());
            }
            t.datetime(storage::CREATED_AT, 3).nullable().default(null());
            t.datetime(storage::UPDATED_AT, 3).nullable().default(null());
        });
        for attribute in attributes {
            if Sql::stores_column(attribute) {
                self.add_attribute_column(&mut table, attribute)?;
            }
        }
        each_table!(&mut table, t => {
            t.json(storage::PERMISSIONS).nullable().default(null());
        });
        let created = table.create().map_err(query_error)?;

        let index = |name: &str, columns: &[&str], options: IndexOptions| -> Result<String> {
            Ok(schema.create_index(&table_raw, name, columns, &options).map_err(query_error)?.query)
        };
        let uid_collation = || vec![(storage::UID.to_owned(), "utf8_ci_ai".to_owned())];
        let short = |suffix: &str| {
            if shared {
                Sql::short_key(&format!("{namespace}_{tenant}_{id}{suffix}"))
            } else {
                Sql::short_key(&format!("{namespace}_{id}{suffix}"))
            }
        };
        let mut statements = Vec::new();
        if shared {
            statements.push(index(
                &short(storage::UID),
                &[storage::UID, storage::TENANT],
                IndexOptions { unique: true, collations: uid_collation(), ..IndexOptions::default() },
            )?);
            statements.push(index(&short("_created"), &[storage::TENANT, storage::CREATED_AT], IndexOptions::default())?);
            statements.push(index(&short("_updated"), &[storage::TENANT, storage::UPDATED_AT], IndexOptions::default())?);
            statements.push(index(
                &short(storage::INDEX_TENANT_ID),
                &[storage::TENANT, storage::SEQUENCE],
                IndexOptions::default(),
            )?);
        } else {
            statements.push(index(
                &short(storage::UID),
                &[storage::UID],
                IndexOptions { unique: true, collations: uid_collation(), ..IndexOptions::default() },
            )?);
            statements.push(index(&short("_created"), &[storage::CREATED_AT], IndexOptions::default())?);
            statements.push(index(&short("_updated"), &[storage::UPDATED_AT], IndexOptions::default())?);
        }
        statements.push(index(
            &short(storage::PERMISSIONS),
            &[storage::PERMISSIONS],
            IndexOptions { method: "gin".into(), ..IndexOptions::default() },
        )?);
        let collection_sql = format!("{}; {}", created.query, statements.join("; "));

        let mut permissions = schema.table(&permissions_raw);
        each_table!(&mut permissions, t => {
            t.id(storage::SEQUENCE);
            t.integer(storage::TENANT).nullable().default(null());
            t.string(storage::PERMISSIONS_TYPE, 12);
            t.string(storage::PERMISSIONS_PERMISSION, 255);
            t.string(storage::PERMISSIONS_DOCUMENT, 255);
        });
        let permissions_created = permissions.create().map_err(query_error)?;
        let permissions_index = |name: &str, columns: &[&str], options: IndexOptions| -> Result<String> {
            Ok(schema.create_index(&permissions_raw, name, columns, &options).map_err(query_error)?.query)
        };
        let btree = || IndexOptions { method: "btree".into(), ..IndexOptions::default() };
        let mut permission_statements = Vec::new();
        if shared {
            permission_statements.push(permissions_index(
                &short("_ukey"),
                &[
                    storage::TENANT,
                    storage::PERMISSIONS_DOCUMENT,
                    storage::PERMISSIONS_TYPE,
                    storage::PERMISSIONS_PERMISSION,
                ],
                IndexOptions { unique: true, ..btree() },
            )?);
            permission_statements.push(permissions_index(
                &short("_permission"),
                &[storage::TENANT, storage::PERMISSIONS_PERMISSION, storage::PERMISSIONS_TYPE],
                btree(),
            )?);
        } else {
            permission_statements.push(permissions_index(
                &short("_ukey"),
                &[storage::PERMISSIONS_DOCUMENT, storage::PERMISSIONS_TYPE, storage::PERMISSIONS_PERMISSION],
                IndexOptions {
                    unique: true,
                    collations: vec![(storage::PERMISSIONS_DOCUMENT.to_owned(), "utf8_ci_ai".to_owned())],
                    ..btree()
                },
            )?);
            permission_statements.push(permissions_index(
                &short("_permission"),
                &[storage::PERMISSIONS_PERMISSION, storage::PERMISSIONS_TYPE],
                btree(),
            )?);
        }
        let permissions_sql = format!("{}; {}", permissions_created.query, permission_statements.join("; "));

        let mut created = false;
        let run = async {
            self.execute_statement(&collection_sql, Event::CollectionCreate).await?;
            created = true;
            self.execute_statement(&permissions_sql, Event::CollectionCreate).await?;
            for index in indexes {
                let mut types: Vec<(String, ColumnType)> = Vec::new();
                for attribute in &index.attributes {
                    let base = attribute.split('.').next().unwrap_or("");
                    for definition in attributes {
                        if definition.key == base {
                            match types.iter_mut().find(|(a, _)| a == attribute) {
                                Some(entry) => entry.1 = definition.kind,
                                None => types.push((attribute.clone(), definition.kind)),
                            }
                        }
                    }
                }
                if index.kind == IndexType::Spatial && !index.orders.is_empty() {
                    return Err(Error::database(
                        "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
                    ));
                }
                self.create_index_with_event(
                    &id,
                    &index.with_key(self.filter(&index.key)),
                    &types,
                    &[],
                    Event::CollectionCreate,
                )
                .await?;
            }
            Ok(())
        };
        let result = run.await;
        if let Err(error) = result {
            let error = self.process_exception(error);
            if created && !error.is_a(Kind::Duplicate) {
                self.discard_created_collection(&id).await;
            }
            return Err(error);
        }
        Ok(true)
    }

    /// MariaDB `createCollection()`: the table with its indexes, and the
    /// permissions table.
    async fn create_collection_mariadb(
        &self,
        collection: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        let id = self.filter(collection);
        let schema = self.schema();
        let shared = self.shared_tables();
        let null = || utopia_query::Value::Null;
        let mut hash: Vec<(String, &Attribute)> = Vec::new();
        for attribute in attributes {
            let key = self.filter(&attribute.key);
            match hash.iter_mut().find(|(k, _)| *k == key) {
                Some(entry) => entry.1 = attribute,
                None => hash.push((key, attribute)),
            }
        }

        let mut table = schema.table(&self.table_raw(&id));
        each_table!(&mut table, t => {
            t.id(storage::SEQUENCE);
            t.string(storage::UID, 255);
            t.datetime(storage::CREATED_AT, 3).nullable().default(null());
            t.datetime(storage::UPDATED_AT, 3).nullable().default(null());
            t.medium_text(storage::PERMISSIONS).nullable().default(null());
        });
        for attribute in attributes {
            if !Sql::stores_column(attribute) {
                continue;
            }
            table.raw_column(&format!("`{}` {}", self.filter(&attribute.key), self.attribute_sql_type(attribute)?));
        }
        for index in indexes {
            let index_id = self.filter(&index.key);
            let kind = index.kind;
            let mut columns = Vec::with_capacity(index.attributes.len() + 1);
            for (nested, attribute) in index.attributes.iter().enumerate() {
                let order = index.orders.get(nested).copied().flatten().map_or("", |o| o.as_str());
                if kind == IndexType::Spatial && !self.supports(Capability::IndexSpatialOrder) && !order.is_empty() {
                    return Err(Error::database(
                        "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
                    ));
                }
                let column = self.filter(storage::column(attribute));
                let array = hash.iter().find(|(k, _)| *k == column).is_some_and(|(_, a)| a.array);
                columns.push(self.compile_index_column(
                    &column,
                    array,
                    index.lengths.get(nested).copied().flatten().unwrap_or(0),
                    if kind == IndexType::Fulltext { "" } else { order },
                ));
            }
            if shared && kind != IndexType::Fulltext && kind != IndexType::Spatial {
                columns.insert(0, self.quote(storage::TENANT));
            }
            let index = utopia_query::schema::Index {
                raw_columns: columns,
                ..utopia_query::schema::Index::new(index_id, Vec::new(), kind)
            };
            table.add_index(index).map_err(query_error)?;
        }
        let plain = |name: &str, columns: &[&str], kind: IndexType| {
            utopia_query::schema::Index::new(name, columns.iter().map(|c| (*c).to_owned()).collect(), kind)
        };
        if shared {
            table.raw_column(&format!("{} INT(11) UNSIGNED DEFAULT NULL", storage::TENANT));
            table.add_index(plain(storage::UID, &[storage::UID, storage::TENANT], IndexType::Unique)).map_err(query_error)?;
            table
                .add_index(plain(storage::INDEX_CREATED_AT, &[storage::TENANT, storage::CREATED_AT], IndexType::Index))
                .map_err(query_error)?;
            table
                .add_index(plain(storage::INDEX_UPDATED_AT, &[storage::TENANT, storage::UPDATED_AT], IndexType::Index))
                .map_err(query_error)?;
            table
                .add_index(plain(storage::INDEX_TENANT_ID, &[storage::TENANT, storage::SEQUENCE], IndexType::Index))
                .map_err(query_error)?;
        } else {
            table.add_index(plain(storage::UID, &[storage::UID], IndexType::Unique)).map_err(query_error)?;
            table.add_index(plain(storage::INDEX_CREATED_AT, &[storage::CREATED_AT], IndexType::Index)).map_err(query_error)?;
            table.add_index(plain(storage::INDEX_UPDATED_AT, &[storage::UPDATED_AT], IndexType::Index)).map_err(query_error)?;
        }
        let collection_sql = table.create().map_err(query_error)?.query;

        let mut permissions = schema.table(&self.table_raw(&storage::permissions_table(&id)));
        each_table!(&mut permissions, t => {
            t.id(storage::SEQUENCE);
            t.string(storage::PERMISSIONS_TYPE, 12);
            t.string(storage::PERMISSIONS_PERMISSION, 255);
            t.string(storage::PERMISSIONS_DOCUMENT, 255);
        });
        if shared {
            each_table!(&mut permissions, t => {
                t.integer(storage::TENANT).unsigned().nullable().default(null());
            });
            permissions
                .add_index(plain(
                    storage::INDEX_1,
                    &[
                        storage::PERMISSIONS_DOCUMENT,
                        storage::TENANT,
                        storage::PERMISSIONS_TYPE,
                        storage::PERMISSIONS_PERMISSION,
                    ],
                    IndexType::Unique,
                ))
                .map_err(query_error)?;
            permissions
                .add_index(plain(
                    storage::PERMISSIONS_PERMISSION,
                    &[storage::TENANT, storage::PERMISSIONS_PERMISSION, storage::PERMISSIONS_TYPE],
                    IndexType::Index,
                ))
                .map_err(query_error)?;
        } else {
            permissions
                .add_index(plain(
                    storage::INDEX_1,
                    &[storage::PERMISSIONS_DOCUMENT, storage::PERMISSIONS_TYPE, storage::PERMISSIONS_PERMISSION],
                    IndexType::Unique,
                ))
                .map_err(query_error)?;
            permissions
                .add_index(plain(
                    storage::PERMISSIONS_PERMISSION,
                    &[storage::PERMISSIONS_PERMISSION, storage::PERMISSIONS_TYPE],
                    IndexType::Index,
                ))
                .map_err(query_error)?;
        }
        let permissions_sql = permissions.create().map_err(query_error)?.query;

        let mut created = false;
        let run = async {
            self.execute_statement(&collection_sql, Event::CollectionCreate).await?;
            created = true;
            self.execute_statement(&permissions_sql, Event::CollectionCreate).await
        };
        if let Err(exception) = run.await {
            let error = self.process_exception(exception);
            if created && !error.is_a(Kind::Duplicate) {
                self.discard_created_collection(&id).await;
            }
            return Err(error);
        }
        Ok(true)
    }

    /// SQLite `createCollection()`: the tables, then their indexes one by
    /// one.
    async fn create_collection_sqlite(
        &self,
        collection: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        let id = self.filter(collection);
        let mut attribute_strings = Vec::with_capacity(attributes.len());
        for attribute in attributes {
            attribute_strings.push(format!(
                "`{}` {}, ",
                self.filter(&attribute.key),
                self.attribute_sql_type(attribute)?
            ));
        }
        let tenant_type = if self.engine_state().emulate_mysql { "\"INT(11) UNSIGNED\"" } else { "INTEGER" };
        let tenant_query = if self.shared_tables() {
            format!("{} {tenant_type} DEFAULT NULL,", self.quote(storage::TENANT))
        } else {
            String::new()
        };
        let joined = attribute_strings.join(" ");
        let columns = joined.get(..joined.len().saturating_sub(2)).unwrap_or("");
        let table = format!(
            "\n\t\t\tCREATE TABLE {} (\n\t\t\t\t{} INTEGER PRIMARY KEY AUTOINCREMENT,\n\t\t\t\t{} VARCHAR(36) NOT NULL,\n\t\t\t\t{tenant_query}\n\t\t\t\t{} DATETIME(3) DEFAULT NULL,\n\t\t\t\t{} DATETIME(3) DEFAULT NULL,\n\t\t\t\t{} MEDIUMTEXT DEFAULT NULL{}\n\t\t\t\t{columns}\n\t\t\t)\n\t\t",
            self.table(&id),
            self.quote(storage::SEQUENCE),
            self.quote(storage::UID),
            self.quote(storage::CREATED_AT),
            self.quote(storage::UPDATED_AT),
            self.quote(storage::PERMISSIONS),
            if attributes.is_empty() { "" } else { "," },
        );
        let permissions = format!(
            "\n\t\t\tCREATE TABLE {} (\n\t\t\t\t{} INTEGER PRIMARY KEY AUTOINCREMENT,\n\t\t\t\t{tenant_query}\n\t\t\t\t{} VARCHAR(12) NOT NULL,\n\t\t\t\t{} VARCHAR(255) NOT NULL,\n\t\t\t\t{} VARCHAR(255) NOT NULL\n\t\t\t)\n\t\t",
            self.table(&storage::permissions_table(&id)),
            self.quote(storage::SEQUENCE),
            self.quote(storage::PERMISSIONS_TYPE),
            self.quote(storage::PERMISSIONS_PERMISSION),
            self.quote(storage::PERMISSIONS_DOCUMENT),
        );
        let mut created = false;
        let run = async {
            let mut prepared = self.sqlite_prepare(&table, Some(Event::CollectionCreate)).await?;
            self.execute(&mut prepared).await?;
            created = true;
            let mut prepared = self.sqlite_prepare(&permissions, Some(Event::CollectionCreate)).await?;
            self.execute(&mut prepared).await?;
            let event = Event::CollectionCreate;
            let unique = |key: &str, attributes: &[&str]| Index::unique(key, attributes.to_vec(), vec![], vec![]);
            let key = |key: &str, attributes: &[&str]| Index::key(key, attributes.to_vec(), vec![], vec![]);
            let perms = storage::permissions_table(&id);
            self.create_index_with_event(&id, &unique(storage::INDEX_1, &[storage::UID])?, &[], &[], event).await?;
            self.create_index_with_event(&id, &key(storage::INDEX_CREATED_AT, &[storage::CREATED_AT])?, &[], &[], event)
                .await?;
            self.create_index_with_event(&id, &key(storage::INDEX_UPDATED_AT, &[storage::UPDATED_AT])?, &[], &[], event)
                .await?;
            self.create_index_with_event(
                &perms,
                &unique(
                    SQLITE_INDEX_1,
                    &[storage::PERMISSIONS_DOCUMENT, storage::PERMISSIONS_TYPE, storage::PERMISSIONS_PERMISSION],
                )?,
                &[],
                &[],
                event,
            )
            .await?;
            self.create_index_with_event(
                &perms,
                &key(SQLITE_INDEX_2, &[storage::PERMISSIONS_PERMISSION, storage::PERMISSIONS_TYPE])?,
                &[],
                &[],
                event,
            )
            .await?;
            if self.shared_tables() {
                self.create_index_with_event(&id, &key(storage::INDEX_TENANT_ID, &[storage::SEQUENCE])?, &[], &[], event)
                    .await?;
            }
            for index in indexes {
                self.create_index_with_event(&id, &index.with_key(self.filter(&index.key)), &[], &[], event).await?;
            }
            Ok::<_, Error>(())
        };
        if let Err(error) = run.await {
            let error = self.process_exception(error);
            if created && !error.is_a(Kind::Duplicate) {
                self.discard_created_collection(&id).await;
            }
            return Err(error);
        }
        Ok(true)
    }

    /// `discardCreatedCollection()`: drops what a failed create created; a
    /// drop that fails too is logged, so the create's error is the one
    /// reported.
    pub(crate) async fn discard_created_collection(&self, id: &str) {
        if let Err(error) = self.drop_created_collection(id).await {
            eprintln!("Failed to rollback collection '{id}': {}", error.message());
        }
    }

    /// `dropCreatedCollection()` (SQLite: `deleteCollection()`).
    pub(crate) async fn drop_created_collection(&self, id: &str) -> Result<()> {
        if self.engine == Engine::Sqlite {
            self.delete_collection(id).await?;
            return Ok(());
        }
        let schema = self.schema();
        let main = schema.table(&self.table_raw(id)).drop_if_exists().map_err(query_error)?;
        let permissions =
            schema.table(&self.table_raw(&storage::permissions_table(id))).drop_if_exists().map_err(query_error)?;
        self.execute_statement(&format!("{}; {}", main.query, permissions.query), Event::CollectionCreate).await?;
        Ok(())
    }

    /// `deleteCollection()`
    pub(crate) async fn delete_collection(&self, collection: &str) -> Result<bool> {
        let id = self.filter(collection);
        if self.engine == Engine::Sqlite {
            for fts in self.find_fulltext_tables(&id).await? {
                let mut prepared =
                    self.sqlite_prepare(&format!("DROP TABLE IF EXISTS `{fts}`"), Some(Event::CollectionDelete)).await?;
                self.execute(&mut prepared).await?;
            }
            for table in [self.table(&id), self.table(&storage::permissions_table(&id))] {
                let mut prepared =
                    self.sqlite_prepare(&format!("DROP TABLE IF EXISTS {table}"), Some(Event::CollectionDelete)).await?;
                self.execute(&mut prepared).await?;
            }
            self.forget_fulltext_tables(&id);
            return Ok(true);
        }
        let schema = self.schema();
        let main = schema.table(&self.table_raw(&id)).drop().map_err(query_error)?;
        let permissions =
            schema.table(&self.table_raw(&storage::permissions_table(&id))).drop_if_exists().map_err(query_error)?;
        match self.execute_statement(&format!("{}; {}", main.query, permissions.query), Event::CollectionDelete).await {
            Ok(done) => Ok(done),
            Err(e) => {
                let error = self.process_exception(e);
                let retry = error.kind() == Kind::NotFound
                    && (self.engine.is_mariadb_family() || self.state().lock().in_transaction == 0);
                if retry {
                    self.execute_statement(&permissions.query, Event::CollectionDelete).await?;
                }
                Err(error)
            }
        }
    }

    /// `analyzeCollection()`: Postgres and MariaDB refresh the planner
    /// statistics, SQLite records them for both tables.
    pub(crate) async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        let name = self.filter(collection);
        let schema = self.schema();
        match self.engine {
            Engine::Postgres => {
                let main = schema.analyze_table(&self.table_raw(&name)).map_err(query_error)?;
                let permissions =
                    schema.analyze_table(&self.table_raw(&storage::permissions_table(&name))).map_err(query_error)?;
                self.execute_statement(&format!("{}; {}", main.query, permissions.query), Event::CollectionUpdate)
                    .await
                    .map_err(|e| self.process_exception(e))
            }
            Engine::MariaDb | Engine::MySql => {
                let sql = schema.analyze_table(&self.table_raw(&name)).map_err(query_error)?.query;
                self.execute_statement(&sql, Event::CollectionUpdate).await
            }
            Engine::Sqlite => {
                for table in [name.clone(), storage::permissions_table(&name)] {
                    self.execute_statement(&format!("ANALYZE {}", self.table(&table)), Event::CollectionUpdate)
                        .await
                        .map_err(|e| self.process_exception(e))?;
                }
                Ok(true)
            }
        }
    }

    /// Runs two size statements built by `dialectBuilder()` and adds
    /// their first columns.
    async fn sum_of_sizes(&self, statements: [utopia_query::builder::Statement; 2]) -> Result<i64> {
        let [first, second] = statements;
        let mut collection = self.execute_result(&first, Some(Event::CollectionRead), "").await?;
        let mut permissions = self.execute_result(&second, Some(Event::CollectionRead), "").await?;
        let size = async {
            self.execute(&mut collection).await?;
            self.execute(&mut permissions).await?;
            let a = collection.statement.fetch_column(0)?;
            let b = permissions.statement.fetch_column(0)?;
            Ok::<_, Error>(numeric(a) + numeric(b))
        }
        .await;
        size.map_err(|e| Error::database(format!("Failed to get collection size: {}", e.message())))
    }

    /// `getSizeOfCollection()`
    pub(crate) async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        let collection = self.filter(collection);
        match self.engine {
            Engine::Postgres => self.postgres_size(&collection, "pg_relation_size(?)").await,
            Engine::Sqlite => self.sqlite_size(&collection).await,
            Engine::MariaDb | Engine::MySql => {
                let collection = format!("{}_{collection}", self.namespace());
                let database = self.database();
                let permissions = storage::permissions_table(&collection);
                let mut builder = self.dialect_builder();
                builder.from_none().select_raw(
                    "SUM(size) FROM (\n                    SELECT data_length + index_length AS size\n                    FROM INFORMATION_SCHEMA.TABLES\n                    WHERE table_name = ? AND\n                    table_schema = ?\n                    UNION ALL\n                    SELECT data_length + index_length AS size\n                    FROM INFORMATION_SCHEMA.TABLES\n                    WHERE table_name = ? AND\n                    table_schema = ?\n                ) AS sizes",
                    vec![
                        utopia_query::Value::from(collection.as_str()),
                        utopia_query::Value::from(database.as_str()),
                        utopia_query::Value::from(permissions.as_str()),
                        utopia_query::Value::from(database.as_str()),
                    ],
                );
                let result = builder.build().map_err(query_error)?;
                let mut statement = self.execute_result(&result, Some(Event::CollectionRead), "").await?;
                let size = async {
                    self.execute(&mut statement).await?;
                    statement.statement.fetch_column(0)
                }
                .await
                .map_err(|e| Error::database(format!("Failed to get collection size: {}", e.message())))?;
                Ok(numeric(size))
            }
        }
    }

    /// Postgres `getSizeOfCollection()` / `getSizeOfCollectionOnDisk()`.
    async fn postgres_size(&self, collection: &str, function: &str) -> Result<i64> {
        let name = self.table(collection);
        let permissions = self.table(&storage::permissions_table(collection));
        let mut builder = self.dialect_builder();
        builder.from_none().select_raw(function, vec![utopia_query::Value::from(name.as_str())]);
        let first = builder.build().map_err(query_error)?;
        builder.reset();
        builder.from_none().select_raw(function, vec![utopia_query::Value::from(permissions.as_str())]);
        let second = builder.build().map_err(query_error)?;
        self.sum_of_sizes([first, second]).await
    }

    /// SQLite `getSizeOfCollection()`: the pages of both tables and of the
    /// FTS5 tables (`dbstat`).
    async fn sqlite_size(&self, collection: &str) -> Result<i64> {
        let namespace = self.namespace();
        let name = format!("{namespace}_{collection}");
        let permissions = format!("{namespace}_{}", storage::permissions_table(collection));
        let pattern = format!(
            "{}%{}%",
            escape_like_pattern(&self.fulltext_table_prefix(collection)),
            escape_like_pattern(FTS_TABLE_SUFFIX)
        );
        let mut statement = self
            .sqlite_prepare(
                "\n             SELECT COALESCE(SUM(\"pgsize\" - \"unused\"), 0)\n             FROM \"dbstat\"\n             WHERE name = :name OR name = :perms OR name LIKE :fts_pattern ESCAPE '\\';\n        ",
                Some(Event::CollectionRead),
            )
            .await?;
        statement.bind_value(":name", string(name), ParamType::Str)?;
        statement.bind_value(":perms", string(permissions), ParamType::Str)?;
        statement.bind_value(":fts_pattern", string(pattern), ParamType::Str)?;
        let size = async {
            self.execute(&mut statement).await?;
            let result = statement.statement.fetch_column(0)?;
            let size = match result {
                Some(Value::Int(i)) => i,
                Some(Value::Str(s)) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
                    crate::value::to_long(&Value::Str(s))
                }
                _ => return Ok(Err(Error::database("Failed to get collection size: invalid database result"))),
            };
            statement.statement.close_cursor();
            Ok::<_, Error>(Ok(size))
        }
        .await
        .map_err(|e| Error::database(format!("Failed to get collection size: {}", e.message())))?;
        size
    }

    /// `getSizeOfCollectionOnDisk()`
    pub(crate) async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        let collection = self.filter(collection);
        match self.engine {
            Engine::Postgres => self.postgres_size(&collection, "pg_total_relation_size(?)").await,
            Engine::Sqlite => self.sqlite_size(&collection).await,
            Engine::MariaDb => {
                let collection = format!("{}_{collection}", self.namespace());
                let database = self.database();
                let name = format!("{database}/{collection}");
                let permissions = format!("{database}/{}", storage::permissions_table(&collection));
                let mut builder = self.dialect_builder();
                let build = |builder: &mut crate::hook::SqlBuilder, value: &str| {
                    builder.from_table("INFORMATION_SCHEMA.INNODB_SYS_TABLESPACES", "").map_err(query_error)?;
                    builder.select_raw("SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)", Vec::new());
                    builder.filter(vec![utopia_query::Query::equal("NAME", [value])]);
                    builder.build().map_err(query_error)
                };
                let first = build(&mut builder, &name)?;
                builder.reset();
                let second = build(&mut builder, &permissions)?;
                self.sum_of_sizes([first, second]).await
            }
            Engine::MySql => {
                let collection = format!("{}_{collection}", self.namespace());
                let database = self.database();
                let name = format!("{database}/{collection}");
                let permissions = format!("{database}/{}", storage::permissions_table(&collection));
                let mut first = self
                    .prepare_statement(
                        "\n             SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  \n             FROM INFORMATION_SCHEMA.INNODB_TABLESPACES\n             WHERE NAME = :name\n        ",
                        Some(Event::CollectionRead),
                    )
                    .await?;
                let mut second = self
                    .prepare_statement(
                        "\n             SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  \n             FROM INFORMATION_SCHEMA.INNODB_TABLESPACES\n             WHERE NAME = :permissions\n        ",
                        Some(Event::CollectionRead),
                    )
                    .await?;
                first.bind_value(":name", string(name), ParamType::Str)?;
                second.bind_value(":permissions", string(permissions), ParamType::Str)?;
                let size = async {
                    self.execute(&mut first).await?;
                    self.execute(&mut second).await?;
                    let a = first.statement.fetch_column(0)?;
                    let b = second.statement.fetch_column(0)?;
                    Ok::<_, Error>(numeric(a) + numeric(b))
                }
                .await;
                size.map_err(|e| Error::database(format!("Failed to get collection size: {}", e.message())))
            }
        }
    }
}
