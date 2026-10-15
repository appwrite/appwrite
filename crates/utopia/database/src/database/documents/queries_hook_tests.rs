//! The query hooks over every read, on the Memory adapter.
//!
//! PHP: `tests/unit/Hook/QueriesTest.php`. Its cases on `HookFixture::memory()`
//! are ported as they are; its `rewrittenReads` cases run on Memory instead
//! of SQLite, but for `aggregate()`, which Memory does not run. The cases
//! that join need an engine with joins and are deferred.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use futures_util::StreamExt;
use utopia_query::CursorDirection;

use super::super::fixtures::{POSTS, any, created, definition, doc, hooks_memory, no_cache, seed};
use super::super::testing::block_on;
use super::super::{Database, Hook, METADATA};
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::event::Event;
use crate::hook::Queries;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::value::{Value, string};

type Rewrite = Box<dyn Fn(Vec<Query>) -> Result<Vec<Query>> + Send + Sync>;

/// `QueriesRecorder`: records each call's event and collection, and
/// rewrites the queries with `rewrite`.
struct Recorder {
    calls: Mutex<Vec<(Event, String)>>,
    rewrite: Option<Rewrite>,
}

impl Recorder {
    fn new() -> Arc<Self> {
        Arc::new(Recorder { calls: Mutex::new(Vec::new()), rewrite: None })
    }

    fn with(rewrite: impl Fn(Vec<Query>) -> Result<Vec<Query>> + Send + Sync + 'static) -> Arc<Self> {
        Arc::new(Recorder { calls: Mutex::new(Vec::new()), rewrite: Some(Box::new(rewrite)) })
    }

    /// `QueriesRecorder::adding()`.
    fn adding(query: Query) -> Arc<Self> {
        Recorder::with(move |mut queries| {
            queries.push(query.clone());
            Ok(queries)
        })
    }

    fn calls(&self) -> Vec<(Event, String)> {
        self.calls.lock().unwrap().clone()
    }
}

#[async_trait]
impl Queries for Recorder {
    async fn rewrite(&self, event: Event, collection: &Collection, queries: Vec<Query>) -> Result<Vec<Query>> {
        self.calls.lock().unwrap().push((event, collection.id().to_owned()));
        match &self.rewrite {
            Some(rewrite) => rewrite(queries),
            None => Ok(queries),
        }
    }
}

fn ids(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}

async fn posts() -> Database {
    let db = hooks_memory().await;
    seed(&db, &["first", "second"]).await;
    db
}

/// `joined()`'s `tickets`, without the joined collections.
async fn tickets() -> Database {
    let db = created(Arc::new(Memory::new()), no_cache(), "queries_hook", "queries_hook", true).await;
    db.authorization().add_role("any");
    db.create_collection(definition(
        "tickets",
        vec![
            Attribute::string("name", 64),
            Attribute::string("theme", 64),
            Attribute::string("status", 16),
            Attribute::integer("amount"),
        ],
        any(&["create", "read"]),
        false,
    ))
    .await
    .unwrap();
    for (id, name, theme, status, amount) in [
        ("k1", "first", "t1", "open", 1),
        ("k2", "second", "t2", "closed", 10),
        ("k3", "third", "t3", "open", 100),
        ("k4", "fourth", "missing", "closed", 1000),
    ] {
        db.create_document(
            "tickets",
            doc(vec![
                ("$id", string(id)),
                ("name", string(name)),
                ("theme", string(theme)),
                ("status", string(status)),
                ("amount", Value::Int(amount)),
            ]),
        )
        .await
        .unwrap();
    }
    db
}

fn find(tickets: &str) -> Vec<(Event, String)> {
    vec![(Event::DocumentFind, tickets.to_owned())]
}

#[test]
fn adding_a_queries_hook_runs_it_on_find() {
    block_on(async {
        let db = hooks_memory().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        db.find(POSTS, Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!(recorder.calls(), find(POSTS));
    });
}

#[test]
fn every_read_uses_the_queries_the_hook_returns() {
    block_on(async {
        let open = || Query::equal("status", ["open"]);

        let db = tickets().await;
        let recorder = Recorder::adding(Query::select(["name"]));
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let read = db.get_document("tickets", "k2", Vec::new(), false).await.unwrap();
        assert_eq!((read.get("name"), read.has("amount")), (Some(&string("second")), false));
        assert_eq!(recorder.calls(), vec![(Event::DocumentRead, "tickets".to_owned())]);

        let db = tickets().await;
        let recorder = Recorder::adding(open());
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let found = db.find("tickets", vec![Query::order_asc("$id", None)], PermissionType::Read).await.unwrap();
        assert_eq!(ids(&found), ["k1", "k3"]);
        assert_eq!(recorder.calls(), find("tickets"));

        let db = tickets().await;
        let recorder = Recorder::adding(open());
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let one = db.find_one("tickets", vec![Query::order_desc("amount", None)]).await.unwrap();
        assert_eq!(one.id(), "k3");
        assert_eq!(recorder.calls(), find("tickets"));

        let db = tickets().await;
        let recorder = Recorder::adding(open());
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        assert_eq!(db.count("tickets", Vec::new(), None).await.unwrap(), 2);
        assert_eq!(recorder.calls(), vec![(Event::DocumentCount, "tickets".to_owned())]);

        let db = tickets().await;
        let recorder = Recorder::adding(open());
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        assert_eq!(db.sum("tickets", "amount", Vec::new(), None).await.unwrap(), Value::Int(101));
        assert_eq!(recorder.calls(), vec![(Event::DocumentSum, "tickets".to_owned())]);

        let db = tickets().await;
        let recorder = Recorder::adding(open());
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let documents: Vec<Document> = db
            .cursor("tickets", vec![Query::order_asc("$id", None)], 100, PermissionType::Read)
            .await
            .unwrap()
            .map(|d| d.unwrap())
            .collect()
            .await;
        assert_eq!(ids(&documents), ["k1", "k3"]);
        assert_eq!(recorder.calls(), [find("tickets"), find("tickets")].concat());

        let db = tickets().await;
        let recorder = Recorder::adding(Query::order_desc("amount", None));
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let cursor = db.resolve_cursor("tickets", "k2", CursorDirection::After, Vec::new()).await.unwrap();
        assert_eq!(cursor.get("amount"), Some(&Value::Int(10)));
        assert_eq!(recorder.calls(), find("tickets"));
    });
}

#[test]
fn a_refusal_reaches_the_caller_before_the_read_checks_anything() {
    block_on(async {
        let db = tickets().await;
        db.add_hook(Hook::queries(Recorder::with(|_| Err(crate::error::Error::new(Kind::Runtime, "refused")))))
            .unwrap();
        let join = || vec![Query::join("unknown", "u", vec![Query::on("theme", "$id", "=")]).unwrap()];
        let errors = vec![
            db.get_document("tickets", "k1", join(), false).await.unwrap_err(),
            db.find("tickets", join(), PermissionType::Read).await.unwrap_err(),
            db.find_one("tickets", join()).await.unwrap_err(),
            db.count("tickets", join(), None).await.unwrap_err(),
            db.sum("tickets", "amount", join(), None).await.unwrap_err(),
            db.aggregate("tickets", [join(), vec![Query::count("*", "total")]].concat()).await.unwrap_err(),
            db.cursor("tickets", join(), 100, PermissionType::Read).await.err().unwrap(),
            db.resolve_cursor("tickets", "k1", CursorDirection::After, join()).await.unwrap_err(),
        ];
        for error in errors {
            assert_eq!((error.kind(), error.message()), (Kind::Runtime, "refused"));
        }
    });
}

#[test]
fn find_one_runs_the_hook_once() {
    block_on(async {
        let db = posts().await;
        let recorder = Recorder::adding(Query::equal("title", ["second"]));
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        assert_eq!(db.find_one(POSTS, Vec::new()).await.unwrap().id(), "second");
        assert_eq!(recorder.calls(), find(POSTS));
    });
}

#[test]
fn hooks_run_in_registration_order_each_given_the_previous_output() {
    block_on(async {
        let db = posts().await;
        let order = Arc::new(Mutex::new(Vec::new()));
        let given = Arc::new(Mutex::new(Vec::new()));
        let output = Arc::new(Mutex::new(Vec::new()));
        let (o, out) = (order.clone(), output.clone());
        let first = Recorder::with(move |mut queries| {
            o.lock().unwrap().push("first");
            queries.push(Query::equal("title", ["first"]));
            *out.lock().unwrap() = queries.clone();
            Ok(queries)
        });
        let (o, g) = (order.clone(), given.clone());
        let second = Recorder::with(move |queries| {
            o.lock().unwrap().push("second");
            *g.lock().unwrap() = queries.clone();
            Ok(queries)
        });
        db.add_hook(Hook::queries(first)).unwrap();
        db.add_hook(Hook::queries(second)).unwrap();
        assert_eq!(ids(&db.find(POSTS, Vec::new(), PermissionType::Read).await.unwrap()), ["first"]);
        assert_eq!(*order.lock().unwrap(), ["first", "second"]);
        assert!(!given.lock().unwrap().is_empty());
        assert_eq!(*given.lock().unwrap(), *output.lock().unwrap());
    });
}

#[test]
fn the_hook_is_never_given_a_read_of_the_metadata_collection() {
    block_on(async {
        let db = posts().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        db.get_collection(POSTS).await.unwrap();
        db.list_collections(25, 0).await.unwrap();
        db.get_document(METADATA, POSTS, Vec::new(), false).await.unwrap();
        db.find(METADATA, Vec::new(), PermissionType::Read).await.unwrap();
        db.count(METADATA, Vec::new(), None).await.unwrap();
        db.get_document(POSTS, "first", Vec::new(), false).await.unwrap();
        assert_eq!(recorder.calls(), vec![(Event::DocumentRead, POSTS.to_owned())]);
    });
}

#[test]
fn writes_do_not_run_the_hook() {
    block_on(async {
        let db = posts().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let updated = db
            .update_documents(
                POSTS,
                doc(vec![("views", Value::Int(9))]),
                vec![Query::equal("title", ["first"])],
                super::super::BATCH_SIZE,
                None,
            )
            .await
            .unwrap();
        assert_eq!(updated, 1);
        let deleted = db
            .delete_documents(POSTS, vec![Query::equal("title", ["second"])], super::super::BATCH_SIZE, None)
            .await
            .unwrap();
        assert_eq!(deleted, 1);
        assert!(recorder.calls().is_empty());

        let db = posts().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let increased = db.increase_document_attribute(POSTS, "first", "views", Value::Int(5), None).await.unwrap();
        let deleted = db.delete_document(POSTS, "second").await.unwrap();
        assert!(recorder.calls().is_empty());
        assert_eq!(increased.get("views"), Some(&Value::Int(6)));
        assert!(deleted);
    });
}

#[test]
fn a_select_the_hook_adds_to_get_document_leaves_the_cached_document() {
    block_on(async {
        let db = super::super::fixtures::hooks(Arc::new(Memory::new()), super::super::fixtures::memory_cache()).await;
        seed(&db, &["first"]).await;
        assert_eq!(
            db.get_document(POSTS, "first", Vec::new(), false).await.unwrap().get("views"),
            Some(&Value::Int(1))
        );

        let selecting = Hook::queries(Recorder::adding(Query::select(["title"])));
        db.add_hook(selecting.clone()).unwrap();
        let selected = db.get_document(POSTS, "first", Vec::new(), false).await.unwrap();
        db.remove_hook(&selecting);

        assert_eq!(selected.get("title"), Some(&string("first")));
        assert!(!selected.has("views"));
        assert_eq!(
            db.get_document(POSTS, "first", Vec::new(), false).await.unwrap().get("views"),
            Some(&Value::Int(1))
        );
    });
}

#[test]
fn the_hook_runs_while_events_are_silenced() {
    block_on(async {
        let db = posts().await;
        let recorder = Recorder::adding(Query::equal("title", ["second"]));
        db.add_hook(Hook::queries(recorder.clone())).unwrap();
        let documents = db.silent(db.find(POSTS, Vec::new(), PermissionType::Read)).await.unwrap();
        assert_eq!(ids(&documents), ["second"]);
        assert_eq!(recorder.calls(), find(POSTS));
    });
}

#[test]
fn a_cursor_without_hooks_over_a_missing_collection_fails_once_read() {
    block_on(async {
        let db = hooks_memory().await;
        let mut documents = db.cursor("missing", Vec::new(), 100, PermissionType::Read).await.unwrap();
        let err = documents.next().await.unwrap().unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::NotFound, "Collection not found"));
    });
}
