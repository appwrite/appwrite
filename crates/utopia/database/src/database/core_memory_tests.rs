//! The core's tests on the Memory adapter.
//!
//! PHP: `tests/unit/EncodeTest.php`, `ObjectFilterTest.php`,
//! `DefaultFilterDecodeTest.php` (Memory), `QueryConversionTest.php`
//! (Memory), `DatabaseGuardsTest.php` (Memory), `Filter/CodecTest.php`.

use std::sync::{Arc, RwLock};

use async_trait::async_trait;
use utopia_query::schema::ColumnType;

use super::fixtures::{any, created, definition, doc, no_cache};
use super::testing::block_on;
use super::{Database, LENGTH_KEY};
use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::event::Event;
use crate::filter::{Callback, Codec, Registry};
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::{Array, ArrayKey, Str, Value, list, string};

fn assoc(pairs: Vec<(&str, Value)>) -> Array {
    pairs.into_iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k)), v)).collect()
}

/// A collection definition of raw attribute documents (`new Document(['attributes' => [...]])`).
fn raw(id: &str, attributes: Vec<Array>) -> Collection {
    let attributes = attributes.into_iter().map(|a| Value::from(Document::new(a).unwrap())).collect::<Vec<_>>();
    Collection::from_array(assoc(vec![("$id", string(id)), ("attributes", list(attributes))])).unwrap()
}

fn memory() -> Database {
    Database::new(Arc::new(Memory::new()), no_cache())
}

// ---- EncodeTest ------------------------------------------------------------

#[test]
fn an_explicit_null_takes_the_declared_default() {
    block_on(async {
        let db = memory();
        let collection = Collection::create(
            "documents",
            "documents",
            vec![Attribute::string("status", LENGTH_KEY).default("pending")],
            Vec::new(),
            None,
            true,
            Array::new(),
        )
        .unwrap();
        let encoded = db.encode(&collection, doc(vec![("status", Value::Null)]), true).await.unwrap();
        assert_eq!(encoded.get("status"), Some(&string("pending")));
    });
}

// ---- ObjectFilterTest ------------------------------------------------------

fn meta() -> Collection {
    raw(
        "meta",
        vec![assoc(vec![
            ("$id", string("meta")),
            ("type", string(ColumnType::Object.as_str())),
            ("array", Value::Bool(false)),
            ("filters", list([string(ColumnType::Object.as_str())])),
        ])],
    )
}

#[test]
fn the_object_filter_keeps_empty_objects_and_arrays() {
    block_on(async {
        let db = memory();
        let object = || Value::Object(Array::new());
        let document = doc(vec![(
            "meta",
            Value::Array(assoc(vec![
                ("emptyObject", object()),
                ("emptyArray", Value::Array(Array::new())),
                ("nested", list([object(), Value::Array(assoc(vec![("value", Value::Int(1))]))])),
            ])),
        )]);
        let encoded = db.encode(&meta(), document, true).await.unwrap();
        assert_eq!(
            encoded.get("meta"),
            Some(&string(r#"{"emptyObject":{},"emptyArray":[],"nested":[{},{"value":1}]}"#))
        );
        let decoded = db.decode(&meta(), encoded, &[]).await.unwrap();
        let Some(Value::Array(value)) = decoded.get("meta") else { panic!("an array, not {:?}", decoded.get("meta")) };
        assert!(matches!(value.get_str("emptyObject"), Some(Value::Object(o)) if o.is_empty()));
        assert!(matches!(value.get_str("emptyArray"), Some(Value::Array(a)) if a.is_empty()));
        let Some(Value::Array(nested)) = value.get_str("nested") else { panic!("a list") };
        assert!(matches!(nested.get(&ArrayKey::Int(0)), Some(Value::Object(o)) if o.is_empty()));
        assert_eq!(nested.get(&ArrayKey::Int(1)), Some(&Value::Array(assoc(vec![("value", Value::Int(1))]))));

        let encoded = db.encode(&meta(), doc(vec![("meta", Value::Object(Array::new()))]), true).await.unwrap();
        assert_eq!(encoded.get("meta"), Some(&string("{}")));
        let decoded = db.decode(&meta(), encoded, &[]).await.unwrap();
        assert!(matches!(decoded.get("meta"), Some(Value::Object(o)) if o.is_empty()));
    });
}

// ---- DefaultFilterDecodeTest (Memory) -------------------------------------

#[test]
fn a_json_attribute_holding_a_scalar_decodes_to_the_scalar() {
    block_on(async {
        let db = created(Arc::new(Memory::new()), no_cache(), "filters", "decode", false).await;
        let mut payload = Attribute::string("payload", 64);
        payload.filters = vec!["json".to_owned()];
        db.create_collection(definition("payloads", vec![payload], any(&["create", "read"]), false)).await.unwrap();
        let scalars = [
            ("integer", "5", Value::Int(5)),
            ("boolean", "true", Value::Bool(true)),
            ("string", "\"x\"", string("x")),
            ("float", "1.5", Value::Float(1.5)),
        ];
        for (id, stored, _) in &scalars {
            db.create_document("payloads", doc(vec![("$id", string(*id)), ("payload", string(*stored))])).await.unwrap();
        }
        for (id, _, decoded) in &scalars {
            let read = db.get_document("payloads", id, Vec::new(), false).await.unwrap();
            assert_eq!(read.get("payload"), Some(decoded), "getDocument() of {id}");
            let found = db.find_one("payloads", vec![Query::equal("$id", [*id])]).await.unwrap();
            assert_eq!(found.get("payload"), Some(decoded), "find() of {id}");
        }
    });
}

#[test]
fn a_spatial_filter_on_a_non_spatial_adapter_decodes_to_null() {
    block_on(async {
        let db = created(Arc::new(Memory::new()), no_cache(), "filters", "decode", false).await;
        for (kind, stored) in [
            (ColumnType::Point, "POINT(1 2)"),
            (ColumnType::Linestring, "LINESTRING(1 2, 3 4)"),
            (ColumnType::Polygon, "POLYGON((0 0, 0 1, 1 1, 0 0))"),
        ] {
            let collection = raw(
                "payloads",
                vec![assoc(vec![
                    ("$id", string("shape")),
                    ("type", string(ColumnType::String.as_str())),
                    ("array", Value::Bool(false)),
                    ("filters", list([string(kind.as_str())])),
                ])],
            );
            let decoded = db
                .decode(&collection, doc(vec![("$id", string("shape")), ("shape", string(stored))]), &[])
                .await
                .unwrap();
            assert_eq!(decoded.get("shape"), None, "{} decodes to null without spatial support", kind.as_str());
        }
    });
}

// ---- QueryConversionTest (Memory) -----------------------------------------

async fn events(adapter: Arc<dyn Adapter>) -> Database {
    let db = created(adapter, no_cache(), "conversion", "conversion", false).await;
    db.create_collection(definition(
        "events",
        vec![Attribute::datetime("occurredAt"), Attribute::string("tags", 16).array(true)],
        any(&["create", "read"]),
        false,
    ))
    .await
    .unwrap();
    db
}

#[test]
fn an_unparseable_datetime_query_value_is_a_query_error() {
    block_on(async {
        let db = events(Arc::new(Memory::new())).await;
        let collection = db.get_collection("events").await.unwrap();
        let err = db.convert_query(&collection, Query::equal("occurredAt", ["not-a-date"])).unwrap_err();
        assert_eq!(err.kind(), Kind::Query);
        assert!(err.previous().is_some(), "the parse failure is kept as the previous error");
        let err = db
            .skip_validation(db.find(
                "events",
                vec![Query::equal("occurredAt", ["not-a-date"])],
                crate::permission::PermissionType::Read,
            ))
            .await
            .unwrap_err();
        assert_eq!(err.kind(), Kind::Query);
    });
}

#[test]
fn an_attribute_without_a_string_type_is_refused() {
    let collection = Collection::from_array(assoc(vec![
        ("$id", string("events")),
        (
            "attributes",
            list([Value::from(
                Document::new(assoc(vec![
                    ("$id", string("untyped")),
                    ("type", Value::Int(5)),
                    ("array", Value::Bool(true)),
                ]))
                .unwrap(),
            )]),
        ),
    ]));
    let err = match collection {
        Err(e) => e,
        Ok(collection) => memory().convert_query(&collection, Query::equal("untyped", ["x"])).unwrap_err(),
    };
    assert_eq!(err.kind(), Kind::Structure);
    assert_eq!(err.message(), "Attribute type must be a string, int given");
}

#[test]
fn only_map_values_of_an_undeclared_attribute_are_object_queries() {
    let spy = super::spy::Spy::memory();
    spy.withhold(Capability::DefinedAttributes);
    let db = Database::new(spy, no_cache());
    let collection = raw("events", Vec::new());
    let map = |pairs: Vec<(&str, i64)>| {
        let mut a = utopia_query::Array::new();
        for (k, v) in pairs {
            a.set(php_std::ArrayKey::from(k), utopia_query::Value::Int(v));
        }
        utopia_query::Value::Array(a)
    };
    let pair = || utopia_query::Value::Array(utopia_query::list([1i64, 2]));
    let object = ColumnType::Object.as_str();
    for (query, kind) in [
        (Query::equal("coords", vec![map(vec![("x", 1)])]), object),
        (Query::equal("coords", vec![map(Vec::new())]), object),
        (Query::equal("coords", vec![pair()]), ""),
        (Query::equal("coords", vec![map(vec![("x", 1)]), pair()]), ""),
        (Query::equal("coords", ["x"]), ""),
        (Query::is_null("coords"), ""),
    ] {
        let converted = db.convert_query(&collection, query.clone()).unwrap();
        assert_eq!(converted.attribute_type(), kind, "{query:?}");
    }
}

#[test]
fn convert_query_matches_convert_queries() {
    block_on(async {
        let db = events(Arc::new(Memory::new())).await;
        let collection = db.get_collection("events").await.unwrap();
        for build in [
            || Query::equal("occurredAt", ["2026-09-30 10:00:00"]),
            || Query::contains_any("tags", ["a"]),
            || Query::equal("$createdAt", ["2026-09-30 10:00:00"]),
        ] {
            let single = db.convert_query(&collection, build()).unwrap();
            let listed = db.convert_queries(&collection, vec![build()], &[]).unwrap().remove(0);
            assert_ne!(single.attribute_type(), "", "{}", single.attribute());
            assert_eq!(listed.attribute_type(), single.attribute_type());
            assert_eq!(listed.on_array(), single.on_array());
            assert_eq!(listed.values(), single.values());
        }
        assert!(db.convert_query(&collection, Query::contains_any("tags", ["a"])).unwrap().on_array());

        if db.adapter().supports(Capability::Objects) {
            db.create_attribute("events", Attribute::object("meta")).await.unwrap();
            let with_object = db.get_collection("events").await.unwrap();
            let converted = db.convert_query(&with_object, Query::equal("meta.level", ["x"])).unwrap();
            assert_eq!(converted.attribute_type(), ColumnType::Object.as_str());
        }
        let converted = db.convert_query(&collection, Query::equal("occurredAt", ["2026-09-30 10:00:00"])).unwrap();
        assert_ne!(converted.values(), &utopia_query::list([utopia_query::string("2026-09-30 10:00:00")]));
    });
}

// ---- DatabaseGuardsTest (Memory) ------------------------------------------

fn guarded() -> Database {
    let db = memory();
    db.set_database("guards").set_namespace(&super::fixtures::unique("guards"));
    db
}

#[test]
fn builders_raw_statements_and_timeouts_are_refused_without_support() {
    block_on(async {
        let db = guarded();
        let authorization = db.authorization();
        let err = authorization.skip_sync(|| db.from("anything", "")).err().unwrap();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "Query builder is not supported by this adapter"));
        let err = db.schema().err().unwrap();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "Schema builder is not supported by this adapter"));

        let mut statement = utopia_query::builder::Statement::new("SELECT 1", Vec::new());
        statement.read_only = true;
        let err = authorization.skip(db.query(&statement)).await.unwrap_err();
        assert_eq!(err.message(), "Raw queries are not supported by this adapter");
        let err = authorization.skip(db.mutate(&statement)).await.unwrap_err();
        assert_eq!(err.message(), "Raw queries are not supported by this adapter");

        let err = db.set_timeout(1_000, Event::All).err().unwrap();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "Adapter does not support timeouts"));
        let err = db.clear_timeout(Event::All).unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "Adapter does not support timeouts"));

        assert_eq!(db.connection_id().await.unwrap(), None);
    });
}

#[test]
fn schema_introspection_without_the_capability_lists_nothing() {
    block_on(async {
        let db = guarded();
        db.create(None).await.unwrap();
        db.create_collection(
            Collection::create(
                "introspected",
                "introspected",
                vec![Attribute::string("name", 32)],
                vec![crate::index::Index::key("byName", ["name"], Vec::new(), Vec::new()).unwrap()],
                None,
                true,
                Array::new(),
            )
            .unwrap(),
        )
        .await
        .unwrap();
        assert!(db.schema_attributes("introspected").await.unwrap().is_empty());
        assert!(db.schema_indexes("introspected").await.unwrap().is_empty());
    });
}

#[test]
fn guards_without_a_relationship_hook() {
    block_on(async {
        let db = guarded();
        assert!(!db.is_dropping_unknown_attributes());
        assert!(db.set_drop_unknown_attributes(true).is_dropping_unknown_attributes());
        assert!(!db.set_drop_unknown_attributes(false).is_dropping_unknown_attributes());

        let relationships = db.skip_relationships(async { db.snapshot().relationships }).await;
        assert!(relationships, "skipping relationships does not install the hook");
        let exist_check = db.skip_relationships_exist_check(async { db.snapshot().exist_check }).await;
        assert!(exist_check, "skipping the exist check does not install the hook");
    });
}

// ---- Filter/CodecTest ------------------------------------------------------

/// `Reversed`: reverses strings.
struct Reversed(&'static str);

impl Codec for Reversed {
    fn name(&self) -> &str {
        self.0
    }

    fn encode(&self, value: Value) -> Result<Value> {
        Ok(reverse(value))
    }

    fn decode(&self, value: Value) -> Result<Value> {
        Ok(reverse(value))
    }
}

fn reverse(value: Value) -> Value {
    match value {
        Value::Str(s) => {
            let mut bytes = s.as_bytes().to_vec();
            bytes.reverse();
            Value::Str(Str::from(bytes))
        }
        other => other,
    }
}

/// `Rot13`.
struct Rot13(&'static str);

impl Codec for Rot13 {
    fn name(&self) -> &str {
        self.0
    }

    fn encode(&self, value: Value) -> Result<Value> {
        Ok(rot13(value))
    }

    fn decode(&self, value: Value) -> Result<Value> {
        Ok(rot13(value))
    }
}

fn rot13(value: Value) -> Value {
    match value {
        Value::Str(s) => Value::Str(Str::from(
            s.as_bytes()
                .iter()
                .map(|b| match b {
                    b'a'..=b'z' => (b - b'a' + 13) % 26 + b'a',
                    b'A'..=b'Z' => (b - b'A' + 13) % 26 + b'A',
                    other => *other,
                })
                .collect::<Vec<u8>>(),
        )),
        other => other,
    }
}

/// `CodecTestPrefixed`: a signed codec told apart by its prefix.
struct Prefixed(&'static str);

impl Codec for Prefixed {
    fn name(&self) -> &str {
        "text"
    }

    fn encode(&self, value: Value) -> Result<Value> {
        Ok(match value {
            Value::Str(s) => string(format!("{}{}", self.0, String::from_utf8_lossy(s.as_bytes()))),
            other => other,
        })
    }

    fn decode(&self, value: Value) -> Result<Value> {
        Ok(match value {
            Value::Str(s) if s.as_bytes().starts_with(self.0.as_bytes()) => {
                Value::Str(Str::from(&s.as_bytes()[self.0.len()..]))
            }
            other => other,
        })
    }

    fn signature(&self) -> String {
        format!("Tests\\Unit\\Filter\\CodecTestPrefixed:{}", self.0)
    }
}

fn types(adapter: Option<Arc<Memory>>, codecs: Vec<Arc<dyn Codec>>) -> Database {
    let adapter = adapter.unwrap_or_else(|| Arc::new(Memory::new()));
    let db = Database::with_filters(adapter, no_cache(), codecs).unwrap();
    db.set_database("types").set_namespace("types");
    db
}

fn registry(codec: Arc<dyn Codec>) -> Arc<RwLock<Registry>> {
    let mut registry = Registry::new();
    registry.register(codec).unwrap();
    Arc::new(RwLock::new(registry))
}

fn notes(filter: &str) -> Collection {
    raw(
        "notes",
        vec![assoc(vec![
            ("$id", string("body")),
            ("type", string(ColumnType::String.as_str())),
            ("array", Value::Bool(false)),
            ("filters", list([string(filter)])),
        ])],
    )
}

async fn create_note(db: &Database, body: &str, filters: &[&str]) {
    db.create(None).await.unwrap();
    db.create_collection(Collection::create("notes", "notes", Vec::new(), Vec::new(), None, true, Array::new()).unwrap())
        .await
        .unwrap();
    let mut attribute = Attribute::string("body", LENGTH_KEY);
    attribute.filters = filters.iter().map(|f| (*f).to_owned()).collect();
    db.create_attribute("notes", attribute).await.unwrap();
    db.create_document(
        "notes",
        doc(vec![
            ("$id", string("note")),
            ("$permissions", list([string(Permission::read(&Role::any()))])),
            ("body", string(body)),
        ]),
    )
    .await
    .unwrap();
}

async fn hash(db: &Database) -> String {
    db.cache_keys("notes", Some("note"), &[]).await.unwrap().2
}

async fn stored_body(adapter: &Memory, db: &Database) -> Option<Value> {
    let collection = db.get_collection("notes").await.unwrap();
    adapter.get_document(&collection, "note", Vec::new(), false).await.unwrap().get("body").cloned()
}

#[test]
fn a_registered_codec_encodes_and_decodes_on_its_handle() {
    block_on(async {
        let adapter = Arc::new(Memory::new());
        let db = types(Some(adapter.clone()), Vec::new());
        let registry = Arc::new(RwLock::new(Registry::new()));
        db.set_filters(registry.clone());
        registry.write().unwrap().register(Arc::new(Reversed("reversed"))).unwrap();
        create_note(&db, "hello", &["reversed"]).await;
        assert_eq!(stored_body(&adapter, &db).await, Some(string("olleh")));
        assert_eq!(db.get_document("notes", "note", Vec::new(), false).await.unwrap().get("body"), Some(&string("hello")));

        let adapter = Arc::new(Memory::new());
        let db = types(Some(adapter.clone()), vec![Arc::new(Reversed("reversed"))]);
        create_note(&db, "hello", &["reversed"]).await;
        assert_eq!(stored_body(&adapter, &db).await, Some(string("olleh")));
        assert_eq!(db.get_document("notes", "note", Vec::new(), false).await.unwrap().get("body"), Some(&string("hello")));
        assert!(db.filters().read().unwrap().has("reversed"));
    });
}

#[test]
fn handles_without_the_registry_cannot_use_its_codecs() {
    block_on(async {
        types(None, Vec::new()).set_filters(registry(Arc::new(Reversed("reversedOnly"))));
        let err =
            types(None, Vec::new()).encode(&notes("reversedOnly"), doc(vec![("body", string("hello"))]), true).await.unwrap_err();
        assert_eq!(err.kind(), Kind::NotFound);
        let err =
            types(None, Vec::new()).decode(&notes("reversedOnly"), doc(vec![("body", string("olleh"))]), &[]).await.unwrap_err();
        assert_eq!(err.kind(), Kind::NotFound);
    });
}

#[test]
fn a_built_in_name_is_refused_and_breaks_no_handle() {
    block_on(async {
        let adapter = Arc::new(Memory::new());
        create_note(&types(Some(adapter.clone()), Vec::new()), "hello", &[]).await;
        let registry = Arc::new(RwLock::new(Registry::new()));
        types(None, Vec::new()).set_filters(registry.clone());
        assert_eq!(registry.write().unwrap().register(Arc::new(Reversed("json"))).err().unwrap().kind(), Kind::Duplicate);
        let read = types(Some(adapter), Vec::new()).get_document("notes", "note", Vec::new(), false).await.unwrap();
        assert_eq!(read.get("body"), Some(&string("hello")));

        let err = Database::with_filters(Arc::new(Memory::new()), no_cache(), vec![Arc::new(Reversed("json"))]).unwrap_err();
        assert_eq!(err.kind(), Kind::Duplicate);
    });
}

/// A global filter answering `global`.
struct Global;

#[async_trait]
impl super::Filter for Global {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(value)
    }

    async fn decode(&self, _value: Value, _document: &Document, _database: &Database, _attribute: &str) -> Result<Value> {
        Ok(string("global"))
    }

    fn signature(&self) -> String {
        "global:global".to_owned()
    }
}

#[test]
fn a_registered_codec_shadows_a_global_filter_only_on_its_handle() {
    block_on(async {
        // A name of its own: the global filters are shared by every test.
        let name = "reversedShadowing";
        let db = types(None, Vec::new());
        db.set_filters(registry(Arc::new(Reversed(name))));
        let other = types(None, Vec::new());
        Database::add_filter(name, Arc::new(Global));
        let decoded = db.decode(&notes(name), doc(vec![("body", string("olleh"))]), &[]).await.unwrap();
        assert_eq!(decoded.get("body"), Some(&string("hello")));
        let decoded = other.decode(&notes(name), doc(vec![("body", string("olleh"))]), &[]).await.unwrap();
        assert_eq!(decoded.get("body"), Some(&string("global")));
    });
}

#[test]
fn cache_keys_follow_the_codecs_of_a_handle() {
    block_on(async {
        let plain = types(None, Vec::new());
        let before = hash(&plain).await;
        let typed = types(None, Vec::new());
        typed.set_filters(registry(Arc::new(Reversed("reversed"))));
        assert_ne!(hash(&typed).await, before);
        assert_eq!(hash(&plain).await, before);

        let reversed = types(None, Vec::new());
        reversed.set_filters(registry(Arc::new(Reversed("text"))));
        let rot13 = types(None, Vec::new());
        rot13.set_filters(registry(Arc::new(Rot13("text"))));
        let same = types(None, Vec::new());
        same.set_filters(registry(Arc::new(Reversed("text"))));
        assert_ne!(hash(&reversed).await, hash(&rot13).await);
        let field = |db: &Database| {
            db.query_cache_field(None, &[Query::limit(1)], "documents", crate::permission::PermissionType::Read).unwrap()
        };
        assert_ne!(field(&reversed), field(&rot13));
        assert_eq!(hash(&reversed).await, hash(&same).await);

        let first = types(None, Vec::new());
        first.set_filters(registry(Arc::new(Prefixed("a:"))));
        let second = types(None, Vec::new());
        second.set_filters(registry(Arc::new(Prefixed("b:"))));
        let same = types(None, Vec::new());
        same.set_filters(registry(Arc::new(Prefixed("a:"))));
        assert_ne!(hash(&first).await, hash(&second).await);
        assert_eq!(hash(&first).await, hash(&same).await);
    });
}

#[test]
fn set_filters_replaces_the_constructor_filters() {
    block_on(async {
        let db = types(None, vec![Arc::new(Callback::new("reversed", Ok, Ok))]);
        db.set_filters(registry(Arc::new(Reversed("reversed"))));
        let registry_only = types(None, Vec::new());
        registry_only.set_filters(registry(Arc::new(Reversed("reversed"))));
        let decoded = db.decode(&notes("reversed"), doc(vec![("body", string("olleh"))]), &[]).await.unwrap();
        assert_eq!(decoded.get("body"), Some(&string("hello")));
        assert_eq!(hash(&registry_only).await, hash(&db).await);
    });
}
