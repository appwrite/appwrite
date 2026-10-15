//! PHP: `tests/unit/Relationships/NestedPreparationTest.php` (the Memory
//! cases; the Swoole child-coroutine case is
//! `a_snapshot_carries_the_grant_check_into_spawned_work`).

use async_trait::async_trait;

use super::nested_pre_check::{Log, TYPES};
use super::*;
use crate::hook::Nested;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipType};

const USER: &str = "u1";
const FIRST: &str = "first";
const SECOND: &str = "second";
const THIRD: &str = "third";
const NEXT: &str = "next";
const PREVIOUS: &str = "previous";
const MARKER: &str = "marker";
const UNHELD_REFUSAL: &str = "Permissions must be one of: (any, users, user:u1)";
const MALFORMED: &str = "Wildcard permission \"*\" has been replaced. Use \"any\" instead.";
const BULK_REFUSAL: &str =
    "Invalid relationship value. A bulk update takes related documents by ID while grants are checked.";
const HOOK_FAILURE: &str = "Refused by the nested hook";

/// A nested hook recording what it prepares and renaming its id.
struct Renaming {
    seen: Log,
    rename: fn(&str) -> Result<String>,
}

#[async_trait]
impl Nested for Renaming {
    async fn prepare(&self, collection: &Collection, mut document: Document) -> Result<Document> {
        self.seen.lock().unwrap().push((collection.id().to_owned(), document.id().to_owned()));
        let id = (self.rename)(document.id())?;
        document.put("$id", s(&id));
        Ok(document)
    }
}

/// A nested hook refusing the documents of one collection.
struct Refusing;

#[async_trait]
impl Nested for Refusing {
    async fn prepare(&self, collection: &Collection, document: Document) -> Result<Document> {
        if collection.id() == THIRD {
            return Err(Error::new(Kind::Runtime, HOOK_FAILURE));
        }
        Ok(document)
    }
}

struct Fixture {
    db: Database,
    seen: Log,
}

impl Fixture {
    fn hook(&self, rename: fn(&str) -> Result<String>) -> Arc<Renaming> {
        Arc::new(Renaming { seen: self.seen.clone(), rename })
    }

    fn marker(&self) -> Arc<Renaming> {
        self.hook(|id| if id == MARKER { crate::id::unique() } else { Ok(id.to_owned()) })
    }

    fn add(&self, hook: Arc<Renaming>) -> &Self {
        self.db.add_hook(Hook::nested(hook)).unwrap();
        self
    }

    fn seen(&self) -> Vec<(String, String)> {
        self.seen.lock().unwrap().clone()
    }

    /// `assertGeneratedId()`.
    async fn generated_id(&self, collection: &str, name: &str) -> String {
        let db = &self.db;
        let found = db.authorization().skip(db.find(collection, Vec::new(), PermissionType::Read)).await.unwrap();
        let matches: Vec<&Document> = found.iter().filter(|d| d.get("name") == Some(&s(name))).collect();
        assert_eq!(matches.len(), 1, "one document named {name} in {collection}");
        let id = matches[0].id().to_owned();
        assert!(!id.is_empty() && id != MARKER);
        let marked = db.authorization().skip(db.get_document(collection, MARKER, Vec::new(), false)).await.unwrap();
        assert!(marked.is_empty(), "nothing is stored under the marker in {collection}");
        id
    }

    /// `related()`.
    async fn related(&self, id: &str) -> Document {
        let db = &self.db;
        let parent = db.authorization().skip(db.get_document(FIRST, id, Vec::new(), false)).await.unwrap();
        let related = match parent.get(NEXT) {
            Some(Value::Array(items)) => items.values().next().cloned().unwrap(),
            Some(v) => v.clone(),
            None => panic!("{id} holds no related document"),
        };
        let related = value::as_document(&related).expect("a related document").id().to_owned();
        db.authorization().skip(db.get_document(SECOND, &related, Vec::new(), false)).await.unwrap()
    }

    async fn next_ids(&self, collection: &str, id: &str, key: &str) -> Vec<String> {
        ids_of_value(get(&self.db, collection, id).await.get(key))
    }

    async fn assert_wrote_nothing(&self) {
        for collection in [FIRST, SECOND, THIRD] {
            assert!(names_in(&self.db, collection).await.is_empty(), "nothing is written to {collection}");
        }
    }
}

/// `collections()`.
async fn collections() -> Fixture {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::new(Arc::new(Memory::new()), cache);
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role(&Role::users("").to_string());
    authorization.add_role(&Role::user(USER, "").to_string());
    db.set_authorization(authorization);
    db.set_database("nested_preparation").set_namespace(&unique("nested_preparation"));
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.create(None).await.unwrap();
    for id in [FIRST, SECOND, THIRD] {
        collection(&db, id, vec![string("name", 64)], crud(), false).await;
    }
    Fixture { db, seen: Log::default() }
}

fn one_way(kind: RelationshipType, related: &str) -> Relationship {
    let relationship = Relationship::new(related, kind).key(NEXT).on_delete(OnDelete::SetNull);
    if matches!(kind, RelationshipType::OneToMany | RelationshipType::ManyToMany) {
        relationship.two_way(true).two_way_key(PREVIOUS)
    } else {
        relationship
    }
}

fn two_way(kind: RelationshipType, related: &str) -> Relationship {
    Relationship::new(related, kind).key(NEXT).two_way(true).two_way_key(PREVIOUS).on_delete(OnDelete::SetNull)
}

/// `database()`.
async fn one_way_database(kind: RelationshipType) -> Fixture {
    let f = collections().await;
    relate(&f.db, FIRST, one_way(kind, SECOND)).await;
    relate(&f.db, SECOND, one_way(kind, THIRD)).await;
    f
}

/// `twoWayDatabase()`.
async fn two_way_database(kind: RelationshipType) -> Fixture {
    let f = collections().await;
    relate(&f.db, FIRST, two_way(kind, SECOND)).await;
    relate(&f.db, SECOND, two_way(kind, THIRD)).await;
    f
}

fn to_many(kind: RelationshipType) -> bool {
    matches!(kind, RelationshipType::OneToMany | RelationshipType::ManyToMany)
}

/// `value()`.
fn value_of(kind: RelationshipType, related: Value) -> Value {
    if to_many(kind) { value::list([related]) } else { related }
}

/// `reverse()`.
fn reverse(kind: RelationshipType, related: Document) -> Value {
    if matches!(kind, RelationshipType::ManyToOne | RelationshipType::ManyToMany) {
        docs(vec![related])
    } else {
        Value::from(related)
    }
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
        .filter_map(|v| match v {
            Value::Ext(value::Ext::Document(d)) => Some(d.id().to_owned()),
            Value::Str(_) => Some(value::to_text(v)),
            _ => None,
        })
        .filter(|id| !id.is_empty())
        .collect()
}

/// `names()`.
async fn names_in(db: &Database, collection: &str) -> Vec<String> {
    let found = db.authorization().skip(db.find(collection, Vec::new(), PermissionType::Read)).await.unwrap();
    found
        .iter()
        .map(|d| match d.get("name") {
            Some(v @ Value::Str(_)) => value::to_text(v),
            _ => d.id().to_owned(),
        })
        .collect()
}

#[track_caller]
fn refusal<T>(result: Result<T>) -> Option<String> {
    match result {
        Ok(_) => None,
        Err(e) if e.is_a(Kind::Authorization) => Some(e.message().to_owned()),
        Err(e) => panic!("unexpected {:?}: {}", e.kind(), e.message()),
    }
}

fn pair(collection: &str, id: &str) -> (String, String) {
    (collection.to_owned(), id.to_owned())
}

fn unheld() -> Vec<String> {
    vec![Permission::read(&Role::user("u2", ""))]
}

#[test]
fn a_nested_hook_rewrites_every_depth_on_create() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            f.add(f.marker());
            let grandchild = doc(&[("$id", s(MARKER)), ("name", s("grandchild"))]);
            let child =
                doc(&[("$id", s(MARKER)), ("name", s("child")), (NEXT, value_of(kind, Value::from(grandchild.clone())))]);
            create(&f.db, FIRST, doc(&[("$id", s("root")), ("name", s("root")), (NEXT, value_of(kind, Value::from(child.clone())))]))
                .await;

            let child_id = f.generated_id(SECOND, "child").await;
            let grandchild_id = f.generated_id(THIRD, "grandchild").await;
            assert_eq!(f.next_ids(FIRST, "root", NEXT).await, vec![child_id.clone()], "{kind:?}");
            assert_eq!(f.next_ids(SECOND, &child_id, NEXT).await, vec![grandchild_id.clone()], "{kind:?}");
            assert_eq!(f.next_ids(SECOND, &child_id, PREVIOUS).await, names(&["root"]), "{kind:?}");
            assert_eq!(f.next_ids(THIRD, &grandchild_id, PREVIOUS).await, vec![child_id], "{kind:?}");
            assert_eq!(child.id(), MARKER);
            assert_eq!(grandchild.id(), MARKER);
            assert_eq!(f.seen(), vec![pair(SECOND, MARKER), pair(THIRD, MARKER)], "{kind:?}");
        });
    }
}

#[test]
fn a_nested_hook_rewrites_the_inverse_side_two_levels_down() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            f.add(f.marker());
            let child = doc(&[
                ("$id", s(MARKER)),
                ("name", s("child")),
                (PREVIOUS, reverse(kind, doc(&[("$id", s(MARKER)), ("name", s("root"))]))),
            ]);
            create(&f.db, THIRD, doc(&[("$id", s("leaf")), ("name", s("leaf")), (PREVIOUS, reverse(kind, child))])).await;

            let child_id = f.generated_id(SECOND, "child").await;
            let root_id = f.generated_id(FIRST, "root").await;
            assert_eq!(f.next_ids(THIRD, "leaf", PREVIOUS).await, vec![child_id.clone()], "{kind:?}");
            assert_eq!(f.next_ids(SECOND, &child_id, PREVIOUS).await, vec![root_id], "{kind:?}");
            assert_eq!(f.next_ids(SECOND, &child_id, NEXT).await, names(&["leaf"]), "{kind:?}");
            assert_eq!(f.seen(), vec![pair(SECOND, MARKER), pair(FIRST, MARKER)], "{kind:?}");
        });
    }
}

#[test]
fn a_nested_hook_reaches_three_levels_down_in_both_directions() {
    block_on(async {
        let f = two_way_database(RelationshipType::ManyToMany).await;
        f.add(f.marker());
        let marked = |name: &str, key: &str, nested: Option<Document>| {
            let mut d = doc(&[("$id", s(MARKER)), ("name", s(name))]);
            if let Some(nested) = nested {
                d.put(key, docs(vec![nested]));
            }
            d
        };
        let grandchild = marked("grandchild", PREVIOUS, Some(marked("great-grandchild", "", None)));
        let child = marked("child", NEXT, Some(grandchild));
        create(&f.db, FIRST, doc(&[("$id", s("root")), ("name", s("root")), (NEXT, docs(vec![child]))])).await;
        let inverse_grandchild = marked("inverse grandchild", NEXT, Some(marked("inverse great-grandchild", "", None)));
        let inverse_child = marked("inverse child", PREVIOUS, Some(inverse_grandchild));
        create(&f.db, THIRD, doc(&[("$id", s("leaf")), ("name", s("leaf")), (PREVIOUS, docs(vec![inverse_child]))])).await;

        let child = f.generated_id(SECOND, "child").await;
        let grandchild = f.generated_id(THIRD, "grandchild").await;
        let great_grandchild = f.generated_id(SECOND, "great-grandchild").await;
        let inverse_child = f.generated_id(SECOND, "inverse child").await;
        let inverse_grandchild = f.generated_id(FIRST, "inverse grandchild").await;
        let inverse_great_grandchild = f.generated_id(SECOND, "inverse great-grandchild").await;
        let mut previous = f.next_ids(THIRD, &grandchild, PREVIOUS).await;
        previous.sort();
        let mut expected = vec![child, great_grandchild];
        expected.sort();
        assert_eq!(previous, expected);
        let mut next = f.next_ids(FIRST, &inverse_grandchild, NEXT).await;
        next.sort();
        let mut expected = vec![inverse_child, inverse_great_grandchild];
        expected.sort();
        assert_eq!(next, expected);
        assert_eq!(
            f.seen(),
            vec![
                pair(SECOND, MARKER),
                pair(THIRD, MARKER),
                pair(SECOND, MARKER),
                pair(SECOND, MARKER),
                pair(FIRST, MARKER),
                pair(SECOND, MARKER),
            ]
        );
    });
}

#[test]
fn a_nested_hook_is_given_a_related_document_sent_as_an_array() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            f.add(f.marker());
            let child: Array =
                [(value::ArrayKey::from("$id"), s(MARKER)), (value::ArrayKey::from("name"), s("child"))].into_iter().collect();
            create(&f.db, FIRST, doc(&[("$id", s("root")), ("name", s("root")), (NEXT, value_of(kind, Value::Array(child)))]))
                .await;
            let child = f.generated_id(SECOND, "child").await;
            assert_eq!(f.next_ids(FIRST, "root", NEXT).await, vec![child], "{kind:?}");
            assert_eq!(f.seen(), vec![pair(SECOND, MARKER)], "{kind:?}");
        });
    }
}

#[test]
fn a_nested_hook_rewrites_on_update() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            create(&f.db, FIRST, doc(&[("$id", s("root")), ("name", s("root"))])).await;
            f.add(f.marker());
            let child = doc(&[
                ("$id", s(MARKER)),
                ("name", s("child")),
                (NEXT, value_of(kind, d(&[("$id", s(MARKER)), ("name", s("grandchild"))]))),
            ]);
            update(&f.db, FIRST, "root", doc(&[(NEXT, value_of(kind, Value::from(child.clone())))])).await;

            let child_id = f.generated_id(SECOND, "child").await;
            let grandchild_id = f.generated_id(THIRD, "grandchild").await;
            assert_eq!(f.next_ids(FIRST, "root", NEXT).await, vec![child_id.clone()], "{kind:?}");
            assert_eq!(f.next_ids(SECOND, &child_id, NEXT).await, vec![grandchild_id], "{kind:?}");
            assert_eq!(child.id(), MARKER);
            assert_eq!(f.seen(), vec![pair(SECOND, MARKER), pair(THIRD, MARKER)], "{kind:?}");
        });
    }
}

#[test]
fn a_nested_hook_rewrites_on_create_documents() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            f.add(f.marker());
            let root = |id: &str| {
                doc(&[
                    ("$id", s(id)),
                    ("name", s(id)),
                    (
                        NEXT,
                        value_of(
                            kind,
                            d(&[
                                ("$id", s(MARKER)),
                                ("name", s(&format!("{id} child"))),
                                (NEXT, value_of(kind, d(&[("$id", s(MARKER)), ("name", s(&format!("{id} grandchild")))]))),
                            ]),
                        ),
                    ),
                ])
            };
            f.db.create_documents(FIRST, vec![root("one"), root("two")], BATCH_SIZE, None).await.unwrap();
            assert!(f.db.adapter().upserts().is_none(), "the Memory adapter has no upserts");

            let mut generated = Vec::new();
            for id in ["one", "two"] {
                let child = f.generated_id(SECOND, &format!("{id} child")).await;
                let grandchild = f.generated_id(THIRD, &format!("{id} grandchild")).await;
                assert_eq!(f.next_ids(FIRST, id, NEXT).await, vec![child.clone()], "{kind:?} {id}");
                assert_eq!(f.next_ids(SECOND, &child, NEXT).await, vec![grandchild.clone()], "{kind:?} {id}");
                generated.push(child);
                generated.push(grandchild);
            }
            let mut distinct = generated.clone();
            distinct.sort();
            distinct.dedup();
            assert_eq!(distinct.len(), generated.len(), "every write gets its own ids");
            let one = vec![pair(SECOND, MARKER), pair(THIRD, MARKER)];
            assert_eq!(f.seen(), [one.clone(), one].concat(), "{kind:?}");
        });
    }
}

#[test]
fn the_top_level_document_and_string_values_are_not_given_to_the_hook() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            f.add(f.marker());
            create(&f.db, SECOND, doc(&[("$id", s(MARKER)), ("name", s("literal"))])).await;
            create(&f.db, FIRST, doc(&[("$id", s(MARKER)), ("name", s("root")), (NEXT, value_of(kind, s(MARKER)))])).await;

            assert_eq!(get(&f.db, FIRST, MARKER).await.get("name"), Some(&s("root")));
            assert_eq!(f.next_ids(FIRST, MARKER, NEXT).await, names(&[MARKER]), "{kind:?}");
            assert_eq!(names_in(&f.db, SECOND).await, names(&["literal"]), "{kind:?}");
            assert!(f.seen().is_empty());
        });
    }
}

#[test]
fn an_empty_id_is_still_generated_and_an_explicit_id_is_kept_with_a_hook() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            f.add(f.marker());
            create(
                &f.db,
                FIRST,
                doc(&[
                    ("$id", s("root")),
                    ("name", s("root")),
                    (
                        NEXT,
                        value_of(
                            kind,
                            d(&[("$id", s("chosen")), ("name", s("child")), (NEXT, value_of(kind, d(&[("name", s("grandchild"))])))]),
                        ),
                    ),
                ]),
            )
            .await;
            assert_eq!(get(&f.db, SECOND, "chosen").await.get("name"), Some(&s("child")));
            let grandchild = f.generated_id(THIRD, "grandchild").await;
            assert_eq!(f.next_ids(SECOND, "chosen", NEXT).await, vec![grandchild], "{kind:?}");
            assert_eq!(f.seen(), vec![pair(SECOND, "chosen"), pair(THIRD, "")], "{kind:?}");
        });
    }
}

#[test]
fn hooks_run_in_registration_order() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            f.add(f.hook(|id| Ok(if id == MARKER { "renamed".into() } else { id.to_owned() })));
            f.add(f.hook(|id| Ok(if id == "renamed" { "final".into() } else { id.to_owned() })));
            create(
                &f.db,
                FIRST,
                doc(&[("$id", s("root")), ("name", s("root")), (NEXT, value_of(kind, d(&[("$id", s(MARKER)), ("name", s("child"))])))]),
            )
            .await;
            assert_eq!(f.seen(), vec![pair(SECOND, MARKER), pair(SECOND, "renamed")], "{kind:?}");
            assert_eq!(f.next_ids(FIRST, "root", NEXT).await, names(&["final"]), "{kind:?}");
            assert_eq!(names_in(&f.db, SECOND).await, names(&["child"]), "{kind:?}");
        });
    }
}

#[test]
fn a_removed_hook_no_longer_runs() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            let hook = f.marker();
            f.add(hook.clone());
            let root = |id: &str, child: &str| {
                doc(&[
                    ("$id", s(id)),
                    ("name", s(id)),
                    (NEXT, value_of(kind, d(&[("$id", s(child)), ("name", s(&format!("{id} child")))]))),
                ])
            };
            create(&f.db, FIRST, root("hooked", MARKER)).await;
            f.generated_id(SECOND, "hooked child").await;
            f.db.remove_hook(&Hook::nested(hook));
            create(&f.db, FIRST, root("removed", MARKER)).await;
            f.add(f.marker());
            f.db.remove_hook_class(std::any::type_name::<Renaming>());
            create(&f.db, FIRST, root("removed by class", "explicit")).await;

            assert_eq!(f.next_ids(FIRST, "removed", NEXT).await, names(&[MARKER]), "{kind:?}");
            assert_eq!(f.seen(), vec![pair(SECOND, MARKER)], "{kind:?}");
        });
    }
}

#[test]
fn a_hook_exception_writes_nothing() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            create(&f.db, FIRST, doc(&[("$id", s("stored")), ("name", s("stored"))])).await;
            f.db.add_hook(Hook::nested(Arc::new(Refusing))).unwrap();
            let children = || value_of(kind, d(&[("name", s("child")), (NEXT, value_of(kind, d(&[("name", s("grandchild"))])))]));

            let results = [
                (
                    "create",
                    f.db.create_document(FIRST, doc(&[("$id", s("created")), ("name", s("created")), (NEXT, children())]))
                        .await
                        .map(|_| ()),
                ),
                (
                    "create in bulk",
                    f.db.create_documents(
                        FIRST,
                        vec![doc(&[("$id", s("batched")), ("name", s("batched")), (NEXT, children())])],
                        BATCH_SIZE,
                        None,
                    )
                    .await
                    .map(|_| ()),
                ),
                ("update", f.db.update_document(FIRST, "stored", doc(&[(NEXT, children())])).await.map(|_| ())),
            ];
            for (write, result) in results {
                let error = failure(result, Kind::Runtime);
                assert_eq!(error.message(), HOOK_FAILURE, "{kind:?} {write}");
            }

            assert_eq!(names_in(&f.db, FIRST).await, names(&["stored"]), "{kind:?}");
            assert!(names_in(&f.db, SECOND).await.is_empty(), "{kind:?}");
            assert!(names_in(&f.db, THIRD).await.is_empty(), "{kind:?}");
            assert!(f.next_ids(FIRST, "stored", NEXT).await.is_empty(), "{kind:?}");
        });
    }
}

fn root_with_marked_child(kind: RelationshipType, id: &str, permissions: &[String]) -> Document {
    doc(&[
        ("$id", s(id)),
        ("name", s(id)),
        (NEXT, value_of(kind, d(&[("$id", s(MARKER)), ("name", s("stored")), ("$permissions", perms(permissions))]))),
    ])
}

#[test]
fn the_grant_check_judges_the_rewritten_document() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            let db = &f.db;
            create(db, SECOND, doc(&[("$id", s(MARKER)), ("name", s("stored")), ("$permissions", perms(&unheld()))])).await;
            db.set_check_grants(true);
            create(db, FIRST, root_with_marked_child(kind, "unhooked", &unheld())).await;
            assert_eq!(f.next_ids(FIRST, "unhooked", NEXT).await, names(&[MARKER]), "{kind:?}");

            f.add(f.marker());
            assert_eq!(
                refusal(db.create_document(FIRST, root_with_marked_child(kind, "refused", &unheld())).await),
                Some(UNHELD_REFUSAL.to_owned()),
                "{kind:?}"
            );
            let refused = db.authorization().skip(db.get_document(FIRST, "refused", Vec::new(), false)).await.unwrap();
            assert!(refused.is_empty());
            assert_eq!(names_in(db, SECOND).await, names(&["stored"]));

            let held = vec![Permission::read(&Role::user(USER, ""))];
            create(db, FIRST, root_with_marked_child(kind, "held", &held)).await;
            let created = f.next_ids(FIRST, "held", NEXT).await;
            assert_eq!(created.len(), 1);
            assert_ne!(created[0], MARKER);
            assert_eq!(get(db, SECOND, &created[0]).await.permissions().unwrap(), held);
            let stored = db.authorization().skip(db.get_document(SECOND, MARKER, Vec::new(), false)).await.unwrap();
            assert_eq!(stored.permissions().unwrap(), unheld());
        });
    }
}

#[test]
fn an_id_the_hook_assigns_is_judged_new_without_reading_the_stored_permissions() {
    for kind in TYPES {
        block_on(async {
            let f = two_way_database(kind).await;
            let db = &f.db;
            create(db, SECOND, doc(&[("$id", s("stored")), ("name", s("stored")), ("$permissions", perms(&unheld()))])).await;
            create(db, FIRST, doc(&[("$id", s("updated")), ("name", s("updated"))])).await;
            db.set_check_grants(true);
            f.add(f.hook(|id| Ok(if id == MARKER { "stored".into() } else { id.to_owned() })));
            let child = |id: &str| d(&[("$id", s(id)), ("name", s("stored")), ("$permissions", perms(&unheld()))]);

            assert_eq!(
                refusal(
                    db.create_document(FIRST, doc(&[("$id", s("created")), ("name", s("created")), (NEXT, value_of(kind, child(MARKER)))]))
                        .await
                ),
                Some(UNHELD_REFUSAL.to_owned()),
                "{kind:?}"
            );
            assert_eq!(
                refusal(db.update_document(FIRST, "updated", doc(&[(NEXT, value_of(kind, child(MARKER)))])).await),
                Some(UNHELD_REFUSAL.to_owned()),
                "{kind:?}"
            );
            assert_eq!(names_in(db, FIRST).await, names(&["updated"]));
            assert!(f.next_ids(FIRST, "updated", NEXT).await.is_empty());

            create(db, FIRST, doc(&[("$id", s("kept")), ("name", s("kept")), (NEXT, value_of(kind, child("stored")))])).await;
            assert_eq!(f.next_ids(FIRST, "kept", NEXT).await, names(&["stored"]), "{kind:?}");
            let stored = db.authorization().skip(db.get_document(SECOND, "stored", Vec::new(), false)).await.unwrap();
            assert_eq!(stored.permissions().unwrap(), unheld());
        });
    }
}

#[test]
fn an_unheld_role_is_refused_at_each_depth() {
    for kind in TYPES {
        block_on(async {
            let f = one_way_database(kind).await;
            let db = &f.db;
            db.set_check_grants(true);
            let depth_one = doc(&[
                ("$id", s("depth one")),
                (NEXT, value_of(kind, d(&[("name", s("child")), ("$permissions", perms(&unheld()))]))),
            ]);
            assert_eq!(refusal(db.create_document(FIRST, depth_one).await), Some(UNHELD_REFUSAL.to_owned()), "{kind:?}");
            let depth_two = doc(&[
                ("$id", s("depth two")),
                (
                    NEXT,
                    value_of(
                        kind,
                        d(&[
                            ("name", s("child")),
                            (NEXT, value_of(kind, d(&[("name", s("grandchild")), ("$permissions", perms(&unheld()))]))),
                        ]),
                    ),
                ),
            ]);
            assert_eq!(refusal(db.create_document(FIRST, depth_two).await), Some(UNHELD_REFUSAL.to_owned()), "{kind:?}");
            f.assert_wrote_nothing().await;
        });
    }
}

#[test]
fn an_unheld_role_on_a_new_to_many_item_is_refused() {
    for kind in [RelationshipType::OneToMany, RelationshipType::ManyToMany] {
        block_on(async {
            let f = one_way_database(kind).await;
            let db = &f.db;
            create(db, FIRST, doc(&[("$id", s("root")), (NEXT, docs(vec![doc(&[("$id", s("held")), ("name", s("held"))])]))]))
                .await;
            db.set_check_grants(true);
            let new = d(&[("name", s("new")), ("$permissions", perms(&[Permission::update(&Role::team("admin", ""))]))]);
            let result = db.update_document(FIRST, "root", doc(&[(NEXT, value::list([s("held"), new]))])).await;
            assert_eq!(refusal(result), Some(UNHELD_REFUSAL.to_owned()), "{kind:?}");
            assert_eq!(names_in(db, SECOND).await, names(&["held"]), "{kind:?}");
            assert_eq!(f.next_ids(FIRST, "root", NEXT).await, names(&["held"]), "{kind:?}");
        });
    }
}

#[test]
fn held_unchanged_and_cleared_permissions_are_allowed() {
    for kind in TYPES {
        block_on(async {
            let f = one_way_database(kind).await;
            let db = &f.db;
            let mut stored = unheld();
            stored.push(Permission::update(&Role::any()));
            create(db, SECOND, doc(&[("$id", s("stored")), ("name", s("stored")), ("$permissions", perms(&stored))])).await;
            db.set_check_grants(true);

            let held = vec![
                Permission::read(&Role::user(USER, "")),
                Permission::update(&Role::users("")),
                Permission::write(&Role::any()),
            ];
            create(db, FIRST, doc(&[("$id", s("held")), (NEXT, value_of(kind, d(&[("name", s("held")), ("$permissions", perms(&held))])))]))
                .await;
            create(
                db,
                FIRST,
                doc(&[
                    ("$id", s("unchanged")),
                    (NEXT, value_of(kind, d(&[("$id", s("stored")), ("name", s("unchanged")), ("$permissions", perms(&unheld()))]))),
                ]),
            )
            .await;
            create(
                db,
                FIRST,
                doc(&[("$id", s("cleared")), (NEXT, value_of(kind, d(&[("name", s("cleared")), ("$permissions", value::list([]))])))]),
            )
            .await;

            assert_eq!(f.related("held").await.permissions().unwrap(), held, "{kind:?}");
            assert_eq!(get(db, SECOND, "stored").await.get("name"), Some(&s("unchanged")), "{kind:?}");
            assert!(f.related("cleared").await.permissions().unwrap().is_empty(), "{kind:?}");
        });
    }
}

#[test]
fn absent_or_null_permissions_inherit_the_parents_unchecked() {
    for kind in TYPES {
        block_on(async {
            let f = one_way_database(kind).await;
            let db = &f.db;
            db.set_check_grants(true);
            let inherited = unheld();
            let mut nulled = doc(&[("name", s("nulled"))]);
            nulled.set("$permissions", Value::Null).unwrap();

            create(
                db,
                FIRST,
                doc(&[("$id", s("absent")), ("$permissions", perms(&inherited)), (NEXT, value_of(kind, d(&[("name", s("absent"))])))]),
            )
            .await;
            create(
                db,
                FIRST,
                doc(&[("$id", s("nulled")), ("$permissions", perms(&inherited)), (NEXT, value_of(kind, Value::from(nulled)))]),
            )
            .await;

            assert_eq!(f.related("absent").await.permissions().unwrap(), inherited, "{kind:?}");
            assert_eq!(f.related("nulled").await.permissions().unwrap(), inherited, "{kind:?}");
        });
    }
}

#[test]
fn malformed_permissions_are_a_structure_error() {
    block_on(async {
        let f = one_way_database(RelationshipType::ManyToOne).await;
        let db = &f.db;
        let create = || {
            db.create_document(
                FIRST,
                doc(&[("$id", s("root")), (NEXT, d(&[("name", s("child")), ("$permissions", strs(&["*"]))]))]),
            )
        };

        let unchecked = create().await.unwrap_err();
        assert!(!unchecked.is_a(Kind::Structure), "{:?}: {}", unchecked.kind(), unchecked.message());
        assert!(unchecked.is_a(Kind::Database), "{:?}: {}", unchecked.kind(), unchecked.message());

        db.set_check_grants(true);
        let checked = failure(create().await, Kind::Structure);
        assert_eq!(checked.message(), MALFORMED);
        f.assert_wrote_nothing().await;
    });
}

#[test]
fn disabled_authorization_or_the_toggle_off_allows_any_role() {
    for kind in TYPES {
        block_on(async {
            let f = one_way_database(kind).await;
            let db = &f.db;
            let root = |id: &str| {
                doc(&[("$id", s(id)), (NEXT, value_of(kind, d(&[("name", s(id)), ("$permissions", perms(&unheld()))])))])
            };
            create(db, FIRST, root("toggle off")).await;
            db.set_check_grants(true);
            db.authorization().skip(db.create_document(FIRST, root("authorization disabled"))).await.unwrap();
            db.with_check_grants(false, db.create_document(FIRST, root("scoped off"))).await.unwrap();

            for id in ["toggle off", "authorization disabled", "scoped off"] {
                assert_eq!(f.related(id).await.permissions().unwrap(), unheld(), "{kind:?} {id}");
            }
            assert!(db.is_checking_grants());
        });
    }
}

#[test]
fn a_bulk_update_refuses_a_related_document_given_as_a_document() {
    for kind in TYPES {
        block_on(async {
            let f = one_way_database(kind).await;
            let db = &f.db;
            db.create_documents(
                FIRST,
                vec![doc(&[("$id", s("one")), ("name", s("one"))]), doc(&[("$id", s("two")), ("name", s("two"))])],
                BATCH_SIZE,
                None,
            )
            .await
            .unwrap();
            db.set_check_grants(true);
            let related: Array = [
                (value::ArrayKey::from("name"), s("child")),
                (value::ArrayKey::from("$permissions"), perms(&[Permission::update(&Role::team("admin", ""))])),
            ]
            .into_iter()
            .collect();
            let mut marked = Document::new(related.clone()).unwrap();
            marked.put("$id", s(MARKER));
            let values = [
                ("document", value_of(kind, Value::from(marked))),
                (
                    "array",
                    if to_many(kind) { value::list([Value::Array(related.clone())]) } else { Value::Array(related) },
                ),
            ];
            for (form, value) in values {
                let error = failure(update_all(db, FIRST, doc(&[(NEXT, value)]), Vec::new()).await, Kind::Relationship);
                assert_eq!(error.message(), BULK_REFUSAL, "{kind:?} {form}");
            }
            assert_eq!(names_in(db, FIRST).await, names(&["one", "two"]));
            assert!(names_in(db, SECOND).await.is_empty());
            assert!(f.next_ids(FIRST, "one", NEXT).await.is_empty());
            assert!(f.next_ids(FIRST, "two", NEXT).await.is_empty());
        });
    }
}

/// `bulkUpdate()`.
async fn bulk_update(f: &Fixture, kind: RelationshipType) -> (Option<String>, Vec<String>, Vec<String>, Vec<String>) {
    let db = &f.db;
    create(db, FIRST, doc(&[("$id", s("one")), ("name", s("one"))])).await;
    let result = update_all(db, FIRST, doc(&[(NEXT, value_of(kind, d(&[("$id", s(MARKER)), ("name", s("child"))])))]), Vec::new())
        .await;
    (
        result.err().map(|e| format!("{:?}: {}", e.kind(), e.message())),
        names_in(db, FIRST).await,
        names_in(db, SECOND).await,
        f.next_ids(FIRST, "one", NEXT).await,
    )
}

#[test]
fn a_bulk_update_is_not_refused_and_does_not_run_the_hook_while_grants_are_not_checked() {
    for kind in TYPES {
        block_on(async {
            let unhooked = bulk_update(&two_way_database(kind).await, kind).await;
            let f = two_way_database(kind).await;
            f.add(f.marker());
            let hooked = bulk_update(&f, kind).await;
            assert_ne!(unhooked.0, Some(format!("{:?}: {}", Kind::Relationship, BULK_REFUSAL)));
            assert_eq!(unhooked, hooked, "{kind:?}: a registered hook changes nothing a bulk update writes");
            assert!(f.seen().is_empty());
        });
    }
}

#[test]
fn a_bulk_update_links_a_related_document_by_id_while_grants_are_checked() {
    block_on(async {
        let f = one_way_database(RelationshipType::ManyToOne).await;
        let db = &f.db;
        create(db, SECOND, doc(&[("$id", s("stored")), ("name", s("stored"))])).await;
        db.create_documents(
            FIRST,
            vec![doc(&[("$id", s("one")), ("name", s("one"))]), doc(&[("$id", s("two")), ("name", s("two"))])],
            BATCH_SIZE,
            None,
        )
        .await
        .unwrap();
        db.set_check_grants(true);
        f.add(f.marker());
        assert_eq!(update_all(db, FIRST, doc(&[(NEXT, s("stored"))]), Vec::new()).await.unwrap(), 2);
        assert_eq!(f.next_ids(FIRST, "one", NEXT).await, names(&["stored"]));
        assert_eq!(f.next_ids(FIRST, "two", NEXT).await, names(&["stored"]));
        assert!(f.seen().is_empty());
    });
}

#[test]
fn the_grant_check_is_scoped_and_off_by_default() {
    block_on(async {
        let f = one_way_database(RelationshipType::ManyToOne).await;
        let db = &f.db;
        assert!(!db.is_checking_grants());
        assert!(db.with_check_grants(true, async { db.is_checking_grants() }).await);
        assert!(!db.is_checking_grants());
    });
}

/// PHP's child coroutine is a task spawned without the caller's scopes here.
#[test]
fn a_snapshot_carries_the_grant_check_into_spawned_work() {
    block_on(async {
        let f = one_way_database(RelationshipType::ManyToOne).await;
        let db = f.db.clone();
        let root_with_child = |id: &str, role: &str| {
            doc(&[
                ("$id", s(id)),
                (
                    NEXT,
                    d(&[
                        ("$id", s(&format!("{id} child"))),
                        ("name", s(id)),
                        ("$permissions", perms(&[Permission::read(&Role::parse(role).unwrap())])),
                    ]),
                ),
            ])
        };
        let snapshot = db.with_check_grants(true, async { db.snapshot() }).await;
        let unscoped = refusal(db.create_document(FIRST, root_with_child("unscoped", "user:u2")).await);
        let refused =
            db.with_snapshot(&snapshot, async { refusal(db.create_document(FIRST, root_with_child("refused", "user:u2")).await) }).await;
        let held = db
            .with_snapshot(&snapshot, async {
                refusal(db.create_document(FIRST, root_with_child("held", &format!("user:{USER}"))).await)
            })
            .await;

        assert_eq!((unscoped, refused, held), (None, Some(UNHELD_REFUSAL.to_owned()), None));
        assert_eq!(f.next_ids(FIRST, "unscoped", NEXT).await, names(&["unscoped child"]));
        assert!(get(&db, FIRST, "refused").await.is_empty());
        assert_eq!(f.next_ids(FIRST, "held", NEXT).await, names(&["held child"]));
    });
}
