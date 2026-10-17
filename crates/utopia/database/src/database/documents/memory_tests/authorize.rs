//! PHP: `Documents/AuthorizeWriteTest`.

use std::sync::Arc;

use super::s;
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::testing::block_on;
use crate::database::testing_memory::doc;
use crate::database::{Database, Hook};
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::hook::relationships::Relationships;
use crate::permission::Permission;
use crate::relationship::{Relationship, RelationshipDeleteAction};
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value, list};

const USER: &str = "u1";
const NO_CREATE: &str = "No permissions provided for action 'create'";
const NO_READ: &str = "No permissions provided for action 'read'";
const NO_UPDATE: &str = "No permissions provided for action 'update'";

fn users() -> Role {
    Role::users("")
}

/// Permissions per collection; `None` grants users create, read and update.
#[derive(Default)]
struct Seed {
    parents: Option<Vec<String>>,
    children: Option<Vec<String>>,
    toys: Option<Vec<String>>,
    document_security: bool,
}

async fn seed(seed: Seed) -> (Database, Arc<Authorization>) {
    let everything = vec![Permission::create(&users()), Permission::read(&users()), Permission::update(&users())];
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role(&Role::any().to_string());
    authorization.add_role(&users().to_string());
    authorization.add_role(&Role::user(USER, "").to_string());
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::new(Arc::new(Memory::new()), cache);
    db.set_authorization(authorization.clone());
    db.set_database("authorize_write").set_namespace(&format!("authorize_write_{}", crate::id::unique().unwrap()));
    db.add_hook(Hook::Relationships(Arc::new(Relationships::new(true)))).unwrap();
    let setup = async {
        db.create(None).await?;
        let collections = [("parents", seed.parents), ("children", seed.children), ("toys", seed.toys)];
        for (id, permissions) in collections {
            let collection = Collection::create(
                id,
                id,
                vec![Attribute::string("name", 64)],
                Vec::new(),
                Some(permissions.unwrap_or_else(|| everything.clone())),
                id == "parents" && seed.document_security,
                Array::new(),
            )?;
            db.create_collection(collection).await?;
        }
        let children = Relationship::one_to_many("children")
            .key("children")
            .two_way(true)
            .two_way_key("parent")
            .on_delete(RelationshipDeleteAction::SetNull);
        db.create_relationship("parents", children).await?;
        let toys = Relationship::many_to_many("toys")
            .key("toys")
            .two_way(true)
            .two_way_key("children")
            .on_delete(RelationshipDeleteAction::SetNull);
        db.create_relationship("children", toys).await?;
        db.create_document("children", doc(vec![("$id", s("stored")), ("name", s("Stored"))])).await?;
        db.create_document("toys", doc(vec![("$id", s("toy")), ("name", s("Toy"))])).await?;
        Result::<()>::Ok(())
    };
    Box::pin(authorization.skip(setup)).await.unwrap();
    (db, authorization)
}

async fn stored(
    db: &Database,
    authorization: &Authorization,
    collection: &str,
    id: &str,
    permissions: Vec<String>,
) -> Document {
    let permissions = list(permissions.iter().map(|p| s(p)));
    let document = doc(vec![("$id", s(id)), ("$permissions", permissions)]);
    Box::pin(authorization.skip(db.create_document(collection, document))).await.unwrap()
}

/// The authorization failure's message, `None` when the check passes.
#[track_caller]
fn refusal(result: Result<()>) -> Option<String> {
    match result {
        Ok(()) => None,
        Err(e) if e.kind() == Kind::Authorization => Some(e.message().to_owned()),
        Err(e) => panic!("not an authorization failure: {e:?}"),
    }
}

fn child(pairs: Vec<(&str, Value)>) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (crate::value::ArrayKey::from(k), v)).collect())
}

fn parent(children: Value) -> Document {
    doc(vec![("$id", s("parent")), ("children", children)])
}

#[test]
fn a_create_needs_create_on_the_collection() {
    block_on(async {
        let (db, _) = seed(Seed { parents: Some(vec![Permission::read(&Role::any())]), ..Seed::default() }).await;
        let check = db.authorize_create("parents", &doc(vec![("$id", s("parent"))]), None).await;
        assert_eq!(refusal(check).as_deref(), Some(NO_CREATE));
    });
}

#[test]
fn a_new_nested_document_needs_create_on_its_collection() {
    block_on(async {
        let new = || parent(list([child(vec![("$id", s("new")), ("name", s("New"))])]));
        let (db, _) = seed(Seed { children: Some(vec![Permission::read(&users())]), ..Seed::default() }).await;
        assert_eq!(refusal(db.authorize_create("parents", &new(), None).await).as_deref(), Some(NO_CREATE));
        let (db, _) = seed(Seed { children: Some(vec![Permission::create(&users())]), ..Seed::default() }).await;
        assert_eq!(refusal(db.authorize_create("parents", &new(), None).await), None);
    });
}

#[test]
fn a_link_two_levels_down_needs_update_on_the_linked_document() {
    block_on(async {
        let nested = || parent(list([child(vec![("$id", s("new")), ("name", s("New")), ("toys", list([s("toy")]))])]));
        let (db, _) = seed(Seed { toys: Some(vec![Permission::read(&users())]), ..Seed::default() }).await;
        assert_eq!(refusal(db.authorize_create("parents", &nested(), None).await).as_deref(), Some(NO_UPDATE));
        let (db, _) = seed(Seed { toys: Some(vec![Permission::create(&users())]), ..Seed::default() }).await;
        let check = db.authorize_create("parents", &nested(), None).await;
        assert_eq!(refusal(check).as_deref(), Some(NO_READ), "a toy the caller cannot read is refused");
        let toys = vec![Permission::read(&users()), Permission::update(&users())];
        let (db, _) = seed(Seed { toys: Some(toys), ..Seed::default() }).await;
        assert_eq!(refusal(db.authorize_create("parents", &nested(), None).await), None);
    });
}

#[test]
fn a_stored_nested_document_the_caller_cannot_read_is_refused_rather_than_created() {
    block_on(async {
        let (db, _) = seed(Seed { children: Some(vec![Permission::create(&users())]), ..Seed::default() }).await;
        let stored = parent(list([child(vec![("$id", s("stored")), ("name", s("Stored"))])]));
        assert_eq!(refusal(db.authorize_create("parents", &stored, None).await).as_deref(), Some(NO_UPDATE));
    });
}

#[test]
fn an_update_needs_update_on_the_document() {
    block_on(async {
        let (db, authorization) = seed(Seed {
            parents: Some(vec![Permission::read(&Role::any())]),
            document_security: true,
            ..Seed::default()
        })
        .await;
        let own = stored(&db, &authorization, "parents", "own", vec![Permission::update(&Role::user(USER, ""))]).await;
        let other = stored(&db, &authorization, "parents", "other", vec![Permission::read(&Role::any())]).await;
        let changed = doc(vec![("name", s("Changed"))]);
        assert_eq!(refusal(db.authorize_update("parents", &own, &changed, None).await), None);
        assert_eq!(refusal(db.authorize_update("parents", &other, &changed, None).await).as_deref(), Some(NO_UPDATE));
    });
}

#[test]
fn an_update_checks_only_the_documents_it_links_anew() {
    block_on(async {
        let (db, authorization) =
            seed(Seed { children: Some(vec![Permission::read(&users())]), ..Seed::default() }).await;
        stored(&db, &authorization, "children", "second", Vec::new()).await;
        let create = async {
            db.create_document("parents", parent(list([s("stored")]))).await?;
            db.get_document("parents", "parent", Vec::new(), false).await
        };
        let old = Box::pin(authorization.skip(create)).await.unwrap();
        let same = doc(vec![("children", list([s("stored")]))]);
        let check = db.authorize_update("parents", &old, &same, None).await;
        assert_eq!(refusal(check), None, "a document the parent already links is not linked again");
        let more = doc(vec![("children", list([s("stored"), s("second")]))]);
        assert_eq!(refusal(db.authorize_update("parents", &old, &more, None).await).as_deref(), Some(NO_UPDATE));
    });
}

#[test]
fn an_api_key_passes_every_check() {
    block_on(async {
        let (db, authorization) = seed(Seed {
            parents: Some(Vec::new()),
            children: Some(Vec::new()),
            toys: Some(Vec::new()),
            ..Seed::default()
        })
        .await;
        let nested = parent(list([s("stored"), child(vec![("$id", s("new")), ("toys", list([s("toy")]))])]));
        let check = Box::pin(authorization.skip(db.authorize_create("parents", &nested, None))).await;
        assert_eq!(refusal(check), None);
    });
}

#[test]
fn with_relationships_skipped_only_the_document_is_checked() {
    block_on(async {
        let (db, _) = seed(Seed { children: Some(Vec::new()), ..Seed::default() }).await;
        let linked = parent(list([s("stored")]));
        assert_eq!(refusal(db.authorize_create("parents", &linked, None).await).as_deref(), Some(NO_READ));
        let check = Box::pin(db.skip_relationships(db.authorize_create("parents", &linked, None))).await;
        assert_eq!(refusal(check), None);
    });
}

#[test]
fn an_unknown_collection_is_not_found() {
    block_on(async {
        let (db, _) = seed(Seed::default()).await;
        let err = db.authorize_create("missing", &Document::empty(), None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::NotFound);
    });
}

/// PHP: `Documents/DocumentMinorsTest::testAnUnchangedListOfRelatedIdsIsNotAChange`
/// (PHP's database subclass, which hands the stored list back as ids, has no
/// counterpart here: the stored list is compared as read).
#[test]
fn an_unchanged_list_of_related_ids_is_not_a_change() {
    block_on(async {
        let db = crate::database::testing_memory::memory("minors").await;
        db.add_hook(Hook::Relationships(Arc::new(Relationships::new(true)))).unwrap();
        let any = Role::any();
        let read_only = vec![Permission::create(&any), Permission::read(&any)];
        let mut writable = read_only.clone();
        writable.push(Permission::update(&any));
        crate::database::testing_memory::collection(&db, "parents", Vec::new(), read_only, true).await;
        crate::database::testing_memory::collection(&db, "children", Vec::new(), writable, true).await;
        let children = Relationship::one_to_many("children").key("children").two_way(true).two_way_key("parent");
        db.create_relationship("parents", children).await.unwrap();
        db.create_document("children", doc(vec![("$id", s("c1"))])).await.unwrap();
        db.create_document("children", doc(vec![("$id", s("c2"))])).await.unwrap();
        let parent = doc(vec![("$id", s("p1")), ("children", list([s("c1"), s("c2")]))]);
        db.create_document("parents", parent).await.unwrap();
        let same = doc(vec![("children", list([s("c1"), s("c2")]))]);
        let updated = db.update_document("parents", "p1", same).await.unwrap();
        assert_eq!(updated.id(), "p1", "an unchanged list needs no update permission");
    });
}

#[test]
fn the_checks_write_nothing() {
    block_on(async {
        let (db, authorization) = seed(Seed::default()).await;
        let nested =
            parent(list([s("stored"), child(vec![("$id", s("new")), ("name", s("New")), ("toys", list([s("toy")]))])]));
        assert_eq!(refusal(db.authorize_create("parents", &nested, None).await), None);
        let read = async {
            let get = |collection: &'static str, id: &'static str| db.get_document(collection, id, Vec::new(), false);
            assert!(get("parents", "parent").await?.is_empty());
            assert!(get("children", "new").await?.is_empty());
            assert_eq!(get("children", "stored").await?.get("parent"), None);
            assert_eq!(get("toys", "toy").await?.get("children"), Some(&list([])));
            Result::<()>::Ok(())
        };
        Box::pin(authorization.skip(read)).await.unwrap();
    });
}
