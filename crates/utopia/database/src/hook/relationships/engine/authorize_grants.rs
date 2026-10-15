//! PHP: `tests/unit/Relationships/AuthorizeGrantsTest.php` (the Memory
//! cases).

use super::nested_pre_check::{Assigning, Log, Marker, TYPES, ids_in};
use super::*;
use crate::database::Resolve;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipType};
use crate::validator::permissions::Permissions as PermissionsValidator;

const USER: &str = "u1";
const UNHELD: &str = "Permissions must be one of: (any, users, user:u1)";
const NO_CREATE: &str = "No permissions provided for action 'create'";
const NO_UPDATE: &str = "No permissions provided for action 'update'";
const PARENTS: &str = "parents";
const PARENT: &str = "parent";
const RELATED: &str = "related";
const LEAVES: &str = "leaves";
const STORED: &str = "stored";
const NEXT: &str = "next";
const BACK: &str = "back";
const MARKER: &str = "marker";

struct Fixture {
    db: Database,
    seen: Log,
    resolved: Log,
}

fn foreign() -> Vec<String> {
    vec![Permission::read(&Role::user("u2", ""))]
}

fn everything() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]
}

fn relationship(kind: RelationshipType, related: &str, key: &str) -> Relationship {
    Relationship::new(related, kind).key(key).two_way(true).two_way_key(BACK).on_delete(OnDelete::SetNull)
}

/// `build()`.
async fn build(kind: RelationshipType, parent: Vec<String>, related: Vec<String>, chained: bool) -> Fixture {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::new(Arc::new(Memory::new()), cache);
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role(&Role::users("").to_string());
    authorization.add_role(&Role::user(USER, "").to_string());
    db.set_authorization(authorization.clone());
    db.set_database("authorize_grants").set_namespace(&unique("authorize_grants"));
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();

    authorization
        .skip(async {
            db.create(None).await.unwrap();
            let parents =
                Collection::create(PARENTS, "", vec![string("name", 64)], Vec::new(), Some(parent), true, Array::new());
            db.create_collection(parents.unwrap()).await.unwrap();
            let collections: &[&str] = if chained { &[RELATED, LEAVES] } else { &[RELATED] };
            for id in collections {
                collection(&db, id, vec![string("name", 64)], related.clone(), false).await;
            }
            relate(&db, PARENTS, relationship(kind, RELATED, RELATED)).await;
            if chained {
                relate(&db, RELATED, relationship(kind, LEAVES, NEXT)).await;
            }
        })
        .await;
    Fixture { db, seen: Log::default(), resolved: Log::default() }
}

impl Fixture {
    /// `store()`.
    async fn store(&self, collection: &str, id: &str, permissions: Vec<String>) {
        let db = &self.db;
        db.authorization()
            .skip(db.create_document(
                collection,
                doc(&[("$id", s(id)), ("name", s("Stored")), ("$permissions", perms(&permissions))]),
            ))
            .await
            .unwrap();
    }

    /// `storedParent()`.
    async fn stored_parent(&self) -> Document {
        let db = &self.db;
        let parent = doc(&[("$id", s(PARENT)), ("$permissions", perms(&[Permission::read(&Role::any())]))]);
        db.authorization().skip(db.create_document(PARENTS, parent)).await.unwrap()
    }

    fn add_marker(&self) {
        self.db.add_hook(Hook::nested(Arc::new(Marker(self.seen.clone())))).unwrap();
    }

    fn resolve_as_stored(&self) -> Resolve {
        let resolved = self.resolved.clone();
        Arc::new(move |collection, id| {
            resolved.lock().unwrap().push((collection.to_owned(), id.to_owned()));
            None
        })
    }

    async fn related_names(&self) -> Vec<String> {
        let db = &self.db;
        let found = db.authorization().skip(db.find(RELATED, Vec::new(), PermissionType::Read)).await.unwrap();
        found
            .iter()
            .map(|d| value::as_str(d.get("name").unwrap_or(&Value::Null)).unwrap_or("").to_owned())
            .filter(|n| n != "Stored")
            .collect()
    }

    async fn grants(&self, collection: &str, document: &Document, resolve: Option<Resolve>) -> Option<String> {
        refusal(self.db.authorize_grants(collection, document, resolve).await)
    }

    async fn create(&self, document: &Document, resolve: Option<Resolve>) -> Option<String> {
        refusal(self.db.authorize_create(PARENTS, document, resolve).await)
    }
}

/// `value()`.
fn value_of(kind: RelationshipType, related: Value) -> Value {
    if matches!(kind, RelationshipType::OneToMany | RelationshipType::ManyToMany) {
        value::list([related])
    } else {
        related
    }
}

/// `parent()`.
fn parent(kind: RelationshipType, related: Value) -> Document {
    doc(&[
        ("$id", s(PARENT)),
        ("$permissions", perms(&[Permission::read(&Role::any())])),
        (RELATED, value_of(kind, related)),
    ])
}

/// `refusal()`: authorization and structure failures.
#[track_caller]
fn refusal<T>(result: Result<T>) -> Option<String> {
    match result {
        Ok(_) => None,
        Err(e) if e.is_a(Kind::Authorization) || e.is_a(Kind::Structure) => Some(e.message().to_owned()),
        Err(e) => panic!("unexpected {:?}: {}", e.kind(), e.message()),
    }
}

fn some(message: &str) -> Option<String> {
    Some(message.to_owned())
}

#[test]
fn an_unheld_grant_is_reported_when_the_parent_permission_is_missing() {
    for kind in TYPES {
        block_on(async {
            let f = build(kind, vec![Permission::read(&Role::any())], everything(), false).await;
            let old = f.stored_parent().await;
            f.db.set_check_grants(true);
            let related = d(&[("name", s("new")), ("$permissions", perms(&foreign()))]);
            let document = parent(kind, related.clone());
            let changes = doc(&[(RELATED, value_of(kind, related))]);

            assert_eq!(f.create(&document, None).await, some(NO_CREATE), "{kind:?}");
            assert_eq!(refusal(f.db.authorize_update(PARENTS, &old, &changes, None).await), some(NO_UPDATE));
            assert_eq!(f.grants(PARENTS, &document, None).await, some(UNHELD));
            assert_eq!(f.grants(PARENTS, &changes, None).await, some(UNHELD));
            assert!(f.related_names().await.is_empty());
        });
    }
}

#[test]
fn it_refuses_the_grants_authorize_create_and_update_refuse() {
    for kind in TYPES {
        block_on(async {
            let f = build(kind, everything(), everything(), false).await;
            f.store(RELATED, STORED, foreign()).await;
            let old = f.stored_parent().await;
            f.db.set_check_grants(true);
            let malformed = PermissionsValidator::default();
            assert!(!malformed.is_valid(&strs(&["malformed"])));
            let malformed = malformed.description();

            let mut adding = foreign();
            adding.push(Permission::update(&Role::user("u2", "")));
            let held = vec![Permission::read(&Role::user(USER, "")), Permission::update(&Role::users(""))];
            let array: Array = [
                (value::ArrayKey::from("name"), s("new")),
                (value::ArrayKey::from("$permissions"), perms(&foreign())),
            ]
            .into_iter()
            .collect();
            let cases: Vec<(&str, Value, Option<String>)> = vec![
                ("a new document adding an unheld role", d(&[("name", s("new")), ("$permissions", perms(&foreign()))]), some(UNHELD)),
                ("a new document adding held roles", d(&[("name", s("new")), ("$permissions", perms(&held))]), None),
                ("a new document given as an array", Value::Array(array), some(UNHELD)),
                ("a new document without permissions", d(&[("name", s("new"))]), None),
                (
                    "a stored document sending its roles back",
                    d(&[("$id", s(STORED)), ("name", s("Stored")), ("$permissions", perms(&foreign()))]),
                    None,
                ),
                (
                    "a stored document adding an unheld role",
                    d(&[("$id", s(STORED)), ("name", s("Stored")), ("$permissions", perms(&adding))]),
                    some(UNHELD),
                ),
                (
                    "a stored document dropping its roles",
                    d(&[("$id", s(STORED)), ("name", s("Stored")), ("$permissions", value::list([]))]),
                    None,
                ),
                ("malformed permissions", d(&[("name", s("new")), ("$permissions", strs(&["malformed"]))]), Some(malformed)),
            ];

            for (case, related, expected) in cases {
                let document = parent(kind, related.clone());
                let changes = doc(&[(RELATED, value_of(kind, related))]);
                let create = f.create(&document, None).await;
                let update = refusal(f.db.authorize_update(PARENTS, &old, &changes, None).await);
                assert_eq!(create, expected, "{kind:?} {case}: authorizeCreate()");
                assert_eq!(update, expected, "{kind:?} {case}: authorizeUpdate()");
                assert_eq!(f.grants(PARENTS, &document, None).await, create, "{kind:?} {case}");
                assert_eq!(f.grants(PARENTS, &changes, None).await, update, "{kind:?} {case}");
            }

            let error = failure(
                f.db.authorize_grants(PARENTS, &parent(kind, d(&[("$permissions", strs(&["malformed"]))])), None).await,
                Kind::Structure,
            );
            assert!(!error.message().is_empty());
        });
    }
}

#[test]
fn it_checks_no_create_or_update_permission() {
    for kind in TYPES {
        block_on(async {
            let f = build(kind, Vec::new(), Vec::new(), false).await;
            f.store(RELATED, STORED, Vec::new()).await;
            f.db.set_check_grants(true);
            let held = vec![Permission::read(&Role::user(USER, ""))];
            let documents = [
                ("a new related document", parent(kind, d(&[("name", s("new")), ("$permissions", perms(&held))]))),
                (
                    "a stored related document the caller cannot read",
                    parent(kind, d(&[("$id", s(STORED)), ("name", s("Changed")), ("$permissions", value::list([]))])),
                ),
                ("a related document given by ID", parent(kind, s(STORED))),
                (
                    "unheld roles on the parent itself",
                    doc(&[("$id", s(PARENT)), ("$permissions", perms(&foreign()))]),
                ),
            ];
            for (case, document) in documents {
                assert_eq!(f.create(&document, None).await, some(NO_CREATE), "{kind:?} {case}");
                assert_eq!(f.grants(PARENTS, &document, None).await, None, "{kind:?} {case}");
            }
            assert!(f.related_names().await.is_empty());
        });
    }
}

#[test]
fn a_staged_view_is_judged_as_authorize_create_judges_it() {
    for kind in TYPES {
        block_on(async {
            let f = build(kind, everything(), everything(), false).await;
            f.store(RELATED, STORED, foreign()).await;
            f.db.set_check_grants(true);
            let document =
                parent(kind, d(&[("$id", s("staged")), ("name", s("Staged")), ("$permissions", perms(&foreign()))]));
            let resolved = f.resolved.clone();
            let staged: Resolve = Arc::new(move |collection, id| {
                resolved.lock().unwrap().push((collection.to_owned(), id.to_owned()));
                (id == "staged").then(|| {
                    doc(&[
                        ("$id", s("staged")),
                        ("$collection", s(RELATED)),
                        ("name", s("Staged")),
                        ("$permissions", perms(&foreign())),
                    ])
                })
            });
            let recreated =
                parent(kind, d(&[("$id", s(STORED)), ("name", s("Recreated")), ("$permissions", perms(&foreign()))]));
            let deleted: Resolve = Arc::new(|_, id| (id == STORED).then(Document::empty));

            assert_eq!(f.grants(PARENTS, &document, None).await, some(UNHELD), "{kind:?}: judged as stored");
            assert_eq!(f.grants(PARENTS, &document, Some(staged.clone())).await, None, "{kind:?}: judged as staged");
            assert_eq!(*f.resolved.lock().unwrap(), vec![(RELATED.to_owned(), "staged".to_owned())]);
            assert_eq!(f.grants(PARENTS, &recreated, None).await, None, "{kind:?}");
            assert_eq!(f.grants(PARENTS, &recreated, Some(deleted.clone())).await, some(UNHELD), "{kind:?}");

            for (checked, resolve) in
                [(&document, None), (&document, Some(staged)), (&recreated, None), (&recreated, Some(deleted))]
            {
                assert_eq!(f.create(checked, resolve.clone()).await, f.grants(PARENTS, checked, resolve).await);
            }
        });
    }
}

#[test]
fn nested_hooks_prepare_every_related_document_first() {
    for kind in TYPES {
        block_on(async {
            let f = build(kind, everything(), everything(), true).await;
            f.store(RELATED, MARKER, foreign()).await;
            f.store(LEAVES, MARKER, foreign()).await;
            let leaf = doc(&[("$id", s(MARKER)), ("name", s("leaf")), ("$permissions", perms(&foreign()))]);
            let related = doc(&[
                ("$id", s(MARKER)),
                ("name", s("related")),
                ("$permissions", perms(&foreign())),
                (NEXT, value_of(kind, Value::from(leaf.clone()))),
            ]);
            let document = parent(kind, Value::from(related.clone()));
            f.db.set_check_grants(true);

            assert_eq!(f.grants(PARENTS, &document, None).await, None, "{kind:?}");

            f.add_marker();

            assert_eq!(f.grants(PARENTS, &document, Some(f.resolve_as_stored())).await, some(UNHELD), "{kind:?}");
            let pair = |c: &str| (c.to_owned(), MARKER.to_owned());
            assert_eq!(*f.seen.lock().unwrap(), vec![pair(RELATED)], "{kind:?}: refused before its children");
            assert!(f.resolved.lock().unwrap().is_empty(), "{kind:?}: a rewritten id is not resolved");
            assert_eq!(f.create(&document, None).await, f.grants(PARENTS, &document, None).await);

            f.seen.lock().unwrap().clear();
            let held = doc(&[
                ("$id", s(MARKER)),
                ("name", s("related")),
                (NEXT, value_of(kind, Value::from(leaf.clone()))),
            ]);
            assert_eq!(f.grants(PARENTS, &parent(kind, Value::from(held)), None).await, some(UNHELD), "{kind:?}");
            assert_eq!(*f.seen.lock().unwrap(), vec![pair(RELATED), pair(LEAVES)], "{kind:?}");

            f.seen.lock().unwrap().clear();
            let leafless = doc(&[
                ("$id", s(MARKER)),
                ("name", s("related")),
                (NEXT, value_of(kind, d(&[("$id", s(MARKER)), ("name", s("leaf"))]))),
            ]);
            assert_eq!(f.grants(PARENTS, &parent(kind, Value::from(leafless)), Some(f.resolve_as_stored())).await, None);
            assert_eq!(*f.seen.lock().unwrap(), vec![pair(RELATED), pair(LEAVES)], "{kind:?}");
            assert_eq!(related.id(), MARKER);
            assert_eq!(leaf.id(), MARKER);
            assert_eq!(ids_in(document.get(RELATED)), names(&[MARKER]));
            assert!(f.related_names().await.is_empty());
        });
    }
}

#[test]
fn an_id_a_nested_hook_assigns_is_judged_new() {
    for kind in TYPES {
        block_on(async {
            let f = build(kind, everything(), everything(), false).await;
            f.store(RELATED, STORED, foreign()).await;
            f.db.set_check_grants(true);
            f.db.add_hook(Hook::nested(Arc::new(Assigning(STORED.to_owned())))).unwrap();
            let staged: Resolve = Arc::new(|_, id| {
                (id == STORED).then(|| {
                    doc(&[
                        ("$id", s(STORED)),
                        ("$collection", s(RELATED)),
                        ("name", s("Stored")),
                        ("$permissions", perms(&foreign())),
                    ])
                })
            });
            let kept = parent(kind, d(&[("$id", s(STORED)), ("name", s("Stored")), ("$permissions", perms(&foreign()))]));
            let assigned =
                parent(kind, d(&[("$id", s(MARKER)), ("name", s("Stored")), ("$permissions", perms(&foreign()))]));

            assert_eq!(f.grants(PARENTS, &kept, None).await, None, "{kind:?}");
            assert_eq!(f.grants(PARENTS, &kept, Some(staged.clone())).await, None, "{kind:?}");
            assert_eq!(f.grants(PARENTS, &assigned, None).await, some(UNHELD), "{kind:?}");
            assert_eq!(f.grants(PARENTS, &assigned, Some(staged.clone())).await, some(UNHELD), "{kind:?}");
            assert_eq!(
                f.create(&assigned, Some(staged.clone())).await,
                f.grants(PARENTS, &assigned, Some(staged)).await
            );
        });
    }
}

#[test]
fn it_does_nothing_unless_grants_are_checked() {
    for kind in TYPES {
        block_on(async {
            let f = build(kind, everything(), everything(), false).await;
            f.add_marker();
            let document = parent(
                kind,
                d(&[("$id", s(MARKER)), ("name", s("new")), ("$permissions", perms(&foreign()))]),
            );
            let db = &f.db;
            let check = |collection: &'static str| db.authorize_grants(collection, &document, Some(f.resolve_as_stored()));

            assert_eq!(refusal(check(PARENTS).await), None, "grants are not checked by default");
            assert_eq!(refusal(check("missing").await), None, "nothing is read while grants are not checked");

            db.set_check_grants(true);

            assert_eq!(refusal(db.with_check_grants(false, check(PARENTS)).await), None);
            assert_eq!(refusal(db.authorization().skip(check(PARENTS)).await), None);
            assert_eq!(refusal(db.skip_relationships(check(PARENTS)).await), None);
            assert!(f.seen.lock().unwrap().is_empty(), "no hook runs while nothing is checked");
            assert!(f.resolved.lock().unwrap().is_empty());
            assert_eq!(refusal(check(PARENTS).await), some(UNHELD));

            failure(check("missing").await, Kind::NotFound);
        });
    }
}
