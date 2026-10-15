//! PHP: `tests/unit/Relationships/NestedLinkPermissionsTest.php`,
//! `StoredRelatedDocumentLinkTest.php` and `OneWayChildSideTest.php` (the
//! Memory cases).

use super::*;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipSide, RelationshipType, Update};

const USER: &str = "u1";
const NO_READ: &str = "No permissions provided for action 'read'";
const NO_UPDATE: &str = "No permissions provided for action 'update'";
const GUEST_NO_READ: &str =
    "Missing \"read\" permission for role \"users\". Only \"[\"any\",\"guests\"]\" scopes are allowed and \"[\"users\"]\" was given.";
const GUEST_NO_UPDATE: &str =
    "Missing \"update\" permission for role \"users\". Only \"[\"any\",\"guests\"]\" scopes are allowed and \"[\"users\"]\" was given.";

#[derive(Clone, Copy, PartialEq, Debug)]
enum Caller {
    Key,
    Session,
    Guest,
}

/// The relationship types of the cases, with the one-way one-to-one.
const TYPES: [&str; 5] = ["oneToOne", "oneToOne one-way", "oneToMany", "manyToOne", "manyToMany"];

fn relationship(kind: &str) -> Relationship {
    let two_way = |kind| Relationship::new("related", kind).key("related").two_way(true).two_way_key("back").on_delete(OnDelete::SetNull);
    match kind {
        "oneToOne" => two_way(RelationshipType::OneToOne),
        "oneToOne one-way" => Relationship::one_to_one("related").key("related").on_delete(OnDelete::SetNull),
        "oneToMany" => two_way(RelationshipType::OneToMany),
        "manyToOne" => two_way(RelationshipType::ManyToOne),
        _ => two_way(RelationshipType::ManyToMany),
    }
}

fn many(kind: &str) -> bool {
    matches!(kind, "oneToMany" | "manyToMany")
}

struct Scenario {
    name: &'static str,
    document_security: bool,
    collection: Vec<String>,
    document: Vec<String>,
    caller: Caller,
    by_id: Option<&'static str>,
    as_document: Option<&'static str>,
}

fn scenarios() -> Vec<Scenario> {
    let users = Role::users("");
    let user = Role::user(USER, "");
    let read = vec![Permission::read(&users)];
    let update = vec![Permission::read(&users), Permission::update(&users)];
    let own = vec![Permission::read(&user), Permission::update(&user)];
    let scenario = |name, document_security, collection, document, caller, by_id, as_document| Scenario {
        name,
        document_security,
        collection,
        document,
        caller,
        by_id,
        as_document,
    };
    vec![
        scenario("update on the collection", false, update.clone(), Vec::new(), Caller::Session, None, None),
        scenario("read without update", false, read, Vec::new(), Caller::Session, Some(NO_UPDATE), Some(NO_UPDATE)),
        scenario("update on the document under document security", true, Vec::new(), own, Caller::Session, None, None),
        scenario(
            "read without update under document security",
            true,
            Vec::new(),
            vec![Permission::read(&user)],
            Caller::Session,
            Some(NO_UPDATE),
            Some(NO_UPDATE),
        ),
        scenario(
            "a document hidden by document security",
            true,
            vec![Permission::create(&users)],
            Vec::new(),
            Caller::Session,
            Some(NO_READ),
            Some(NO_UPDATE),
        ),
        scenario(
            "update and create without read",
            false,
            vec![Permission::create(&users), Permission::update(&users)],
            Vec::new(),
            Caller::Session,
            Some(NO_READ),
            Some(NO_READ),
        ),
        scenario(
            "a guest where users may read and update",
            false,
            [update, vec![Permission::create(&Role::any())]].concat(),
            Vec::new(),
            Caller::Guest,
            Some(GUEST_NO_READ),
            Some(GUEST_NO_UPDATE),
        ),
        scenario("an API key without any permission", false, Vec::new(), Vec::new(), Caller::Key, None, None),
    ]
}

/// Every case: a write (in a transaction or not), a type, a scenario, and
/// the related document given by id or as a document.
fn each_case(mut test: impl FnMut(bool, &str, &Scenario, bool, Option<&'static str>)) {
    for transaction in [true, false] {
        for kind in TYPES {
            for scenario in scenarios() {
                test(transaction, kind, &scenario, true, scenario.by_id);
                test(transaction, kind, &scenario, false, scenario.as_document);
            }
        }
    }
}

/// `seed()`.
async fn seed(kind: &str, scenario: &Scenario) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::new(Arc::new(Memory::new()), cache);
    db.set_authorization(Arc::new(Authorization::new(true)));
    db.set_database("nested_links").set_namespace(&unique("nested_links"));
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.authorization()
        .skip(async {
            db.create(None).await.unwrap();
            let parents = Collection::create(
                "parents",
                "",
                vec![string("name", 64)],
                Vec::new(),
                Some(vec![
                    Permission::create(&Role::any()),
                    Permission::read(&Role::any()),
                    Permission::update(&Role::any()),
                ]),
                true,
                Array::new(),
            );
            db.create_collection(parents.unwrap()).await.unwrap();
            collection(&db, "related", vec![string("name", 64)], scenario.collection.clone(), scenario.document_security)
                .await;
            relate(&db, "parents", relationship(kind)).await;
            create(
                &db,
                "related",
                doc(&[("$id", s("stored")), ("name", s("Stored")), ("$permissions", perms(&scenario.document))]),
            )
            .await;
        })
        .await;
    db
}

/// `as()`: runs `future` as the caller.
async fn as_caller<F: std::future::Future>(db: &Database, caller: Caller, future: F) -> F::Output {
    let authorization = db.authorization();
    match caller {
        Caller::Key => authorization.skip(future).await,
        Caller::Guest => {
            authorization.with_roles(&[Role::any().to_string(), Role::guests().to_string()], future).await
        }
        Caller::Session => {
            let roles = [Role::any().to_string(), Role::users("").to_string(), Role::user(USER, "").to_string()];
            authorization.with_roles(&roles, future).await
        }
    }
}

fn related_value(kind: &str, by_id: bool) -> Value {
    let related = if by_id { s("stored") } else { d(&[("$id", s("stored")), ("name", s("Changed"))]) };
    if many(kind) { value::list([related]) } else { related }
}

/// `ids()`.
fn ids_of_value(value: Option<&Value>) -> Vec<String> {
    let items: Vec<Value> = match value {
        Some(Value::Array(a)) => a.values().cloned().collect(),
        Some(v) => vec![v.clone()],
        None => Vec::new(),
    };
    items
        .iter()
        .map(|v| match v {
            Value::Ext(value::Ext::Document(d)) => d.id().to_owned(),
            Value::Str(_) => value::to_text(v),
            _ => String::new(),
        })
        .collect()
}

async fn as_stored(db: &Database, collection: &str, id: &str) -> Document {
    db.authorization().skip(db.get_document(collection, id, Vec::new(), false)).await.unwrap()
}

/// The stored related document is untouched and unlinked.
async fn assert_untouched(db: &Database, context: &str) {
    let stored = as_stored(db, "related", "stored").await;
    assert_eq!(stored.get("name"), Some(&s("Stored")), "{context}: the stored document is unchanged");
    assert!(ids_of_value(stored.get("back")).is_empty(), "{context}: no link on the stored document");
}

#[track_caller]
fn refusal<T>(result: Result<T>, context: &str) -> Option<String> {
    match result {
        Ok(_) => None,
        Err(e) if e.is_a(Kind::Authorization) => Some(e.message().to_owned()),
        Err(e) => panic!("{context}: unexpected {:?}: {}", e.kind(), e.message()),
    }
}

fn context(transaction: bool, kind: &str, scenario: &Scenario, by_id: bool) -> String {
    format!(
        "{} {kind} {} {}",
        if transaction { "batch create in a transaction" } else { "single create" },
        if by_id { "by ID" } else { "as a document" },
        scenario.name
    )
}

#[test]
fn creating_a_parent_with_a_stored_related_document() {
    each_case(|transaction, kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(transaction, kind, scenario, by_id);
            let db = seed(kind, scenario).await;
            let parent = doc(&[
                ("$id", s("parent")),
                ("$permissions", perms(&[Permission::read(&Role::any())])),
                ("related", related_value(kind, by_id)),
            ]);
            let result = if transaction {
                as_caller(
                    &db,
                    scenario.caller,
                    db.with_transaction(|| Box::pin(db.create_documents("parents", vec![parent.clone()], BATCH_SIZE, None))),
                )
                .await
                .map(|_| ())
            } else {
                as_caller(&db, scenario.caller, db.create_document("parents", parent.clone())).await.map(|_| ())
            };

            let refused = refusal(result, &context);
            assert_eq!(refused.as_deref(), expected, "{context}");
            if refused.is_some() {
                assert!(as_stored(&db, "parents", "parent").await.is_empty(), "{context}: no parent");
                assert_untouched(&db, &context).await;
                return;
            }
            let parent = as_stored(&db, "parents", "parent").await;
            assert_eq!(ids_of_value(parent.get("related")), names(&["stored"]), "{context}");
            if kind != "oneToOne one-way" {
                let stored = as_stored(&db, "related", "stored").await;
                assert_eq!(ids_of_value(stored.get("back")), names(&["parent"]), "{context}");
            }
        });
    });
}

async fn stored_parent(db: &Database) -> Document {
    let parent = doc(&[
        ("$id", s("parent")),
        ("$permissions", perms(&[Permission::read(&Role::any()), Permission::update(&Role::any())])),
    ]);
    db.authorization().skip(db.create_document("parents", parent)).await.unwrap()
}

#[test]
fn updating_a_parent_to_a_stored_related_document() {
    each_case(|transaction, kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(transaction, kind, scenario, by_id);
            let db = seed(kind, scenario).await;
            stored_parent(&db).await;
            let change = doc(&[("related", related_value(kind, by_id))]);
            let result = if transaction {
                as_caller(
                    &db,
                    scenario.caller,
                    db.with_transaction(|| Box::pin(db.update_document("parents", "parent", change.clone()))),
                )
                .await
            } else {
                as_caller(&db, scenario.caller, db.update_document("parents", "parent", change.clone())).await
            };

            let refused = refusal(result, &context);
            assert_eq!(refused.as_deref(), expected, "{context}");
            let parent = as_stored(&db, "parents", "parent").await;
            if refused.is_some() {
                assert!(ids_of_value(parent.get("related")).is_empty(), "{context}: nothing linked");
                assert_untouched(&db, &context).await;
                return;
            }
            assert_eq!(ids_of_value(parent.get("related")), names(&["stored"]), "{context}");
            if kind != "oneToOne one-way" {
                let stored = as_stored(&db, "related", "stored").await;
                assert_eq!(ids_of_value(stored.get("back")), names(&["parent"]), "{context}");
            }
        });
    });
}

#[test]
fn authorize_create_refuses_what_the_create_refuses() {
    each_case(|transaction, kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(transaction, kind, scenario, by_id);
            let db = seed(kind, scenario).await;
            let parent = doc(&[
                ("$id", s("parent")),
                ("$permissions", perms(&[Permission::read(&Role::any())])),
                ("related", related_value(kind, by_id)),
            ]);
            let given = parent.array_copy();
            let result = if transaction {
                as_caller(&db, scenario.caller, db.with_transaction(|| Box::pin(db.authorize_create("parents", &parent, None))))
                    .await
            } else {
                as_caller(&db, scenario.caller, db.authorize_create("parents", &parent, None)).await
            };
            assert_eq!(refusal(result, &context).as_deref(), expected, "{context}");
            assert_eq!(parent.array_copy(), given, "{context}: the document given is unchanged");
            assert!(as_stored(&db, "parents", "parent").await.is_empty(), "{context}");
            assert_untouched(&db, &context).await;
        });
    });
}

#[test]
fn authorize_update_refuses_what_the_update_refuses() {
    each_case(|transaction, kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(transaction, kind, scenario, by_id);
            let db = seed(kind, scenario).await;
            let old = stored_parent(&db).await;
            let change = doc(&[("related", related_value(kind, by_id))]);
            let result = if transaction {
                as_caller(&db, scenario.caller, db.with_transaction(|| Box::pin(db.authorize_update("parents", &old, &change, None))))
                    .await
            } else {
                as_caller(&db, scenario.caller, db.authorize_update("parents", &old, &change, None)).await
            };
            assert_eq!(refusal(result, &context).as_deref(), expected, "{context}");
            let parent = as_stored(&db, "parents", "parent").await;
            assert!(ids_of_value(parent.get("related")).is_empty(), "{context}: the check links nothing");
            assert_untouched(&db, &context).await;
        });
    });
}

// ----- StoredRelatedDocumentLinkTest -----

#[test]
fn a_stored_related_document_sent_as_a_document_is_updated_and_linked() {
    for kind in
        [RelationshipType::OneToOne, RelationshipType::OneToMany, RelationshipType::ManyToOne, RelationshipType::ManyToMany]
    {
        for transaction in [false, true] {
            block_on(async {
                let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
                let db = Database::new(Arc::new(Memory::new()), cache);
                db.set_authorization(Arc::new(Authorization::new(true)));
                db.set_database("stored_link").set_namespace(&unique("stored_link"));
                db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
                db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();

                db.authorization()
                    .skip(async {
                        db.create(None).await.unwrap();
                        for id in ["parents", "related"] {
                            let definition =
                                Collection::create(id, "", vec![string("name", 64)], Vec::new(), None, true, Array::new());
                            db.create_collection(definition.unwrap()).await.unwrap();
                        }
                        relate(
                            &db,
                            "parents",
                            Relationship::new("related", kind)
                                .key("related")
                                .two_way(true)
                                .two_way_key("back")
                                .on_delete(OnDelete::SetNull),
                        )
                        .await;
                        create(&db, "related", doc(&[("$id", s("stored")), ("name", s("Stored"))])).await;

                        let related = d(&[("$id", s("stored")), ("name", s("Changed"))]);
                        let single = matches!(kind, RelationshipType::OneToOne | RelationshipType::ManyToOne);
                        let parent = doc(&[
                            ("$id", s("parent")),
                            ("name", s("Parent")),
                            ("related", if single { related } else { value::list([related]) }),
                        ]);
                        if transaction {
                            db.with_transaction(|| Box::pin(db.create_documents("parents", vec![parent.clone()], BATCH_SIZE, None)))
                                .await
                                .unwrap();
                        } else {
                            create(&db, "parents", parent).await;
                        }

                        let stored = get(&db, "related", "stored").await;
                        assert_eq!(stored.get("name"), Some(&s("Changed")), "{kind:?} {transaction}");
                        assert_eq!(ids_of_value(stored.get("back")), names(&["parent"]), "{kind:?} {transaction}");
                        let parent = get(&db, "parents", "parent").await;
                        assert_eq!(ids_of_value(parent.get("related")), names(&["stored"]), "{kind:?} {transaction}");
                    })
                    .await;
            });
        }
    }
}

// ----- OneWayChildSideTest -----

async fn one_way_database() -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::new(Arc::new(Memory::new()), cache);
    db.set_authorization(Arc::new(Authorization::new(true)));
    db.set_database("one_way").set_namespace(&unique("one_way"));
    db.create(None).await.unwrap();
    collection(&db, "people", vec![string("name", 64)], crud(), false).await;
    collection(&db, "passports", vec![string("number", 64)], crud(), false).await;
    relate(&db, "people", Relationship::one_to_one("passports").key("passport").two_way_key("holder")).await;
    create(&db, "passports", doc(&[("$id", s("x1")), ("number", s("A-1"))])).await;
    create(&db, "people", doc(&[("$id", s("p1")), ("name", s("Ada")), ("passport", s("x1"))])).await;
    db
}

async fn attribute(db: &Database, collection: &str, key: &str) -> Option<Attribute> {
    db.get_collection(collection).await.unwrap().attributes().unwrap().iter().find(|a| a.key == key).cloned()
}

async fn index_keys(db: &Database, collection: &str) -> Vec<String> {
    db.get_collection(collection).await.unwrap().indexes().unwrap().iter().map(|i| i.key.clone()).collect()
}

fn id_of(value: Option<&Value>) -> Option<String> {
    match value? {
        Value::Ext(value::Ext::Document(d)) => Some(d.id().to_owned()),
        v @ Value::Str(_) => Some(value::to_text(v)),
        _ => None,
    }
}

#[test]
fn a_child_side_update_renames_the_parent_key() {
    block_on(async {
        let db = one_way_database().await;
        let updated = db
            .update_relationship(
                "passports",
                "holder",
                Update { key: Some("owner".into()), two_way_key: Some("document".into()), ..Update::default() },
            )
            .await
            .unwrap();
        assert_eq!(updated.key.as_deref(), Some("owner"));
        assert_eq!(updated.two_way_key.as_deref(), Some("document"));
        assert_eq!(attribute(&db, "passports", "owner").await.and_then(|a| a.side), Some(RelationshipSide::Child));
        assert_eq!(attribute(&db, "people", "document").await.and_then(|a| a.side), Some(RelationshipSide::Parent));
        assert!(attribute(&db, "people", "passport").await.is_none());
        assert_eq!(index_keys(&db, "people").await, names(&["_index_document"]));
        assert!(index_keys(&db, "passports").await.is_empty());

        let person = get(&db, "people", "p1").await;
        assert_eq!(id_of(person.get("document")).as_deref(), Some("x1"));
        assert!(!person.has("passport"));
    });
}

#[test]
fn a_child_side_delete_removes_the_parent_key() {
    block_on(async {
        let db = one_way_database().await;
        db.delete_relationship("passports", "holder").await.unwrap();
        assert!(attribute(&db, "passports", "holder").await.is_none());
        assert!(attribute(&db, "people", "passport").await.is_none());
        assert!(index_keys(&db, "people").await.is_empty());

        let person = get(&db, "people", "p1").await;
        assert_eq!(person.get("name"), Some(&s("Ada")));
        assert_eq!(person.get("passport"), None);

        relate(&db, "people", Relationship::one_to_one("passports").key("passport").two_way_key("holder")).await;
        update(&db, "people", "p1", doc(&[("passport", s("x1"))])).await;
        assert_eq!(id_of(get(&db, "people", "p1").await.get("passport")).as_deref(), Some("x1"));
    });
}
