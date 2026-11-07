//! PHP: the Memory cases of `Attributes\TypeTableTest`: every attribute
//! type round-trips through `createAttribute()`, `createCollection()` and
//! `updateAttribute()`, takes its default, and increments when numeric;
//! types Memory cannot store are refused up front.

use utopia_query::schema::ColumnType;

use super::memory;
use crate::attribute::{Attribute, Patch, Update};
use crate::collection::Collection;
use crate::database::Database;
use crate::database::testing::block_on;
use crate::document::{self, Document};
use crate::error::Kind;
use crate::permission::Permission;
use crate::role::Role;
use crate::value::{Array, ArrayKey, Str, Value};

/// `TypeSample`: a value, its default (if the type takes one), whether it
/// increments.
struct Sample {
    factory: &'static str,
    value: Value,
    default: Option<Value>,
    incrementable: bool,
}

fn s(v: &str) -> Value {
    Value::Str(Str::from(v))
}

fn assoc(pairs: Vec<(&str, Value)>) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
}

/// The samples of the types Memory stores (PHP's `samples()` less
/// `UNAVAILABLE[Memory]`).
fn samples() -> Vec<Sample> {
    let sample =
        |factory, value, default: Option<Value>, incrementable| Sample { factory, value, default, incrementable };
    vec![
        sample("string", s("text"), Some(s("fallback")), false),
        sample("varchar", s("text"), Some(s("fallback")), false),
        sample("text", s("text"), Some(s("fallback")), false),
        sample("mediumText", s("text"), Some(s("fallback")), false),
        sample("longText", s("text"), Some(s("fallback")), false),
        sample("integer", Value::Int(5), Some(Value::Int(1)), true),
        sample("bigInteger", Value::Int(5), Some(Value::Int(1)), true),
        sample("float", Value::Float(1.5), Some(Value::Float(0.5)), true),
        sample("double", Value::Float(1.5), Some(Value::Float(0.5)), true),
        sample("boolean", Value::Bool(true), Some(Value::Bool(false)), false),
        sample("datetime", s("2024-01-01T00:00:00.000+00:00"), Some(s("2023-06-01T12:00:00.000+00:00")), false),
        sample("id", s("7"), None, false),
        sample("object", assoc(vec![("colour", s("red"))]), Some(assoc(vec![("colour", s("blue"))])), false),
    ]
}

fn attribute(factory: &str, default: Option<Value>) -> Attribute {
    let attribute = match factory {
        "string" => Attribute::string("value", 64),
        "varchar" => Attribute::varchar("value", 64),
        "text" => Attribute::text("value"),
        "mediumText" => Attribute::medium_text("value"),
        "longText" => Attribute::long_text("value"),
        "integer" => Attribute::integer("value"),
        "bigInteger" => Attribute::big_integer("value"),
        "float" => Attribute::float("value"),
        "double" => Attribute::double("value"),
        "boolean" => Attribute::boolean("value"),
        "datetime" => Attribute::datetime("value"),
        "id" => Attribute::id("value"),
        "object" => Attribute::object("value"),
        "point" => Attribute::point("value"),
        "lineString" => Attribute::line_string("value"),
        "polygon" => Attribute::polygon("value"),
        "vector" => Attribute::vector("value", 3),
        other => panic!("no factory {other}"),
    };
    match default {
        Some(default) => attribute.default(default),
        None => attribute,
    }
}

fn permissions() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any), Permission::update(&any), Permission::delete(&any)]
}

fn items(attributes: Vec<Attribute>) -> Collection {
    Collection::create("items", "", attributes, Vec::new(), Some(permissions()), true, Array::new()).unwrap()
}

async fn write(db: &Database, values: Vec<(&str, Value)>) {
    let mut document = Document::empty();
    document.set(document::ID, s("one")).unwrap();
    document
        .set(document::PERMISSIONS, Value::Array(Array::from_list(permissions().iter().map(|p| s(p)).collect())))
        .unwrap();
    for (k, v) in values {
        document.set(k, v).unwrap();
    }
    db.create_document("items", document).await.unwrap();
}

async fn read(db: &Database, key: &str) -> Option<Value> {
    db.get_document("items", "one", Vec::new(), false).await.unwrap().get(key).cloned()
}

#[test]
fn every_type_round_trips_through_create_attribute_and_create_collection() {
    block_on(async {
        for sample in samples() {
            let (_, db) = memory().await;
            db.create_collection(items(Vec::new())).await.unwrap();
            let created = db.create_attribute("items", attribute(sample.factory, None)).await.unwrap();
            assert_eq!(created.kind, attribute(sample.factory, None).kind, "{}", sample.factory);
            write(&db, vec![("value", sample.value.clone())]).await;
            assert_eq!(
                read(&db, "value").await,
                Some(sample.value.clone()),
                "{} through createAttribute",
                sample.factory
            );

            let (_, db) = memory().await;
            db.create_collection(items(vec![attribute(sample.factory, None)])).await.unwrap();
            write(&db, vec![("value", sample.value.clone())]).await;
            assert_eq!(
                read(&db, "value").await,
                Some(sample.value.clone()),
                "{} through createCollection",
                sample.factory
            );
        }
    });
}

#[test]
fn every_type_can_be_renamed() {
    block_on(async {
        for sample in samples() {
            let (_, db) = memory().await;
            db.create_collection(items(Vec::new())).await.unwrap();
            db.create_attribute("items", attribute(sample.factory, None)).await.unwrap();
            write(&db, vec![("value", sample.value.clone())]).await;
            let updated = db
                .update_attribute("items", "value", Update { key: Some("renamed".to_owned()), ..Update::default() })
                .await
                .unwrap();
            assert_eq!(updated.key, "renamed");
            assert_eq!(updated.kind, attribute(sample.factory, None).kind);
            assert_eq!(read(&db, "renamed").await, Some(sample.value.clone()), "{}", sample.factory);
        }
    });
}

#[test]
fn every_defaulted_type_takes_its_default() {
    block_on(async {
        for sample in samples() {
            let Some(default) = sample.default.clone() else { continue };
            let (_, db) = memory().await;
            db.create_collection(items(Vec::new())).await.unwrap();
            db.create_attribute("items", attribute(sample.factory, Some(default.clone()))).await.unwrap();
            write(&db, Vec::new()).await;
            assert_eq!(read(&db, "value").await, Some(default), "{}", sample.factory);
            let updated = db
                .update_attribute(
                    "items",
                    "value",
                    Update { default: Patch::Set(sample.value.clone()), ..Update::default() },
                )
                .await
                .unwrap();
            assert_eq!(updated.default, sample.value, "{}", sample.factory);
        }
    });
}

#[test]
fn a_default_on_an_id_is_a_mismatch() {
    block_on(async {
        let (_, db) = memory().await;
        db.create_collection(items(Vec::new())).await.unwrap();
        db.create_attribute("items", Attribute::id("value")).await.unwrap();
        let error = db
            .update_attribute("items", "value", Update { default: Patch::Set(s("5")), ..Update::default() })
            .await
            .unwrap_err();
        assert_eq!(error.message(), "Default value 5 does not match given type id");
    });
}

#[test]
fn only_numeric_types_increment() {
    block_on(async {
        for sample in samples() {
            let (_, db) = memory().await;
            db.create_collection(items(Vec::new())).await.unwrap();
            db.create_attribute("items", attribute(sample.factory, None)).await.unwrap();
            write(&db, vec![("value", sample.value.clone())]).await;
            let increased = db.increase_document_attribute("items", "one", "value", Value::Int(2), None).await;
            if !sample.incrementable {
                assert!(increased.unwrap_err().is_a(Kind::Type), "{}", sample.factory);
                continue;
            }
            let plus = |v: &Value, n: i64| match v {
                Value::Int(i) => Value::Int(i + n),
                Value::Float(f) => Value::Float(f + n as f64),
                other => panic!("{other:?}"),
            };
            assert_eq!(increased.unwrap().get("value"), Some(&plus(&sample.value, 2)), "{}", sample.factory);
            let decreased = db.decrease_document_attribute("items", "one", "value", Value::Int(2), None).await.unwrap();
            assert_eq!(decreased.get("value"), Some(&sample.value), "{}", sample.factory);
        }
    });
}

#[test]
fn types_memory_cannot_store_are_refused_up_front() {
    block_on(async {
        for (factory, message) in [
            ("point", "Spatial attributes are not supported"),
            ("lineString", "Spatial attributes are not supported"),
            ("polygon", "Spatial attributes are not supported"),
            ("vector", "Vector types are not supported by the current database"),
        ] {
            let (_, db) = memory().await;
            db.create_collection(items(Vec::new())).await.unwrap();
            let error = db.create_attribute("items", attribute(factory, None)).await.unwrap_err();
            assert_eq!(error.message(), message, "{factory}");
            let inline = Collection::create(
                "inline",
                "",
                vec![attribute(factory, None)],
                Vec::new(),
                Some(permissions()),
                true,
                Array::new(),
            );
            let error = db.create_collection(inline.unwrap()).await.unwrap_err();
            assert_eq!(error.message(), message, "{factory}");
            assert!(db.find_collection("inline").await.unwrap().is_none());
        }
    });
}

#[test]
fn unstorable_column_types_are_refused_up_front() {
    block_on(async {
        for kind in
            [ColumnType::TinyInteger, ColumnType::Decimal, ColumnType::Json, ColumnType::Uuid7, ColumnType::Array]
        {
            let key = |k: &str| ArrayKey::Str(Str::from(k));
            let data: Array = [(key("key"), s("value")), (key("type"), s(kind.as_str()))].into_iter().collect();
            let error = Attribute::from_array(&data).unwrap_err();
            assert_eq!(error.message(), format!("Unknown attribute type: {}", kind.as_str()));
        }
    });
}
