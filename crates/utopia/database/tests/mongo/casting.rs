//! `MongoResultDecodingTest` and `MongoAttributesSupportTest`.

use utopia_database::adapter::mongo::{Mongo, replace_characters};
use utopia_database::adapter::{Casting, Find};
use utopia_database::collection::Collection;
use utopia_database::operator::Operator;
use utopia_database::value::{Array, BsonValue, Value};
use utopia_database::{Adapter, Capability, Document, Kind, Query};
use utopia_mongo::bson::{Bson, doc};

use crate::common::{assert_error, s, val};
use crate::fake::{Fake, at, batch, empty_batch};

/// `json_decode($json, true)` as an array.
fn array(json: &str) -> Array {
    match val(json) {
        Value::Array(a) => a,
        other => panic!("not an array: {other:?}"),
    }
}

/// A `stdClass` of `pairs`.
fn object(pairs: Vec<(&str, Value)>) -> Value {
    Value::Object(pairs.into_iter().map(|(k, v)| (utopia_database::value::ArrayKey::normalize(k.into()), v)).collect())
}

#[test]
fn stored_record_keys_are_restored() {
    let restored = replace_characters(
        "_",
        "$",
        array(
            r#"{"_uid":"movie1","_id":"17","_permissions":["read(\"any\")","update(\"user:1\")"],"_createdAt":"2026-01-01 00:00:00.000","tags":["t1","t2"],"profile__dot__name":"Ann","matrix":[{"_uid":"nested","a__dot__b":1},["x","y"]]}"#,
        ),
    );

    assert_eq!(
        restored,
        array(
            r#"{"tags":["t1","t2"],"matrix":[{"a.b":1,"$id":"nested"},["x","y"]],"$permissions":["read(\"any\")","update(\"user:1\")"],"$createdAt":"2026-01-01 00:00:00.000","profile.name":"Ann","$sequence":"17","$id":"movie1"}"#
        )
    );
}

#[test]
fn document_keys_are_stored() {
    let stored = replace_characters(
        "$",
        "_",
        array(
            r#"{"$id":"movie1","$permissions":["read(\"any\")"],"tags":["a.b","$c"],"profile.name":"Ann","$custom":"value"}"#,
        ),
    );

    assert_eq!(
        stored,
        array(
            r#"{"tags":["a.b","$c"],"_permissions":["read(\"any\")"],"profile__dot__name":"Ann","_custom":"value","_uid":"movie1"}"#
        )
    );
}

async fn casting_adapter(defined: bool) -> Mongo {
    let adapter = Mongo::with_driver(Fake::new()).await.unwrap();
    adapter.schemaless().unwrap().set_schemaless(!defined);
    adapter
}

fn casting_collection() -> Collection {
    Collection::from_document(&crate::common::doc(
        r#"{"$id":"movies","attributes":[
            {"$id":"score","key":"score","type":"integer","array":false},
            {"$id":"scores","key":"scores","type":"biginteger","array":true},
            {"$id":"price","key":"price","type":"float","array":false},
            {"$id":"ratio","key":"ratio","type":"double","array":false},
            {"$id":"active","key":"active","type":"boolean","array":false},
            {"$id":"flags","key":"flags","type":"boolean","array":true},
            {"$id":"tags","key":"tags","type":"string","array":true},
            {"$id":"name","key":"name","type":"string","array":false},
            {"$id":"meta","key":"meta","type":"object","array":false},
            {"$id":"when","key":"when","type":"datetime","array":false},
            {"$id":"legacy","type":"bigint","array":false}
        ]}"#,
    ))
    .unwrap()
}

#[tokio::test]
async fn cast_after_casts_collection_and_internal_attributes() {
    let adapter = casting_adapter(true).await;
    let collection = Collection::from_document(&crate::common::doc(
        r#"{"$id":"movies","attributes":[
            {"$id":"score","key":"score","type":"integer","array":false},
            {"$id":"price","key":"price","type":"float","array":false},
            {"$id":"active","key":"active","type":"boolean","array":false},
            {"$id":"tags","key":"tags","type":"string","array":true}
        ]}"#,
    ))
    .unwrap();

    for (stored, expected) in [(s("42"), 42), (s("7"), 7)] {
        let document = Document::from_array_unchecked(
            [
                ("$id", s("movie1")),
                ("$sequence", Value::Int(5)),
                ("$permissions", val(r#"["read(\"any\")"]"#)),
                ("score", stored),
                ("price", Value::Int(3)),
                ("active", Value::Int(1)),
                ("tags", val(r#"["a","b"]"#)),
            ]
            .into_iter()
            .map(|(k, v)| (k.into(), v))
            .collect(),
        );

        let cast = adapter.cast_after(&collection, vec![document]).unwrap().remove(0);

        let mut expected_array = array(
            r#"{"$id":"movie1","$sequence":"5","$permissions":["read(\"any\")"],"score":0,"price":3.0,"active":true,"tags":["a","b"]}"#,
        );
        expected_array.set("score".into(), Value::Int(expected));
        assert_eq!(cast.array_copy(), expected_array);
    }
}

#[tokio::test]
async fn cast_after_normalises_permissions_written_past_set_attribute() {
    let mut document = crate::common::doc(r#"{"$id":"movie1"}"#);
    document.put("$permissions", val(r#"["read(\"any\")","read(\"any\")","update(\"any\")"]"#));

    let cast = casting_adapter(true).await.cast_after(&casting_collection(), vec![document]).unwrap().remove(0);

    assert_eq!(cast.raw("$permissions"), Some(&val(r#"["read(\"any\")","update(\"any\")"]"#)));
}

/// `castingDocuments()`, each with what a cast with defined attributes and
/// one without make of it.
fn casting_documents() -> Vec<(&'static str, Document, Array, Array)> {
    let doc = |pairs: Vec<(&str, Value)>| {
        Document::from_array_unchecked(pairs.into_iter().map(|(k, v)| (k.into(), v)).collect())
    };
    let meta = object(vec![("a", Value::Int(1)), ("b", object(vec![("c", val("[2,3]"))]))]);
    let meta_array = val(r#"{"a":1,"b":{"c":[2,3]}}"#);
    let date = |ms: i64| Value::from(BsonValue::UtcDateTime(ms));
    let entries = |pairs: Vec<(&str, Value)>| -> Array { pairs.into_iter().map(|(k, v)| (k.into(), v)).collect() };
    vec![
        (
            "typed",
            doc(vec![
                ("$id", s("d1")),
                ("$sequence", s("1")),
                ("$permissions", val(r#"["read(\"any\")"]"#)),
                ("score", Value::Int(7)),
                ("price", Value::Float(1.5)),
                ("active", Value::Bool(true)),
                ("tags", val(r#"["a","b"]"#)),
                ("name", s("n")),
            ]),
            array(
                r#"{"$id":"d1","$sequence":"1","$permissions":["read(\"any\")"],"score":7,"price":1.5,"active":true,"tags":["a","b"],"name":"n"}"#,
            ),
            array(
                r#"{"$id":"d1","$sequence":"1","$permissions":["read(\"any\")"],"score":7,"price":1.5,"active":true,"tags":["a","b"],"name":"n"}"#,
            ),
        ),
        (
            "stored as other types",
            doc(vec![
                ("$id", s("d2")),
                ("$sequence", Value::Int(2)),
                ("score", s("42")),
                ("price", Value::Int(3)),
                ("active", Value::Int(0)),
                ("tags", Value::Int(5)),
                ("name", Value::Int(12)),
                ("ratio", s("0.25")),
            ]),
            array(
                r#"{"$id":"d2","$sequence":"2","score":42,"price":3.0,"active":false,"tags":["5"],"name":"12","ratio":0.25}"#,
            ),
            array(
                r#"{"$id":"d2","$sequence":"2","score":42,"price":3.0,"active":false,"tags":["5"],"name":"12","ratio":0.25}"#,
            ),
        ),
        (
            "json list",
            doc(vec![("$id", s("d3")), ("tags", s(r#"["x","y"]"#)), ("scores", s(r#"[1,"2",3.0]"#))]),
            array(r#"{"$id":"d3","tags":["x","y"],"scores":[1,2,3]}"#),
            array(r#"{"$id":"d3","tags":["x","y"],"scores":[1,2,3]}"#),
        ),
        (
            "numbers in lists",
            doc(vec![("$id", s("d4")), ("scores", val(r#"["1",2,"x",4.7]"#)), ("flags", val(r#"[0,1,"yes",""]"#))]),
            array(r#"{"$id":"d4","scores":[1,2,0,4],"flags":[false,true,true,false]}"#),
            array(r#"{"$id":"d4","scores":[1,2,0,4],"flags":[false,true,true,false]}"#),
        ),
        (
            "object",
            doc(vec![("$id", s("d5")), ("meta", meta.clone()), ("free", meta.clone())]),
            entries(vec![("$id", s("d5")), ("meta", meta_array.clone()), ("free", meta.clone())]),
            entries(vec![("$id", s("d5")), ("meta", meta_array.clone()), ("free", meta_array)]),
        ),
        (
            "datetime strings",
            doc(vec![("$id", s("d6")), ("$createdAt", s("2026-01-01 00:00:00.000")), ("when", s("not a date"))]),
            array(r#"{"$id":"d6","$createdAt":"2026-01-01 00:00:00.000","when":"not a date"}"#),
            array(r#"{"$id":"d6","$createdAt":"2026-01-01 00:00:00.000","when":"not a date"}"#),
        ),
        (
            "nulls and operators",
            doc(vec![
                ("$id", s("d7")),
                ("score", Value::Null),
                ("price", Value::from(Operator::increment(2, None))),
                ("tags", Value::Null),
            ]),
            entries(vec![
                ("$id", s("d7")),
                ("score", Value::Null),
                ("price", Value::from(Operator::increment(2, None))),
                ("tags", Value::Null),
            ]),
            entries(vec![
                ("$id", s("d7")),
                ("score", Value::Null),
                ("price", Value::from(Operator::increment(2, None))),
                ("tags", Value::Null),
            ]),
        ),
        ("empty", Document::empty(), Array::new(), Array::new()),
        (
            "internal only",
            doc(vec![("$id", s("d8")), ("$tenant", Value::Int(3)), ("$collection", s("movies"))]),
            array(r#"{"$id":"d8","$tenant":"3","$collection":"movies"}"#),
            array(r#"{"$id":"d8","$tenant":"3","$collection":"movies"}"#),
        ),
        (
            "bson values",
            doc(vec![
                ("$id", s("d9")),
                ("$sequence", Value::Int(9)),
                ("$createdAt", date(1_760_405_478_290)),
                ("score", Value::Int(12)),
                ("scores", val("[1,2]")),
                ("when", date(0)),
                ("free", date(1000)),
            ]),
            entries(vec![
                ("$id", s("d9")),
                ("$sequence", s("9")),
                ("$createdAt", s("2025-10-14 01:31:18.290")),
                ("score", Value::Int(12)),
                ("scores", val("[1,2]")),
                ("when", s("1970-01-01 00:00:00.000")),
                ("free", date(1000)),
            ]),
            entries(vec![
                ("$id", s("d9")),
                ("$sequence", s("9")),
                ("$createdAt", s("2025-10-14 01:31:18.290")),
                ("score", Value::Int(12)),
                ("scores", val("[1,2]")),
                ("when", s("1970-01-01 00:00:00.000")),
                ("free", s("1970-01-01 00:00:01.000")),
            ]),
        ),
    ]
}

#[tokio::test]
async fn cast_after_casts_a_batch_as_it_casts_one_document() {
    let collection = casting_collection();
    let one = casting_adapter(false).await;
    let many = casting_adapter(false).await;
    for (name, document, _, schemaless) in casting_documents() {
        let expected = one.cast_after(&collection, vec![document.clone()]).unwrap().remove(0).array_copy();
        assert_eq!(expected, schemaless, "{name}");

        let documents = many.cast_after(&collection, vec![document.clone(), document]).unwrap();

        assert_eq!(documents.len(), 2);
        assert_eq!(documents[0].array_copy(), expected, "{name}");
        assert_eq!(documents[1].array_copy(), expected, "{name}");
    }
    assert!(many.cast_after(&collection, vec![]).unwrap().is_empty());
}

#[tokio::test]
async fn cast_after_casts_by_attribute_type_with_defined_attributes() {
    let collection = casting_collection();
    let adapter = casting_adapter(true).await;
    for (name, document, defined, _) in casting_documents() {
        assert_eq!(adapter.cast_after(&collection, vec![document]).unwrap().remove(0).array_copy(), defined, "{name}");
    }
}

#[tokio::test]
async fn cast_after_refuses_a_stored_attribute_of_an_unknown_type() {
    let mut definition = casting_collection().to_document();
    definition
        .set_with(
            "attributes",
            val(r#"{"$id":"unknown","type":"no such type","array":false}"#),
            utopia_database::document::SetType::Append,
        )
        .unwrap();
    let collection = Collection::from_document(&definition).unwrap();

    let result = casting_adapter(true)
        .await
        .cast_after(&collection, vec![crate::common::doc(r#"{"$id":"m1","unknown":"value"}"#)]);
    assert_error(result, Kind::Structure, "Unknown attribute type: no such type");
}

#[tokio::test]
async fn found_records_turn_nested_objects_into_arrays() {
    let fake = Fake::new();
    fake.on("find", |_| {
        Ok(batch(vec![doc! {
            "_uid": "movie1",
            "_id": "17",
            "_permissions": ["read(\"any\")"],
            "meta": { "a": 1, "b": { "c": [1, { "d": "x" }] }, "e": {} },
            "list": [1, "two", { "k": true }, [3, {}], Bson::Null],
            "keyed": { "x": { "y": 2 }, "z": 1.5 },
            "empty": {},
            "none": Bson::Null,
            "score": 4,
        }]))
    });
    let adapter = Mongo::with_driver(fake).await.unwrap();

    let found = adapter
        .find(&Collection::from_document(&crate::common::doc(r#"{"$id":"movies"}"#)).unwrap(), vec![], Find::default())
        .await
        .unwrap();

    let empty = || Value::Object(Array::new());
    let mut meta = array(r#"{"a":1,"b":{"c":[1,{"d":"x"}]}}"#);
    meta.set("e".into(), empty());
    let mut list = array(r#"[1,"two",{"k":true}]"#);
    let mut nested = array("[3]");
    let _ = nested.push(empty());
    let _ = list.push(Value::Array(nested));
    let _ = list.push(Value::Null);
    let mut expected = Array::new();
    expected.set("meta".into(), Value::Array(meta));
    expected.set("list".into(), Value::Array(list));
    expected.set("keyed".into(), val(r#"{"x":{"y":2},"z":1.5}"#));
    expected.set("empty".into(), empty());
    expected.set("none".into(), Value::Null);
    expected.set("score".into(), Value::Int(4));
    expected.set("$permissions".into(), val(r#"["read(\"any\")"]"#));
    expected.set("$sequence".into(), s("17"));
    expected.set("$id".into(), s("movie1"));
    assert_eq!(found.len(), 1);
    assert_eq!(found[0].array_copy(), expected);
}

#[tokio::test]
async fn projection_skips_internal_attributes() {
    let fake = Fake::new();
    fake.on("find", |_| Ok(empty_batch()));
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();

    adapter
        .get_document(
            &Collection::from_document(&crate::common::doc(r#"{"$id":"movies"}"#)).unwrap(),
            "movie1",
            vec![Query::select(["name", "$id", "$createdAt"])],
            false,
        )
        .await
        .unwrap();

    let projections: Vec<Option<Bson>> =
        fake.args("find").into_iter().map(|a| at(&a[2], "projection").cloned()).collect();
    assert_eq!(
        projections,
        vec![Some(Bson::Document(
            doc! { "name": 1, "_uid": 1, "_id": 1, "_createdAt": 1, "_updatedAt": 1, "_permissions": 1 }
        ))]
    );
}

// ---- MongoAttributesSupportTest

#[tokio::test]
async fn defined_attributes_follows_the_schemaless_mode() {
    let adapter = Mongo::with_driver(Fake::new()).await.unwrap();
    let schemaless = adapter.schemaless().unwrap();

    assert!(!schemaless.is_schemaless());
    assert!(adapter.supports(Capability::DefinedAttributes));

    schemaless.set_schemaless(true);
    assert!(schemaless.is_schemaless());
    assert!(!adapter.supports(Capability::DefinedAttributes));

    schemaless.set_schemaless(false);
    assert!(!schemaless.is_schemaless());
    assert!(adapter.supports(Capability::DefinedAttributes));
}
