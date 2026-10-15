//! PHP: `tests/unit/Relationships/StagedViewAuthorizationTest.php` (the
//! Memory cases).

use super::*;
use crate::database::Resolve;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipType};

const USER: &str = "u1";
const NO_CREATE: &str = "No permissions provided for action 'create'";
const NO_READ: &str = "No permissions provided for action 'read'";
const NO_UPDATE: &str = "No permissions provided for action 'update'";
const ONLY_ADMINS_UPDATE: &str = "Missing \"update\" permission for role \"team:admin\". Only \"[\"any\",\"users\",\"user:u1\"]\" scopes are allowed and \"[\"team:admin\"]\" was given.";

#[derive(Clone, Copy, PartialEq, Debug)]
enum State {
    Unstaged,
    Deleted,
    Updated,
    Created,
}

const TYPES: [&str; 5] = ["oneToOne", "oneToOne one-way", "oneToMany", "manyToOne", "manyToMany"];

struct Scenario {
    name: &'static str,
    state: State,
    document_security: bool,
    collection: Vec<String>,
    stored: Option<Vec<String>>,
    staged: Option<Vec<String>>,
    by_id: Option<&'static str>,
    as_document: Option<&'static str>,
}

fn scenarios() -> Vec<Scenario> {
    let users = Role::users("");
    let user = Role::user(USER, "");
    let create_read = vec![Permission::create(&users), Permission::read(&users)];
    let any = Role::any();
    let s = |name,
             state,
             document_security,
             collection: Vec<String>,
             stored: Option<Vec<String>>,
             staged: Option<Vec<String>>,
             by_id,
             as_document| Scenario {
        name,
        state,
        document_security,
        collection,
        stored,
        staged,
        by_id,
        as_document,
    };
    vec![
        s(
            "unstaged, update on the collection",
            State::Unstaged,
            false,
            [create_read.clone(), vec![Permission::update(&users)]].concat(),
            Some(Vec::new()),
            None,
            None,
            None,
        ),
        s(
            "unstaged, read without update",
            State::Unstaged,
            true,
            create_read.clone(),
            Some(Vec::new()),
            None,
            Some(NO_UPDATE),
            Some(NO_UPDATE),
        ),
        s(
            "staged deleted, create on the collection, update only for another team",
            State::Deleted,
            true,
            vec![Permission::create(&any), Permission::read(&any), Permission::delete(&any)],
            Some(vec![Permission::update(&Role::team("admin", ""))]),
            None,
            None,
            None,
        ),
        s(
            "staged deleted, no create on the collection",
            State::Deleted,
            false,
            vec![Permission::read(&users), Permission::update(&users)],
            Some(Vec::new()),
            None,
            None,
            Some(NO_CREATE),
        ),
        s(
            "staged update revokes update",
            State::Updated,
            true,
            create_read.clone(),
            Some(vec![Permission::update(&user)]),
            Some(Vec::new()),
            Some(NO_UPDATE),
            Some(NO_UPDATE),
        ),
        s(
            "staged update grants update",
            State::Updated,
            true,
            create_read.clone(),
            Some(Vec::new()),
            Some(vec![Permission::update(&user)]),
            None,
            None,
        ),
        s(
            "staged update hides it",
            State::Updated,
            true,
            vec![Permission::create(&users)],
            Some(vec![Permission::read(&user), Permission::update(&user)]),
            Some(vec![Permission::update(&user)]),
            Some(NO_READ),
            Some(NO_READ),
        ),
        s(
            "staged update reveals it",
            State::Updated,
            true,
            vec![Permission::create(&users)],
            Some(Vec::new()),
            Some(vec![Permission::read(&user), Permission::update(&user)]),
            None,
            None,
        ),
        s(
            "staged create without update",
            State::Created,
            true,
            create_read.clone(),
            None,
            Some(Vec::new()),
            Some(NO_UPDATE),
            Some(NO_UPDATE),
        ),
        s(
            "staged create with update",
            State::Created,
            true,
            create_read,
            None,
            Some(vec![Permission::update(&user)]),
            None,
            None,
        ),
    ]
}

fn relationship(kind: &str) -> Relationship {
    let two_way = |kind| {
        Relationship::new("related", kind).key("related").two_way(true).two_way_key("back").on_delete(OnDelete::SetNull)
    };
    match kind {
        "oneToOne" => two_way(RelationshipType::OneToOne),
        "oneToOne one-way" => Relationship::one_to_one("related").key("related").on_delete(OnDelete::SetNull),
        "oneToMany" => two_way(RelationshipType::OneToMany),
        "manyToOne" => two_way(RelationshipType::ManyToOne),
        _ => two_way(RelationshipType::ManyToMany),
    }
}

/// `seed()`.
async fn seed(kind: &str, document_security: bool, permissions: Vec<String>, stored: Option<Vec<String>>) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::new(Arc::new(Memory::new()), cache);
    db.set_authorization(Arc::new(Authorization::new(true)));
    db.set_database("staged_view").set_namespace(&unique("staged_view"));
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.authorization()
        .skip(async {
            db.create(None).await.unwrap();
            let any = Role::any();
            let parents = Collection::create(
                "parents",
                "",
                vec![string("name", 64)],
                Vec::new(),
                Some(vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]),
                true,
                Array::new(),
            );
            db.create_collection(parents.unwrap()).await.unwrap();
            collection(&db, "related", vec![string("name", 64)], permissions, document_security).await;
            relate(&db, "parents", relationship(kind)).await;
            if let Some(stored) = stored {
                create(
                    &db,
                    "related",
                    doc(&[("$id", s("stored")), ("name", s("Stored")), ("$permissions", perms(&stored))]),
                )
                .await;
            }
        })
        .await;
    db
}

/// `resolver()`.
fn resolver(state: State, staged: Option<Vec<String>>) -> Resolve {
    Arc::new(move |collection, id| {
        if collection != "related" || id != "stored" {
            return None;
        }
        match state {
            State::Deleted => Some(Document::empty()),
            State::Updated | State::Created => Some(doc(&[
                ("$id", s("stored")),
                ("$collection", s("related")),
                ("name", s("Stored")),
                ("$permissions", perms(&staged.clone().unwrap_or_default())),
            ])),
            State::Unstaged => None,
        }
    })
}

/// `applyStaged()`.
async fn apply_staged(db: &Database, state: State, staged: Option<Vec<String>>) -> Result<()> {
    let permissions = perms(&staged.unwrap_or_default());
    let authorization = db.authorization();
    match state {
        State::Deleted => authorization.skip(db.delete_document("related", "stored")).await.map(|_| ()),
        State::Updated => authorization
            .skip(db.update_document("related", "stored", doc(&[("$permissions", permissions)])))
            .await
            .map(|_| ()),
        State::Created => authorization
            .skip(db.create_document(
                "related",
                doc(&[("$id", s("stored")), ("name", s("Stored")), ("$permissions", permissions)]),
            ))
            .await
            .map(|_| ()),
        State::Unstaged => Ok(()),
    }
}

fn related_value(kind: &str, by_id: bool) -> Value {
    let related = if by_id { s("stored") } else { d(&[("$id", s("stored")), ("name", s("Changed"))]) };
    if matches!(kind, "oneToMany" | "manyToMany") { value::list([related]) } else { related }
}

fn parent(kind: &str, by_id: bool) -> Document {
    doc(&[
        ("$id", s("parent")),
        ("$permissions", perms(&[Permission::read(&Role::any())])),
        ("related", related_value(kind, by_id)),
    ])
}

async fn as_user<F: std::future::Future>(db: &Database, future: F) -> F::Output {
    let roles = [Role::any().to_string(), Role::users("").to_string(), Role::user(USER, "").to_string()];
    db.authorization().with_roles(&roles, future).await
}

#[track_caller]
fn refusal<T>(result: Result<T>, context: &str) -> Option<String> {
    match result {
        Ok(_) => None,
        Err(e) if e.is_a(Kind::Authorization) => Some(e.message().to_owned()),
        Err(e) => panic!("{context}: unexpected {:?}: {}", e.kind(), e.message()),
    }
}

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

async fn stored_parent(db: &Database) -> Document {
    let any = Role::any();
    let parent =
        doc(&[("$id", s("parent")), ("$permissions", perms(&[Permission::read(&any), Permission::update(&any)]))]);
    db.authorization().skip(db.create_document("parents", parent)).await.unwrap()
}

/// Each case: a type, a scenario, and the related document given by id or
/// as a document.
fn each_case(mut test: impl FnMut(&str, &Scenario, bool, Option<&'static str>)) {
    for kind in TYPES {
        for scenario in scenarios() {
            test(kind, &scenario, true, scenario.by_id);
            test(kind, &scenario, false, scenario.as_document);
        }
    }
}

fn context(kind: &str, scenario: &Scenario, by_id: bool) -> String {
    format!("{kind} {} {}", if by_id { "by ID" } else { "as a document" }, scenario.name)
}

#[test]
fn authorize_create_judges_the_staged_view() {
    each_case(|kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(kind, scenario, by_id);
            let db = seed(kind, scenario.document_security, scenario.collection.clone(), scenario.stored.clone()).await;
            let resolve = resolver(scenario.state, scenario.staged.clone());
            let parent = parent(kind, by_id);
            let result = as_user(&db, db.authorize_create("parents", &parent, Some(resolve))).await;
            assert_eq!(refusal(result, &context).as_deref(), expected, "{context}");

            assert!(as_stored(&db, "parents", "parent").await.is_empty(), "{context}: no parent");
            let related = as_stored(&db, "related", "stored").await;
            if scenario.stored.is_none() {
                assert!(related.is_empty(), "{context}: no related document");
            } else {
                assert_eq!(related.get("name"), Some(&s("Stored")), "{context}");
                assert!(ids_of_value(related.get("back")).is_empty(), "{context}");
            }
        });
    });
}

#[test]
fn the_create_decides_the_same_after_the_staged_writes() {
    each_case(|kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(kind, scenario, by_id);
            let db = seed(kind, scenario.document_security, scenario.collection.clone(), scenario.stored.clone()).await;
            let result = db
                .with_transaction(|| {
                    Box::pin(async {
                        apply_staged(&db, scenario.state, scenario.staged.clone()).await?;
                        as_user(&db, db.create_document("parents", parent(kind, by_id))).await?;
                        Ok(())
                    })
                })
                .await;
            assert_eq!(refusal(result, &context).as_deref(), expected, "{context}");
        });
    });
}

#[test]
fn authorize_update_judges_the_staged_view() {
    each_case(|kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(kind, scenario, by_id);
            let db = seed(kind, scenario.document_security, scenario.collection.clone(), scenario.stored.clone()).await;
            let old = stored_parent(&db).await;
            let resolve = resolver(scenario.state, scenario.staged.clone());
            let change = doc(&[("related", related_value(kind, by_id))]);
            let result = as_user(&db, db.authorize_update("parents", &old, &change, Some(resolve))).await;
            assert_eq!(refusal(result, &context).as_deref(), expected, "{context}");
            let parent = as_stored(&db, "parents", "parent").await;
            assert!(ids_of_value(parent.get("related")).is_empty(), "{context}: the check links nothing");
        });
    });
}

#[test]
fn the_update_decides_the_same_after_the_staged_writes() {
    each_case(|kind, scenario, by_id, expected| {
        block_on(async {
            let context = context(kind, scenario, by_id);
            let db = seed(kind, scenario.document_security, scenario.collection.clone(), scenario.stored.clone()).await;
            stored_parent(&db).await;
            let result = db
                .with_transaction(|| {
                    Box::pin(async {
                        apply_staged(&db, scenario.state, scenario.staged.clone()).await?;
                        let change = doc(&[("related", related_value(kind, by_id))]);
                        as_user(&db, db.update_document("parents", "parent", change)).await?;
                        Ok(())
                    })
                })
                .await;
            assert_eq!(refusal(result, &context).as_deref(), expected, "{context}");
        });
    });
}

#[test]
fn a_related_document_staged_for_deletion_is_recreated_with_the_given_permissions() {
    block_on(async {
        let foreign = vec![Permission::update(&Role::team("admin", ""))];
        let any = Role::any();
        let db = seed(
            "oneToOne one-way",
            true,
            vec![Permission::create(&any), Permission::read(&any), Permission::delete(&any)],
            Some(foreign.clone()),
        )
        .await;
        let related: Array = [
            (value::ArrayKey::from("$id"), s("stored")),
            (value::ArrayKey::from("name"), s("Recreated")),
            (value::ArrayKey::from("$permissions"), perms(&foreign)),
        ]
        .into_iter()
        .collect();
        let parent = doc(&[("$id", s("parent")), ("related", Value::Array(related))]);

        let judged = as_user(&db, db.authorize_create("parents", &parent, None)).await;
        assert_eq!(refusal(judged, "stored").as_deref(), Some(ONLY_ADMINS_UPDATE));
        let staged = as_user(&db, db.authorize_create("parents", &parent, Some(resolver(State::Deleted, None)))).await;
        assert_eq!(refusal(staged, "staged"), None);

        db.with_transaction(|| {
            Box::pin(async {
                apply_staged(&db, State::Deleted, None).await?;
                as_user(&db, db.create_document("parents", parent.clone())).await?;
                Ok(())
            })
        })
        .await
        .unwrap();

        let recreated = as_stored(&db, "related", "stored").await;
        assert_eq!(recreated.get("name"), Some(&s("Recreated")));
        assert_eq!(recreated.permissions().unwrap(), foreign);
    });
}

#[test]
fn a_resolver_returning_null_judges_the_stored_document() {
    block_on(async {
        let users = Role::users("");
        let db = seed(
            "manyToOne",
            true,
            vec![Permission::create(&users), Permission::read(&users)],
            Some(vec![Permission::update(&Role::user(USER, ""))]),
        )
        .await;
        for by_id in [true, false] {
            let none: Resolve = Arc::new(|_, _| None);
            let result = as_user(&db, db.authorize_create("parents", &parent("manyToOne", by_id), Some(none))).await;
            assert_eq!(refusal(result, "resolver returning null"), None);
        }
    });
}

#[test]
fn an_api_key_passes_whatever_the_staged_view() {
    block_on(async {
        let db = seed("oneToMany", true, Vec::new(), Some(Vec::new())).await;
        for state in [State::Deleted, State::Updated] {
            for by_id in [true, false] {
                let resolve = resolver(state, Some(Vec::new()));
                let result = db
                    .authorization()
                    .skip(db.authorize_create("parents", &parent("oneToMany", by_id), Some(resolve)))
                    .await;
                assert_eq!(refusal(result, "key"), None, "{state:?} {by_id}");
            }
        }
    });
}

#[test]
fn a_nested_document_is_judged_against_the_links_the_staged_writes_leave() {
    block_on(async {
        let users = Role::users("");
        let db = seed(
            "oneToMany",
            false,
            vec![Permission::create(&users), Permission::read(&users), Permission::update(&users)],
            Some(Vec::new()),
        )
        .await;
        db.authorization()
            .skip(async {
                let toys = Collection::create(
                    "toys",
                    "",
                    vec![string("name", 64)],
                    Vec::new(),
                    Some(vec![Permission::read(&users)]),
                    true,
                    Array::new(),
                );
                db.create_collection(toys.unwrap()).await.unwrap();
                relate(&db, "related", Relationship::many_to_one("toys").key("toy").on_delete(OnDelete::SetNull)).await;
                create(&db, "toys", doc(&[("$id", s("toy")), ("name", s("Toy"))])).await;
            })
            .await;
        let entry: Array = [(value::ArrayKey::from("$id"), s("stored")), (value::ArrayKey::from("toy"), s("toy"))]
            .into_iter()
            .collect();
        let parent = doc(&[("$id", s("parent")), ("related", value::list([Value::Array(entry)]))]);
        let stored = as_stored(&db, "related", "stored").await;
        let linked: Resolve = Arc::new(move |collection, id| {
            (collection == "related" && id == "stored").then(|| {
                let mut stored = stored.clone();
                stored.put("toy", s("toy"));
                stored
            })
        });

        let judged = as_user(&db, db.authorize_create("parents", &parent, None)).await;
        assert_eq!(refusal(judged, "stored").as_deref(), Some(NO_UPDATE), "judged as stored, the toy is linked anew");
        let staged = as_user(&db, db.authorize_create("parents", &parent, Some(linked))).await;
        assert_eq!(refusal(staged, "staged"), None, "judged as staged, the toy is already linked");

        db.with_transaction(|| {
            Box::pin(async {
                db.authorization().skip(db.update_document("related", "stored", doc(&[("toy", s("toy"))]))).await?;
                as_user(&db, db.create_document("parents", parent.clone())).await?;
                Ok(())
            })
        })
        .await
        .unwrap();
        let parent = as_stored(&db, "parents", "parent").await;
        assert_eq!(ids_of_value(parent.get("related")), names(&["stored"]));
    });
}
