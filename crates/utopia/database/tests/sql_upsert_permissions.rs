//! An upsert that removes permissions deletes them from the collection's
//! permissions table, named once: PHP's `Hook\Permissions::afterDocumentUpsert()`
//! reads it with `builder()->fromTable($context->rawTable(...))`, the stored
//! name as it is (`Hook/Permissions.php:226`).

#![cfg(feature = "sqlite")]

use std::sync::Arc;

use utopia_database::adapter::Adapter;
use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::database::Hook;
use utopia_database::hook::Permissions;
use utopia_database::value::{Array, ArrayKey, Str, Value};
use utopia_database::{Attribute, Authorization, Collection, Database, Document};

fn doc(pairs: Vec<(&str, Value)>) -> Document {
    let mut a = Array::new();
    for (k, v) in pairs {
        a.set(ArrayKey::from(k), v);
    }
    Document::new(a).unwrap()
}

fn perms(p: &[&str]) -> Value {
    Value::Array(Array::from_list(p.iter().map(|s| Value::Str(Str::from(*s))).collect()))
}

#[tokio::test]
async fn an_upsert_removing_permissions_deletes_them_from_the_permissions_table() {
    let adapter = Sql::connect(Engine::Sqlite, "sqlite::memory:", None, None).await.unwrap();
    let profiler = Arc::new(utopia_database::Profiler::new());
    profiler.enable();
    adapter.set_profiler(Some(profiler.clone()));
    let db = Database::new(Arc::new(adapter), Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None))));
    let auth = Authorization::new(true);
    auth.add_role("any");
    db.set_authorization(Arc::new(auth));
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db.set_database("x").set_namespace("ns");
    db.create(None).await.unwrap();
    db.create_collection(Collection::create("t", "t", vec![], vec![], None, true, Array::new()).unwrap())
        .await
        .unwrap();
    db.create_attribute("t", Attribute::integer("v").required(true)).await.unwrap();
    db.create_document(
        "t",
        doc(vec![("$id", Value::from("a")), ("v", Value::Int(0)), ("$permissions", perms(&["update(\"any\")"]))]),
    )
    .await
    .unwrap();
    db.create_document(
        "t",
        doc(vec![("$id", Value::from("b")), ("v", Value::Int(0)), ("$permissions", perms(&["update(\"any\")"]))]),
    )
    .await
    .unwrap();
    profiler.reset();
    let r = db
        .upsert_documents(
            "t",
            vec![
                doc(vec![
                    ("$id", Value::from("a")),
                    ("v", Value::Int(0)),
                    ("$permissions", perms(&["read(\"any\")", "update(\"any\")"])),
                ]),
                doc(vec![("$id", Value::from("b")), ("v", Value::Int(0)), ("$permissions", perms(&["read(\"any\")"]))]),
            ],
            1000,
            None,
            None,
        )
        .await;
    assert_eq!(r.map_err(|e| e.message().to_owned()), Ok(2));
    let deletes: Vec<String> =
        profiler.logs().into_iter().map(|l| l.query).filter(|q| q.starts_with("DELETE")).collect();
    assert_eq!(deletes.len(), 1);
    assert!(deletes[0].starts_with("DELETE FROM `ns_t_perms` "), "{}", deletes[0]);
    let b = db.get_document("t", "b", Vec::new(), false).await.unwrap();
    assert_eq!(b.permissions().unwrap(), vec!["read(\"any\")".to_owned()]);
}
