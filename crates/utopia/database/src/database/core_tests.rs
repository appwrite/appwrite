//! Tests of the core: hooks and events, scopes, filters, conversion and
//! metadata persistence, on [`StubAdapter`].
//!
//! PHP: `EncodeTest`, `ObjectFilterTest`, `DefaultFilterDecodeTest`
//! (`testSpatialFilterOnANonSpatialAdapterDecodesToNull`), and the parts of
//! `Hook\NamedTest`, `Hook\RemoveHookTest`, `Event\HookFailureTest` and
//! `TogglesTest` that need no engine.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_query::schema::ColumnType;

use super::testing::{StubAdapter, block_on, uncached};
use super::{Database, Hook};
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::filter::Filter as BuiltinFilter;
use crate::hook::{Decorator, Lifecycle, Named, Queries};
use crate::query::Query;
use crate::value::{Array, ArrayKey, Str, Value, string};

/// A lifecycle hook recording the events it receives, failing with
/// `fail` when set.
struct Recording {
    name: Option<String>,
    events: Mutex<Vec<Event>>,
    fail: Option<Error>,
}

impl Recording {
    fn new() -> Arc<Self> {
        Arc::new(Recording { name: None, events: Mutex::new(Vec::new()), fail: None })
    }

    fn named(name: &str) -> Arc<Self> {
        Arc::new(Recording { name: Some(name.to_owned()), events: Mutex::new(Vec::new()), fail: None })
    }

    fn failing(error: Error) -> Arc<Self> {
        Arc::new(Recording { name: None, events: Mutex::new(Vec::new()), fail: Some(error) })
    }

    fn events(&self) -> Vec<Event> {
        self.events.lock().unwrap().clone()
    }
}

impl Named for Recording {
    fn name(&self) -> &str {
        self.name.as_deref().unwrap_or("")
    }
}

#[async_trait]
impl Lifecycle for Recording {
    async fn handle(&self, event: &Domain) -> Result<()> {
        self.events.lock().unwrap().push(event.event());
        match &self.fail {
            Some(e) => Err(e.clone()),
            None => Ok(()),
        }
    }

    fn named(&self) -> Option<&dyn Named> {
        self.name.as_ref().map(|_| self as &dyn Named)
    }
}

fn purge(collection: &str) -> Domain {
    Domain::DocumentPurged { collection: collection.to_owned(), id: "d".to_owned() }
}

fn database() -> Database {
    uncached(StubAdapter::new())
}

#[test]
fn a_named_hook_replaces_the_one_registered_under_its_name_in_place() {
    block_on(async {
        let db = database();
        let first = Recording::named("audits");
        let other = Recording::new();
        let second = Recording::named("audits");
        db.add_hook(Hook::lifecycle(first.clone())).unwrap();
        db.add_hook(Hook::lifecycle(other.clone())).unwrap();
        db.add_hook(Hook::lifecycle(second.clone())).unwrap();
        let listeners = db.listens(Event::DocumentPurge);
        assert_eq!(listeners.len(), 2);
        db.dispatch(&purge("c"), &listeners).await.unwrap();
        assert!(first.events().is_empty());
        assert_eq!(second.events(), vec![Event::DocumentPurge]);
        assert_eq!(other.events(), vec![Event::DocumentPurge]);
        assert!(
            Arc::ptr_eq(&listeners[0], &(second.clone() as Arc<dyn Lifecycle>)),
            "the replacement keeps the position"
        );
    });
}

#[test]
fn silences_every_hook_or_the_named_ones_and_never_narrows_an_outer_silence() {
    block_on(async {
        let db = database();
        let audits = Recording::named("audits");
        let plain = Recording::new();
        db.add_hook(Hook::lifecycle(audits.clone())).unwrap();
        db.add_hook(Hook::lifecycle(plain.clone())).unwrap();

        assert!(db.silent(async { db.listens(Event::DocumentPurge) }).await.is_empty());
        let named = db.silent_hooks(&["audits"], async { db.listens(Event::DocumentPurge).len() }).await;
        assert_eq!(named, 1, "the unnamed hook keeps firing");
        let nested = db
            .silent_hooks(&["audits"], async {
                db.silent_hooks(&["other"], async { db.listens(Event::DocumentPurge).len() }).await
            })
            .await;
        assert_eq!(nested, 1);
        let inside =
            db.silent(async { db.silent_hooks(&["x"], async { db.listens(Event::DocumentPurge).len() }).await }).await;
        assert_eq!(inside, 0);
        assert_eq!(db.listens(Event::DocumentPurge).len(), 2, "the silence ends with its future");
    });
}

#[test]
fn removes_hooks_by_instance_or_type() {
    let db = database();
    let a = Recording::new();
    let b = Recording::new();
    let hook_a = Hook::lifecycle(a.clone());
    db.add_hook(hook_a.clone()).unwrap();
    db.add_hook(Hook::lifecycle(b.clone())).unwrap();
    db.remove_hook(&hook_a);
    assert_eq!(db.listens(Event::DocumentPurge).len(), 1);
    db.remove_hook_class(std::any::type_name::<Recording>());
    assert!(db.listens(Event::DocumentPurge).is_empty());
}

#[test]
fn isolates_or_propagates_hook_failures_by_event() {
    block_on(async {
        let db = database();
        let failing = Recording::failing(Error::database("boom"));
        let after = Recording::new();
        db.add_hook(Hook::lifecycle(failing.clone())).unwrap();
        db.add_hook(Hook::lifecycle(after.clone())).unwrap();

        // DocumentPurge is isolated: the failure is swallowed, the next hook runs.
        let listeners = db.listens(Event::DocumentPurge);
        db.dispatch(&purge("c"), &listeners).await.unwrap();
        assert_eq!(after.events(), vec![Event::DocumentPurge]);
        // ... unless dispatched propagating.
        let err = db.dispatch_propagating(&purge("c"), &listeners).await.unwrap_err();
        assert_eq!(err.message(), "boom");

        // A document read propagates the first failure, and stops there.
        let read = Domain::DocumentRead { collection: "c".into(), document: Document::empty() };
        let listeners = db.listens(Event::DocumentRead);
        let err = db.dispatch(&read, &listeners).await.unwrap_err();
        assert_eq!(err.message(), "boom");
        assert_eq!(after.events(), vec![Event::DocumentPurge], "the later hook did not run");

        // An engine error always reaches the caller.
        let db = database();
        db.add_hook(Hook::lifecycle(Recording::failing(Error::new(Kind::TypeError, "type")))).unwrap();
        let listeners = db.listens(Event::DocumentPurge);
        assert_eq!(db.dispatch(&purge("c"), &listeners).await.unwrap_err().kind(), Kind::TypeError);
    });
}

struct Upper;

#[async_trait]
impl Decorator for Upper {
    async fn decorate(&self, _event: Event, _collection: &Collection, mut document: Document) -> Result<Document> {
        let name = crate::value::to_text(document.get("name").unwrap_or(&Value::Null)).to_uppercase();
        document.put("name", string(name));
        Ok(document)
    }
}

#[test]
fn decorators_run_unless_events_are_silenced() {
    block_on(async {
        let db = database();
        db.add_hook(Hook::decorator(Arc::new(Upper))).unwrap();
        let collection = Collection::create("c", "", vec![], vec![], None, true, Array::new()).unwrap();
        let document = Document::from_pairs([("name", string("ada"))]).unwrap();
        let decorated = db.decorate_document(Event::DocumentRead, &collection, document.clone()).await.unwrap();
        assert_eq!(decorated.get("name"), Some(&string("ADA")));
        let silent = db.silent(db.decorate_document(Event::DocumentRead, &collection, document.clone())).await.unwrap();
        assert_eq!(silent.get("name"), Some(&string("ada")));
    });
}

struct Limit2;

#[async_trait]
impl Queries for Limit2 {
    async fn rewrite(&self, _event: Event, _collection: &Collection, mut queries: Vec<Query>) -> Result<Vec<Query>> {
        queries.push(Query::limit(2));
        Ok(queries)
    }
}

#[test]
fn query_hooks_rewrite_reads_but_not_the_metadata_collection_nor_under_skip() {
    block_on(async {
        let db = database();
        db.add_hook(Hook::queries(Arc::new(Limit2))).unwrap();
        let collection = Collection::create("c", "", vec![], vec![], None, true, Array::new()).unwrap();
        let rewritten = db.rewrite_queries(Event::DocumentFind, &collection, vec![]).await.unwrap();
        assert_eq!(rewritten.len(), 1);
        let skipped = db.skip_query_hooks(db.rewrite_queries(Event::DocumentFind, &collection, vec![])).await.unwrap();
        assert!(skipped.is_empty());
        let metadata = super::collection_definition();
        assert!(db.rewrite_queries(Event::DocumentFind, &metadata, vec![]).await.unwrap().is_empty());
    });
}

#[test]
fn toggles_are_scoped_to_their_future() {
    block_on(async {
        let db = database();
        assert!(db.is_validating());
        assert!(!db.with_validation(false, async { db.is_validating() }).await);
        assert!(db.is_validating());
        assert!(db.with_preserve_dates(true, async { db.is_preserving_dates() }).await);
        assert!(db.ignore_duplicates(async { db.is_ignoring_duplicates() }).await);
        assert!(!db.is_ignoring_duplicates());
        db.set_preserve_sequence(true);
        assert!(db.is_preserving_sequence());

        // Named filters: only those are turned off, filtering stays on.
        let (on, off) = db.skip_filters(Some(&["json"]), async { (db.is_filtering(), db.filter_exclusions()) }).await;
        assert!(on);
        assert_eq!(off.as_deref(), Some(&vec![("json".to_owned(), true)]));
        assert!(!db.skip_filters::<_, &str>(None, async { db.is_filtering() }).await);
    });
}

#[test]
fn a_snapshot_carries_the_scoped_state_to_other_work() {
    block_on(async {
        let db = database();
        db.authorization().add_role("user:1");
        let snapshot = db.with_validation(false, db.silent_hooks(&["audits"], async { db.snapshot() })).await;
        assert!(!snapshot.validation);
        assert_eq!(snapshot.silenced_listeners, vec!["audits".to_owned()]);
        let seen = db
            .with_snapshot(&snapshot, async {
                (db.is_validating(), db.silenced_listeners().as_ref().clone(), db.authorization().has_role("user:1"))
            })
            .await;
        assert_eq!(seen, (false, vec!["audits".to_owned()], true));
        assert!(db.is_validating());
    });
}

#[test]
fn encode_applies_the_declared_default_to_an_explicit_null() {
    block_on(async {
        let db = database();
        let collection = Collection::create(
            "documents",
            "",
            vec![Attribute::string("status", 64).default(string("pending"))],
            vec![],
            None,
            true,
            Array::new(),
        )
        .unwrap();
        let encoded =
            db.encode(&collection, Document::from_pairs([("status", Value::Null)]).unwrap(), true).await.unwrap();
        assert_eq!(encoded.get("status"), Some(&string("pending")));
    });
}

fn object_collection() -> Collection {
    let attribute = Document::from_pairs([
        ("$id", string("meta")),
        ("type", string("object")),
        ("array", Value::Bool(false)),
        ("filters", crate::value::list([string("object")])),
    ])
    .unwrap();
    let data: Array =
        [(ArrayKey::Str(Str::from("attributes")), crate::value::list([Value::from(attribute)]))].into_iter().collect();
    Collection::from_array(data).unwrap()
}

#[test]
fn the_object_filter_keeps_empty_objects_and_arrays() {
    block_on(async {
        let db = database();
        let collection = object_collection();
        let meta = php_std::json::decode_ext::<crate::value::Ext>(
            br#"{"emptyObject":{},"emptyArray":[],"nested":[{},{"value":1}]}"#,
            Some(false),
            512,
            php_std::json::Flags::NONE,
        )
        .unwrap();
        // json_decode without assoc gives objects; PHP's fixture has arrays for the
        // non-empty ones, objects for the empty ones.
        let meta = match meta {
            Value::Object(o) => {
                let mut a = Array::new();
                for (k, v) in o {
                    let v = match v {
                        Value::Array(items) => Value::Array(
                            items
                                .into_iter()
                                .map(|(k, v)| match v {
                                    Value::Object(o) if !o.is_empty() => (k, Value::Array(o)),
                                    other => (k, other),
                                })
                                .collect(),
                        ),
                        other => other,
                    };
                    a.set(k, v);
                }
                Value::Array(a)
            }
            other => other,
        };
        let document = Document::from_pairs([("meta", meta)]).unwrap();
        let encoded = db.encode(&collection, document, true).await.unwrap();
        assert_eq!(
            encoded.get("meta"),
            Some(&string(r#"{"emptyObject":{},"emptyArray":[],"nested":[{},{"value":1}]}"#))
        );
        let decoded = db.decode(&collection, encoded, &[]).await.unwrap();
        let Some(Value::Array(meta)) = decoded.get("meta") else { panic!("an array") };
        assert!(matches!(meta.get_str("emptyObject"), Some(Value::Object(o)) if o.is_empty()));
        assert!(matches!(meta.get_str("emptyArray"), Some(Value::Array(a)) if a.is_empty()));

        let top = Document::from_pairs([("meta", Value::Object(Array::new()))]).unwrap();
        let encoded = db.encode(&collection, top, true).await.unwrap();
        assert_eq!(encoded.get("meta"), Some(&string("{}")));
        let decoded = db.decode(&collection, encoded, &[]).await.unwrap();
        assert!(matches!(decoded.get("meta"), Some(Value::Object(o)) if o.is_empty()));
    });
}

#[test]
fn a_spatial_filter_on_an_adapter_without_spatial_values_decodes_to_null() {
    block_on(async {
        let db = database();
        for (kind, stored) in [
            (BuiltinFilter::Point, "POINT(1 2)"),
            (BuiltinFilter::LineString, "LINESTRING(1 2, 3 4)"),
            (BuiltinFilter::Polygon, "POLYGON((0 0, 0 1, 1 1, 0 0))"),
        ] {
            let attribute = Document::from_pairs([
                ("$id", string("shape")),
                ("type", string("string")),
                ("array", Value::Bool(false)),
                ("filters", crate::value::list([string(kind.as_str())])),
            ])
            .unwrap();
            let data: Array = [
                (ArrayKey::Str(Str::from("$id")), string("payloads")),
                (ArrayKey::Str(Str::from("attributes")), crate::value::list([Value::from(attribute)])),
            ]
            .into_iter()
            .collect();
            let collection = Collection::from_array(data).unwrap();
            let document = Document::from_pairs([("$id", string("shape")), ("shape", string(stored))]).unwrap();
            let decoded = db.decode(&collection, document, &[]).await.unwrap();
            assert_eq!(decoded.get("shape"), None, "{kind:?}");
        }
    });
}

#[test]
fn casting_converts_stored_values_to_their_types() {
    let db = database();
    let collection = Collection::create(
        "c",
        "",
        vec![
            Attribute::integer("count"),
            Attribute::boolean("flag"),
            Attribute::float("ratio"),
            Attribute::integer("list").array(true),
        ],
        vec![],
        None,
        true,
        Array::new(),
    )
    .unwrap();
    let document = Document::from_pairs([
        ("count", string("12")),
        ("flag", Value::Int(0)),
        ("ratio", string("1.5")),
        ("list", string("[1,\"2\"]")),
    ])
    .unwrap();
    let cast = db.casting(&collection, document).unwrap();
    assert_eq!(cast.get("count"), Some(&Value::Int(12)));
    assert_eq!(cast.get("flag"), Some(&Value::Bool(false)));
    assert_eq!(cast.get("ratio"), Some(&Value::Float(1.5)));
    assert_eq!(cast.get("list"), Some(&crate::value::list([Value::Int(1), Value::Int(2)])));
}

#[test]
fn converts_datetime_filters_to_the_storage_timezone() {
    let db = database();
    let collection =
        Collection::create("c", "", vec![Attribute::datetime("at")], vec![], None, true, Array::new()).unwrap();
    let converted =
        db.convert_queries(&collection, vec![Query::equal("at", ["2024-01-01T10:00:00.000+02:00"])], &[]).unwrap();
    assert_eq!(converted[0].attribute_type(), ColumnType::Datetime.as_str());
    assert_eq!(
        converted[0].values().values().next().and_then(|v| match v {
            utopia_query::Value::Str(s) => s.as_str().map(str::to_owned),
            _ => None,
        }),
        Some("2024-01-01 08:00:00.000".to_owned())
    );
    let err = db.convert_queries(&collection, vec![Query::equal("at", ["not a date"])], &[]).unwrap_err();
    assert_eq!(err.kind(), Kind::Query);
}

#[test]
fn retries_only_what_another_attempt_can_clear() {
    block_on(async {
        let db = database();
        let attempts = Mutex::new(0);
        let result = db
            .with_retries(
                || async {
                    *attempts.lock().unwrap() += 1;
                    Err(Error::transaction("deadlock"))
                },
                3,
                1,
            )
            .await;
        assert_eq!(result.unwrap_err().message(), "deadlock");
        assert_eq!(*attempts.lock().unwrap(), 3);

        *attempts.lock().unwrap() = 0;
        let result = db
            .with_retries(
                || async {
                    *attempts.lock().unwrap() += 1;
                    Err(Error::duplicate("exists"))
                },
                3,
                1,
            )
            .await;
        assert_eq!(result.unwrap_err().kind(), Kind::Duplicate);
        assert_eq!(*attempts.lock().unwrap(), 1, "a deterministic failure is not retried");
    });
}
