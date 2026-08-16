//! Ports PHP `tests/unit/*` (Memory adapter). Live SQL/Mongo engines are in `e2e.rs`.

use serde_json::json;
use utopia_cache::adapter::Memory as MemoryCache;
use utopia_cache::Cache;
use utopia_database::document::SET_TYPE_APPEND;
use utopia_database::helpers::{Id, Permission, Role};
use utopia_database::operator::{self, Operator};
use utopia_database::prelude::*;
use utopia_database::query;
use utopia_database::validator::key::Key;
use utopia_database::validator::query::base::QueryMethodValidator;
use utopia_database::validator::query::Limit;
use utopia_database::validator::uid::Uid;
use utopia_database::{AttrValue, Query, PERMISSION_READ};
use utopia_validators::Validator;

fn sample_document() -> Document {
    Document::from_pairs([
        ("$id", AttrValue::from(Id::custom("doc1"))),
        ("$collection", AttrValue::from("col1")),
        (
            "$permissions",
            AttrValue::from(vec![
                Permission::read(&Role::user("123", "")),
                Permission::read(&Role::team("123", "")),
                Permission::create(&Role::any()),
                Permission::create(&Role::user("creator", "")),
                Permission::update(&Role::any()),
                Permission::update(&Role::user("updater", "")),
                Permission::delete(&Role::any()),
                Permission::delete(&Role::user("deleter", "")),
            ]),
        ),
        ("title", AttrValue::from("This is a test.")),
        ("list", AttrValue::from(vec!["one"])),
    ])
    .unwrap()
}

#[test]
fn document_nulls() {
    let document =
        Document::from_pairs([("cat", AttrValue::Null), ("dog", AttrValue::Null)]).unwrap();
    assert!(document.get_attribute("cat").is_null());
    assert!(!document.is_set("cat"));
    assert_eq!(
        document
            .get_attribute_or("cat", &AttrValue::from("cat"))
            .as_str(),
        Some("cat")
    );
}

#[test]
fn document_id_permissions_and_attributes() {
    let empty = Document::new();
    assert!(empty.get_id().is_empty());
    assert!(empty.get_collection().is_empty());
    assert!(empty.get_create().is_empty());
    assert!(empty.get_read().is_empty());

    let document = sample_document();
    assert_eq!(document.get_id(), "doc1");
    assert_eq!(document.get_collection(), "col1");
    assert_eq!(
        document.get_create(),
        vec!["any".to_string(), "user:creator".to_string()]
    );
    assert_eq!(
        document.get_read(),
        vec!["user:123".to_string(), "team:123".to_string()]
    );
    assert_eq!(
        document.get_update(),
        vec!["any".to_string(), "user:updater".to_string()]
    );
    assert_eq!(
        document.get_delete(),
        vec!["any".to_string(), "user:deleter".to_string()]
    );
    assert_eq!(
        document
            .get_attribute_or("title", &AttrValue::from(""))
            .as_str(),
        Some("This is a test.")
    );
    assert_eq!(
        document
            .get_attribute_or("titlex", &AttrValue::from(""))
            .as_str(),
        Some("")
    );
}

#[test]
fn document_set_append_and_id_must_be_string() {
    let mut document = sample_document();
    document.set_attribute("title", "New title");
    assert_eq!(document.get_attribute("title").as_str(), Some("New title"));
    document.set_attribute_typed("list", AttrValue::from("two"), SET_TYPE_APPEND);
    let list = document.get_attribute("list").as_array().unwrap();
    assert_eq!(list.len(), 2);

    let err = Document::from_pairs([("$id", AttrValue::from(1i64))]).unwrap_err();
    assert!(err.message().contains("$id must be of type string"));
}

#[test]
fn query_constructors_match_php() {
    let query = Query::new(
        query::TYPE_EQUAL,
        "title",
        vec![AttrValue::from("Iron Man")],
    );
    assert_eq!(query.get_method(), query::TYPE_EQUAL);
    assert_eq!(query.get_attribute(), "title");
    assert_eq!(query.get_values()[0].as_str(), Some("Iron Man"));

    let query = Query::new(query::TYPE_ORDER_DESC, "score", vec![]);
    assert_eq!(query.get_method(), query::TYPE_ORDER_DESC);
    assert_eq!(query.get_attribute(), "score");
    assert!(query.get_values().is_empty());

    let query = Query::new(query::TYPE_LIMIT, "", vec![AttrValue::from(10i64)]);
    assert_eq!(query.get_method(), query::TYPE_LIMIT);
    assert_eq!(query.get_attribute(), "");
    assert_eq!(query.get_values()[0].as_i64(), Some(10));

    let query = Query::equal("title", vec![AttrValue::from("Iron Man")]);
    assert_eq!(query.get_method(), query::TYPE_EQUAL);

    let query = Query::greater_than("score", 10i64);
    assert_eq!(query.get_method(), query::TYPE_GREATER);
    assert_eq!(query.get_values()[0].as_i64(), Some(10));

    let vector = vec![0.1, 0.2, 0.3];
    let query = Query::vector_dot("embedding", vector.clone());
    assert_eq!(query.get_method(), query::TYPE_VECTOR_DOT);
    assert_eq!(query.get_attribute(), "embedding");

    let query = Query::search("search", "John Doe");
    assert_eq!(query.get_values()[0].as_str(), Some("John Doe"));

    let query = Query::order_asc("score");
    assert_eq!(query.get_method(), query::TYPE_ORDER_ASC);
    assert_eq!(query.get_attribute(), "score");

    assert!(Query::is_method(query::TYPE_EQUAL));
    assert!(!Query::is_method("regex"));
}

#[test]
fn operator_helpers_match_php() {
    let operator = Operator::new(
        operator::TYPE_INCREMENT,
        "count",
        vec![AttrValue::from(1i64)],
    );
    assert_eq!(operator.get_method(), operator::TYPE_INCREMENT);
    assert_eq!(operator.get_attribute(), "count");
    assert_eq!(operator.get_value().as_i64(), Some(1));

    let operator = Operator::increment(5.0, None);
    assert_eq!(operator.get_method(), operator::TYPE_INCREMENT);
    assert_eq!(operator.get_attribute(), "");
    assert_eq!(operator.get_value().as_i64(), Some(5));

    let operator = Operator::increment(1.0, None);
    assert_eq!(operator.get_value().as_i64(), Some(1));

    let operator = Operator::string_concat(" - Updated");
    assert_eq!(operator.get_method(), operator::TYPE_STRING_CONCAT);

    let operator = Operator::multiply(2.0, Some(1000.0));
    assert_eq!(operator.get_method(), operator::TYPE_MULTIPLY);
}

#[test]
fn helpers_id_role_permission() {
    assert_eq!(Id::custom("test"), "test");
    let id = Id::unique().unwrap();
    assert!(!id.is_empty());

    let role = Role::parse("user:123").unwrap();
    assert_eq!(role.get_role(), "user");
    assert_eq!(role.get_identifier(), "123");

    let role = Role::parse("team:123/admin").unwrap();
    assert_eq!(role.get_identifier(), "123");
    assert_eq!(role.get_dimension(), "admin");

    let role = Role::parse("users/verified").unwrap();
    assert_eq!(role.get_role(), "users");
    assert_eq!(role.get_dimension(), "verified");

    assert_eq!(Role::any().to_string(), "any");
    assert_eq!(Role::user("123", "").to_string(), "user:123");
    assert_eq!(Role::team("123", "admin").to_string(), "team:123/admin");

    let permission = Permission::parse("read(\"any\")").unwrap();
    assert_eq!(permission.get_permission(), "read");
    assert_eq!(permission.get_role(), "any");

    let permission = Permission::parse("read(\"team:123/admin\")").unwrap();
    assert_eq!(permission.get_identifier(), "123");
    assert_eq!(permission.get_dimension(), "admin");

    assert_eq!(Permission::read(&Role::any()), "read(\"any\")");
    assert_eq!(
        Permission::create(&Role::user("123", "")),
        "create(\"user:123\")"
    );
    assert_eq!(
        Permission::read(&Role::team("123", "admin")),
        "read(\"team:123/admin\")"
    );

    let aggregated = Permission::aggregate(
        Some(&["write(\"any\")".into()]),
        &["create", "update", "delete"],
    )
    .unwrap()
    .unwrap();
    assert!(aggregated.contains(&"create(\"any\")".to_string()));
    assert!(aggregated.contains(&"update(\"any\")".to_string()));
    assert!(aggregated.contains(&"delete(\"any\")".to_string()));
}

#[test]
fn validator_key_and_uid() {
    let key = Key::default();
    assert!(!key.is_valid(&json!(false)));
    assert!(!key.is_valid(&json!(null)));
    assert!(!key.is_valid(&json!(["value"])));
    assert!(!key.is_valid(&json!(0)));
    assert!(key.is_valid(&json!("asdas7as9as")));
    assert!(key.is_valid(&json!("5f058a8925807")));
    assert!(!key.is_valid(&json!("")));
    assert!(key.is_valid(&json!("0")));
    assert!(!key.is_valid(&json!("_asdasdasdas")));
    assert!(key.is_valid(&json!("as_5dasdasdas")));

    let uid = Uid::default();
    assert!(uid.is_valid(&json!("5f058a8925807")));
}

#[test]
fn validator_query_limit() {
    let validator = Limit::new(100);
    assert!(validator.is_valid_query(&Query::limit(1)));
    assert!(validator.is_valid_query(&Query::limit(100)));
    assert!(!validator.is_valid_query(&Query::limit(0)));
    assert_eq!(
        validator.description(),
        "Invalid limit: Value must be a valid range between 1 and 100"
    );
    assert!(!validator.is_valid_query(&Query::limit(-1)));
    assert!(!validator.is_valid_query(&Query::limit(101)));
}

#[test]
fn memory_crud_find_count() {
    let cache = Cache::new(MemoryCache::new());
    let mut db = Database::new(Memory::new(), cache);
    db.set_database("app").unwrap();
    db.set_namespace("ns").unwrap();
    db.create(None).unwrap();

    db.skip_authorization(|db| {
        db.create_collection(
            "movies",
            vec![Document::from_pairs([
                ("$id", AttrValue::from("title")),
                ("type", AttrValue::from("string")),
                ("size", AttrValue::from(128i64)),
                ("required", AttrValue::from(true)),
                ("signed", AttrValue::from(true)),
                ("array", AttrValue::from(false)),
            ])
            .unwrap()],
            vec![],
            Some(vec![
                Permission::create(&Role::any()),
                Permission::read(&Role::any()),
                Permission::update(&Role::any()),
                Permission::delete(&Role::any()),
            ]),
            true,
        )
    })
    .unwrap();

    let doc = db
        .skip_authorization(|db| {
            db.create_document(
                "movies",
                Document::from_pairs([
                    ("$id", AttrValue::from("tt1")),
                    ("title", AttrValue::from("Dune")),
                ])
                .unwrap(),
            )
        })
        .unwrap();
    assert_eq!(doc.get_id(), "tt1");

    let found = db
        .skip_authorization(|db| db.get_document("movies", "tt1", &[], false))
        .unwrap();
    assert_eq!(found.get_attribute("title").as_str(), Some("Dune"));

    let listed = db
        .skip_authorization(|db| {
            db.find(
                "movies",
                &[Query::equal("title", vec![AttrValue::from("Dune")])],
                PERMISSION_READ,
            )
        })
        .unwrap();
    assert_eq!(listed.len(), 1);

    let count = db
        .skip_authorization(|db| db.count("movies", &[], None))
        .unwrap();
    assert_eq!(count, 1);

    db.skip_authorization(|db| db.delete_document("movies", "tt1"))
        .unwrap();
    let missing = db
        .skip_authorization(|db| db.get_document("movies", "tt1", &[], false))
        .unwrap();
    assert!(missing.is_empty());
}
