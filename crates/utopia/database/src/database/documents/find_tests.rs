//! The reads' tests on the stub adapter.
//!
//! PHP: `tests/unit/Documents/FindLogicTest.php`,
//! `Documents/AggregationErrorTest.php` and the stub-backed cases of the
//! join and cursor tests.

use std::sync::{Arc, Mutex};

use futures_util::StreamExt;
use utopia_query::{CursorDirection, Method, OrderDirection};

use super::super::METADATA;
use super::super::testing::{Call, Reply, StubAdapter, block_on, uncached};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Kind;
use crate::index::Index;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::value::{Array, Value, string};

type Extra = Box<dyn Fn(&Call) -> Option<Reply> + Send + Sync>;

/// `collectionDoc()`.
fn collection(
    id: &str,
    attributes: Vec<Attribute>,
    indexes: Vec<Index>,
    permissions: Option<Vec<String>>,
    document_security: bool,
) -> Collection {
    let any = Role::any();
    let permissions = permissions.unwrap_or_else(|| {
        vec![Permission::read(&any), Permission::create(&any), Permission::update(&any), Permission::delete(&any)]
    });
    Collection::create(id, id, attributes, indexes, Some(permissions), document_security, Array::new()).unwrap()
}

fn plain(id: &str) -> Collection {
    collection(id, Vec::new(), Vec::new(), None, true)
}

/// `buildDbWithCapabilities()`'s `testCol`.
fn test_col() -> Collection {
    let any = Role::any();
    collection(
        "testCol",
        vec![Attribute::string("status", 64)],
        Vec::new(),
        Some(vec![Permission::read(&any), Permission::create(&any)]),
        true,
    )
}

/// A stub answering collection lookups from `collections`, every other call
/// through `extra`.
fn stub(collections: Vec<Collection>, capabilities: &[Capability], extra: Extra) -> Arc<StubAdapter> {
    let adapter = StubAdapter::new();
    adapter.set_capabilities(capabilities);
    adapter.on(move |call| {
        if let Some(reply) = extra(call) {
            return Some(reply);
        }
        match call {
            Call::GetDocument { collection, id, .. } if collection.id() == METADATA => {
                if id == METADATA {
                    return Some(Reply::Document(super::super::collection_definition().into()));
                }
                Some(Reply::Document(
                    collections.iter().find(|c| c.id() == id).map(|c| c.document().clone()).unwrap_or_default(),
                ))
            }
            _ => None,
        }
    });
    adapter
}

const BASE: [Capability; 5] = [
    Capability::IndexKey,
    Capability::IndexArray,
    Capability::IndexUnique,
    Capability::DefinedAttributes,
    Capability::Vectors,
];

fn with(extra: &[Capability]) -> Vec<Capability> {
    let mut all = BASE[..4].to_vec();
    all.extend_from_slice(extra);
    all
}

fn none() -> Extra {
    Box::new(|_| None)
}

fn finds(adapter: &StubAdapter) -> Vec<(Collection, Vec<Query>, crate::adapter::Find)> {
    adapter
        .calls_of("find")
        .into_iter()
        .map(|c| match c {
            Call::Find { collection, queries, options } => (collection, queries, options),
            _ => unreachable!(),
        })
        .collect()
}

fn doc(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).unwrap()
}

fn join_other() -> Query {
    Query::join("other", "j0", vec![Query::on("fk", "$id", "=")]).unwrap()
}

#[test]
fn find_with_empty_queries_returns_adapter_results() {
    block_on(async {
        let adapter = stub(
            vec![plain("testCol")],
            &BASE,
            Box::new(|c| {
                matches!(c, Call::Find { .. })
                    .then(|| Reply::Documents(vec![doc(vec![("$id", string("doc1")), ("name", string("test"))])]))
            }),
        );
        let db = uncached(adapter);
        let results = db.find("testCol", Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].id(), "doc1");
        assert_eq!(results[0].collection(), "testCol");
    });
}

#[test]
fn find_count_and_sum_fail_for_a_missing_collection() {
    block_on(async {
        let db = uncached(stub(Vec::new(), &BASE, none()));
        let err = db.find("nonexistent", Vec::new(), PermissionType::Read).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::NotFound, "Collection not found"));
        let err = db.count("nonexistent", Vec::new(), None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::NotFound, "Collection not found"));
        let err = db.sum("nonexistent", "amount", Vec::new(), None).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::NotFound, "Collection not found"));
    });
}

#[test]
fn find_validates_queries() {
    block_on(async {
        let db = uncached(stub(vec![plain("testCol")], &BASE, none()));
        db.set_validation(true);
        let err = db
            .find("testCol", vec![Query::equal("nonexistent_attr", ["val"])], PermissionType::Read)
            .await
            .unwrap_err();
        assert_eq!(err.kind(), Kind::Query);
        let err = db.sum("testCol", "amount", vec![Query::equal("unknown_field", ["val"])], None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Query);

        let results = db
            .skip_validation(db.find("testCol", vec![Query::equal("nonexistent", ["val"])], PermissionType::Read))
            .await
            .unwrap();
        assert!(results.is_empty());
    });
}

#[test]
fn find_passes_limit_offset_and_tie_breaks() {
    block_on(async {
        let attributes = vec![Attribute::string("name", 128), Attribute::integer("age")];
        let adapter = stub(vec![collection("testCol", attributes, Vec::new(), None, true)], &BASE, none());
        let db = uncached(adapter.clone());

        db.find("testCol", Vec::new(), PermissionType::Read).await.unwrap();
        db.find("testCol", vec![Query::limit(10)], PermissionType::Read).await.unwrap();
        db.find("testCol", vec![Query::offset(5)], PermissionType::Read).await.unwrap();
        db.find("testCol", vec![Query::order_asc("$id", None)], PermissionType::Read).await.unwrap();
        db.find("testCol", vec![Query::order_asc("$sequence", None)], PermissionType::Read).await.unwrap();
        db.find(
            "testCol",
            vec![Query::order_desc("$createdAt", None), Query::order_asc("$updatedAt", None)],
            PermissionType::Read,
        )
        .await
        .unwrap();
        db.find("testCol", vec![Query::order_asc("name", None), Query::order_desc("age", None)], PermissionType::Read)
            .await
            .unwrap();

        let calls = finds(&adapter);
        assert_eq!(calls[0].2.limit, Some(25));
        assert_eq!(calls[0].2.offset, Some(0));
        assert_eq!(calls[0].2.order_attributes, ["$sequence"]);
        assert_eq!(calls[1].2.limit, Some(10));
        assert_eq!(calls[2].2.offset, Some(5));
        assert_eq!(calls[3].2.order_attributes, ["$id"]);
        assert_eq!(calls[4].2.order_attributes, ["$sequence"]);
        assert_eq!(calls[5].2.order_attributes, ["$createdAt", "$updatedAt", "$sequence"]);
        assert_eq!(calls[5].2.order_types, [OrderDirection::Desc, OrderDirection::Asc, OrderDirection::Desc]);
        assert_eq!(calls[6].2.order_attributes, ["name", "age", "$sequence"]);
        assert!(calls.iter().all(|c| c.2.cursor_direction == CursorDirection::After));
    });
}

#[test]
fn vector_find_orders_by_distance_alone_without_a_cursor() {
    block_on(async {
        let attributes = vec![Attribute::vector("embedding", 2)];
        let adapter = stub(vec![collection("testCol", attributes, Vec::new(), None, true)], &BASE, none());
        let db = uncached(adapter.clone());
        let vector = || utopia_query::list([0.1f64, 0.2]);

        db.find("testCol", vec![Query::vector_cosine("embedding", vector())], PermissionType::Read).await.unwrap();
        let cursor = doc(vec![
            ("$id", string("page")),
            ("$sequence", Value::Int(17)),
            ("$collection", string("testCol")),
            ("$distance", Value::Float(0.25)),
        ]);
        db.find(
            "testCol",
            vec![Query::vector_cosine("embedding", vector()), Query::cursor_after(&cursor)],
            PermissionType::Read,
        )
        .await
        .unwrap();

        let calls = finds(&adapter);
        assert!(calls[0].2.order_attributes.is_empty());
        assert!(calls[0].2.order_types.is_empty());
        assert_eq!(calls[1].2.order_attributes, ["$sequence"]);
        assert_eq!(calls[1].2.order_types, [OrderDirection::Asc]);
    });
}

#[test]
fn find_checks_the_cursor() {
    block_on(async {
        let attributes = vec![Attribute::string("name", 128), Attribute::integer("age")];
        let db = uncached(stub(vec![collection("testCol", attributes, Vec::new(), None, true)], &BASE, none()));

        let cursor =
            doc(vec![("$id", string("cursor1")), ("$collection", string("testCol")), ("name", string("test"))]);
        let err = db
            .skip_validation(db.find(
                "testCol",
                vec![Query::order_asc("name", None), Query::order_asc("age", None), Query::cursor_after(&cursor)],
                PermissionType::Read,
            ))
            .await
            .unwrap_err();
        assert_eq!(err.kind(), Kind::Order);
        assert_eq!(err.message(), "Order attribute 'age' is empty");
        assert_eq!(err.attribute(), Some("age"));

        let other = doc(vec![
            ("$id", string("cursor1")),
            ("$collection", string("otherCollection")),
            ("$sequence", string("1")),
        ]);
        let err = db.find("testCol", vec![Query::cursor_after(&other)], PermissionType::Read).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "cursor Document must be from the same Collection."));
    });
}

#[test]
fn find_encodes_the_cursor_and_passes_its_direction() {
    block_on(async {
        let adapter = stub(vec![plain("testCol")], &BASE, none());
        let db = uncached(adapter.clone());
        let cursor = doc(vec![("$id", string("c1")), ("$collection", string("testCol")), ("$sequence", string("100"))]);
        db.find("testCol", vec![Query::cursor_after(&cursor)], PermissionType::Read).await.unwrap();
        db.find("testCol", vec![Query::cursor_before(&cursor)], PermissionType::Read).await.unwrap();
        let calls = finds(&adapter);
        assert!(!calls[0].2.cursor.is_empty());
        assert_eq!(calls[0].2.cursor_direction, CursorDirection::After);
        assert_eq!(calls[1].2.cursor_direction, CursorDirection::Before);
    });
}

#[test]
fn find_passes_queries_to_the_adapter() {
    block_on(async {
        let attributes = vec![Attribute::string("status", 64)];
        let indexes = vec![Index::key("idx_status", ["status"], Vec::new(), Vec::new()).unwrap()];
        let adapter = stub(vec![collection("testCol", attributes, indexes, None, true)], &BASE, none());
        let db = uncached(adapter.clone());
        db.find("testCol", vec![Query::equal("status", ["active"])], PermissionType::Read).await.unwrap();
        assert!(finds(&adapter)[0].1.iter().any(|q| q.attribute() == "status"));
    });
}

#[test]
fn find_decodes_documents_and_sets_their_collection() {
    block_on(async {
        let attributes = vec![Attribute::string("name", 128), Attribute::integer("age")];
        let raw = doc(vec![
            ("$id", string("doc1")),
            ("$collection", string("testCol")),
            ("$createdAt", string("2024-01-01T00:00:00.000+00:00")),
            ("$updatedAt", string("2024-01-01T00:00:00.000+00:00")),
            ("name", string("Alice")),
            ("age", Value::Int(30)),
        ]);
        let adapter = stub(
            vec![collection("testCol", attributes, Vec::new(), None, true)],
            &BASE,
            Box::new(move |c| matches!(c, Call::Find { .. }).then(|| Reply::Documents(vec![raw.clone()]))),
        );
        let db = uncached(adapter);
        let results = db.find("testCol", Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].collection(), "testCol");
        let results = db.find("testCol", vec![Query::select(["name"])], PermissionType::Read).await.unwrap();
        assert_eq!(results.len(), 1);
    });
}

#[test]
fn aggregation_needs_the_capability_and_find_refuses_it() {
    block_on(async {
        let db = uncached(stub(vec![plain("testCol")], &BASE, none()));
        let err = db.skip_validation(db.aggregate("testCol", vec![Query::count("*", "cnt")])).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Query);
        assert!(err.message().contains("Aggregation queries are not supported"));

        let err = db
            .skip_validation(db.find("testCol", vec![Query::count("*", "cnt")], PermissionType::Read))
            .await
            .unwrap_err();
        assert_eq!(err.message(), "find() does not run aggregate or groupBy queries: use aggregate()");

        let err = db.skip_validation(db.find("testCol", vec![join_other()], PermissionType::Read)).await.unwrap_err();
        assert!(err.message().contains("Join queries are not supported"));

        let err = db.skip_validation(db.get_document("testCol", "doc1", vec![join_other()], false)).await.unwrap_err();
        assert_eq!(err.message(), "Join queries are not supported by this adapter");
    });
}

#[test]
fn aggregate_refuses_a_cursor() {
    block_on(async {
        let db = uncached(stub(vec![test_col()], &with(&[Capability::Aggregations]), none()));
        let cursor = doc(vec![("$id", string("c1")), ("$collection", string("testCol")), ("$sequence", string("100"))]);
        let err = db
            .skip_validation(db.aggregate("testCol", vec![Query::count("*", "cnt"), Query::cursor_after(&cursor)]))
            .await
            .unwrap_err();
        assert_eq!(err.message(), "Cursor pagination is not supported with aggregation queries");
    });
}

#[test]
fn aggregate_groups_and_returns_rows_as_arrays() {
    block_on(async {
        let adapter = stub(
            vec![test_col()],
            &with(&[Capability::Aggregations]),
            Box::new(|c| {
                matches!(c, Call::Find { .. })
                    .then(|| Reply::Documents(vec![doc(vec![("status", string("active")), ("cnt", Value::Int(5))])]))
            }),
        );
        let db = uncached(adapter.clone());
        let rows = db
            .skip_validation(db.aggregate("testCol", vec![Query::group_by(["status"]), Query::count("*", "cnt")]))
            .await
            .unwrap();
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].get_str("status"), Some(&string("active")));
        assert_eq!(rows[0].get_str("cnt"), Some(&Value::Int(5)));
        assert_eq!(rows[0].len(), 2);
        let calls = finds(&adapter);
        assert_eq!(calls.len(), 1);
        assert!(calls[0].1.iter().any(|q| q.method() == Method::GroupBy));
    });
}

#[test]
fn distinct_reaches_the_adapter_without_a_sequence_order() {
    block_on(async {
        let adapter = stub(vec![test_col()], &with(&[Capability::Aggregations]), none());
        let db = uncached(adapter.clone());
        db.skip_validation(db.find("testCol", vec![Query::distinct()], PermissionType::Read)).await.unwrap();
        let calls = finds(&adapter);
        assert!(calls[0].1.iter().any(|q| q.method() == Method::Distinct));
        assert!(!calls[0].2.order_attributes.iter().any(|a| a == "$sequence"));
    });
}

/// The authorization status each adapter read ran under.
fn recording(
    statuses: Arc<Mutex<Vec<bool>>>,
    auth: Arc<Mutex<Option<Arc<crate::validator::authorization::Authorization>>>>,
) -> Extra {
    Box::new(move |c| {
        if matches!(c, Call::Find { .. } | Call::Count { .. } | Call::Sum { .. })
            || matches!(c, Call::GetDocument { collection, .. } if collection.id() != METADATA)
        {
            if let Some(a) = auth.lock().unwrap().as_ref() {
                statuses.lock().unwrap().push(a.status());
            }
        }
        None
    })
}

#[test]
fn reads_skip_authorization_only_when_the_collection_grants_read_without_joins() {
    block_on(async {
        let statuses = Arc::new(Mutex::new(Vec::new()));
        let auth = Arc::new(Mutex::new(None));
        let any = Role::any();
        let unreadable = collection(
            "unreadable",
            Vec::new(),
            Vec::new(),
            Some(vec![Permission::create(&any), Permission::update(&any), Permission::delete(&any)]),
            true,
        );
        let adapter =
            stub(vec![plain("testCol"), unreadable, plain("other")], &BASE, recording(statuses.clone(), auth.clone()));
        let db = uncached(adapter);
        *auth.lock().unwrap() = Some(db.authorization());

        db.skip_validation(db.find("testCol", Vec::new(), PermissionType::Read)).await.unwrap();
        db.skip_validation(db.find("unreadable", Vec::new(), PermissionType::Read)).await.unwrap();
        assert_eq!(*statuses.lock().unwrap(), [false, true]);
    });
}

#[test]
fn joined_reads_keep_authorization_enabled() {
    block_on(async {
        let statuses = Arc::new(Mutex::new(Vec::new()));
        let auth = Arc::new(Mutex::new(None));
        let adapter = stub(
            vec![test_col(), plain("other")],
            &with(&[Capability::Joins]),
            recording(statuses.clone(), auth.clone()),
        );
        let db = uncached(adapter.clone());
        *auth.lock().unwrap() = Some(db.authorization());

        db.skip_validation(db.find("testCol", vec![join_other()], PermissionType::Read)).await.unwrap();
        db.skip_validation(db.count("testCol", vec![join_other(), Query::equal("status", ["ok"])], None))
            .await
            .unwrap();
        db.skip_validation(db.sum("testCol", "status", vec![join_other()], None)).await.unwrap();
        db.skip_validation(db.get_document("testCol", "doc1", vec![join_other()], false)).await.unwrap();
        assert_eq!(*statuses.lock().unwrap(), [true, true, true, true]);

        let count = adapter.calls_of("count");
        let Call::Count { queries, .. } = &count[0] else { unreachable!() };
        assert!(queries.iter().any(|q| q.method().is_join()));
    });
}

#[test]
fn find_stamps_join_document_security_for_physical_ids() {
    block_on(async {
        let stored = collection("jp_public", Vec::new(), Vec::new(), None, false).document().clone();
        let main = collection("database_1_collection_1", Vec::new(), Vec::new(), None, false);
        let adapter = StubAdapter::new();
        adapter.set_capabilities(&with(&[Capability::Joins]));
        let captured: Arc<Mutex<Option<Value>>> = Arc::new(Mutex::new(None));
        let sink = captured.clone();
        adapter.on(move |call| match call {
            Call::GetDocument { collection, id, .. } if collection.id() == METADATA => match id.as_str() {
                "database_1_collection_1" => Some(Reply::Document(main.document().clone())),
                "database_1_collection_2" => Some(Reply::Document(stored.clone())),
                _ => Some(Reply::Document(Document::empty())),
            },
            Call::Find { collection, .. } => {
                *sink.lock().unwrap() = collection.get("joinDocumentSecurity").cloned();
                Some(Reply::Documents(Vec::new()))
            }
            _ => None,
        });
        let db = uncached(adapter);
        db.skip_validation(db.find(
            "database_1_collection_1",
            vec![Query::left_join("database_1_collection_2", "rev", vec![Query::on("$id", "mainId", "=")]).unwrap()],
            PermissionType::Read,
        ))
        .await
        .unwrap();
        let captured = captured.lock().unwrap().clone().expect("the join's document security");
        let Value::Array(map) = captured else { panic!("an array") };
        assert_eq!(map.get_str("database_1_collection_2"), Some(&Value::Bool(false)));
        assert_eq!(map.get_str("jp_public"), Some(&Value::Bool(false)));
    });
}

#[test]
fn count_and_sum_delegate_to_the_adapter() {
    block_on(async {
        let attributes = vec![Attribute::double("amount")];
        let adapter = stub(
            vec![collection("testCol", attributes, Vec::new(), None, true)],
            &BASE,
            Box::new(|c| match c {
                Call::Count { .. } => Some(Reply::Int(42)),
                Call::Sum { .. } => Some(Reply::Value(Value::Float(150.5))),
                _ => None,
            }),
        );
        let db = uncached(adapter.clone());
        assert_eq!(db.count("testCol", Vec::new(), None).await.unwrap(), 42);
        assert_eq!(db.sum("testCol", "amount", Vec::new(), None).await.unwrap(), Value::Float(150.5));
        assert_eq!(adapter.calls_of("count").len(), 1);
        assert_eq!(adapter.calls_of("sum").len(), 1);
        let err = db.count("testCol", Vec::new(), Some(0)).await.unwrap_err();
        assert_eq!(err.message(), "Max must be greater than 0");
    });
}

fn paged(results: Vec<Vec<Document>>) -> Extra {
    let calls = Mutex::new(0usize);
    Box::new(move |c| {
        if !matches!(c, Call::Find { .. }) {
            return None;
        }
        let mut n = calls.lock().unwrap();
        let page = results.get(*n).cloned().unwrap_or_default();
        *n += 1;
        Some(Reply::Documents(page))
    })
}

fn row(id: &str, sequence: &str) -> Document {
    doc(vec![("$id", string(id)), ("$collection", string("testCol")), ("$sequence", string(sequence))])
}

#[test]
fn cursor_yields_documents_from_batches() {
    block_on(async {
        let adapter = stub(
            vec![plain("testCol")],
            &BASE,
            paged(vec![vec![row("d1", "1"), row("d2", "2")], vec![row("d3", "3")]]),
        );
        let db = uncached(adapter.clone());
        let documents: Vec<Document> = db
            .cursor("testCol", Vec::new(), 2, PermissionType::Read)
            .await
            .unwrap()
            .map(|d| d.unwrap())
            .collect()
            .await;
        assert_eq!(documents.iter().map(|d| d.id()).collect::<Vec<_>>(), ["d1", "d2", "d3"]);
        let calls = finds(&adapter);
        assert_eq!(calls.len(), 2);
        assert_eq!(calls[1].2.limit, Some(2));
        assert_eq!(calls[1].2.cursor.get_str("$id"), Some(&string("d2")));
    });
}

#[test]
fn cursor_stops_on_an_empty_or_short_batch() {
    block_on(async {
        let adapter = stub(vec![plain("testCol")], &BASE, paged(Vec::new()));
        let db = uncached(adapter.clone());
        let stream = db.cursor("testCol", Vec::new(), 10, PermissionType::Read).await.unwrap();
        assert_eq!(stream.count().await, 0);

        let adapter = stub(vec![plain("testCol")], &BASE, paged(vec![vec![row("d1", "1")]]));
        let db = uncached(adapter.clone());
        let stream = db.cursor("testCol", Vec::new(), 5, PermissionType::Read).await.unwrap();
        assert_eq!(stream.count().await, 1);
        assert_eq!(adapter.calls_of("find").len(), 1);
    });
}

#[test]
fn cursor_honours_the_limit_and_refuses_cursor_before_and_large_batches() {
    block_on(async {
        let adapter = stub(
            vec![plain("testCol")],
            &BASE,
            paged(vec![vec![row("d1", "1"), row("d2", "2")], vec![row("d3", "3")]]),
        );
        let db = uncached(adapter.clone());
        let stream = db.cursor("testCol", vec![Query::limit(3)], 2, PermissionType::Read).await.unwrap();
        assert_eq!(stream.count().await, 3);
        let calls = finds(&adapter);
        assert_eq!(calls[0].2.limit, Some(2));
        assert_eq!(calls[1].2.limit, Some(1));

        let cursor = row("d1", "1");
        let err =
            db.cursor("testCol", vec![Query::cursor_before(&cursor)], 2, PermissionType::Read).await.err().unwrap();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "Cursor before not supported in this method."));
        let err = db.cursor("testCol", Vec::new(), 1001, PermissionType::Read).await.err().unwrap();
        assert_eq!((err.kind(), err.message()), (Kind::Limit, "Batch size must be at most 1000, got 1001"));
    });
}

#[test]
fn find_authorization() {
    block_on(async {
        let restricted = collection(
            "restricted",
            Vec::new(),
            Vec::new(),
            Some(vec![Permission::read(&Role::user("admin", ""))]),
            false,
        );
        let doc_sec =
            collection("docSec", Vec::new(), Vec::new(), Some(vec![Permission::read(&Role::user("admin", ""))]), true);
        let adapter = stub(vec![restricted, doc_sec], &BASE, none());
        let db = uncached(adapter);
        db.authorization().clean_roles();
        let err = db.find("restricted", Vec::new(), PermissionType::Read).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Authorization);
        assert!(db.find("docSec", Vec::new(), PermissionType::Read).await.unwrap().is_empty());
    });
}

#[test]
fn find_one_returns_the_first_match_or_an_empty_document() {
    block_on(async {
        let adapter = stub(vec![plain("testCol")], &BASE, paged(vec![vec![row("d1", "1")]]));
        let db = uncached(adapter.clone());
        assert_eq!(db.find_one("testCol", Vec::new()).await.unwrap().id(), "d1");
        assert_eq!(finds(&adapter)[0].2.limit, Some(1));
        assert!(db.find_one("testCol", Vec::new()).await.unwrap().is_empty());
    });
}

#[test]
fn aggregate_aliases_and_their_errors() {
    let queries = super::find::alias_aggregates(vec![
        Query::count("*", ""),
        Query::sum("price", ""),
        Query::avg("a-b", ""),
        Query::max("x", "top"),
    ])
    .unwrap();
    let aliases: Vec<&str> = queries.iter().map(|q| q.alias()).collect();
    assert_eq!(aliases, ["count", "sum_price", "avg_a_b", "top"]);

    let err = super::find::alias_aggregates(vec![Query::count("*", "n"), Query::sum("a", "n")]).unwrap_err();
    assert_eq!(err.message(), "Invalid query: Aggregate alias \"n\" is given to more than one aggregate");
    let err = super::find::alias_aggregates(vec![Query::count("*", "count"), Query::count("", "")]).unwrap_err();
    assert_eq!(
        err.message(),
        "The default aggregate alias 'count' is used by more than one aggregate; give each of them an explicit alias"
    );
}

#[test]
fn refetch_reads_documents_again_by_sequence() {
    block_on(async {
        let adapter = stub(
            vec![plain("testCol")],
            &BASE,
            Box::new(|c| {
                matches!(c, Call::Find { .. }).then(|| {
                    Reply::Documents(vec![doc(vec![
                        ("$id", string("d2")),
                        ("$sequence", Value::Int(2)),
                        ("fresh", Value::Bool(true)),
                    ])])
                })
            }),
        );
        let db = uncached(adapter.clone());
        let definition = db.get_collection("testCol").await.unwrap();
        let documents = vec![row("d1", "1"), row("d2", "2")];
        let refetched = db.skip_validation(db.refetch_documents(&definition, documents, Vec::new())).await.unwrap();
        assert_eq!(refetched[0].get("fresh"), None);
        assert_eq!(refetched[1].get("fresh"), Some(&Value::Bool(true)));

        let err =
            db.refetch_documents(&definition, vec![doc(vec![("$id", string("x"))])], Vec::new()).await.unwrap_err();
        assert_eq!(err.message(), "Cannot refetch document without a $sequence: x");
    });
}

#[test]
fn select_filters_keep_selected_and_internal_attributes() {
    let db = uncached(StubAdapter::new());
    let mut documents = vec![doc(vec![
        ("$id", string("d")),
        ("name", string("n")),
        ("age", Value::Int(1)),
        ("$custom", Value::Int(2)),
    ])];
    db.apply_select_filters_to_documents(&mut documents, &[Query::select(["name"])]);
    let keys: Vec<String> = documents[0].keys().map(|k| super::super::filters::key_text(&k)).collect();
    assert_eq!(keys, ["$id", "name", "$custom"]);

    let mut documents = vec![doc(vec![("name", string("n")), ("age", Value::Int(1))])];
    db.apply_select_filters_to_documents(&mut documents, &[Query::select(["*"])]);
    assert_eq!(documents[0].len(), 2);
}

#[test]
fn resolve_cursor_carries_the_order_values() {
    block_on(async {
        let main = collection("posts", vec![Attribute::string("title", 64)], Vec::new(), None, true);
        let joined = collection("authors", vec![Attribute::integer("rank")], Vec::new(), None, true);
        let adapter = StubAdapter::new();
        adapter.set_capabilities(&with(&[Capability::Joins]));
        adapter.on(move |call| match call {
            Call::GetDocument { collection, id, .. } if collection.id() == METADATA => Some(Reply::Document(
                [&main, &joined].into_iter().find(|c| c.id() == id).map(|c| c.document().clone()).unwrap_or_default(),
            )),
            Call::GetDocument { id, .. } if id == "p1" => Some(Reply::Document(doc(vec![
                ("$id", string("p1")),
                ("$sequence", Value::Int(7)),
                ("title", string("Hello")),
                ("author", string("a1")),
            ]))),
            Call::Find { collection, .. } if collection.id() == "authors" => {
                Some(Reply::Documents(vec![doc(vec![("$id", string("a1")), ("rank", Value::Int(4))])]))
            }
            _ => None,
        });
        let db = uncached(adapter.clone());

        let cursor = db
            .resolve_cursor("posts", "p1", CursorDirection::After, vec![Query::order_desc("title", None)])
            .await
            .unwrap();
        assert_eq!(cursor.id(), "p1");
        assert_eq!(cursor.get("$sequence"), Some(&string("7")), "the tie break takes the read value");
        assert_eq!(cursor.collection(), "posts");
        assert_eq!(cursor.get("title"), Some(&string("Hello")));
        assert!(db.resolve_cursor("posts", "missing", CursorDirection::After, Vec::new()).await.unwrap().is_empty());

        let joins = vec![
            Query::join("authors", "a", vec![Query::on("author", "a.$id", "=")]).unwrap(),
            Query::order_asc("a.rank", None),
        ];
        let cursor =
            db.skip_validation(db.resolve_cursor("posts", "p1", CursorDirection::Before, joins)).await.unwrap();
        assert_eq!(cursor.get("a.rank"), Some(&Value::Int(4)));
        let calls = finds(&adapter);
        let (_, queries, options) = calls.last().unwrap();
        assert_eq!(options.limit, Some(1));
        assert!(queries.iter().any(|q| q.method() == Method::Equal && q.attribute() == "$id"));
        assert_eq!(options.order_attributes[0], "rank");
        assert_eq!(options.order_types[0], OrderDirection::Asc, "a page before ends ahead of the first row");
    });
}

/// `AggregationErrorTest::buildDatabase()`.
fn amounts(capabilities: &[Capability]) -> crate::Database {
    let any = Role::any();
    let col = collection(
        "testCol",
        vec![Attribute::double("amount")],
        Vec::new(),
        Some(vec![Permission::read(&any), Permission::create(&any)]),
        true,
    );
    uncached(stub(vec![col], capabilities, none()))
}

#[test]
fn aggregation_errors() {
    block_on(async {
        let db = amounts(&with(&[]));
        let join = || vec![join_other()];
        let err = db.skip_validation(db.count("testCol", join(), None)).await.unwrap_err();
        assert!(err.message().contains("Join queries are not supported"));
        let err = db.skip_validation(db.sum("testCol", "amount", join(), None)).await.unwrap_err();
        assert!(err.message().contains("Join queries are not supported"));
        db.set_validation(true);
        let err = db.sum("testCol", "amount", vec![Query::equal("nonexistent", ["val"])], None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Query);

        let db = amounts(&with(&[Capability::Aggregations]));
        let rows = db.skip_validation(db.aggregate("testCol", vec![Query::count("*", "cnt")])).await.unwrap();
        assert!(rows.is_empty());
        let err = db.aggregate("testCol", vec![Query::equal("amount", [1])]).await.unwrap_err();
        assert_eq!(err.message(), "aggregate() needs an aggregate or groupBy query");
    });
}

#[test]
fn sum_refuses_an_attribute_a_sum_cannot_add_up() {
    block_on(async {
        let any = Role::any();
        let col = collection(
            "testCol",
            vec![Attribute::double("amount"), Attribute::string("name", 64)],
            Vec::new(),
            Some(vec![Permission::read(&any)]),
            true,
        );
        let db = uncached(stub(vec![col], &BASE, none()));
        let err = db.sum("testCol", "name", Vec::new(), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Query);
        assert!(err.message().starts_with("Invalid query: "), "{}", err.message());
        assert_eq!(db.sum("testCol", "amount", Vec::new(), None).await.unwrap(), Value::Int(0));
    });
}

#[test]
fn find_results_use_the_mapped_type() {
    block_on(async {
        let any = Role::any();
        let posts = collection(
            "posts",
            Vec::new(),
            Vec::new(),
            Some(vec![Permission::read(&any), Permission::create(&any)]),
            false,
        );
        let rows = vec![
            doc(vec![("$id", string("post1")), ("title", string("First Post"))]),
            doc(vec![("$id", string("post2")), ("title", string("Second Post"))]),
        ];
        let db = uncached(stub(
            vec![posts],
            &BASE,
            Box::new(move |c| matches!(c, Call::Find { .. }).then(|| Reply::Documents(rows.clone()))),
        ));
        db.set_document_type("posts", "Tests\\TestPostDocument");
        let results = db.find("posts", Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!(results.len(), 2);
        assert!(results.iter().all(|d| d.class() == "Tests\\TestPostDocument"));
        assert_eq!(results[0].get("title"), Some(&string("First Post")));
    });
}

/// A lifecycle hook recording the events it handles.
#[derive(Default)]
struct Events(Mutex<Vec<crate::event::Domain>>);

#[async_trait::async_trait]
impl crate::hook::Lifecycle for Events {
    async fn handle(&self, event: &crate::event::Domain) -> crate::error::Result<()> {
        self.0.lock().unwrap().push(event.clone());
        Ok(())
    }
}

#[test]
fn find_one_fires_one_find_event_only_on_a_hit() {
    block_on(async {
        let adapter = stub(vec![plain("testCol")], &BASE, paged(vec![Vec::new(), vec![row("d1", "1")]]));
        let db = uncached(adapter);
        let events = Arc::new(Events::default());
        db.add_hook(super::super::Hook::lifecycle(events.clone())).unwrap();

        assert!(db.find_one("testCol", Vec::new()).await.unwrap().is_empty());
        assert!(events.0.lock().unwrap().is_empty());

        let found = db.find_one("testCol", Vec::new()).await.unwrap();
        let recorded = events.0.lock().unwrap().clone();
        assert_eq!(recorded.len(), 1);
        let crate::event::Domain::DocumentFound { collection, documents } = &recorded[0] else {
            panic!("a find event, not {:?}", recorded[0]);
        };
        assert_eq!(collection, "testCol");
        assert_eq!(documents, &vec![found]);
    });
}

#[test]
fn aggregate_fires_the_aggregate_event_with_its_rows() {
    block_on(async {
        let adapter = stub(
            vec![test_col()],
            &with(&[Capability::Aggregations]),
            Box::new(|c| {
                matches!(c, Call::Find { .. }).then(|| Reply::Documents(vec![doc(vec![("total", Value::Int(2))])]))
            }),
        );
        let db = uncached(adapter);
        let events = Arc::new(Events::default());
        db.add_hook(super::super::Hook::lifecycle(events.clone())).unwrap();

        let rows = db.aggregate("testCol", vec![Query::count("*", "total")]).await.unwrap();
        let recorded = events.0.lock().unwrap().clone();
        assert_eq!(recorded.len(), 1);
        let crate::event::Domain::DocumentAggregated { collection, rows: seen } = &recorded[0] else {
            panic!("an aggregate event, not {:?}", recorded[0]);
        };
        assert_eq!(collection, "testCol");
        assert_eq!(seen, &rows);

        db.silent(db.aggregate("testCol", vec![Query::count("*", "")])).await.unwrap();
        assert_eq!(events.0.lock().unwrap().len(), 1);
    });
}

#[test]
fn qualified_orders_and_tie_breaks_of_joins() {
    let db = uncached(StubAdapter::new());
    let main = collection("main", vec![Attribute::string("name", 64)], Vec::new(), None, true);
    let joined: super::joins::Joined = vec![
        ("a".to_owned(), collection("as", vec![Attribute::integer("score")], Vec::new(), None, true)),
        ("b".to_owned(), collection("bs", vec![Attribute::integer("rank")], Vec::new(), None, true)),
    ];
    let cursor = doc(vec![("$id", string("x")), ("score", Value::Int(3))]);
    let (orders, cursor) =
        db.qualify_joined_orders(&main, vec!["name".into(), "score".into(), "b.rank".into()], cursor, &joined).unwrap();
    assert_eq!(orders, ["name", "a.score", "b.rank"]);
    assert_eq!(cursor.get("a.score"), Some(&Value::Int(3)));

    let ambiguous: super::joins::Joined = vec![
        ("a".to_owned(), collection("as", vec![Attribute::integer("score")], Vec::new(), None, true)),
        ("b".to_owned(), collection("bs", vec![Attribute::integer("score")], Vec::new(), None, true)),
    ];
    let err = db.qualify_joined_orders(&main, vec!["score".into()], Document::empty(), &ambiguous).unwrap_err();
    assert_eq!(err.message(), "Attribute \"score\" is ambiguous across joins; qualify it with a join alias");

    // A join on the joined `$id` matches at most one row; another one gets a
    // joined `$id` tie break when the rows show it.
    let joins = vec![
        Query::join("as", "a", vec![Query::on("ref", "a.$id", "=")]).unwrap(),
        Query::left_join("bs", "b", vec![Query::on("$id", "b.owner", "=")]).unwrap(),
    ];
    let (orders, types) =
        super::find::add_tie_breaks(vec!["name".into()], vec![OrderDirection::Desc], &[], &joins, &joined, false, &[]);
    assert_eq!(orders, ["name", "$sequence", "b.$id"]);
    assert_eq!(types, [OrderDirection::Desc, OrderDirection::Asc, OrderDirection::Asc]);
    let (orders, _) = super::find::add_tie_breaks(
        vec!["name".into()],
        vec![OrderDirection::Asc],
        &[],
        &joins,
        &joined,
        false,
        &[Query::select(["name"])],
    );
    assert_eq!(orders, ["name", "$sequence"]);
}

// ---- CustomDocumentTypeTest ------------------------------------------------
// PHP checks the class exists and extends `Document`; Rust has no class to
// check, so those two cases have no port.

const USER: &str = "Tests\\Unit\\TestUserDocument";
const POST: &str = "Tests\\Unit\\TestPostDocument";

#[test]
fn document_types_are_set_read_and_cleared() {
    let db = uncached(StubAdapter::new());
    assert_eq!(db.document_type("nonexistent"), None);
    db.set_document_type("users", USER).set_document_type("posts", POST);
    assert_eq!(db.document_type("users").as_deref(), Some(USER));
    assert_eq!(db.document_type("posts").as_deref(), Some(POST));
    db.clear_document_type("users");
    assert_eq!(db.document_type("users"), None);
    assert_eq!(db.document_type("posts").as_deref(), Some(POST));
    db.set_document_type("users", USER);
    db.clear_document_types();
    assert_eq!(db.document_type("users"), None);
    assert_eq!(db.document_type("posts"), None);
    assert_eq!(db.document_type(METADATA).as_deref(), Some(crate::collection::CLASS));
}

#[test]
fn a_created_document_takes_its_collection_type() {
    block_on(async {
        let any = Role::any();
        let users = collection(
            "users",
            Vec::new(),
            Vec::new(),
            Some(vec![Permission::create(&any), Permission::read(&any)]),
            false,
        );
        let generic = collection(
            "generic",
            Vec::new(),
            Vec::new(),
            Some(vec![Permission::create(&any), Permission::read(&any)]),
            false,
        );
        let adapter = stub(vec![users, generic], &[Capability::DefinedAttributes], none());
        let db = uncached(adapter);
        db.set_validation(false);
        db.set_filtering(false);
        db.set_document_type("users", USER);
        db.authorization().clean_roles();
        db.authorization().add_role("any");

        let created = db
            .create_document(
                "users",
                doc(vec![
                    ("$id", string("user1")),
                    ("$permissions", Value::Array(Array::new())),
                    ("email", string("test@example.com")),
                    ("name", string("Test User")),
                    ("status", string("active")),
                ]),
            )
            .await
            .unwrap();
        assert_eq!(created.class(), USER);
        assert_eq!(created.get("email"), Some(&string("test@example.com")));
        assert_eq!(created.get("name"), Some(&string("Test User")));
        assert_eq!(created.get("status"), Some(&string("active")));

        let created = db
            .create_document(
                "generic",
                doc(vec![("$id", string("doc1")), ("$permissions", Value::Array(Array::new())), ("data", string("test"))]),
            )
            .await
            .unwrap();
        assert_ne!(created.class(), USER);
        assert_ne!(created.class(), POST);
    });
}
