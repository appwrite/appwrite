//! PHP: `tests/unit/Relationships/RelationshipHookTest.php` (the Memory
//! cases).

use std::sync::{Arc, Mutex};

use async_trait::async_trait;

use super::*;
use crate::event::Domain;
use crate::hook::Lifecycle;
use crate::operator::Operator;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipType, Update};

const ADMIN: &str = "user:admin";

fn one_to_many(on_delete: OnDelete) -> Relationship {
    Relationship::one_to_many("child").two_way(true).key("children").two_way_key("parent").on_delete(on_delete)
}

fn many_to_one(on_delete: OnDelete) -> Relationship {
    Relationship::many_to_one("parent").two_way(true).key("parent").two_way_key("children").on_delete(on_delete)
}

fn many_to_many(on_delete: OnDelete) -> Relationship {
    Relationship::many_to_many("child").two_way(true).key("children").two_way_key("parents").on_delete(on_delete)
}

fn admin() -> Role {
    Role::user("admin", "")
}

async fn database() -> Database {
    memory(Arc::new(Relationships::default())).await
}

/// `relate()`: `parent` (with a `name`) and `child`, related.
async fn related(
    database: &Database,
    relationship: Relationship,
    child_permissions: Vec<String>,
    child_security: bool,
) {
    collection(database, "parent", vec![string("name", 64)], crud(), false).await;
    let child_permissions = if child_permissions.is_empty() { crud() } else { child_permissions };
    collection(database, "child", Vec::new(), child_permissions, child_security).await;
    let from = if relationship.related_collection == "parent" { "child" } else { "parent" };
    relate(database, from, relationship).await;
}

async fn assert_delete_rejected(database: &Database, collection: &str, id: &str) {
    let error = failure(database.delete_document(collection, id).await, Kind::Authorization);
    assert_eq!(
        error.message(),
        "Missing \"delete\" permission for role \"user:admin\". Only \"[\"any\"]\" scopes are allowed and \"[\"user:admin\"]\" was given."
    );
}

#[track_caller]
fn assert_link_rejected<T: std::fmt::Debug>(result: Result<T>) {
    let error = failure(result, Kind::Authorization);
    assert!(error.message().contains("\"update\""), "{}", error.message());
}

fn with_permissions(id: &str, permissions: Vec<String>) -> Document {
    doc(&[("$id", s(id)), ("$permissions", perms(&permissions))])
}

#[test]
fn one_to_many_cascade_deletes_more_children_than_the_query_value_limit() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::Cascade), Vec::new(), true).await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        for id in ["child1", "child2", "child3"] {
            create(&db, "child", doc(&[("$id", s(id)), ("parent", s("parent1"))])).await;
        }
        db.set_max_query_values(2);

        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(get(&db, "parent", "parent1").await.is_empty());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn many_to_one_cascade_deletes_more_children_than_the_query_value_limit() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_one(OnDelete::Cascade), Vec::new(), true).await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        for id in ["child1", "child2", "child3"] {
            create(&db, "child", doc(&[("$id", s(id)), ("parent", s("parent1"))])).await;
        }
        db.set_max_query_values(2);

        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(get(&db, "parent", "parent1").await.is_empty());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn many_to_many_cascade_deletes_more_related_documents_than_the_query_value_limit() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_many(OnDelete::Cascade), Vec::new(), true).await;
        for id in ["child1", "child2", "child3"] {
            create(&db, "child", doc(&[("$id", s(id))])).await;
        }
        create(&db, "parent", doc(&[("$id", s("parent1")), ("children", strs(&["child1", "child2", "child3"]))])).await;
        db.set_max_query_values(2);

        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(get(&db, "parent", "parent1").await.is_empty());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn many_to_many_set_null_deletes_more_junction_rows_than_the_query_value_limit() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_many(OnDelete::SetNull), Vec::new(), true).await;
        for id in ["child1", "child2", "child3"] {
            create(&db, "child", doc(&[("$id", s(id))])).await;
        }
        create(&db, "parent", doc(&[("$id", s("parent1")), ("children", strs(&["child1", "child2", "child3"]))])).await;
        create(&db, "parent", doc(&[("$id", s("parent2")), ("children", strs(&["child1"]))])).await;
        db.set_max_query_values(2);

        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(get(&db, "parent", "parent1").await.is_empty());
        assert_eq!(ids(&db, "child").await, names(&["child1", "child2", "child3"]));
        assert_eq!(related_ids(&get(&db, "child", "child1").await, "parents"), names(&["parent2"]));
        assert!(related_ids(&get(&db, "child", "child2").await, "parents").is_empty());
        assert!(related_ids(&get(&db, "child", "child3").await, "parents").is_empty());
    });
}

#[test]
fn one_to_many_cascade_rolls_back_when_a_child_cannot_be_deleted() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::Cascade), create_read(), true).await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        let mut deletable = with_permissions("deletable", vec![Permission::delete(&Role::any())]);
        deletable.put("parent", s("parent1"));
        create(&db, "child", deletable).await;
        let mut protected = with_permissions("protected", vec![Permission::delete(&admin())]);
        protected.put("parent", s("parent1"));
        create(&db, "child", protected).await;

        assert_delete_rejected(&db, "parent", "parent1").await;

        assert!(!get(&db, "parent", "parent1").await.is_empty());
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["deletable", "protected"]));

        db.authorization().add_role(ADMIN);
        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn many_to_one_cascade_rolls_back_when_a_child_cannot_be_deleted() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_one(OnDelete::Cascade), create_read(), true).await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        let mut deletable = with_permissions("deletable", vec![Permission::delete(&Role::any())]);
        deletable.put("parent", s("parent1"));
        create(&db, "child", deletable).await;
        let mut protected = with_permissions("protected", vec![Permission::delete(&admin())]);
        protected.put("parent", s("parent1"));
        create(&db, "child", protected).await;

        assert_delete_rejected(&db, "parent", "parent1").await;

        assert!(!get(&db, "parent", "parent1").await.is_empty());
        assert_eq!(ids(&db, "child").await, names(&["deletable", "protected"]));

        db.authorization().add_role(ADMIN);
        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn many_to_many_cascade_rolls_back_when_a_related_document_cannot_be_deleted() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_many(OnDelete::Cascade), create_read(), true).await;
        create(&db, "child", with_permissions("deletable", vec![Permission::delete(&Role::any())])).await;
        create(&db, "child", with_permissions("protected", vec![Permission::delete(&admin())])).await;
        db.authorization()
            .skip(db.create_document(
                "parent",
                doc(&[("$id", s("parent1")), ("children", strs(&["deletable", "protected"]))]),
            ))
            .await
            .unwrap();

        assert_delete_rejected(&db, "parent", "parent1").await;

        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["deletable", "protected"]));
        assert_eq!(ids(&db, "child").await, names(&["deletable", "protected"]));

        db.authorization().add_role(ADMIN);
        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn cascade_skips_a_related_document_that_is_already_gone() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_many(OnDelete::Cascade), create_read(), true).await;
        for id in ["child1", "child2", "child3"] {
            create(&db, "child", with_permissions(id, vec![Permission::delete(&Role::any())])).await;
        }
        db.authorization()
            .skip(db.create_document(
                "parent",
                doc(&[("$id", s("parent1")), ("children", strs(&["child1", "child2", "child3"]))]),
            ))
            .await
            .unwrap();

        db.skip_relationships(db.delete_document("child", "child2")).await.unwrap();

        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn cascade_retried_after_a_failed_cascade_still_deletes_the_children() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::Cascade), Vec::new(), true).await;
        collection(&db, "grandchild", Vec::new(), crud(), false).await;
        relate(
            &db,
            "child",
            Relationship::one_to_many("grandchild")
                .two_way(true)
                .key("grandchildren")
                .two_way_key("child")
                .on_delete(OnDelete::Restrict),
        )
        .await;

        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        create(&db, "child", doc(&[("$id", s("child1")), ("parent", s("parent1"))])).await;
        create(&db, "grandchild", doc(&[("$id", s("grandchild1")), ("child", s("child1"))])).await;

        failure(db.delete_document("parent", "parent1").await, Kind::Restricted);

        db.delete_document("grandchild", "grandchild1").await.unwrap();

        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn linking_a_child_without_update_permission_is_rejected() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::SetNull), create_read(), true).await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        create(&db, "child", with_permissions("updatable", vec![Permission::update(&Role::any())])).await;
        create(&db, "child", with_permissions("readonly", vec![Permission::update(&admin())])).await;

        let error = failure(
            db.update_document("parent", "parent1", doc(&[("children", strs(&["updatable", "readonly"]))])).await,
            Kind::Authorization,
        );
        assert_eq!(
            error.message(),
            "Missing \"update\" permission for role \"user:admin\". Only \"[\"any\"]\" scopes are allowed and \"[\"user:admin\"]\" was given."
        );
        assert!(related_ids(&get(&db, "parent", "parent1").await, "children").is_empty());

        db.authorization().add_role(ADMIN);
        update(&db, "parent", "parent1", doc(&[("children", strs(&["updatable", "readonly"]))])).await;
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["readonly", "updatable"]));
    });
}

#[test]
fn nested_update_cannot_link_a_child_without_update_permission() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::SetNull), create_read(), true).await;
        collection(&db, "grandparent", Vec::new(), crud(), false).await;
        relate(&db, "grandparent", Relationship::one_to_one("parent").key("parent").on_delete(OnDelete::SetNull)).await;

        create(&db, "child", with_permissions("readonly", vec![Permission::update(&admin())])).await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        create(&db, "grandparent", doc(&[("$id", s("grandparent1")), ("parent", s("parent1"))])).await;

        let error = failure(
            db.update_document(
                "grandparent",
                "grandparent1",
                doc(&[("parent", d(&[("$id", s("parent1")), ("children", strs(&["readonly"]))]))]),
            )
            .await,
            Kind::Authorization,
        );
        assert_eq!(
            error.message(),
            "Missing \"update\" permission for role \"user:admin\". Only \"[\"any\"]\" scopes are allowed and \"[\"user:admin\"]\" was given."
        );
        assert!(related_ids(&get(&db, "parent", "parent1").await, "children").is_empty());
    });
}

/// `nestedLinkDatabase()`.
async fn nested_link_database(relationship: Relationship) -> Database {
    let db = database().await;
    related(&db, relationship, create_read(), true).await;
    collection(&db, "grandparent", Vec::new(), crud(), false).await;
    relate(&db, "grandparent", Relationship::one_to_one("parent").key("parent").on_delete(OnDelete::SetNull)).await;

    create(&db, "child", with_permissions("readonly", vec![Permission::update(&admin())])).await;
    create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
    create(&db, "grandparent", doc(&[("$id", s("grandparent1")), ("parent", s("parent1"))])).await;
    db
}

#[test]
fn linking_a_child_given_as_a_document_through_a_nested_update_needs_update_permission() {
    block_on(async {
        let db = nested_link_database(one_to_many(OnDelete::SetNull)).await;
        assert_link_rejected(
            db.update_document(
                "grandparent",
                "grandparent1",
                doc(&[(
                    "parent",
                    d(&[("$id", s("parent1")), ("children", docs(vec![doc(&[("$id", s("readonly"))])]))]),
                )]),
            )
            .await,
        );
        assert!(related_ids(&get(&db, "parent", "parent1").await, "children").is_empty());
    });
}

#[test]
fn linking_existing_children_through_a_nested_create_needs_update_permission() {
    block_on(async {
        let db = nested_link_database(one_to_many(OnDelete::SetNull)).await;
        assert_link_rejected(
            db.create_document(
                "grandparent",
                doc(&[
                    ("$id", s("grandparent2")),
                    ("parent", d(&[("$id", s("parent2")), ("children", strs(&["readonly"]))])),
                ]),
            )
            .await,
        );
        assert_eq!(get(&db, "child", "readonly").await.get("parent"), None);
    });
}

#[test]
fn linking_a_partner_through_a_nested_two_way_one_to_one_update_needs_update_permission() {
    block_on(async {
        let db = nested_link_database(
            Relationship::one_to_one("child")
                .two_way(true)
                .key("partner")
                .two_way_key("parent")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        assert_link_rejected(
            db.update_document(
                "grandparent",
                "grandparent1",
                doc(&[("parent", d(&[("$id", s("parent1")), ("partner", s("readonly"))]))]),
            )
            .await,
        );
        assert_eq!(get(&db, "child", "readonly").await.get("parent"), None);
    });
}

#[test]
fn relinking_an_unchanged_child_needs_only_read_permission() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::SetNull), create_read(), false).await;
        create(&db, "parent", doc(&[("$id", s("parent1")), ("name", s("before"))])).await;
        create(&db, "child", doc(&[("$id", s("child1")), ("parent", s("parent1"))])).await;

        let parent =
            update(&db, "parent", "parent1", doc(&[("name", s("after")), ("children", strs(&["child1"]))])).await;
        assert_eq!(parent.get("name"), Some(&s("after")));
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["child1"]));
    });
}

#[test]
fn relinking_keeps_an_unchanged_child_the_caller_may_not_update() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::SetNull), create_read(), true).await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
        let mut linked = with_permissions("linked", vec![Permission::update(&admin())]);
        linked.put("parent", s("parent1"));
        create(&db, "child", linked).await;
        create(&db, "child", with_permissions("added", vec![Permission::update(&Role::any())])).await;

        update(&db, "parent", "parent1", doc(&[("children", strs(&["linked", "added"]))])).await;
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["added", "linked"]));
    });
}

#[test]
fn relationship_maintenance_keeps_the_tenant_of_every_related_document() {
    block_on(async {
        let db = database().await;
        db.set_shared_tables(true).set_tenant_per_document(true).set_tenant(Value::Null);
        related(&db, one_to_many(OnDelete::SetNull), Vec::new(), true).await;
        db.set_tenant(Value::Int(1));

        create(&db, "parent", doc(&[("$id", s("parent1")), ("$tenant", Value::Int(1))])).await;
        create(&db, "child", doc(&[("$id", s("child1")), ("$tenant", Value::Int(1)), ("parent", s("parent1"))])).await;
        create(&db, "child", doc(&[("$id", s("child2")), ("$tenant", Value::Int(1))])).await;
        create(&db, "child", doc(&[("$id", s("foreign")), ("$tenant", Value::Int(2)), ("parent", s("parent1"))])).await;

        update(&db, "parent", "parent1", doc(&[("children", strs(&["child2"]))])).await;
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["child2"]));

        assert!(db.delete_document("parent", "parent1").await.unwrap());

        for id in ["child1", "child2"] {
            let child = get(&db, "child", id).await;
            assert_eq!(child.tenant(), Some(Value::Int(1)), "{id} must keep its tenant");
            assert_eq!(child.get("parent"), None, "{id} must no longer reference the deleted parent");
        }

        let foreign = db
            .with_tenant(Value::Int(2), db.skip_relationships(db.get_document("child", "foreign", Vec::new(), false)))
            .await
            .unwrap();
        assert_eq!(foreign.tenant(), Some(Value::Int(2)));
        assert_eq!(foreign.get("parent"), Some(&s("parent1")));
    });
}

/// `nestedCascadeDatabase()`.
async fn nested_cascade_database(grandchild_permissions: Vec<String>, on_delete: OnDelete) -> Database {
    let db = database().await;
    related(&db, one_to_many(OnDelete::Cascade), Vec::new(), true).await;
    collection(&db, "grandchild", Vec::new(), grandchild_permissions, false).await;
    relate(
        &db,
        "child",
        Relationship::one_to_many("grandchild")
            .two_way(true)
            .key("grandchildren")
            .two_way_key("child")
            .on_delete(on_delete),
    )
    .await;
    db.authorization()
        .skip(async {
            create(&db, "parent", doc(&[("$id", s("parent1"))])).await;
            create(&db, "child", doc(&[("$id", s("child1")), ("parent", s("parent1"))])).await;
            create(&db, "grandchild", doc(&[("$id", s("grandchild1")), ("child", s("child1"))])).await;
        })
        .await;
    db
}

#[test]
fn cascade_deletes_a_grandchild_the_caller_cannot_read() {
    block_on(async {
        let db = nested_cascade_database(
            vec![Permission::create(&Role::any()), Permission::delete(&Role::any())],
            OnDelete::Cascade,
        )
        .await;
        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(ids(&db, "child").await.is_empty());
        assert!(ids(&db, "grandchild").await.is_empty());
    });
}

#[test]
fn cascade_is_restricted_by_a_grandchild_the_caller_cannot_read() {
    block_on(async {
        let db = nested_cascade_database(
            vec![Permission::create(&Role::any()), Permission::delete(&Role::any())],
            OnDelete::Restrict,
        )
        .await;
        failure(db.delete_document("parent", "parent1").await, Kind::Restricted);
        assert_eq!(ids(&db, "parent").await, names(&["parent1"]));
        assert_eq!(ids(&db, "child").await, names(&["child1"]));
        assert_eq!(ids(&db, "grandchild").await, names(&["grandchild1"]));
    });
}

#[test]
fn cascade_rolls_back_when_a_grandchild_the_caller_cannot_read_is_protected() {
    block_on(async {
        let db = nested_cascade_database(
            vec![Permission::create(&Role::any()), Permission::delete(&admin())],
            OnDelete::Cascade,
        )
        .await;
        assert_delete_rejected(&db, "parent", "parent1").await;
        assert_eq!(ids(&db, "parent").await, names(&["parent1"]));
        assert_eq!(ids(&db, "child").await, names(&["child1"]));
        assert_eq!(ids(&db, "grandchild").await, names(&["grandchild1"]));
    });
}

/// Records the options of every updated attribute.
#[derive(Default)]
struct Updates(Mutex<Vec<(String, Value)>>);

#[async_trait]
impl Lifecycle for Updates {
    async fn handle(&self, event: &Domain) -> Result<()> {
        if let Domain::AttributeUpdated { collection, attribute } = event {
            let options = attribute.to_document()?.get("options").cloned().unwrap_or(Value::Null);
            self.0.lock().unwrap().push((format!("{collection}.{}", attribute.key), options));
        }
        Ok(())
    }
}

#[test]
fn update_relationship_fires_string_options() {
    block_on(async {
        let db = database().await;
        related(&db, one_to_many(OnDelete::Cascade), Vec::new(), true).await;
        let updates = Arc::new(Updates::default());
        db.add_hook(Hook::lifecycle(updates.clone())).unwrap();

        let updated = db
            .update_relationship(
                "parent",
                "children",
                Update { key: Some("kids".into()), on_delete: Some(OnDelete::SetNull), ..Update::default() },
            )
            .await
            .unwrap();
        assert_eq!(updated.key.as_deref(), Some("kids"));
        assert_eq!(updated.on_delete, OnDelete::SetNull);

        let options = |pairs: &[(&str, Value)]| -> Value {
            Value::Array(pairs.iter().map(|(k, v)| (value::ArrayKey::from(*k), v.clone())).collect())
        };
        let seen = updates.0.lock().unwrap().clone();
        assert_eq!(
            seen,
            vec![
                (
                    "parent.kids".to_owned(),
                    options(&[
                        ("relatedCollection", s("child")),
                        ("relationType", s("oneToMany")),
                        ("twoWay", Value::Bool(true)),
                        ("twoWayKey", s("parent")),
                        ("onDelete", s("setNull")),
                        ("side", s("parent")),
                    ])
                ),
                (
                    "child.parent".to_owned(),
                    options(&[
                        ("relatedCollection", s("parent")),
                        ("relationType", s("oneToMany")),
                        ("twoWay", Value::Bool(true)),
                        ("twoWayKey", s("kids")),
                        ("onDelete", s("setNull")),
                        ("side", s("child")),
                    ])
                ),
            ]
        );
    });
}

/// `writeStackDatabase()`.
async fn write_stack_database(hook: Arc<Relationships>) -> Database {
    let db = memory(hook).await;
    collection(&db, "owner", Vec::new(), crud(), false).await;
    collection(&db, "solo", vec![string("name", 64)], create_read(), false).await;
    collection(&db, "item", Vec::new(), crud(), false).await;
    collection(&db, "detail", Vec::new(), crud(), false).await;
    relate(
        &db,
        "owner",
        Relationship::one_to_one("solo").two_way(true).key("solo").two_way_key("owner").on_delete(OnDelete::SetNull),
    )
    .await;
    relate(
        &db,
        "owner",
        Relationship::one_to_many("item").two_way(true).key("items").two_way_key("owner").on_delete(OnDelete::SetNull),
    )
    .await;
    relate(
        &db,
        "item",
        Relationship::one_to_many("detail")
            .two_way(true)
            .key("details")
            .two_way_key("item")
            .on_delete(OnDelete::SetNull),
    )
    .await;
    db.authorization()
        .skip(async {
            create(&db, "owner", doc(&[("$id", s("owner1"))])).await;
            create(&db, "solo", doc(&[("$id", s("solo1")), ("name", s("before"))])).await;
        })
        .await;
    db
}

async fn fail_nested_one_to_one_write(db: &Database, attempt: i32) {
    let error = failure(
        db.update_document(
            "owner",
            "owner1",
            doc(&[("solo", d(&[("$id", s("solo1")), ("name", s(&format!("attempt {attempt}")))]))]),
        )
        .await,
        Kind::Authorization,
    );
    assert_eq!(error.message(), "No permissions provided for action 'update'");
}

#[test]
fn a_failed_nested_one_to_one_write_leaves_no_write_stack_entry() {
    block_on(async {
        let hook = Arc::new(Relationships::default());
        let db = write_stack_database(hook.clone()).await;
        for attempt in [1, 2] {
            fail_nested_one_to_one_write(&db, attempt).await;
            assert_eq!(hook.write_stack_count(), 0, "attempt {attempt} left an entry on the write stack");
        }
    });
}

#[test]
fn a_nested_create_after_a_failed_nested_write_stores_its_related_documents() {
    block_on(async {
        let db = write_stack_database(Arc::new(Relationships::default())).await;
        fail_nested_one_to_one_write(&db, 1).await;
        fail_nested_one_to_one_write(&db, 2).await;

        create(
            &db,
            "owner",
            doc(&[
                ("$id", s("owner2")),
                (
                    "items",
                    docs(vec![doc(&[("$id", s("item1")), ("details", docs(vec![doc(&[("$id", s("detail1"))])]))])]),
                ),
            ]),
        )
        .await;

        assert_eq!(ids(&db, "item").await, names(&["item1"]));
        assert_eq!(ids(&db, "detail").await, names(&["detail1"]));
        assert_eq!(related_ids(&get(&db, "owner", "owner2").await, "items"), names(&["item1"]));
    });
}

/// `deletePairs()`: each relationship type with how a parent and a child
/// are linked.
/// A relationship type, its relationship by delete action, and how a pair is linked.
type Pair = (&'static str, fn(OnDelete) -> Relationship, &'static str);

fn delete_pairs() -> Vec<Pair> {
    vec![
        ("one-to-one", |o| Relationship::one_to_one("child").key("child").two_way_key("parent").on_delete(o), "holds"),
        ("one-to-many", one_to_many, "child"),
        ("many-to-one", many_to_one, "child"),
        ("many-to-many", many_to_many, "lists"),
    ]
}

async fn link(db: &Database, how: &str, parent: &str, child: &str) {
    match how {
        "holds" => {
            create(db, "child", doc(&[("$id", s(child))])).await;
            create(db, "parent", doc(&[("$id", s(parent)), ("child", s(child))])).await;
        }
        "lists" => {
            create(db, "child", doc(&[("$id", s(child))])).await;
            create(db, "parent", doc(&[("$id", s(parent)), ("children", strs(&[child]))])).await;
        }
        _ => {
            create(db, "parent", doc(&[("$id", s(parent))])).await;
            create(db, "child", doc(&[("$id", s(child)), ("parent", s(parent))])).await;
        }
    }
}

#[test]
fn delete_documents_with_a_select_cascades_to_children() {
    block_on(async {
        for (kind, relationship, how) in delete_pairs() {
            let db = database().await;
            related(&db, relationship(OnDelete::Cascade), Vec::new(), true).await;
            link(&db, how, "parent1", "child1").await;
            link(&db, how, "parent2", "child2").await;

            let deleted =
                delete_all(&db, "parent", vec![Query::equal("$id", ["parent2"]), Query::select(["$id", "name"])])
                    .await
                    .unwrap();
            assert_eq!(deleted, 1, "{kind}");
            assert_eq!(ids(&db, "parent").await, names(&["parent1"]), "{kind}");
            assert_eq!(ids(&db, "child").await, names(&["child1"]), "{kind}: the deleted parent's child goes with it");
        }
    });
}

#[test]
fn delete_documents_with_a_select_honours_restrict() {
    block_on(async {
        for (kind, relationship, how) in delete_pairs() {
            let db = database().await;
            related(&db, relationship(OnDelete::Restrict), Vec::new(), true).await;
            link(&db, how, "parent1", "child1").await;
            link(&db, how, "parent2", "child2").await;

            let result =
                delete_all(&db, "parent", vec![Query::equal("$id", ["parent2"]), Query::select(["$id", "name"])]).await;
            assert!(result.as_ref().is_err_and(|e| e.is_a(Kind::Restricted)), "{kind}: {result:?}");
            assert_eq!(ids(&db, "parent").await, names(&["parent1", "parent2"]), "{kind}");
            assert_eq!(ids(&db, "child").await, names(&["child1", "child2"]), "{kind}");
        }
    });
}

#[test]
fn delete_documents_with_a_select_cascades_from_the_child_side_of_a_two_way_one_to_one() {
    block_on(async {
        let db = database().await;
        related(
            &db,
            Relationship::one_to_one("child")
                .two_way(true)
                .key("child")
                .two_way_key("parent")
                .on_delete(OnDelete::Cascade),
            Vec::new(),
            true,
        )
        .await;
        for suffix in ["1", "2"] {
            create(&db, "child", doc(&[("$id", s(&format!("child{suffix}")))])).await;
            create(
                &db,
                "parent",
                doc(&[("$id", s(&format!("parent{suffix}"))), ("child", s(&format!("child{suffix}")))]),
            )
            .await;
        }

        let deleted =
            delete_all(&db, "child", vec![Query::equal("$id", ["child2"]), Query::select(["$id"])]).await.unwrap();
        assert_eq!(deleted, 1);
        assert_eq!(ids(&db, "child").await, names(&["child1"]));
        assert_eq!(ids(&db, "parent").await, names(&["parent1"]));
    });
}

#[test]
fn linking_a_many_to_many_document_by_id_needs_update_permission() {
    block_on(async {
        let db = nested_link_database(many_to_many(OnDelete::SetNull)).await;
        assert_link_rejected(db.update_document("parent", "parent1", doc(&[("children", strs(&["readonly"]))])).await);
        assert!(related_ids(&get(&db, "parent", "parent1").await, "children").is_empty());
        assert!(related_ids(&get(&db, "child", "readonly").await, "parents").is_empty());

        db.authorization().add_role(ADMIN);
        update(&db, "parent", "parent1", doc(&[("children", strs(&["readonly"]))])).await;
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["readonly"]));
    });
}

#[test]
fn linking_a_many_to_many_document_given_as_a_document_needs_update_permission() {
    block_on(async {
        let db = nested_link_database(many_to_many(OnDelete::SetNull)).await;
        assert_link_rejected(
            db.update_document("parent", "parent1", doc(&[("children", docs(vec![doc(&[("$id", s("readonly"))])]))]))
                .await,
        );
        assert!(related_ids(&get(&db, "parent", "parent1").await, "children").is_empty());
        assert!(related_ids(&get(&db, "child", "readonly").await, "parents").is_empty());
    });
}

#[test]
fn linking_a_many_to_many_document_through_a_nested_update_needs_update_permission() {
    block_on(async {
        let db = nested_link_database(many_to_many(OnDelete::SetNull)).await;
        assert_link_rejected(
            db.update_document(
                "grandparent",
                "grandparent1",
                doc(&[("parent", d(&[("$id", s("parent1")), ("children", strs(&["readonly"]))]))]),
            )
            .await,
        );
        assert!(related_ids(&get(&db, "child", "readonly").await, "parents").is_empty());
    });
}

#[test]
fn linking_an_existing_many_to_many_document_through_a_create_needs_update_permission() {
    block_on(async {
        let db = nested_link_database(many_to_many(OnDelete::SetNull)).await;
        assert_link_rejected(
            db.create_document("parent", doc(&[("$id", s("parent2")), ("children", strs(&["readonly"]))])).await,
        );
        assert_link_rejected(
            db.create_document(
                "parent",
                doc(&[("$id", s("parent3")), ("children", docs(vec![doc(&[("$id", s("readonly"))])]))]),
            )
            .await,
        );
        assert_link_rejected(
            db.create_document(
                "grandparent",
                doc(&[
                    ("$id", s("grandparent2")),
                    ("parent", d(&[("$id", s("parent4")), ("children", strs(&["readonly"]))])),
                ]),
            )
            .await,
        );
        assert_eq!(ids(&db, "parent").await, names(&["parent1"]));
        assert!(related_ids(&get(&db, "child", "readonly").await, "parents").is_empty());
    });
}

#[test]
fn creating_a_many_to_many_document_through_a_link_needs_no_update_permission() {
    block_on(async {
        let db = nested_link_database(many_to_many(OnDelete::SetNull)).await;
        update(&db, "parent", "parent1", doc(&[("children", docs(vec![doc(&[("$id", s("created"))])]))])).await;
        create(&db, "parent", doc(&[("$id", s("parent2")), ("children", docs(vec![doc(&[("$id", s("nested"))])]))]))
            .await;
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["created"]));
        assert_eq!(related_ids(&get(&db, "parent", "parent2").await, "children"), names(&["nested"]));
    });
}

#[test]
fn keeping_or_unlinking_a_many_to_many_document_needs_no_update_permission() {
    block_on(async {
        let db = nested_link_database(many_to_many(OnDelete::SetNull)).await;
        db.authorization()
            .skip(db.update_document("parent", "parent1", doc(&[("children", strs(&["readonly"]))])))
            .await
            .unwrap();

        update(&db, "parent", "parent1", doc(&[("name", s("kept")), ("children", strs(&["readonly"]))])).await;
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["readonly"]));

        update(&db, "parent", "parent1", doc(&[("children", strs(&[]))])).await;
        assert!(related_ids(&get(&db, "parent", "parent1").await, "children").is_empty());
        assert!(related_ids(&get(&db, "child", "readonly").await, "parents").is_empty());
    });
}

#[test]
fn a_new_nested_many_to_many_document_keeps_its_own_permissions() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_many(OnDelete::SetNull), Vec::new(), true).await;
        create(&db, "parent", with_permissions("parent1", vec![Permission::read(&Role::any())])).await;

        let own = vec![Permission::read(&Role::any()), Permission::update(&Role::user("owner", ""))];
        update(&db, "parent", "parent1", doc(&[("children", docs(vec![with_permissions("child1", own.clone())]))]))
            .await;

        let child = db.authorization().skip(db.get_document("child", "child1", Vec::new(), false)).await.unwrap();
        assert_eq!(child.permissions().unwrap(), own);
        assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), names(&["child1"]));
    });
}

#[test]
fn a_new_nested_many_to_many_document_without_permissions_takes_the_parent_permissions() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_many(OnDelete::SetNull), Vec::new(), true).await;
        let parent_permissions = vec![Permission::read(&Role::any()), Permission::update(&Role::user("owner", ""))];
        create(&db, "parent", with_permissions("parent1", parent_permissions.clone())).await;

        update(&db, "parent", "parent1", doc(&[("children", docs(vec![doc(&[("$id", s("child1"))])]))])).await;

        let child = db.authorization().skip(db.get_document("child", "child1", Vec::new(), false)).await.unwrap();
        assert_eq!(child.permissions().unwrap(), parent_permissions);
    });
}

/// The most values one query of `queries` carries, nested queries looked
/// into (`largestValueCount()`).
fn largest_value_count(queries: &[utopia_query::Query]) -> usize {
    queries
        .iter()
        .map(|query| {
            if query.method().is_nested() {
                let nested: Vec<utopia_query::Query> = query
                    .values()
                    .values()
                    .filter_map(|v| match v {
                        utopia_query::Value::Ext(utopia_query::Object::Query(q)) => Some((**q).clone()),
                        _ => None,
                    })
                    .collect();
                largest_value_count(&nested)
            } else {
                query.values().len()
            }
        })
        .max()
        .unwrap_or(0)
}

#[test]
fn nested_path_filters_stay_within_the_query_value_limit() {
    block_on(async {
        let spy = crate::database::spy::Spy::memory();
        let counts: Arc<Mutex<Vec<(String, usize)>>> = Arc::default();
        let seen = counts.clone();
        spy.watch(|w| {
            w.find = Some(Arc::new(move |(collection, queries): (Collection, Vec<Query>)| {
                let inner: Vec<utopia_query::Query> = queries.iter().map(|q| (**q).clone()).collect();
                let largest = largest_value_count(&inner);
                let mut counts = seen.lock().unwrap();
                match counts.iter_mut().find(|(c, _)| c == collection.id()) {
                    Some((_, most)) => *most = (*most).max(largest),
                    None => counts.push((collection.id().to_owned(), largest)),
                }
            }));
        });
        let db = database_on(spy, "relationship_hook").await;
        db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
        db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
        collection(&db, "parent", Vec::new(), crud(), false).await;
        collection(&db, "child", Vec::new(), crud(), false).await;
        collection(&db, "tag", Vec::new(), crud(), false).await;
        collection(&db, "label", vec![string("name", 64)], crud(), false).await;
        collection(&db, "owner", vec![string("name", 64)], crud(), false).await;
        relate(&db, "parent", one_to_many(OnDelete::SetNull)).await;
        relate(
            &db,
            "child",
            Relationship::many_to_many("tag")
                .two_way(true)
                .key("tags")
                .two_way_key("children")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        relate(
            &db,
            "tag",
            Relationship::one_to_many("label")
                .two_way(true)
                .key("labels")
                .two_way_key("tag")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        relate(
            &db,
            "child",
            Relationship::many_to_one("owner")
                .two_way(true)
                .key("owner")
                .two_way_key("children")
                .on_delete(OnDelete::SetNull),
        )
        .await;
        relate(
            &db,
            "parent",
            Relationship::many_to_many("tag")
                .two_way(true)
                .key("topics")
                .two_way_key("parents")
                .on_delete(OnDelete::SetNull),
        )
        .await;

        for number in 1..=6 {
            let name = if number == 6 { "other" } else { "match" };
            let n = |prefix: &str| format!("{prefix}{number}");
            create(&db, "owner", doc(&[("$id", s(&n("owner"))), ("name", s(name))])).await;
            create(&db, "tag", doc(&[("$id", s(&n("tag")))])).await;
            create(&db, "label", doc(&[("$id", s(&n("label"))), ("name", s(name)), ("tag", s(&n("tag")))])).await;
            create(
                &db,
                "child",
                doc(&[("$id", s(&n("child"))), ("tags", strs(&[&n("tag")])), ("owner", s(&n("owner")))]),
            )
            .await;
            create(
                &db,
                "parent",
                doc(&[("$id", s(&n("parent"))), ("children", strs(&[&n("child")])), ("topics", strs(&[&n("tag")]))]),
            )
            .await;
        }

        db.set_max_query_values(2);

        let matching = names(&["parent1", "parent2", "parent3", "parent4", "parent5"]);
        type Filter<'a> = (&'a str, Vec<&'a str>, Vec<String>, Vec<&'a str>);
        let filters: Vec<Filter> = vec![
            ("children.tags.labels.name", vec!["match"], matching.clone(), vec!["child", "junction", "label"]),
            ("children.owner.name", vec!["match"], matching.clone(), vec!["child", "owner"]),
            ("topics.labels.name", vec!["match"], matching, vec!["junction", "label", "tag"]),
            ("children.$id", vec!["child1", "child2"], names(&["parent1", "parent2"]), vec!["child"]),
        ];
        for (path, values, expected, collections) in filters {
            counts.lock().unwrap().clear();
            let found = db
                .find("parent", vec![Query::equal(path, values), Query::select(["$id"])], PermissionType::Read)
                .await
                .unwrap();
            assert_eq!(sorted_ids(&found), expected, "filtering by {path}");

            let counts = counts.lock().unwrap().clone();
            let mut read: Vec<String> = counts
                .iter()
                .filter(|(c, _)| c != "parent")
                .map(|(c, _)| if c.starts_with('_') { "junction".to_owned() } else { c.clone() })
                .collect();
            read.sort();
            read.dedup();
            assert_eq!(read, names(&collections), "filtering by {path} reads only the collections on the path");
            for (collection, largest) in counts.iter().filter(|(c, _)| c != "parent") {
                assert!(*largest <= 2, "a read of {collection} while filtering by {path} carried {largest} values");
            }
        }
    });
}

#[test]
fn cascade_with_only_a_dangling_junction_row_deletes_the_parent() {
    block_on(async {
        let db = database().await;
        related(&db, many_to_many(OnDelete::Cascade), create_read(), false).await;
        let sequence = |c: &Collection| c.sequence().map(value::to_text).unwrap_or_default();
        let junction = format!(
            "_{}_{}",
            sequence(&db.get_collection("parent").await.unwrap()),
            sequence(&db.get_collection("child").await.unwrap())
        );

        db.authorization()
            .skip(async {
                create(&db, "child", doc(&[("$id", s("child1"))])).await;
                create(&db, "parent", doc(&[("$id", s("parent1")), ("children", strs(&["child1"]))])).await;
                db.skip_relationships(db.delete_document("child", "child1")).await.unwrap();
            })
            .await;

        assert!(ids(&db, "child").await.is_empty());
        assert_eq!(ids(&db, &junction).await.len(), 1, "the junction row must outlive the child it points at");

        assert!(db.delete_document("parent", "parent1").await.unwrap());
        assert!(ids(&db, "parent").await.is_empty());
        assert!(ids(&db, &junction).await.is_empty());
    });
}

#[test]
fn creating_with_a_list_on_the_child_side_of_a_one_way_one_to_one_is_rejected() {
    block_on(async {
        let db = database().await;
        related(
            &db,
            Relationship::one_to_one("child").key("partner").two_way_key("parent").on_delete(OnDelete::SetNull),
            Vec::new(),
            true,
        )
        .await;
        create(&db, "parent", doc(&[("$id", s("parent1"))])).await;

        let error = failure(
            db.create_document("child", doc(&[("$id", s("child1")), ("parent", strs(&["parent1"]))])).await,
            Kind::Relationship,
        );
        assert_eq!(
            error.message(),
            "Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false."
        );
        assert!(ids(&db, "child").await.is_empty());
    });
}

#[test]
fn update_rejects_invalid_relationship_values() {
    let one_to_one =
        || Relationship::one_to_one("child").key("partner").two_way_key("parent").on_delete(OnDelete::SetNull);
    let two_way_one_to_one = || {
        Relationship::one_to_one("child")
            .two_way(true)
            .key("partner")
            .two_way_key("parent")
            .on_delete(OnDelete::SetNull)
    };
    let one_to_many = || one_to_many(OnDelete::SetNull);
    let many_to_one = || {
        Relationship::many_to_one("child")
            .two_way(true)
            .key("child")
            .two_way_key("parents")
            .on_delete(OnDelete::SetNull)
    };
    let many_to_many = || many_to_many(OnDelete::SetNull);

    let cases: Vec<(&str, Relationship, &str, bool, Document, &str, bool)> = vec![
        (
            "one-way one-to-one child side",
            one_to_one(),
            "child",
            false,
            doc(&[("parent", s("parent1"))]),
            "Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.",
            false,
        ),
        (
            "two-way one-to-one integer",
            two_way_one_to_one(),
            "parent",
            false,
            doc(&[("partner", Value::Int(123))]),
            "Invalid relationship value. Must be either a document, document ID or null.",
            false,
        ),
        (
            "two-way one-to-one list",
            two_way_one_to_one(),
            "parent",
            false,
            doc(&[("partner", strs(&["child1"]))]),
            "Invalid relationship value. Must be either a document, document ID or null.",
            false,
        ),
        (
            "one-to-many list item",
            one_to_many(),
            "parent",
            false,
            doc(&[("children", value::list([Value::Int(123)]))]),
            "Invalid relationship value. Must be either a document or document ID.",
            false,
        ),
        (
            "many-to-many list item",
            many_to_many(),
            "parent",
            false,
            doc(&[("children", value::list([Value::Int(123)]))]),
            "Invalid relationship value. Must be either a document or document ID.",
            false,
        ),
        (
            "many-to-one document without id",
            many_to_one(),
            "parent",
            false,
            doc(&[("child", d(&[("name", s("n"))]))]),
            "Invalid relationship value. Document must have a valid $id.",
            false,
        ),
        (
            "many-to-one empty scalar",
            many_to_one(),
            "parent",
            true,
            doc(&[("child", Value::Bool(false))]),
            "Invalid relationship value. Must be either a document ID or a document.",
            false,
        ),
        (
            "many-to-one scalar",
            many_to_one(),
            "parent",
            false,
            doc(&[("child", Value::Int(123))]),
            "Invalid relationship value.",
            false,
        ),
        (
            "many-to-many bulk string",
            many_to_many(),
            "parent",
            false,
            doc(&[("children", s("child1"))]),
            "Invalid relationship value. Must be an array of documents or document IDs.",
            true,
        ),
    ];

    for (name, relationship, collection, linked, change, message, bulk) in cases {
        block_on(async {
            let db = database().await;
            let key = relationship.key.clone().unwrap();
            related(&db, relationship, Vec::new(), true).await;
            create(&db, "child", doc(&[("$id", s("child1"))])).await;
            let mut parent = doc(&[("$id", s("parent1"))]);
            if linked {
                parent.put(key.as_str(), s("child1"));
            }
            create(&db, "parent", parent).await;

            let id = if collection == "parent" { "parent1" } else { "child1" };
            let before = stored(&db, collection, id).await;

            let result = if bulk {
                update_all(&db, collection, change, Vec::new()).await.map(|_| ())
            } else {
                db.update_document(collection, id, change).await.map(|_| ())
            };
            let error = failure(result, Kind::Relationship);
            assert_eq!(error.message(), message, "{name}");
            assert_eq!(stored(&db, collection, id).await.array_copy(), before.array_copy(), "{name}");
        });
    }
}

#[test]
fn selecting_nested_attributes_through_the_child_side_of_a_many_to_one() {
    block_on(async {
        let db = database().await;
        collection(&db, "store", vec![string("name", 64)], crud(), false).await;
        collection(&db, "product", vec![string("name", 64), string("sku", 64)], crud(), false).await;
        relate(
            &db,
            "product",
            Relationship::many_to_one("store")
                .two_way(true)
                .key("store")
                .two_way_key("products")
                .on_delete(OnDelete::SetNull),
        )
        .await;

        create(&db, "store", doc(&[("$id", s("store1")), ("name", s("Store 1"))])).await;
        for id in ["product1", "product2"] {
            create(
                &db,
                "product",
                doc(&[
                    ("$id", s(id)),
                    ("name", s(&format!("Name {id}"))),
                    ("sku", s(&format!("sku-{id}"))),
                    ("store", s("store1")),
                ]),
            )
            .await;
        }

        let stores = [
            (
                "getDocument",
                db.get_document("store", "store1", vec![Query::select(["*", "products.name"])], false).await.unwrap(),
            ),
            ("findOne", db.find_one("store", vec![Query::select(["*", "products.name"])]).await.unwrap()),
        ];
        for (read, store) in stores {
            assert_eq!(store.get("name"), Some(&s("Store 1")), "{read}");
            assert_eq!(related_ids(&store, "products"), names(&["product1", "product2"]), "{read}");
            for product in store.documents("products").unwrap() {
                assert_eq!(product.get("name"), Some(&s(&format!("Name {}", product.id()))), "{read}");
                assert!(!product.has("sku"), "{read} must return only the selected attribute");
                assert!(!product.has("store"), "{read} must not return the back-reference");
            }
        }

        let store = db.get_document("store", "store1", vec![Query::select(["*", "products."])], false).await.unwrap();
        assert_eq!(related_ids(&store, "products"), names(&["product1", "product2"]));
        for product in store.documents("products").unwrap() {
            assert_eq!(product.get("sku"), Some(&s(&format!("sku-{}", product.id()))));
            assert_eq!(product.get("name"), Some(&s(&format!("Name {}", product.id()))));
        }
    });
}

#[test]
fn relationship_filter_conversion_edge_cases() {
    block_on(async {
        let db = database().await;
        collection(&db, "project", Vec::new(), crud(), false).await;
        collection(&db, "developer", vec![string("devName", 64)], crud(), false).await;
        relate(
            &db,
            "project",
            Relationship::many_to_many("developer")
                .two_way(true)
                .key("developers")
                .two_way_key("projects")
                .on_delete(OnDelete::SetNull),
        )
        .await;

        for (id, name) in [("dev1", "Alice"), ("dev2", "Bob"), ("dev3", "Carol")] {
            create(&db, "developer", doc(&[("$id", s(id)), ("devName", s(name))])).await;
        }
        create(&db, "project", doc(&[("$id", s("project1")), ("developers", strs(&["dev1", "dev2"]))])).await;
        create(&db, "project", doc(&[("$id", s("project2")), ("developers", strs(&["dev1", "dev3"]))])).await;

        let projects = |query: Query| {
            let db = &db;
            async move { sorted_ids(&db.find("project", vec![query], PermissionType::Read).await.unwrap()) }
        };
        assert_eq!(projects(Query::contains_all("developers.$id", ["dev2"])).await, names(&["project1"]));
        assert_eq!(projects(Query::contains_all("developers.$id", ["dev1", "dev3"])).await, names(&["project2"]));
        assert!(projects(Query::contains_all("developers.$id", ["dev1", "nobody"])).await.is_empty());
        assert!(projects(Query::contains_all("developers.$id", ["dev2", "dev3"])).await.is_empty());
        assert!(projects(Query::equal("developers.devName", ["Nobody"])).await.is_empty());

        let error = failure(
            db.find("project", vec![Query::equal("developers.unknownAttribute", ["x"])], PermissionType::Read).await,
            Kind::Query,
        );
        assert!(error.message().contains("unknownAttribute"), "{}", error.message());
    });
}

#[test]
fn set_operators_decide_which_documents_stay_linked() {
    for relationship in [one_to_many(OnDelete::SetNull), many_to_many(OnDelete::SetNull)] {
        block_on(async {
            let kind = relationship.kind;
            let db = database().await;
            related(&db, relationship, Vec::new(), true).await;
            for id in ["child1", "child2", "child3"] {
                create(&db, "child", doc(&[("$id", s(id))])).await;
            }
            create(&db, "parent", doc(&[("$id", s("parent1")), ("children", strs(&["child1", "child2", "child3"]))]))
                .await;

            let steps: Vec<(&str, Operator, Vec<String>)> = vec![
                ("arrayUnique", Operator::array_unique(), names(&["child1", "child2", "child3"])),
                (
                    "arrayFilter",
                    Operator::array_filter("isNotNull", Value::Null),
                    names(&["child1", "child2", "child3"]),
                ),
                ("arrayIntersect", Operator::array_intersect([s("child1"), s("child2")]), names(&["child1", "child2"])),
                ("arrayDiff", Operator::array_diff([s("child1")]), names(&["child2"])),
                ("arrayInsert", Operator::array_insert(0, s("child3")), names(&["child2", "child3"])),
            ];
            for (step, operator, expected) in steps {
                update(&db, "parent", "parent1", doc(&[("children", Value::from(operator))])).await;
                assert_eq!(related_ids(&get(&db, "parent", "parent1").await, "children"), expected, "after {step}");
                for id in ["child1", "child2", "child3"] {
                    let child = get(&db, "child", id).await;
                    let linked = if kind == RelationshipType::OneToMany {
                        related_id(&child, "parent") == "parent1"
                    } else {
                        related_ids(&child, "parents") == names(&["parent1"])
                    };
                    assert_eq!(expected.contains(&id.to_owned()), linked, "after {step}, {id} seen from its own side");
                }
            }
        });
    }
}
