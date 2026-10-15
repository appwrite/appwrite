//! PHP: `tests/unit/Relationships/PreparedCreateTest.php` (the Memory cases;
//! `RelationshipMemory` is a [`Spy`] over Memory, without savepoints where
//! the mode has none). PHP also checks that a failed write leaves the
//! related documents it was given unchanged; documents are values here, so
//! a write cannot change the caller's.

use std::sync::atomic::Ordering;

use super::*;
use crate::database::spy::Spy;
use crate::filter::Callback;
use crate::index::Index;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipType};

#[derive(Clone, Copy, PartialEq, Debug)]
enum Mode {
    OneByOne,
    Deferred,
    OneByOneWithoutSavepoints,
    Immediate,
}

impl Mode {
    fn savepoints(self) -> bool {
        matches!(self, Mode::OneByOne | Mode::Deferred)
    }

    fn prepare(self) -> bool {
        matches!(self, Mode::Deferred | Mode::Immediate)
    }
}

const PREPARED: [&str; 21] = [
    "one to many",
    "one to many past the maximum depth",
    "one to many one way",
    "many to many",
    "many to many past the maximum depth",
    "many to many one way",
    "many to one",
    "one to one",
    "one to one one way",
    "documents mixed with ids",
    "one to many documents mixed with ids",
    "one to one by id",
    "existence checks skipped",
    "new document then its id",
    "one collection at several depths",
    "inside a transaction",
    "created through an update",
    "one to many update",
    "many to many update",
    "many to one update",
    "shared tables",
];

const SCENARIOS: [&str; 39] = [
    "one to many",
    "one to many past the maximum depth",
    "one to many one way",
    "many to many",
    "many to many past the maximum depth",
    "many to many one way",
    "many to one",
    "one to one",
    "one to one one way",
    "generated ids and own permissions",
    "documents mixed with ids",
    "one to many documents mixed with ids",
    "existing related documents",
    "existing many to many related document",
    "associative related document holding a stored one",
    "repeated related document",
    "one to one by id",
    "existence checks skipped",
    "new document then its id",
    "one collection at several depths",
    "id already taken",
    "related document with the id of the document it belongs to",
    "inside a transaction",
    "created through an update",
    "one to many update",
    "many to many update",
    "many to one update",
    "update with an existing related document",
    "update with an invalid related document",
    "shared tables",
    "shared tables with an existing related document",
    "tenant per document",
    "missing tenant on a related document",
    "invalid related document",
    "invalid relationship value",
    "related collection the caller may not create in",
    "many to many link the caller may not update",
    "unreadable existing related document",
    "unique attribute shared by related documents",
];

/// How a scenario writes its document.
enum Write {
    Create(&'static str),
    CreateWithoutExistChecks(&'static str),
    CreateInTransaction(&'static str),
    Update(&'static str, &'static str),
}

/// `database()`.
async fn database(spy: &Arc<Spy>, mode: Mode, tenant: Option<i64>, tenant_per_document: bool, codecs: Vec<Callback>) -> Database {
    if !mode.savepoints() {
        spy.without_savepoints();
    }
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let codecs: Vec<Arc<dyn crate::filter::Codec>> =
        codecs.into_iter().map(|c| Arc::new(c) as Arc<dyn crate::filter::Codec>).collect();
    let db = Database::with_filters(spy.clone(), cache, codecs).unwrap();
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role(&Role::any().to_string());
    db.set_authorization(authorization);
    db.set_database("prepared_create").set_namespace("prepared");
    if tenant.is_some() || tenant_per_document {
        db.set_shared_tables(true)
            .set_tenant_per_document(tenant_per_document)
            .set_tenant(tenant.map(Value::Int).unwrap_or(Value::Null));
    }
    db.create(None).await.unwrap();
    db.add_hook(Hook::Relationships(Arc::new(Relationships::new(mode.prepare())))).unwrap();
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db
}

/// `chain()`.
async fn chain(db: &Database, kind: RelationshipType, depth: usize, two_way: bool) {
    for level in 0..=depth {
        collection(db, &format!("level{level}"), vec![string("name", 64), Attribute::integer("score")], crud(), true).await;
    }
    for level in 0..depth {
        relate(
            db,
            &format!("level{level}"),
            Relationship::new(format!("level{}", level + 1), kind)
                .two_way(two_way)
                .key("next")
                .two_way_key("prev")
                .on_delete(OnDelete::Cascade),
        )
        .await;
    }
}

/// `tree()`.
fn tree(kind: RelationshipType, id: &str, level: i64, depth: i64) -> Document {
    let any = Role::any();
    let mut node = doc(&[
        ("$id", s(id)),
        ("name", s(&format!("node {id}"))),
        ("score", Value::Int(level)),
        ("$permissions", perms(&[Permission::read(&any), Permission::update(&any), Permission::delete(&any)])),
    ]);
    if level < depth {
        let child = |n: usize| tree(kind, &format!("{id}_{n}"), level + 1, depth);
        let next = match kind {
            RelationshipType::ManyToOne | RelationshipType::OneToOne => Value::from(child(0)),
            _ => docs(vec![child(0), child(1)]),
        };
        node.put("next", next);
    }
    node
}

async fn nodes(db: &Database) {
    collection(db, "node", vec![string("name", 64)], crud(), true).await;
    collection(db, "tag", vec![string("name", 64)], crud(), true).await;
    relate(db, "node", Relationship::one_to_many("tag").two_way(true).key("tags").two_way_key("node").on_delete(OnDelete::Cascade))
        .await;
    relate(
        db,
        "tag",
        Relationship::many_to_many("node").two_way(true).key("nodes").two_way_key("labels").on_delete(OnDelete::Cascade),
    )
    .await;
}

fn named(id: &str, name: &str) -> Document {
    doc(&[("$id", s(id)), ("name", s(name))])
}

fn with_next(mut document: Document, next: Value) -> Document {
    document.put("next", next);
    document
}

/// `writes()`: sets a scenario up; returns its document and how it is written.
async fn setup(db: &Database, scenario: &str) -> (Document, Write) {
    use RelationshipType::{ManyToMany, ManyToOne, OneToMany, OneToOne};
    let level0 = Write::Create("level0");
    let tree_of = |kind, depth| tree(kind, "root", 0, depth);
    match scenario {
        "one to many" => {
            chain(db, OneToMany, 2, true).await;
            (tree_of(OneToMany, 2), level0)
        }
        "one to many past the maximum depth" => {
            chain(db, OneToMany, 4, true).await;
            (tree_of(OneToMany, 4), level0)
        }
        "one to many one way" => {
            chain(db, OneToMany, 2, false).await;
            (tree_of(OneToMany, 2), level0)
        }
        "many to many" | "shared tables" => {
            chain(db, ManyToMany, 2, true).await;
            (tree_of(ManyToMany, 2), level0)
        }
        "many to many past the maximum depth" => {
            chain(db, ManyToMany, 4, true).await;
            (tree_of(ManyToMany, 4), level0)
        }
        "many to many one way" => {
            chain(db, ManyToMany, 2, false).await;
            (tree_of(ManyToMany, 2), level0)
        }
        "many to one" => {
            chain(db, ManyToOne, 3, true).await;
            (tree_of(ManyToOne, 3), level0)
        }
        "one to one" => {
            chain(db, OneToOne, 3, true).await;
            (tree_of(OneToOne, 3), level0)
        }
        "one to one one way" => {
            chain(db, OneToOne, 3, false).await;
            (tree_of(OneToOne, 3), level0)
        }
        "generated ids and own permissions" => {
            chain(db, OneToMany, 2, true).await;
            let any = Role::any();
            let root = doc(&[
                ("$id", s("root")),
                ("name", s("root")),
                ("$permissions", perms(&[Permission::read(&any), Permission::update(&any)])),
                (
                    "next",
                    docs(vec![
                        doc(&[("name", s("first")), ("next", docs(vec![doc(&[("name", s("deep"))])]))]),
                        doc(&[
                            ("name", s("second")),
                            ("$permissions", perms(&[Permission::read(&Role::user("someone", ""))])),
                        ]),
                    ]),
                ),
            ]);
            (root, level0)
        }
        "documents mixed with ids" => {
            chain(db, ManyToMany, 2, true).await;
            create(db, "level1", named("existing", "existing")).await;
            create(db, "level2", named("leaf", "leaf")).await;
            let a = with_next(named("a", "a"), value::list([s("leaf"), d(&[("$id", s("a1")), ("name", s("a1"))])]));
            let root = with_next(named("root", "root"), value::list([Value::from(a), s("existing"), d(&[("$id", s("b")), ("name", s("b"))])]));
            (root, level0)
        }
        "one to many documents mixed with ids" => {
            chain(db, OneToMany, 2, true).await;
            create(db, "level1", named("existing", "existing")).await;
            let a = with_next(named("a", "a"), docs(vec![named("a1", "a1")]));
            let root = with_next(named("root", "root"), value::list([Value::from(a), s("existing"), Value::from(named("b", "b"))]));
            (root, level0)
        }
        "existing related documents" => {
            chain(db, OneToMany, 2, true).await;
            let read = perms(&[Permission::read(&Role::any())]);
            create(db, "level1", doc(&[("$id", s("same")), ("name", s("same")), ("score", Value::Int(1)), ("$permissions", read.clone())]))
                .await;
            create(db, "level1", doc(&[("$id", s("changed")), ("name", s("before")), ("score", Value::Int(1))])).await;
            let root = with_next(
                named("root", "root"),
                docs(vec![
                    with_next(named("new", "new"), docs(vec![named("leaf", "leaf")])),
                    doc(&[("$id", s("same")), ("name", s("same")), ("score", Value::Int(1)), ("$permissions", read)]),
                    with_next(named("changed", "after"), docs(vec![named("under", "under")])),
                ]),
            );
            (root, level0)
        }
        "existing many to many related document" => {
            chain(db, ManyToMany, 2, true).await;
            create(db, "level1", named("shared", "shared")).await;
            (with_next(named("root", "root"), docs(vec![named("new", "new"), named("shared", "renamed")])), level0)
        }
        "associative related document holding a stored one" => {
            chain(db, ManyToOne, 2, true).await;
            create(db, "level2", named("existing", "before")).await;
            let mid: Array = [
                (value::ArrayKey::from("name"), s("mid")),
                (value::ArrayKey::from("next"), Value::from(named("existing", "after"))),
            ]
            .into_iter()
            .collect();
            (with_next(named("root", "root"), Value::Array(mid)), level0)
        }
        "repeated related document" => {
            chain(db, ManyToMany, 2, true).await;
            (with_next(named("root", "root"), docs(vec![named("twice", "twice"), named("twice", "twice")])), level0)
        }
        "one to one by id" => {
            chain(db, OneToOne, 2, true).await;
            create(db, "level2", named("leaf", "leaf")).await;
            (with_next(named("root", "root"), Value::from(with_next(named("a", "a"), s("leaf")))), level0)
        }
        "existence checks skipped" => {
            chain(db, OneToOne, 2, true).await;
            (tree_of(OneToOne, 2), Write::CreateWithoutExistChecks("level0"))
        }
        "new document then its id" => {
            chain(db, ManyToMany, 2, true).await;
            let a = with_next(named("a", "a"), value::list([Value::from(named("a1", "a1")), s("a1")]));
            (with_next(named("root", "root"), value::list([Value::from(a), s("a")])), level0)
        }
        "one collection at several depths" => {
            nodes(db).await;
            let t1 = doc(&[("$id", s("t1")), ("name", s("t1")), ("nodes", docs(vec![named("n1", "n1"), named("n2", "n2")]))]);
            let t2 = doc(&[("$id", s("t2")), ("name", s("t2")), ("nodes", docs(vec![named("n3", "n3")]))]);
            (doc(&[("$id", s("n0")), ("name", s("n0")), ("tags", docs(vec![t1, t2]))]), Write::Create("node"))
        }
        "id already taken" => {
            chain(db, OneToOne, 2, true).await;
            create(db, "level0", named("root", "taken")).await;
            (tree_of(OneToOne, 2), level0)
        }
        "related document with the id of the document it belongs to" => {
            nodes(db).await;
            let t1 = doc(&[("$id", s("t1")), ("nodes", docs(vec![doc(&[("$id", s("n1"))]), doc(&[("$id", s("n0"))])]))]);
            (doc(&[("$id", s("n0")), ("tags", docs(vec![t1]))]), Write::Create("node"))
        }
        "inside a transaction" => {
            chain(db, ManyToMany, 2, true).await;
            (tree_of(ManyToMany, 2), Write::CreateInTransaction("level0"))
        }
        "created through an update" => {
            chain(db, OneToMany, 2, true).await;
            create(db, "level0", named("root", "root")).await;
            let a = with_next(named("a", "a"), docs(vec![named("a1", "a1"), named("a2", "a2")]));
            (doc(&[("next", docs(vec![a]))]), Write::Update("level0", "root"))
        }
        "one to many update" => {
            chain(db, OneToMany, 2, true).await;
            create(db, "level0", with_next(named("root", "root"), docs(vec![named("kept", "kept"), named("dropped", "dropped")]))).await;
            create(db, "level1", named("loose", "loose")).await;
            let a = with_next(named("a", "a"), docs(vec![named("a1", "a1"), named("a2", "a2")]));
            let change = doc(&[
                ("name", s("renamed")),
                ("next", value::list([s("kept"), Value::from(a), s("loose"), Value::from(named("b", "b"))])),
            ]);
            (change, Write::Update("level0", "root"))
        }
        "many to many update" => {
            chain(db, ManyToMany, 2, true).await;
            create(db, "level0", with_next(named("root", "root"), docs(vec![named("kept", "kept"), named("dropped", "dropped")]))).await;
            create(db, "level1", named("loose", "loose")).await;
            let a = with_next(named("a", "a"), docs(vec![named("a1", "a1")]));
            let change = doc(&[("next", value::list([s("kept"), Value::from(a), s("loose"), Value::from(named("b", "b"))]))]);
            (change, Write::Update("level0", "root"))
        }
        "many to one update" => {
            chain(db, ManyToOne, 2, true).await;
            create(db, "level1", named("shared", "shared")).await;
            create(db, "level0", with_next(named("old", "old"), s("shared"))).await;
            let change = doc(&[("prev", value::list([s("old"), Value::from(named("p1", "p1")), Value::from(named("p2", "p2"))]))]);
            (change, Write::Update("level1", "shared"))
        }
        "update with an existing related document" => {
            chain(db, OneToMany, 2, true).await;
            create(db, "level0", named("root", "root")).await;
            create(db, "level1", named("loose", "before")).await;
            (doc(&[("next", docs(vec![named("a", "a"), named("loose", "after")]))]), Write::Update("level0", "root"))
        }
        "update with an invalid related document" => {
            chain(db, ManyToMany, 2, true).await;
            create(db, "level0", named("root", "root")).await;
            let a = with_next(named("a", "a"), docs(vec![named("a1", "a1")]));
            let b = doc(&[("$id", s("b")), ("score", s("many"))]);
            (doc(&[("next", docs(vec![a, b]))]), Write::Update("level0", "root"))
        }
        "shared tables with an existing related document" => {
            chain(db, OneToMany, 2, true).await;
            create(db, "level2", named("existing", "before")).await;
            let a = doc(&[("$id", s("a")), ("next", docs(vec![doc(&[("$id", s("a1"))]), named("existing", "after")]))]);
            (doc(&[("$id", s("root")), ("next", docs(vec![a]))]), level0)
        }
        "tenant per document" => {
            chain(db, OneToMany, 2, true).await;
            let tenant = |id: &str, next: Vec<Document>| {
                let mut d = doc(&[("$id", s(id)), ("name", s(id)), ("$tenant", Value::Int(7))]);
                if !next.is_empty() {
                    d.put("next", docs(next));
                }
                d
            };
            (tenant("root", vec![tenant("a", vec![tenant("a1", Vec::new())]), tenant("b", Vec::new())]), level0)
        }
        "missing tenant on a related document" => {
            chain(db, OneToMany, 2, true).await;
            let a = doc(&[("$id", s("a")), ("$tenant", Value::Int(7)), ("next", docs(vec![doc(&[("$id", s("a1"))])]))]);
            (doc(&[("$id", s("root")), ("$tenant", Value::Int(7)), ("next", docs(vec![a]))]), level0)
        }
        "invalid related document" => {
            chain(db, OneToMany, 2, true).await;
            let a = with_next(named("a", "a"), docs(vec![named("a1", "a1"), doc(&[("$id", s("a2")), ("score", s("many"))])]));
            let b = doc(&[("$id", s("b")), ("name", s("b")), ("score", s("many"))]);
            (doc(&[("$id", s("root")), ("next", docs(vec![a, b]))]), level0)
        }
        "invalid relationship value" => {
            chain(db, OneToMany, 2, true).await;
            let a = doc(&[("$id", s("a")), ("next", docs(vec![doc(&[("$id", s("a1"))])]))]);
            let b = doc(&[("$id", s("b")), ("next", value::list([Value::Int(7)]))]);
            (doc(&[("$id", s("root")), ("next", docs(vec![a, b]))]), level0)
        }
        "related collection the caller may not create in" => {
            chain(db, OneToMany, 2, true).await;
            let update = crate::collection::Update {
                permissions: Some(vec![Permission::read(&Role::any())]),
                document_security: Some(true),
            };
            db.update_collection("level2", update).await.unwrap();
            (tree_of(OneToMany, 2), level0)
        }
        "many to many link the caller may not update" => {
            chain(db, ManyToMany, 2, true).await;
            let update = crate::collection::Update {
                permissions: Some(vec![Permission::create(&Role::any()), Permission::read(&Role::any())]),
                document_security: Some(true),
            };
            db.update_collection("level2", update).await.unwrap();
            create(
                db,
                "level2",
                doc(&[("$id", s("locked")), ("name", s("locked")), ("$permissions", perms(&[Permission::read(&Role::any())]))]),
            )
            .await;
            let a = doc(&[("$id", s("a")), ("next", docs(vec![doc(&[("$id", s("a1"))]), doc(&[("$id", s("locked"))])]))]);
            (doc(&[("$id", s("root")), ("next", docs(vec![a]))]), level0)
        }
        "unreadable existing related document" => {
            chain(db, OneToMany, 2, true).await;
            create(db, "level2", doc(&[("$id", s("hidden")), ("name", s("hidden")), ("$permissions", value::list([]))])).await;
            let a = doc(&[("$id", s("a")), ("next", docs(vec![doc(&[("$id", s("a1"))]), named("hidden", "mine")]))]);
            (doc(&[("$id", s("root")), ("next", docs(vec![a]))]), level0)
        }
        "unique attribute shared by related documents" => {
            chain(db, OneToMany, 2, true).await;
            db.create_index("level2", Index::unique("name", ["name"], Vec::new(), Vec::new()).unwrap()).await.unwrap();
            let a = doc(&[("$id", s("a")), ("next", docs(vec![named("a1", "same")]))]);
            let b = doc(&[("$id", s("b")), ("score", s("many")), ("next", docs(vec![named("b1", "same")]))]);
            (doc(&[("$id", s("root")), ("next", docs(vec![a, b]))]), level0)
        }
        other => panic!("unknown scenario {other}"),
    }
}

/// `SHARED` and `TENANT_PER_DOCUMENT`.
fn tenancy(scenario: &str) -> (Option<i64>, bool) {
    match scenario {
        "shared tables" | "shared tables with an existing related document" => (Some(7), false),
        "tenant per document" | "missing tenant on a related document" => (None, true),
        _ => (None, false),
    }
}

/// Every attribute but the timestamps, at every level (`export()`).
fn without_timestamps(values: &Array) -> Array {
    values
        .iter()
        .filter(|(k, _)| !matches!(k, value::KeyRef::Str(s) if *s == b"$createdAt" || *s == b"$updatedAt"))
        .map(|(k, v)| {
            let v = match v {
                Value::Array(a) => Value::Array(without_timestamps(a)),
                other => other.clone(),
            };
            (k.to_owned(), v)
        })
        .collect()
}

fn export(document: &Document) -> Value {
    Value::Array(without_timestamps(&document.array_copy()))
}

/// A generated id (`/^[0-9a-f]{20}$/`).
fn generated(text: &[u8]) -> bool {
    text.len() == 20 && text.iter().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(b))
}

/// `normalize()`: generated ids named in the order met.
fn normalize(value: &Value, ids: &mut Vec<String>) -> Value {
    match value {
        Value::Array(a) => Value::Array(a.iter().map(|(k, v)| (k.to_owned(), normalize(v, ids))).collect()),
        Value::Str(s) if generated(s.as_bytes()) => {
            let text = value::to_text(value);
            let index = match ids.iter().position(|i| *i == text) {
                Some(i) => i,
                None => {
                    ids.push(text);
                    ids.len() - 1
                }
            };
            Value::Str(format!("generated-{index}").into())
        }
        other => other.clone(),
    }
}

/// What a write of a scenario left: its outcome, what is stored, the
/// events, and the related documents read.
#[derive(Debug, PartialEq)]
struct Observation {
    outcome: Value,
    stored: Value,
    events: Vec<String>,
    reads: usize,
}

/// `stored()`: every collection's documents, as stored.
async fn stored_dump(db: &Database) -> Value {
    db.authorization()
        .skip(db.skip_relationships(async {
            let mut collections: Vec<String> =
                db.list_collections(100, 0).await.unwrap().iter().map(|c| c.id().to_owned()).collect();
            collections.sort();
            let mut stored = Array::new();
            for collection in collections {
                let rows = db
                    .find(&collection, vec![Query::limit(1000), Query::order_asc("$sequence", None)], PermissionType::Read)
                    .await
                    .unwrap();
                stored.set(value::ArrayKey::from(collection.as_str()), value::list(rows.iter().map(export)));
            }
            Value::Array(stored)
        }))
        .await
}

/// `observe()`.
async fn observe(mode: Mode, scenario: &str) -> Observation {
    let spy = Spy::memory();
    let (tenant, per_document) = tenancy(scenario);
    let db = database(&spy, mode, tenant, per_document, Vec::new()).await;
    let (document, write) = setup(&db, scenario).await;
    let recorder = Recorder::on(&db);
    spy.reset();

    let result = match write {
        Write::Create(collection) => Box::pin(db.create_document(collection, document)).await,
        Write::CreateWithoutExistChecks(collection) => {
            Box::pin(db.skip_relationships_exist_check(db.create_document(collection, document))).await
        }
        Write::CreateInTransaction(collection) => {
            Box::pin(db.with_transaction(|| Box::pin(db.create_document(collection, document.clone())))).await
        }
        Write::Update(collection, id) => Box::pin(db.update_document(collection, id, document)).await,
    };
    let reads = spy.document_reads.load(Ordering::SeqCst);
    let outcome = match result {
        Ok(returned) => export(&returned),
        Err(e) => value::list([s(&format!("{:?}", e.kind())), s(e.message())]),
    };
    let stored = stored_dump(&db).await;
    let events = recorder.events().iter().map(|e| format!("{e:?}")).collect();

    let mut ids = Vec::new();
    Observation { outcome: normalize(&outcome, &mut ids), stored: normalize(&stored, &mut ids), events, reads }
}

fn same(expected: Observation, actual: Observation, context: &str) {
    assert_eq!(expected.outcome, actual.outcome, "{context}: outcome");
    assert_eq!(expected.stored, actual.stored, "{context}: stored");
    assert_eq!(expected.events, actual.events, "{context}: events");
}

#[test]
fn a_deferred_create_matches_one_by_one() {
    for scenario in SCENARIOS {
        block_on(async {
            let one_by_one = observe(Mode::OneByOne, scenario).await;
            let deferred = observe(Mode::Deferred, scenario).await;
            same(one_by_one, deferred, scenario);
        });
    }
}

#[test]
fn an_immediate_create_matches_one_by_one() {
    for scenario in SCENARIOS {
        block_on(async {
            let one_by_one = observe(Mode::OneByOneWithoutSavepoints, scenario).await;
            let immediate = observe(Mode::Immediate, scenario).await;
            same(one_by_one, immediate, scenario);
        });
    }
}

#[test]
fn every_prepared_scenario_prepares_its_related_documents() {
    for scenario in PREPARED {
        block_on(async {
            for (prepared, one_by_one) in [(Mode::Deferred, Mode::OneByOne), (Mode::Immediate, Mode::OneByOneWithoutSavepoints)] {
                let unprepared = observe(one_by_one, scenario).await.reads;
                let prepared_reads = observe(prepared, scenario).await.reads;
                assert!(
                    prepared_reads < unprepared,
                    "{scenario}: the {prepared:?} write read {prepared_reads} related documents, one by one {unprepared}"
                );
            }
        });
    }
}

#[test]
fn a_prepared_create_reads_no_related_document_before_or_after_writing_it() {
    for mode in [Mode::OneByOne, Mode::Deferred, Mode::OneByOneWithoutSavepoints, Mode::Immediate] {
        block_on(async {
            let spy = Spy::memory();
            let db = database(&spy, mode, None, false, Vec::new()).await;
            chain(&db, RelationshipType::OneToMany, 2, true).await;
            spy.reset();
            Box::pin(db.create_document("level0", tree(RelationshipType::OneToMany, "root", 0, 2))).await.unwrap();
            let reads = spy.document_reads.load(Ordering::SeqCst);
            if mode.prepare() {
                assert_eq!(reads, 0, "a {mode:?} create read a related document");
            } else {
                assert!(reads > 0, "relating one by one reads each related document");
            }
        });
    }
}

#[test]
fn a_filter_is_applied_once_when_an_associative_value_holds_a_stored_document() {
    for mode in [Mode::OneByOne, Mode::Deferred] {
        block_on(async {
            let wrap = Callback::new(
                "wrap",
                |v: Value| Ok(if matches!(v, Value::Str(_)) { s(&format!("[{}]", value::to_text(&v))) } else { v }),
                |v: Value| {
                    Ok(match &v {
                        Value::Str(text) if text.as_bytes().starts_with(b"[") => {
                            let text = value::to_text(&v);
                            s(&text[1..text.len() - 1])
                        }
                        _ => v,
                    })
                },
            );
            let spy = Spy::memory();
            let db = database(&spy, mode, None, false, vec![wrap]).await;
            for id in ["root", "mid", "side"] {
                collection(&db, id, vec![string("name", 64)], crud(), true).await;
            }
            collection(&db, "leaf", vec![string("name", 64).filters(["wrap"])], crud(), true).await;
            relate(&db, "root", Relationship::many_to_one("mid").two_way(true).key("mid").two_way_key("roots").on_delete(OnDelete::Cascade))
                .await;
            relate(&db, "root", Relationship::many_to_one("side").two_way(true).key("side").two_way_key("roots").on_delete(OnDelete::Cascade))
                .await;
            relate(&db, "mid", Relationship::one_to_many("leaf").two_way(true).key("leaves").two_way_key("mid").on_delete(OnDelete::Cascade))
                .await;
            create(&db, "leaf", named("existing", "old")).await;

            let mid: Array = [
                (value::ArrayKey::from("name"), s("mid")),
                (value::ArrayKey::from("leaves"), docs(vec![named("k1", "k1"), named("existing", "new")])),
            ]
            .into_iter()
            .collect();
            let root = doc(&[("$id", s("root")), ("side", d(&[("name", s("side"))])), ("mid", Value::Array(mid))]);
            Box::pin(db.create_document("root", root)).await.unwrap();

            let mut names_read = Vec::new();
            for id in ["k1", "existing"] {
                let leaf = db.skip_relationships(db.get_document("leaf", id, Vec::new(), false)).await.unwrap();
                names_read.push(leaf.get("name").map(value::to_text).unwrap_or_default());
            }
            assert_eq!(names_read, names(&["k1", "new"]), "relating {mode:?} encoded a name more than once");
        });
    }
}
