//! PHP: `tests/unit/Relationships/NestedPreCheckTest.php` (the Memory
//! cases).

use std::sync::Mutex;

use async_trait::async_trait;

use super::*;
use crate::database::Resolve;
use crate::hook::Nested;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipType};

const USER: &str = "u1";
const UNHELD_REFUSAL: &str = "Permissions must be one of: (any, users, user:u1)";
const NO_CREATE: &str = "No permissions provided for action 'create'";
const RELATED: &str = "related";
const STORED: &str = "stored";
const LEAVES: &str = "leaves";
const NEXT: &str = "next";
const BACK: &str = "back";
const MARKER: &str = "marker";

pub(super) const TYPES: [RelationshipType; 4] = [
    RelationshipType::OneToOne,
    RelationshipType::OneToMany,
    RelationshipType::ManyToOne,
    RelationshipType::ManyToMany,
];

pub(super) type Log = Arc<Mutex<Vec<(String, String)>>>;

struct Fixture {
    db: Database,
    seen: Log,
    resolved: Log,
}

fn any() -> Role {
    Role::any()
}

fn u2() -> Vec<String> {
    vec![Permission::read(&Role::user("u2", ""))]
}

fn cru() -> Vec<String> {
    vec![Permission::create(&any()), Permission::read(&any()), Permission::update(&any())]
}

/// `connect()`.
async fn connect() -> Fixture {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::new(Arc::new(Memory::new()), cache);
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role(&Role::users("").to_string());
    authorization.add_role(&Role::user(USER, "").to_string());
    db.set_authorization(authorization);
    db.set_database("nested_pre_check").set_namespace(&unique("nested_pre_check"));
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    Fixture { db, seen: Log::default(), resolved: Log::default() }
}

/// `createCollections()`.
async fn create_collections(db: &Database, related: &[&str], permissions: Vec<String>) {
    db.create(None).await.unwrap();
    let parents =
        Collection::create("parents", "", vec![string("name", 64)], Vec::new(), Some(cru()), true, Array::new());
    db.create_collection(parents.unwrap()).await.unwrap();
    for id in related {
        collection(db, id, vec![string("name", 64)], permissions.clone(), false).await;
    }
}

/// `seed()`.
async fn seed(kind: RelationshipType, permissions: Vec<String>, stored: Option<Vec<String>>) -> Fixture {
    let f = connect().await;
    let db = &f.db;
    db.authorization()
        .skip(async {
            create_collections(db, &[RELATED], permissions).await;
            let relationship = match kind {
                RelationshipType::OneToOne => {
                    Relationship::one_to_one(RELATED).key(RELATED).on_delete(OnDelete::SetNull)
                }
                RelationshipType::ManyToOne => {
                    Relationship::many_to_one(RELATED).key(RELATED).on_delete(OnDelete::SetNull)
                }
                RelationshipType::OneToMany => Relationship::one_to_many(RELATED)
                    .key(RELATED)
                    .two_way(true)
                    .two_way_key(BACK)
                    .on_delete(OnDelete::SetNull),
                RelationshipType::ManyToMany => Relationship::many_to_many(RELATED)
                    .key(RELATED)
                    .two_way(true)
                    .two_way_key(BACK)
                    .on_delete(OnDelete::SetNull),
            };
            relate(db, "parents", relationship).await;
            if let Some(stored) = stored {
                create(
                    db,
                    RELATED,
                    doc(&[("$id", s(STORED)), ("name", s("Stored")), ("$permissions", perms(&stored))]),
                )
                .await;
            }
        })
        .await;
    f
}

fn two_way(kind: RelationshipType, related: &str, key: &str) -> Relationship {
    Relationship::new(related, kind).key(key).two_way(true).two_way_key(BACK).on_delete(OnDelete::SetNull)
}

/// `chain()`.
async fn chain(kind: RelationshipType, permissions: Vec<String>) -> Fixture {
    let f = connect().await;
    let db = &f.db;
    db.authorization()
        .skip(async {
            create_collections(db, &[RELATED, LEAVES], permissions).await;
            relate(db, "parents", two_way(kind, RELATED, RELATED)).await;
            relate(db, RELATED, two_way(kind, LEAVES, NEXT)).await;
        })
        .await;
    f
}

/// A nested hook recording what it prepares and giving the marker a new id.
pub(super) struct Marker(pub(super) Log);

#[async_trait]
impl Nested for Marker {
    async fn prepare(&self, collection: &Collection, mut document: Document) -> Result<Document> {
        self.0.lock().unwrap().push((collection.id().to_owned(), document.id().to_owned()));
        if document.id() == MARKER {
            document.put("$id", s(&crate::id::unique()?));
        }
        Ok(document)
    }
}

/// A nested hook giving the marker the id `self.0`.
pub(super) struct Assigning(pub(super) String);

#[async_trait]
impl Nested for Assigning {
    async fn prepare(&self, _collection: &Collection, mut document: Document) -> Result<Document> {
        if document.id() == MARKER {
            document.put("$id", s(&self.0));
        }
        Ok(document)
    }
}

impl Fixture {
    fn add_marker(&self) {
        self.db.add_hook(Hook::nested(Arc::new(Marker(self.seen.clone())))).unwrap();
    }

    /// `resolveAsStored()`: records each lookup and judges every document as stored.
    fn resolve_as_stored(&self) -> Resolve {
        let resolved = self.resolved.clone();
        Arc::new(move |collection, id| {
            resolved.lock().unwrap().push((collection.to_owned(), id.to_owned()));
            None
        })
    }

    /// `storedParent()`.
    async fn stored_parent(&self) -> Document {
        let db = &self.db;
        let stored = db.authorization().skip(db.get_document("parents", "parent", Vec::new(), false)).await.unwrap();
        if !stored.is_empty() {
            return stored;
        }
        let parent = doc(&[
            ("$id", s("parent")),
            ("$permissions", perms(&[Permission::read(&any()), Permission::update(&any())])),
        ]);
        db.authorization().skip(db.create_document("parents", parent)).await.unwrap()
    }

    async fn names(&self, collection: &str) -> Vec<String> {
        let db = &self.db;
        let found = db.authorization().skip(db.find(collection, Vec::new(), PermissionType::Read)).await.unwrap();
        found.iter().map(|d| value::as_str(d.get("name").unwrap_or(&Value::Null)).unwrap_or("").to_owned()).collect()
    }

    /// `relatedNames()`.
    async fn related_names(&self) -> Vec<String> {
        self.names(RELATED).await.into_iter().filter(|n| n != "Stored").collect()
    }
}

fn many(kind: RelationshipType) -> bool {
    matches!(kind, RelationshipType::OneToMany | RelationshipType::ManyToMany)
}

/// `value()`: a list for the many side.
pub(super) fn related_value(kind: RelationshipType, related: Document) -> Value {
    if many(kind) { docs(vec![related]) } else { Value::from(related) }
}

/// `reverse()`.
fn reverse(kind: RelationshipType, related: Document) -> Value {
    if matches!(kind, RelationshipType::ManyToOne | RelationshipType::ManyToMany) {
        docs(vec![related])
    } else {
        Value::from(related)
    }
}

/// `parent()`.
fn parent(kind: RelationshipType, related: Document) -> Document {
    doc(&[
        ("$id", s("parent")),
        ("$permissions", perms(&[Permission::read(&any())])),
        (RELATED, related_value(kind, related)),
    ])
}

/// The ids a relationship value holds (`ids()`).
pub(super) fn ids_in(value: Option<&Value>) -> Vec<String> {
    let items: Vec<Value> = match value {
        Some(Value::Array(a)) => a.values().cloned().collect(),
        Some(v) => vec![v.clone()],
        None => vec![Value::Null],
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

/// `refusal()`: the message of an authorization failure, `None` on success.
#[track_caller]
fn refusal<T>(result: Result<T>) -> Option<String> {
    match result {
        Ok(_) => None,
        Err(e) if e.is_a(Kind::Authorization) => Some(e.message().to_owned()),
        Err(e) => panic!("unexpected {:?}: {}", e.kind(), e.message()),
    }
}

fn staged_as(id: &'static str, name: &'static str, permissions: Vec<String>) -> Resolve {
    Arc::new(move |collection, requested| {
        (collection == RELATED && requested == id).then(|| {
            doc(&[
                ("$id", s(id)),
                ("$collection", s(RELATED)),
                ("name", s(name)),
                ("$permissions", perms(&permissions)),
            ])
        })
    })
}

fn unheld() -> Option<String> {
    Some(UNHELD_REFUSAL.to_owned())
}

#[test]
fn an_unheld_grant_is_refused_and_nothing_is_written() {
    for kind in TYPES {
        block_on(async {
            let f = seed(kind, cru(), None).await;
            let db = &f.db;
            db.set_check_grants(true);
            let related = doc(&[("name", s("new")), ("$permissions", perms(&u2()))]);
            let old = f.stored_parent().await;

            assert_eq!(refusal(db.authorize_create("parents", &parent(kind, related.clone()), None).await), unheld());
            let changes = doc(&[(RELATED, related_value(kind, related.clone()))]);
            assert_eq!(refusal(db.authorize_update("parents", &old, &changes, None).await), unheld());
            assert!(f.related_names().await.is_empty());
            let off =
                db.with_check_grants(false, db.authorize_create("parents", &parent(kind, related.clone()), None)).await;
            assert_eq!(refusal(off), None, "with the toggle off the grant is not checked");
            let skipped =
                db.authorization().skip(db.authorize_create("parents", &parent(kind, related.clone()), None)).await;
            assert_eq!(refusal(skipped), None, "with authorization disabled the grant is not checked");
        });
    }
}

#[test]
fn a_staged_views_permissions_count_as_current() {
    for kind in TYPES {
        block_on(async {
            let f = seed(kind, cru(), Some(Vec::new())).await;
            let db = &f.db;
            db.set_check_grants(true);
            let staged = staged_as(STORED, "Stored", u2());
            let parent =
                parent(kind, doc(&[("$id", s(STORED)), ("name", s("Changed")), ("$permissions", perms(&u2()))]));

            assert_eq!(refusal(db.authorize_create("parents", &parent, None).await), unheld(), "judged as stored");
            assert_eq!(refusal(db.authorize_create("parents", &parent, Some(staged.clone())).await), None);
            let old = f.stored_parent().await;
            let changes = doc(&[(RELATED, parent.get(RELATED).cloned().unwrap())]);
            assert_eq!(refusal(db.authorize_update("parents", &old, &changes, Some(staged)).await), None);
        });
    }
}

#[test]
fn a_document_staged_for_deletion_is_new() {
    for kind in TYPES {
        block_on(async {
            let f = seed(kind, cru(), Some(u2())).await;
            let db = &f.db;
            db.set_check_grants(true);
            let deleted: Resolve =
                Arc::new(|collection, id| (collection == RELATED && id == STORED).then(Document::empty));
            let parent =
                parent(kind, doc(&[("$id", s(STORED)), ("name", s("Recreated")), ("$permissions", perms(&u2()))]));

            assert_eq!(refusal(db.authorize_create("parents", &parent, None).await), None, "judged as stored");
            assert_eq!(
                refusal(db.authorize_create("parents", &parent, Some(deleted)).await),
                unheld(),
                "judged as staged"
            );
        });
    }
}

#[test]
fn a_nested_hook_rewrites_in_the_pre_checks() {
    for kind in TYPES {
        block_on(async {
            let f = chain(kind, cru()).await;
            let db = &f.db;
            let old = f.stored_parent().await;
            f.add_marker();
            let resolve = f.resolve_as_stored();
            let leaf = doc(&[("$id", s(MARKER)), ("name", s("leaf"))]);
            let related = doc(&[("$id", s(MARKER)), ("name", s("related")), (NEXT, related_value(kind, leaf.clone()))]);
            let parent = parent(kind, related.clone());

            db.authorize_create("parents", &parent, Some(resolve.clone())).await.unwrap();
            let changes = doc(&[(RELATED, related_value(kind, related.clone()))]);
            db.authorize_update("parents", &old, &changes, Some(resolve.clone())).await.unwrap();
            let inverse = doc(&[
                ("$id", s(MARKER)),
                ("name", s("inverse related")),
                (BACK, reverse(kind, doc(&[("$id", s(MARKER))]))),
            ]);
            db.authorize_create(LEAVES, &doc(&[("$id", s("leaf")), (BACK, reverse(kind, inverse))]), Some(resolve))
                .await
                .unwrap();

            let pair = |c: &str| (c.to_owned(), MARKER.to_owned());
            let forward = [pair(RELATED), pair(LEAVES)];
            let mut expected: Vec<(String, String)> = forward.to_vec();
            expected.extend(forward.iter().cloned());
            expected.push(pair(RELATED));
            expected.push(pair("parents"));
            assert_eq!(*f.seen.lock().unwrap(), expected, "{kind:?}");

            let resolved = f.resolved.lock().unwrap().clone();
            let collections: Vec<&str> = resolved.iter().map(|(c, _)| c.as_str()).collect();
            assert_eq!(collections, [RELATED, LEAVES, RELATED, LEAVES, RELATED, "parents"], "{kind:?}");
            let ids: Vec<&String> = resolved.iter().map(|(_, i)| i).collect();
            assert!(!ids.iter().any(|i| *i == MARKER || i.is_empty()), "{kind:?}");
            let mut distinct = ids.clone();
            distinct.sort();
            distinct.dedup();
            assert_eq!(distinct.len(), ids.len(), "{kind:?}");
            assert_eq!(ids_in(parent.get(RELATED)), names(&[MARKER]));
            assert_eq!(related.id(), MARKER);
            assert_eq!(leaf.id(), MARKER);
            assert!(f.related_names().await.is_empty());
            assert!(f.names(LEAVES).await.is_empty());
        });
    }
}

#[test]
fn a_rewritten_id_needs_create_where_the_marked_document_needs_update() {
    for kind in TYPES {
        block_on(async {
            let f = chain(kind, vec![Permission::read(&any()), Permission::update(&any())]).await;
            let db = &f.db;
            db.authorization()
                .skip(db.create_document(RELATED, doc(&[("$id", s(MARKER)), ("name", s("Literal"))])))
                .await
                .unwrap();
            let parent = parent(kind, doc(&[("$id", s(MARKER)), ("name", s("Changed"))]));

            assert_eq!(refusal(db.authorize_create("parents", &parent, None).await), None, "{kind:?}");
            f.add_marker();
            assert_eq!(
                refusal(db.authorize_create("parents", &parent, None).await),
                Some(NO_CREATE.to_owned()),
                "{kind:?}: a rewritten id is new, which needs create"
            );
        });
    }
}

#[test]
fn the_grant_check_runs_after_the_rewrite() {
    for kind in TYPES {
        block_on(async {
            let f = chain(kind, cru()).await;
            let db = &f.db;
            db.authorization()
                .skip(db.create_document(
                    RELATED,
                    doc(&[("$id", s(MARKER)), ("name", s("Stored")), ("$permissions", perms(&u2()))]),
                ))
                .await
                .unwrap();
            let old = f.stored_parent().await;
            db.set_check_grants(true);
            let related = doc(&[("$id", s(MARKER)), ("name", s("Stored")), ("$permissions", perms(&u2()))]);
            let parent = parent(kind, related.clone());
            let changes = doc(&[(RELATED, related_value(kind, related))]);
            let staged = staged_as(MARKER, "Stored", u2());

            assert_eq!(refusal(db.authorize_create("parents", &parent, None).await), None, "{kind:?}");
            assert_eq!(refusal(db.authorize_update("parents", &old, &changes, None).await), None, "{kind:?}");

            f.add_marker();

            assert_eq!(refusal(db.authorize_create("parents", &parent, None).await), unheld(), "{kind:?}");
            assert_eq!(refusal(db.authorize_update("parents", &old, &changes, None).await), unheld(), "{kind:?}");
            assert_eq!(refusal(db.authorize_create("parents", &parent, Some(staged)).await), unheld(), "{kind:?}");
            assert!(f.related_names().await.is_empty());
        });
    }
}

#[test]
fn an_id_the_hook_assigns_is_judged_new_without_the_stored_or_staged_permissions() {
    for kind in TYPES {
        block_on(async {
            let f = seed(kind, cru(), Some(u2())).await;
            let db = &f.db;
            db.set_check_grants(true);
            db.add_hook(Hook::nested(Arc::new(Assigning(STORED.to_owned())))).unwrap();
            let staged = staged_as(STORED, "Stored", u2());
            let kept = parent(kind, doc(&[("$id", s(STORED)), ("name", s("Stored")), ("$permissions", perms(&u2()))]));
            let assigned =
                parent(kind, doc(&[("$id", s(MARKER)), ("name", s("Stored")), ("$permissions", perms(&u2()))]));

            assert_eq!(refusal(db.authorize_create("parents", &kept, None).await), None, "{kind:?}");
            assert_eq!(refusal(db.authorize_create("parents", &kept, Some(staged.clone())).await), None, "{kind:?}");
            assert_eq!(refusal(db.authorize_create("parents", &assigned, None).await), unheld(), "{kind:?}");
            assert_eq!(
                refusal(db.authorize_create("parents", &assigned, Some(staged.clone())).await),
                unheld(),
                "{kind:?}"
            );
            let old = f.stored_parent().await;
            let changes = doc(&[(RELATED, assigned.get(RELATED).cloned().unwrap())]);
            assert_eq!(
                refusal(db.authorize_update("parents", &old, &changes, Some(staged)).await),
                unheld(),
                "{kind:?}"
            );
            assert!(f.related_names().await.is_empty());
        });
    }
}

#[test]
fn an_id_the_hook_assigns_needs_create_while_grants_are_checked() {
    for kind in TYPES {
        block_on(async {
            let held = vec![Permission::read(&Role::user(USER, ""))];
            let related = doc(&[("$id", s(MARKER)), ("name", s("Created")), ("$permissions", perms(&held))]);

            let f = chain(kind, vec![Permission::read(&any()), Permission::update(&any())]).await;
            let db = &f.db;
            db.set_check_grants(true);
            f.add_marker();
            let no_create = Some(NO_CREATE.to_owned());
            assert_eq!(refusal(db.authorize_create("parents", &parent(kind, related.clone()), None).await), no_create);
            assert_eq!(refusal(db.create_document("parents", parent(kind, related.clone())).await), no_create);
            assert!(f.related_names().await.is_empty());

            let f = chain(kind, cru()).await;
            let db = &f.db;
            db.set_check_grants(true);
            f.add_marker();
            assert_eq!(refusal(db.authorize_create("parents", &parent(kind, related.clone()), None).await), None);
            let created = db.create_document("parents", parent(kind, related.clone())).await.unwrap();
            let created = ids_in(created.get(RELATED));
            assert_eq!(created.len(), 1, "{kind:?}");
            assert_ne!(created[0], MARKER);
            let stored =
                db.authorization().skip(db.get_document(RELATED, &created[0], Vec::new(), false)).await.unwrap();
            assert_eq!(stored.permissions().unwrap(), held);
            assert_eq!(f.related_names().await, names(&["Created"]));
        });
    }
}
