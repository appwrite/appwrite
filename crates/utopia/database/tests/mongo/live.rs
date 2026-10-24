//! The adapter against a MongoDB server, on the official driver: the
//! operations `oracle/mongo_ops.php` runs with PHP's adapter, their results
//! printed as JSON. Runs only when `MONGO_CROSSCHECK` names the namespace
//! to write (the compat replica set of `tests/compat/database/services.sh`,
//! on `localhost:16018`, database `crosscheck`); `MONGO_CROSSCHECK_SHARED`
//! turns shared tables on, with tenant 7.
//!
//! ```text
//! docker cp tests/mongo/oracle/mongo_ops.php appwrite:/tmp/ && docker cp tests/mongo/oracle/mongo_dump.php appwrite:/tmp/
//! docker exec appwrite php -d error_reporting=0 /tmp/mongo_ops.php php [shared]
//! MONGO_CROSSCHECK=rust [MONGO_CROSSCHECK_SHARED=1] cargo test -p utopia-database --features mongo --test mongo live -- --nocapture
//! docker exec appwrite php /tmp/mongo_dump.php   # IDENTICAL: both namespaces store the same documents and indexes
//! ```

use std::sync::Arc;

use utopia_database::adapter::mongo::Mongo;
use utopia_database::adapter::{Casting, Find};
use utopia_database::attribute::Attribute;
use utopia_database::change::Change;
use utopia_database::collection::Collection;
use utopia_database::operator::Operator;
use utopia_database::validator::authorization::Authorization;
use utopia_database::value::Value;
use utopia_database::{Adapter, Document, Index, Query};
use utopia_mongo::{Client, Config};
use utopia_query::{CursorDirection, OrderDirection};

use crate::common::{doc, s};

fn json(value: &Value) -> serde_json::Value {
    value.to_json()
}

#[utopia_async::test]
async fn crosscheck() {
    let Ok(ns) = std::env::var("MONGO_CROSSCHECK") else { return };
    let config = Config::new("crosscheck", "localhost", 16018, "root", "password");
    let client = Client::new(config).unwrap();
    let adapter = Mongo::new(Arc::new(client)).await.unwrap();
    let authorization = Authorization::new(true);
    authorization.disable();
    adapter.set_authorization(Arc::new(authorization));
    adapter.set_database("crosscheck");
    adapter.set_namespace(&ns);
    if std::env::var("MONGO_CROSSCHECK_SHARED").is_ok() {
        adapter.set_shared_tables(true);
        adapter.set_tenant(Value::Int(7));
    }

    let mut out = serde_json::Map::new();
    let attributes = vec![
        Attribute::string("title", 128),
        Attribute::integer("year"),
        Attribute::string("tags", 32).array(true),
        Attribute::object("meta"),
        Attribute::datetime("released"),
        Attribute::double("rating"),
    ];
    let indexes = vec![
        Index::unique("by_title", ["title"], vec![], vec![]).unwrap(),
        Index::key("by_year", ["year"], vec![], vec![Some(OrderDirection::Desc)]).unwrap(),
    ];
    let _ = adapter.delete_collection("movies").await;
    out.insert("create".into(), adapter.create_collection("movies", &attributes, &indexes).await.unwrap().into());
    let collection = Collection::create("movies", "", attributes, vec![], None, true, Default::default()).unwrap();

    let document = doc(
        r#"{"$id":"m1","$permissions":["read(\"any\")"],"$createdAt":"2026-01-01 10:00:00.000","$updatedAt":"2026-01-01 10:00:00.000","title":"Alpha.$One","year":2001,"tags":["a","b"],"meta":"{\"k\":{\"n\":1},\"list\":[1,2]}","released":"2001-05-06 07:08:09.123","rating":7.5,"$custom":"x","dotted.key":"v"}"#,
    );
    let created =
        adapter.create_document(&collection, adapter.cast_before(&collection, document).unwrap()).await.unwrap();
    out.insert("created".into(), created.keys().map(|k| serde_json::Value::String(key_name(k))).collect());

    let mut batch = Vec::new();
    for (id, title, year) in [("m2", "Beta", 1999), ("m3", "Gamma", 2010)] {
        let document = doc(&format!(
            r#"{{"$id":"{id}","$permissions":[],"$createdAt":"2026-01-02 00:00:00.000","$updatedAt":"2026-01-02 00:00:00.000","title":"{title}","year":{year},"tags":["c"],"released":"2010-01-01T00:00:00.000+02:00","rating":5}}"#
        ));
        batch.push(adapter.cast_before(&collection, document).unwrap());
    }
    out.insert("createMany".into(), adapter.create_documents(&collection, batch).await.unwrap().len().into());

    let update = Document::from_pairs([
        ("year", Value::from(Operator::increment(5, Some(3000.into())))),
        ("tags", Value::from(Operator::array_append([s("z")]))),
        ("title", Value::from(Operator::string_concat("!"))),
        ("rating", Value::from(Operator::multiply(2, None))),
        ("$updatedAt", s("2026-02-01 00:00:00.000")),
    ])
    .unwrap();
    adapter.update_document(&collection, "m1", adapter.cast_before(&collection, update).unwrap(), true).await.unwrap();
    adapter
        .increase_document_attribute(
            &collection,
            "m2",
            "year",
            Value::Int(3),
            "2026-03-01 00:00:00.000",
            None,
            Some(Value::Int(5000)),
        )
        .await
        .unwrap();
    adapter
        .increase_document_attribute(&collection, "m3", "year", s("-10"), "2026-03-01 00:00:00.000", Some(s("0")), None)
        .await
        .unwrap();

    let upserts = vec![
        Change::new(
            Document::empty(),
            adapter
                .cast_before(&collection, doc(r#"{"$id":"m4","$permissions":[],"$createdAt":"2026-04-01 00:00:00.000","$updatedAt":"2026-04-01 00:00:00.000","title":"Delta","year":1980,"tags":[]}"#))
                .unwrap(),
        ),
        Change::new(
            Document::empty(),
            adapter
                .cast_before(&collection, doc(r#"{"$id":"m2","$permissions":["read(\"any\")"],"$createdAt":"2026-01-02 00:00:00.000","$updatedAt":"2026-04-01 00:00:00.000","title":"Beta2","year":1999,"tags":["q"]}"#))
                .unwrap(),
        ),
    ];
    adapter.upserts().unwrap().upsert_documents(&collection, upserts, None).await.unwrap();

    let ids = |documents: Vec<Document>| -> serde_json::Value {
        documents.iter().map(|d| serde_json::Value::String(d.id().to_owned())).collect()
    };
    let ordered =
        Find { order_attributes: vec!["year".into()], order_types: vec![OrderDirection::Asc], ..Find::default() };
    out.insert("find_all".into(), ids(adapter.find(&collection, vec![], ordered).await.unwrap()));
    out.insert(
        "find_between".into(),
        ids(adapter.find(&collection, vec![Query::between("year", 1990, 2010)], Find::default()).await.unwrap()),
    );
    out.insert(
        "find_contains".into(),
        ids(adapter.find(&collection, vec![Query::contains("title", ["ET"])], Find::default()).await.unwrap()),
    );
    out.insert(
        "find_starts".into(),
        ids(adapter.find(&collection, vec![Query::starts_with("title", "Alpha.$")], Find::default()).await.unwrap()),
    );
    let mut tags = Query::contains("tags", ["c", "z"]);
    tags.set_on_array(true);
    out.insert("find_tags".into(), ids(adapter.find(&collection, vec![tags], Find::default()).await.unwrap()));
    out.insert(
        "find_created".into(),
        ids(adapter
            .find(
                &collection,
                vec![Query::greater_than("$createdAt", "2026-01-01T12:00:00.000+00:00")],
                Find::default(),
            )
            .await
            .unwrap()),
    );
    let mut cursor = utopia_database::value::Array::new();
    cursor.set("year".into(), Value::Int(2002));
    cursor.set("$sequence".into(), s("0"));
    let paged = Find {
        order_attributes: vec!["year".into(), "$sequence".into()],
        order_types: vec![OrderDirection::Desc, OrderDirection::Asc],
        cursor,
        cursor_direction: CursorDirection::After,
        ..Find::default()
    };
    out.insert("find_cursor".into(), ids(adapter.find(&collection, vec![], paged).await.unwrap()));
    out.insert(
        "find_or".into(),
        ids(adapter
            .find(
                &collection,
                vec![Query::or([Query::equal("year", [2006]), Query::less_than("year", 1990)])],
                Find::default(),
            )
            .await
            .unwrap()),
    );
    let selected = adapter
        .find(&collection, vec![Query::select(["title"]), Query::equal("$id", ["m1"])], Find::default())
        .await
        .unwrap();
    out.insert(
        "find_select".into(),
        selected
            .iter()
            .map(|d| d.keys().map(|k| serde_json::Value::String(key_name(k))).collect::<serde_json::Value>())
            .collect(),
    );
    out.insert(
        "count".into(),
        adapter.count(&collection, vec![Query::greater_than("year", 1990)], None).await.unwrap().into(),
    );
    out.insert("count_max".into(), adapter.count(&collection, vec![], Some(2)).await.unwrap().into());
    out.insert("sum".into(), json(&adapter.sum(&collection, "year", vec![], None).await.unwrap()));
    out.insert("sum_rating".into(), json(&adapter.sum(&collection, "rating", vec![], None).await.unwrap()));
    let read = adapter.get_document(&collection, "m1", vec![], false).await.unwrap();
    let mut read = adapter.cast_after(&collection, vec![read]).unwrap().remove(0);
    read.remove("$sequence");
    let mut fields: Vec<(String, Value)> = read.iter().map(|(k, v)| (key_name(k), v.clone())).collect();
    fields.sort_by(|a, b| a.0.cmp(&b.0));
    out.insert(
        "get_m1".into(),
        fields.into_iter().map(|(k, v)| (k, json(&v))).collect::<serde_json::Map<_, _>>().into(),
    );
    let sequences =
        adapter.get_sequences(&collection, vec![doc(r#"{"$id":"m3"}"#), doc(r#"{"$id":"nope"}"#)]).await.unwrap();
    out.insert("sequences".into(), sequences.iter().map(|d| serde_json::Value::Bool(d.sequence().is_some())).collect());
    out.insert("delete".into(), adapter.delete_document(&collection, "m3").await.unwrap().into());
    let failure = adapter
        .create_document(
            &collection,
            adapter.cast_before(&collection, doc(r#"{"$id":"m9","$permissions":[],"title":"Delta"}"#)).unwrap(),
        )
        .await;
    if let Err(failure) = failure {
        out.insert("dup".into(), serde_json::json!([failure.php_class(), failure.message()]));
    }
    let failure = adapter
        .create_document(
            &collection,
            adapter.cast_before(&collection, doc(r#"{"$id":"m1","$permissions":[],"title":"Other"}"#)).unwrap(),
        )
        .await;
    if let Err(failure) = failure {
        out.insert("dup_id".into(), serde_json::json!([failure.php_class(), failure.message()]));
    }
    let committed = utopia_database::adapter::with_transaction(&adapter, || async {
        let m5 = doc(r#"{"$id":"m5","$permissions":[],"title":"Tx","year":1}"#);
        adapter.create_document(&collection, adapter.cast_before(&collection, m5)?).await?;
        adapter
            .increase_document_attribute(
                &collection,
                "m5",
                "year",
                Value::Int(1),
                "2026-05-01 00:00:00.000",
                None,
                None,
            )
            .await?;
        Ok("ok")
    })
    .await
    .unwrap();
    out.insert("tx".into(), committed.into());
    let failure = utopia_database::adapter::with_transaction(&adapter, || async {
        let m6 = doc(r#"{"$id":"m6","$permissions":[],"title":"Rolled","year":1}"#);
        adapter.create_document(&collection, adapter.cast_before(&collection, m6)?).await?;
        Err::<(), _>(utopia_database::Error::structure("boom"))
    })
    .await
    .unwrap_err();
    out.insert("tx_failed".into(), serde_json::json!([failure.php_class(), failure.message()]));
    out.insert("tx_m6".into(), adapter.get_document(&collection, "m6", vec![], false).await.unwrap().is_empty().into());
    adapter.rename_attribute("movies", "rating", "score").await.unwrap();
    println!("{}", serde_json::to_string_pretty(&serde_json::Value::Object(out)).unwrap());
}

fn key_name(k: utopia_database::value::KeyRef<'_>) -> String {
    match k {
        utopia_database::value::KeyRef::Int(i) => i.to_string(),
        utopia_database::value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}
