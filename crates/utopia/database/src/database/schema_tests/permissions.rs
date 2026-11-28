//! PHP: `Hook\PermissionsTest` and `Hook\PermissionsBatchSkipTest` (engine
//! behaviour on SQLite deferred), as the statements [`Permissions`] hands a
//! recording [`WriteContext`].

use std::sync::Mutex;

use async_trait::async_trait;
use utopia_query::builder::{MySql, Statement};

use crate::change::Change;
use crate::database::testing::block_on;
use crate::document::{self, Document};
use crate::error::Result;
use crate::event::Event;
use crate::hook::{Permissions, SqlBuilder, Write, WriteContext};
use crate::permission::Permission;
use crate::role::Role;
use crate::storage;
use crate::value::{Array, ArrayKey, Str, Value};

/// A write context on MySQL builders that records the statements it runs
/// and answers reads with `rows` (PHP: the SQLite adapter's context, its
/// PDO recording statements).
#[derive(Default)]
struct Recorder {
    statements: Mutex<Vec<(Event, String, Vec<String>)>>,
    rows: Vec<Array>,
    skip: bool,
    ignore: bool,
    tenant: Option<i64>,
    run_result: Option<bool>,
}

impl Recorder {
    fn statements(&self) -> Vec<(Event, String, Vec<String>)> {
        self.statements.lock().unwrap().clone()
    }

    fn record(&self, statement: &Statement, event: Event) {
        let bindings = statement
            .bindings
            .iter()
            .map(|b| String::from_utf8_lossy(&b.to_php_string().unwrap_or_default()).into_owned())
            .collect();
        self.statements.lock().unwrap().push((event, statement.query.clone(), bindings));
    }
}

#[async_trait]
impl WriteContext for Recorder {
    fn builder(&self) -> SqlBuilder {
        SqlBuilder::MySql(MySql::new())
    }

    fn raw_table(&self, table: &str) -> String {
        format!("ns_{table}")
    }

    async fn run(&self, statement: Statement, event: Event) -> Result<bool> {
        self.record(&statement, event);
        Ok(self.run_result.unwrap_or(true))
    }

    async fn fetch(&self, statement: Statement, event: Event) -> Result<Vec<Array>> {
        self.record(&statement, event);
        Ok(self.rows.clone())
    }

    fn decorate_row(&self, mut row: Array, document: &Document) -> Array {
        if let Some(tenant) = self.tenant {
            let tenant = document.tenant().unwrap_or(Value::Int(tenant));
            row.set(ArrayKey::Str(Str::from(storage::TENANT)), tenant);
        }
        row
    }

    fn skip_permissions(&self, _document: &Document) -> bool {
        self.skip
    }

    fn ignore_duplicates(&self) -> bool {
        self.ignore
    }
}

fn document(id: &str, permissions: &[String]) -> Document {
    let mut d = Document::empty();
    d.set(document::ID, Value::Str(Str::from(id))).unwrap();
    d.set(
        document::PERMISSIONS,
        Value::Array(Array::from_list(permissions.iter().map(|p| Value::Str(Str::from(p.as_str()))).collect())),
    )
    .unwrap();
    d
}

fn row(document: &str, kind: &str, permission: &str) -> Array {
    [
        (storage::PERMISSIONS_DOCUMENT, document),
        (storage::PERMISSIONS_TYPE, kind),
        (storage::PERMISSIONS_PERMISSION, permission),
    ]
    .into_iter()
    .map(|(k, v)| (ArrayKey::Str(Str::from(k)), Value::Str(Str::from(v))))
    .collect()
}

#[test]
fn a_create_inserts_one_row_per_role() {
    block_on(async {
        let context = Recorder::default();
        let any = Role::any();
        let guests = Role::guests();
        let first = document("first", &[Permission::read(&any), Permission::update(&guests)]);
        let empty = document("second", &[]);
        Permissions::new().after_document_create("movies", &[first, empty], &context).await.unwrap();
        let statements = context.statements();
        assert_eq!(statements.len(), 1);
        let (event, query, bindings) = &statements[0];
        assert_eq!(*event, Event::PermissionsCreate);
        assert!(query.starts_with("INSERT INTO `ns_movies_perms`"), "{query}");
        assert_eq!(bindings, &["first", "read", "any", "first", "update", "guests"]);
    });
}

#[test]
fn a_create_without_permissions_runs_nothing_and_ignoring_duplicates_ignores_them() {
    block_on(async {
        let context = Recorder::default();
        Permissions::new().after_document_create("movies", &[document("x", &[])], &context).await.unwrap();
        assert!(context.statements().is_empty());

        let context = Recorder { ignore: true, ..Recorder::default() };
        let any = Role::any();
        Permissions::new()
            .after_document_create("movies", &[document("x", &[Permission::read(&any)])], &context)
            .await
            .unwrap();
        assert!(context.statements()[0].1.starts_with("INSERT IGNORE"), "{:?}", context.statements());
    });
}

#[test]
fn an_update_keeps_permission_rows_under_the_stored_document_id_casing() {
    block_on(async {
        let context = Recorder {
            rows: vec![row("caseSensitive", "create", "any"), row("caseSensitive", "read", "any")],
            ..Recorder::default()
        };
        let any = Role::any();
        let guests = Role::guests();
        let update = document(
            "CaseSensitive",
            &[
                Permission::create(&any),
                Permission::create(&guests),
                Permission::create(&guests),
                Permission::read(&guests),
            ],
        );
        Permissions::new().after_document_update("movies", "CaseSensitive", &update, &context).await.unwrap();
        let statements = context.statements();
        assert_eq!(statements.len(), 3, "{statements:?}");
        assert_eq!(statements[0].0, Event::PermissionsRead);
        assert_eq!(statements[1].0, Event::PermissionsDelete);
        assert_eq!(statements[1].2, ["caseSensitive", "read", "any"]);
        assert_eq!(statements[2].0, Event::PermissionsCreate);
        assert_eq!(statements[2].2, ["caseSensitive", "create", "guests", "caseSensitive", "read", "guests"]);
    });
}

#[test]
fn an_update_keeping_the_permissions_touches_no_rows() {
    block_on(async {
        let context = Recorder { skip: true, ..Recorder::default() };
        let any = Role::any();
        Permissions::new()
            .after_document_update("movies", "first", &document("first", &[Permission::read(&any)]), &context)
            .await
            .unwrap();
        assert!(context.statements().is_empty());

        let context = Recorder { rows: vec![row("first", "read", "any")], ..Recorder::default() };
        Permissions::new()
            .after_document_update("movies", "first", &document("first", &[Permission::read(&any)]), &context)
            .await
            .unwrap();
        assert_eq!(context.statements().len(), 1, "only the read: nothing to add or remove");
    });
}

#[test]
fn an_update_moves_permission_rows_to_the_renamed_document() {
    block_on(async {
        let context = Recorder::default();
        let alice = Role::user("alice", "");
        let after = document("after", &[Permission::read(&alice), Permission::update(&alice)]);
        Permissions::new().after_document_update("movies", "before", &after, &context).await.unwrap();
        let statements = context.statements();
        assert_eq!(statements.len(), 2);
        assert_eq!(statements[0].0, Event::PermissionsDelete);
        assert_eq!(statements[0].2, ["before"]);
        assert_eq!(statements[1].0, Event::PermissionsCreate);
        assert_eq!(statements[1].2, ["after", "read", "user:alice", "after", "update", "user:alice"]);
    });
}

#[test]
fn a_batch_update_reads_no_permissions_when_every_document_keeps_its_own() {
    block_on(async {
        let context = Recorder { skip: true, ..Recorder::default() };
        let any = Role::any();
        let updates = document("", &[Permission::read(&any)]);
        let documents = [document("first", &[]), document("second", &[])];
        Permissions::new().after_document_batch_update("movies", &updates, &documents, &context).await.unwrap();
        assert!(context.statements().is_empty());

        let context = Recorder::default();
        let mut without = Document::empty();
        without.set("title", Value::Str(Str::from("renamed"))).unwrap();
        Permissions::new().after_document_batch_update("movies", &without, &documents, &context).await.unwrap();
        assert!(context.statements().is_empty(), "an update without permissions keeps them");
    });
}

#[test]
fn a_batch_update_rewrites_only_the_changed_rows_under_the_stored_casing() {
    block_on(async {
        let context = Recorder {
            rows: vec![row("caseSensitive", "create", "any"), row("caseSensitive", "read", "any")],
            ..Recorder::default()
        };
        let any = Role::any();
        let users = Role::users("");
        let guests = Role::guests();
        let updates = document(
            "",
            &[
                Permission::create(&any),
                Permission::create(&users),
                Permission::create(&users),
                Permission::read(&guests),
            ],
        );
        Permissions::new()
            .after_document_batch_update("movies", &updates, &[document("CaseSensitive", &[])], &context)
            .await
            .unwrap();
        let statements = context.statements();
        assert_eq!(statements.len(), 3, "{statements:?}");
        assert_eq!(statements[1].0, Event::PermissionsDelete);
        assert_eq!(statements[1].2, ["caseSensitive", "read", "any"]);
        assert_eq!(statements[2].2, ["caseSensitive", "create", "users", "caseSensitive", "read", "guests"]);
    });
}

#[test]
fn an_upsert_scopes_each_removal_to_its_documents_tenant() {
    block_on(async {
        let context = Recorder { tenant: Some(3), ..Recorder::default() };
        let any = Role::any();
        let old = document("doc", &[Permission::read(&any), Permission::update(&any)]);
        let mut new = document("doc", &[Permission::read(&any)]);
        new.set(document::TENANT, Value::Int(9)).unwrap();
        Permissions::new().after_document_upsert("movies", &[Change::new(old, new)], &context).await.unwrap();
        let statements = context.statements();
        assert_eq!(statements.len(), 1, "{statements:?}");
        assert_eq!(statements[0].0, Event::PermissionsDelete);
        assert!(statements[0].1.contains("`ns_movies_perms`"), "{}", statements[0].1);
        assert_eq!(statements[0].2, ["doc", "9", "update", "any"]);
    });
}

#[test]
fn a_delete_removes_every_row_of_the_documents() {
    block_on(async {
        let context = Recorder::default();
        Permissions::new().after_document_delete("movies", &[], &context).await.unwrap();
        assert!(context.statements().is_empty());

        Permissions::new().after_document_delete("movies", &["a".to_owned(), "b".to_owned()], &context).await.unwrap();
        assert_eq!(context.statements()[0].2, ["a", "b"]);

        let failing = Recorder { run_result: Some(false), ..Recorder::default() };
        let error = Permissions::new().after_document_delete("movies", &["a".to_owned()], &failing).await.unwrap_err();
        assert_eq!(error.message(), "Failed to delete permissions");
    });
}
