//! Relationship storage.
//!
//! PHP: `createRelationship()`, `updateRelationship()`,
//! `deleteRelationship()`, `getJunctionName()` of `SQL` and `SQLite` (which
//! runs one statement per call: its driver drops all but the first of a
//! multi-statement text).

use utopia_query::schema::ColumnType;

use super::attribute::interpolate;
use super::{Engine, Sql};
use crate::error::Result;
use crate::event::Event;
use crate::query::query_error;
use crate::relationship::{self, Relationship, RelationshipSide, RelationshipType};
use crate::storage;
use crate::value::Value;

impl Sql {
    /// `ALTER TABLE ... ADD` of a nullable relationship key column.
    fn add_key_column(&self, table: &str, column: &str) -> Result<String> {
        let mut blueprint = self.schema().table(&self.table_raw(table));
        blueprint.nullable_string(column, 255);
        Ok(blueprint.alter().map_err(query_error)?.query)
    }

    /// `ALTER TABLE ... RENAME COLUMN`.
    fn rename_key_column(&self, table: &str, from: &str, to: &str) -> Result<String> {
        let mut blueprint = self.schema().table(&self.table_raw(table));
        blueprint.rename_column(from, to);
        Ok(blueprint.alter().map_err(query_error)?.query)
    }

    /// `ALTER TABLE ... DROP COLUMN`.
    fn drop_key_column(&self, table: &str, column: &str) -> Result<String> {
        let mut blueprint = self.schema().table(&self.table_raw(table));
        blueprint.drop_column(column);
        Ok(blueprint.alter().map_err(query_error)?.query)
    }

    /// Runs SQLite's statements one by one.
    async fn run_each(&self, statements: Vec<String>, event: Event) -> Result<bool> {
        for statement in statements {
            let mut prepared = self.sqlite_prepare(&statement, Some(event)).await?;
            self.execute(&mut prepared).await?;
        }
        Ok(true)
    }

    /// `createRelationship()`: the foreign key columns of a relationship.
    pub(crate) async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        let name = self.filter(collection);
        let related = self.filter(&relationship.related_collection);
        let key = self.filter(relationship.key.as_deref().unwrap_or(""));
        let two_way_key = self.filter(relationship.two_way_key.as_deref().unwrap_or(""));
        if self.engine == Engine::Sqlite {
            let table = self.table(&name);
            let related_table = self.table(&related);
            let sql_type = self.sql_type(ColumnType::Relationship, 0, false, false, false)?;
            let add = |table: &str, column: &str| format!("ALTER TABLE {table} ADD COLUMN `{column}` {sql_type} DEFAULT NULL");
            let statements = match relationship.kind {
                RelationshipType::OneToOne if relationship.two_way => {
                    vec![add(&table, &key), add(&related_table, &two_way_key)]
                }
                RelationshipType::OneToOne | RelationshipType::ManyToOne => vec![add(&table, &key)],
                RelationshipType::OneToMany => vec![add(&related_table, &two_way_key)],
                RelationshipType::ManyToMany => Vec::new(),
            };
            return self.run_each(statements, Event::AttributeCreate).await;
        }
        let sql = match relationship.kind {
            RelationshipType::OneToOne => {
                let mut sql = format!("{};", self.add_key_column(&name, &key)?);
                if relationship.two_way {
                    sql.push_str(&format!("{};", self.add_key_column(&related, &two_way_key)?));
                }
                sql
            }
            RelationshipType::OneToMany => format!("{};", self.add_key_column(&related, &two_way_key)?),
            RelationshipType::ManyToOne => format!("{};", self.add_key_column(&name, &key)?),
            RelationshipType::ManyToMany => return Ok(true),
        };
        self.execute_statement(&sql, Event::AttributeCreate).await
    }

    /// `updateRelationship()`: renames the foreign key columns of a
    /// relationship.
    pub(crate) async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        let name = self.filter(collection);
        let related = self.filter(&relationship.related_collection);
        let key = self.filter(relationship.key.as_deref().unwrap_or(""));
        let two_way_key = self.filter(relationship.two_way_key.as_deref().unwrap_or(""));
        let two_way = update.two_way.unwrap_or(relationship.two_way);
        let new_key = update.key.as_deref().map(|k| self.filter(k));
        let new_two_way_key = update.two_way_key.as_deref().map(|k| self.filter(k));
        let changes_key = new_key.as_ref().is_some_and(|k| *k != key);
        let changes_two_way_key = new_two_way_key.as_ref().is_some_and(|k| *k != two_way_key);
        let new_key = new_key.unwrap_or_default();
        let new_two_way_key = new_two_way_key.unwrap_or_default();

        // (table, from, to) of each rename, in order.
        let mut renames: Vec<(String, &str, &str)> = Vec::new();
        match relationship.kind {
            RelationshipType::OneToOne => {
                if (two_way || side == RelationshipSide::Parent) && changes_key {
                    renames.push((name.clone(), &key, &new_key));
                }
                if (two_way || side == RelationshipSide::Child) && changes_two_way_key {
                    renames.push((related.clone(), &two_way_key, &new_two_way_key));
                }
            }
            RelationshipType::OneToMany => {
                if side == RelationshipSide::Parent {
                    if changes_two_way_key {
                        renames.push((related.clone(), &two_way_key, &new_two_way_key));
                    }
                } else if changes_key {
                    renames.push((name.clone(), &key, &new_key));
                }
            }
            RelationshipType::ManyToOne => {
                if side == RelationshipSide::Child {
                    if changes_two_way_key {
                        renames.push((related.clone(), &two_way_key, &new_two_way_key));
                    }
                } else if changes_key {
                    renames.push((name.clone(), &key, &new_key));
                }
            }
            RelationshipType::ManyToMany => {
                let junction = self.junction_name(collection, &relationship.related_collection, side).await?;
                if changes_key {
                    renames.push((junction.clone(), &key, &new_key));
                }
                if changes_two_way_key {
                    renames.push((junction, &two_way_key, &new_two_way_key));
                }
            }
        }
        if self.engine == Engine::Sqlite {
            let statements = renames
                .into_iter()
                .map(|(table, from, to)| format!("ALTER TABLE {} RENAME COLUMN `{from}` TO `{to}`", self.table(&table)))
                .collect();
            return self.run_each(statements, Event::AttributeUpdate).await;
        }
        let mut sql = String::new();
        for (table, from, to) in renames {
            sql.push_str(&self.rename_key_column(&table, from, to)?);
            sql.push(';');
        }
        if sql.is_empty() {
            return Ok(true);
        }
        self.execute_statement(&sql, Event::AttributeUpdate).await
    }

    /// `deleteRelationship()`: drops the foreign key columns of a
    /// relationship, or its junction tables.
    pub(crate) async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        let name = self.filter(collection);
        let related = self.filter(&relationship.related_collection);
        let key = self.filter(relationship.key.as_deref().unwrap_or(""));
        let two_way_key = self.filter(relationship.two_way_key.as_deref().unwrap_or(""));
        let two_way = relationship.two_way;
        let parent = side == RelationshipSide::Parent;

        // (table, column) of each drop, in order.
        let drops: Vec<(&str, &str)> = match relationship.kind {
            RelationshipType::OneToOne => {
                let mut drops = if parent { vec![(name.as_str(), key.as_str())] } else { vec![(related.as_str(), two_way_key.as_str())] };
                if two_way {
                    drops.push(if parent { (related.as_str(), two_way_key.as_str()) } else { (name.as_str(), key.as_str()) });
                }
                drops
            }
            RelationshipType::OneToMany => {
                vec![if parent { (related.as_str(), two_way_key.as_str()) } else { (name.as_str(), key.as_str()) }]
            }
            RelationshipType::ManyToOne => {
                vec![if parent { (name.as_str(), key.as_str()) } else { (related.as_str(), two_way_key.as_str()) }]
            }
            RelationshipType::ManyToMany => {
                let junction = self.junction_name(collection, &relationship.related_collection, side).await?;
                let permissions = storage::permissions_table(&junction);
                if self.engine == Engine::Sqlite {
                    return self
                        .run_each(
                            vec![
                                format!("DROP TABLE {}", self.table(&junction)),
                                format!("DROP TABLE {}", self.table(&permissions)),
                            ],
                            Event::AttributeDelete,
                        )
                        .await;
                }
                let schema = self.schema();
                let main = schema.table(&self.table_raw(&junction)).drop().map_err(query_error)?;
                let perms = schema.table(&self.table_raw(&permissions)).drop().map_err(query_error)?;
                return self
                    .execute_statement(&format!("{}; {}", main.query, perms.query), Event::AttributeDelete)
                    .await;
            }
        };
        if self.engine == Engine::Sqlite {
            let statements = drops
                .into_iter()
                .map(|(table, column)| format!("ALTER TABLE {} DROP COLUMN `{column}`", self.table(table)))
                .collect();
            return self.run_each(statements, Event::AttributeDelete).await;
        }
        let mut sql = String::new();
        for (table, column) in drops {
            sql.push_str(&self.drop_key_column(table, column)?);
            sql.push(';');
        }
        self.execute_statement(&sql, Event::AttributeDelete).await
    }

    /// `getJunctionName()`: the junction collection of a many-to-many
    /// relationship, named after the parent's sequence first.
    pub(crate) async fn junction_name(
        &self,
        collection: &str,
        related_collection: &str,
        side: RelationshipSide,
    ) -> Result<String> {
        let document = self.metadata_document(collection).await?;
        let related = self.metadata_document(related_collection).await?;
        let sequence = |d: &crate::document::Document| interpolate(d.sequence().unwrap_or(&Value::Null));
        Ok(match side {
            RelationshipSide::Parent => format!("_{}_{}", sequence(&document), sequence(&related)),
            RelationshipSide::Child => format!("_{}_{}", sequence(&related), sequence(&document)),
        })
    }
}
