//! Tests of the relationships hook on the stub adapter: everything that
//! does not need the reads and writes the hook makes through the database.
//! The PHP tests of the hook run on an engine (Memory, SQLite, pools) and
//! are deferred to the adapters' ports.

use std::sync::Arc;

use async_trait::async_trait;
use utopia_query::{Array as QArray, Value as QValue};

use super::populate::{Link, Tree};
use super::update::apply_relationship_operator;
use super::*;
use crate::database::testing::{Call, Reply, StubAdapter, block_on, uncached};
use crate::database::{Hook, METADATA, collection_definition};
use crate::operator::Operator;
use crate::permission::Permission;
use crate::relationship::Relationship;
use crate::role::Role;
use crate::value::{list, string};

fn relation(key: &str, relationship: Relationship, side: RelationshipSide) -> Attribute {
    Attribute::relationship(key, relationship, side).unwrap()
}

fn collection(id: &str, sequence: i64, attributes: Vec<Attribute>, permissions: Vec<String>) -> Collection {
    let mut collection =
        Collection::create(id, id, attributes, Vec::new(), Some(permissions), false, value::Array::new()).unwrap();
    collection.document_mut().put(document::SEQUENCE, Value::Int(sequence));
    collection
}

fn any_permissions() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any), Permission::update(&any), Permission::delete(&any)]
}

/// Answers the reads of the definitions of `collections`.
fn definitions(collections: Vec<Collection>) -> impl Fn(&Call) -> Option<Reply> + Send + Sync + 'static {
    move |call| match call {
        Call::GetDocument { collection: c, id, .. } if c.id() == METADATA => {
            if id == METADATA {
                return Some(Reply::Document(collection_definition().into()));
            }
            let found = collections.iter().find(|c| c.id() == id).map(|c| c.document().clone());
            Some(Reply::Document(found.unwrap_or_default()))
        }
        _ => None,
    }
}

/// A stub serving the definitions of `collections`.
fn stub(collections: Vec<Collection>) -> Arc<StubAdapter> {
    let adapter = StubAdapter::new();
    adapter.on(definitions(collections));
    adapter
}

fn attached(adapter: Arc<StubAdapter>) -> (Database, Arc<Relationships>) {
    let db = uncached(adapter);
    let hook = Arc::new(Relationships::default());
    db.add_hook(Hook::Relationships(hook.clone())).unwrap();
    (db, hook)
}

fn doc(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).unwrap()
}

fn select_values(query: &Query) -> Vec<String> {
    query.values().values().map(|v| String::from_utf8_lossy(&v.to_php_string().unwrap()).into_owned()).collect()
}

// ----- attach -----

#[test]
fn attaching_again_to_its_own_database_does_nothing() {
    let db = uncached(StubAdapter::new());
    let hook = Arc::new(Relationships::default());
    db.add_hook(Hook::Relationships(hook.clone())).unwrap();
    db.add_hook(Hook::Relationships(hook.clone())).unwrap();
    assert!(hook.database().unwrap().same(&db));
}

#[test]
fn refuses_to_move_to_another_database() {
    let first = uncached(StubAdapter::new());
    let second = uncached(StubAdapter::new());
    let hook = Arc::new(Relationships::default());
    first.add_hook(Hook::Relationships(hook.clone())).unwrap();

    let error = second.add_hook(Hook::Relationships(hook.clone())).unwrap_err();
    assert_eq!(error.kind(), Kind::Database);
    assert_eq!(
        error.message(),
        "This relationships hook is already attached to another database; attach a clone of it instead"
    );
    assert!(hook.database().unwrap().same(&first));
}

#[test]
fn a_fresh_copy_attaches_to_another_database() {
    let first = uncached(StubAdapter::new());
    let second = uncached(StubAdapter::new());
    let hook = Arc::new(Relationships::new(false));
    first.add_hook(Hook::Relationships(hook.clone())).unwrap();
    let copy = Arc::new(hook.fresh());
    assert!(copy.database().is_err());
    second.add_hook(Hook::Relationships(copy.clone())).unwrap();
    assert!(copy.database().unwrap().same(&second));
    assert!(!copy.prepare);
}

// ----- scoped state -----

#[test]
fn skipping_relationships_in_one_sibling_leaves_the_other_enabled() {
    block_on(async {
        let (db, hook) = attached(StubAdapter::new());
        let inside = db.skip_relationships(async {
            utopia_async::yield_now().await;
            hook.is_enabled()
        });
        let sibling = async {
            utopia_async::yield_now().await;
            hook.is_enabled()
        };
        let (inside, sibling) = futures_util::join!(inside, sibling);
        assert_eq!((inside, sibling, hook.is_enabled()), (false, true, true));

        let checking = db.skip_relationships_exist_check(async { hook.should_check_exist() }).await;
        assert!(!checking);
        assert!(hook.should_check_exist());
    });
}

#[test]
fn a_snapshot_carries_the_population_state() {
    block_on(async {
        let (db, hook) = attached(StubAdapter::new());
        let mut snapshot = db.snapshot();
        assert!(!snapshot.population);
        snapshot.population = true;
        snapshot.relationships = false;
        let seen = hook.with_snapshot(&snapshot, async { (hook.is_in_batch_population(), hook.is_enabled()) }).await;
        assert_eq!(seen, (true, false));
        assert!(!hook.is_in_batch_population());
        assert!(hook.is_enabled());
    });
}

#[test]
fn the_write_stack_is_scoped_to_the_write() {
    block_on(async {
        let hook = Relationships::default();
        let seen = hook
            .in_write("parents", async {
                let one = hook.write_stack_count();
                let two = hook.in_write("children", async { hook.write_stack_count() }).await;
                (one, two, hook.write_stack_count())
            })
            .await;
        assert_eq!(seen, (1, 2, 1));
        assert_eq!(hook.write_stack_count(), 0);
        assert_eq!(hook.fetch_depth(), 0);
    });
}

// ----- processQueries -----

fn library() -> Vec<Attribute> {
    vec![
        relation("author", Relationship::many_to_one("authors"), RelationshipSide::Parent),
        relation("reviews", Relationship::one_to_many("reviews"), RelationshipSide::Parent),
        relation("shelf", Relationship::one_to_many("shelves"), RelationshipSide::Child),
        relation("tags", Relationship::many_to_many("tags"), RelationshipSide::Parent),
        relation("cover", Relationship::one_to_one("covers"), RelationshipSide::Parent),
        relation("series", Relationship::many_to_one("series"), RelationshipSide::Child),
    ]
}

#[test]
fn process_queries_without_relationships_changes_nothing() {
    let hook = Relationships::default();
    let mut queries = vec![Query::select(["author.name"])];
    let nested = hook.process_queries(&[], &mut queries).unwrap();
    assert!(nested.is_empty());
    assert_eq!(queries, vec![Query::select(["author.name"])]);
}

#[test]
fn process_queries_collects_nested_selects_and_keeps_the_keys_populating_needs() {
    let hook = Relationships::default();
    let mut queries = vec![
        Query::select([
            "title",
            "author.name",
            "reviews.body",
            "shelf.label",
            "tags.name",
            "cover.url",
            "series.name",
            "author.country.name",
            "unknown.field",
        ]),
        Query::limit(5),
    ];
    let nested = hook.process_queries(&library(), &mut queries).unwrap();

    assert_eq!(
        select_values(&queries[0]),
        ["title", "author", "shelf", "cover", "author", "unknown.field"],
        "one-to-many parents, many-to-one children and many-to-many are read from the other side"
    );
    assert_eq!(queries[1], Query::limit(5));

    let keys: Vec<&str> = nested.iter().map(|(k, _)| k.as_str()).collect();
    assert_eq!(keys, ["author", "reviews", "shelf", "tags", "cover", "series"]);
    assert_eq!(nested[0].1, vec![Query::select(["name"]), Query::select(["country.name"])]);
    assert_eq!(nested[1].1, vec![Query::select(["body"])]);
}

#[test]
fn process_queries_selects_everything_for_an_empty_path_and_a_select_left_empty() {
    let hook = Relationships::default();
    let mut queries = vec![Query::select(["tags.", "reviews.0"])];
    let nested = hook.process_queries(&library(), &mut queries).unwrap();
    assert_eq!(select_values(&queries[0]), ["*"]);
    assert_eq!(nested[0], ("tags".to_owned(), vec![Query::select(["*"])]));
    assert_eq!(nested[1], ("reviews".to_owned(), vec![Query::select(["*"])]));
}

#[test]
fn process_queries_refuses_a_select_value_that_is_not_a_string() {
    let hook = Relationships::default();
    let mut queries = vec![Query::select_values(QArray::from_list(vec![QValue::Int(1)]))];
    let error = hook.process_queries(&library(), &mut queries).unwrap_err();
    assert_eq!(error.kind(), Kind::Query);
    assert_eq!(error.message(), "Select queries must contain only string attributes.");
}

// ----- convertQueries -----

#[test]
fn convert_queries_leaves_queries_without_relationship_paths() {
    block_on(async {
        let hook = Relationships::default();
        let queries = vec![Query::equal("title", ["Dune"]), Query::limit(3)];
        let none = hook.convert_queries(&[], queries.clone(), None).await.unwrap();
        assert_eq!(none, Some(queries.clone()));
        let flat = hook.convert_queries(&library(), queries.clone(), None).await.unwrap();
        assert_eq!(flat, Some(queries));
    });
}

#[test]
fn convert_queries_refuses_two_equal_queries_on_one_related_attribute() {
    block_on(async {
        let hook = Relationships::default();
        let queries = vec![Query::equal("author.name", ["Frank"]), Query::equal("author.name", ["Ursula"])];
        let error = hook.convert_queries(&library(), queries, None).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Query);
        assert_eq!(
            error.message(),
            "Multiple equal queries on 'author.name' will never match a single document. Use Query::containsAll() to match across different related documents."
        );
    });
}

// ----- applyRelationshipOperator -----

fn ids(ids: &[&str]) -> Vec<String> {
    ids.iter().map(|s| (*s).to_owned()).collect()
}

#[test]
fn applies_array_operators_to_related_ids() {
    let existing = ids(&["a", "b", "c"]);
    let related = doc(vec![("$id", string("d"))]);

    let append = Operator::array_append([Value::from(related.clone()), string("e"), string("")]);
    assert_eq!(apply_relationship_operator(&append, &existing), ids(&["a", "b", "c", "d", "e"]));

    let prepend = Operator::array_prepend([string("z")]);
    assert_eq!(apply_relationship_operator(&prepend, &existing), ids(&["z", "a", "b", "c"]));

    let insert = Operator::array_insert(1, Value::from(related));
    assert_eq!(apply_relationship_operator(&insert, &existing), ids(&["a", "d", "b", "c"]));
    let at_end = Operator::array_insert(10, string("x"));
    assert_eq!(apply_relationship_operator(&at_end, &existing), ids(&["a", "b", "c", "x"]));
    let from_end = Operator::array_insert(-1, string("x"));
    assert_eq!(apply_relationship_operator(&from_end, &existing), ids(&["a", "b", "x", "c"]));

    let remove = Operator::array_remove(string("b"));
    assert_eq!(apply_relationship_operator(&remove, &existing), ids(&["a", "c"]));
    let remove_many = Operator::array_remove(list([string("a"), string("c")]));
    assert_eq!(apply_relationship_operator(&remove_many, &existing), ids(&["b"]));

    let unique = Operator::array_unique();
    assert_eq!(apply_relationship_operator(&unique, &ids(&["a", "b", "a"])), ids(&["a", "b"]));

    let intersect = Operator::array_intersect([string("c"), string("a")]);
    assert_eq!(apply_relationship_operator(&intersect, &existing), ids(&["a", "c"]));

    let diff = Operator::array_diff([string("c")]);
    assert_eq!(apply_relationship_operator(&diff, &existing), ids(&["a", "b"]));
}

// ----- population tree -----

#[test]
fn the_tree_resolves_shared_and_nested_holdings() {
    let mut tree =
        Tree::new(vec![doc(vec![("$id", string("p1")), ("child", string("c1"))]), doc(vec![("$id", string("p2"))])]);
    let children = tree.add(vec![doc(vec![("$id", string("c1")), ("parent", string("p1"))])]);
    let grandchildren = tree.add(vec![doc(vec![("$id", string("g1"))])]);

    // Both parents hold the child; the second holds it in a list it takes
    // as a new attribute.
    tree.link(0, "child", Link::One(children.start));
    tree.link(1, "children", Link::Many(vec![children.start]));
    // The level below changes the child after it was set on its parents.
    tree.remove(children.start, "parent");
    tree.link(children.start, "grandchild", Link::One(grandchildren.start));
    tree.set(1, "missing", Value::from(Document::empty()));

    let resolved = tree.resolve(2);
    assert_eq!(resolved.len(), 2);
    let child = resolved[0].get("child").and_then(value::as_document).unwrap();
    assert_eq!(child.id(), "c1");
    assert!(!child.has("parent"));
    assert_eq!(child.get("grandchild").and_then(value::as_document).unwrap().id(), "g1");

    let keys: Vec<String> = resolved[1]
        .keys()
        .map(|k| match k {
            value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            value::KeyRef::Int(i) => i.to_string(),
        })
        .collect();
    assert_eq!(keys, ["$id", "children", "missing"]);
    let Some(Value::Array(held)) = resolved[1].get("children") else { panic!("a list") };
    assert_eq!(value::as_document(held.values().next().unwrap()).unwrap(), child);
}

#[test]
fn removing_an_attribute_drops_what_it_held() {
    let mut tree = Tree::new(vec![doc(vec![("$id", string("p1"))])]);
    let child = tree.add(vec![doc(vec![("$id", string("c1"))])]);
    tree.link(0, "child", Link::One(child.start));
    tree.remove(0, "child");
    let resolved = tree.resolve(1);
    assert!(!resolved[0].has("child"));
}

#[test]
fn population_at_the_deepest_level_removes_the_relationships() {
    block_on(async {
        let (_db, hook) = attached(StubAdapter::new());
        let books = collection("books", 1, library(), any_permissions());
        let documents = vec![doc(vec![("$id", string("b1")), ("title", string("Dune")), ("author", string("a1"))])];
        let populated = hook.populate_documents(documents, &books, 2, Vec::new()).await.unwrap();
        assert_eq!(populated[0].get("title"), Some(&string("Dune")));
        assert!(!populated[0].has("author"));

        let untouched =
            hook.populate_documents(vec![doc(vec![("$id", string("b1"))])], &books, 3, Vec::new()).await.unwrap();
        assert_eq!(untouched, vec![doc(vec![("$id", string("b1"))])]);
    });
}

// ----- PreparedCreate -----

#[test]
fn a_prepared_create_tracks_the_documents_it_prepares() {
    let mut prepared = PreparedCreate::new(true);
    assert!(prepared.deferred);
    prepared.start_preparing("books", "b1");
    prepared.start_preparing("books", "b1");
    assert!(prepared.is_preparing("books", "b1"));
    assert!(!prepared.is_preparing("authors", "b1"));
    prepared.stop_preparing("books", "b1");
    assert!(!prepared.is_preparing("books", "b1"));

    let books = collection("books", 1, Vec::new(), Vec::new());
    prepared.remember(books.clone());
    prepared.remember(collection("books", 2, Vec::new(), Vec::new()));
    assert_eq!(prepared.collection("books"), Some(&books));
}

#[test]
fn junction_collections_are_named_after_the_parent_then_the_child() {
    let books = collection("books", 3, Vec::new(), Vec::new());
    let tags = collection("tags", 7, Vec::new(), Vec::new());
    assert_eq!(junction(&books, &tags, RelationshipSide::Parent), "_3_7");
    assert_eq!(junction(&tags, &books, RelationshipSide::Child), "_3_7");
}

// ----- relating: the checks made before any related document is read -----

fn relationship_collections() -> Vec<Collection> {
    let parents = collection(
        "parents",
        1,
        vec![
            relation("child", Relationship::one_to_one("children").two_way(false), RelationshipSide::Parent),
            relation("items", Relationship::one_to_many("items").two_way_key("parent"), RelationshipSide::Parent),
            relation("tags", Relationship::many_to_many("tags").two_way_key("parents"), RelationshipSide::Parent),
        ],
        any_permissions(),
    );
    let children = collection(
        "children",
        2,
        vec![relation(
            "parent",
            Relationship::one_to_one("parents").two_way(false).two_way_key("child"),
            RelationshipSide::Child,
        )],
        any_permissions(),
    );
    let items = collection("items", 3, Vec::new(), any_permissions());
    let tags = collection("tags", 4, Vec::new(), any_permissions());
    vec![parents, children, items, tags]
}

#[test]
fn refuses_invalid_relationship_values_on_create() {
    block_on(async {
        let collections = relationship_collections();
        let (_db, hook) = attached(stub(collections.clone()));
        let (parents, children) = (&collections[0], &collections[1]);

        let error = hook
            .after_document_create(children, doc(vec![("$id", string("c1")), ("parent", string("p1"))]))
            .await
            .unwrap_err();
        assert_eq!(error.kind(), Kind::Relationship);
        assert_eq!(
            error.message(),
            "Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false."
        );

        let error = hook
            .after_document_create(
                parents,
                doc(vec![("$id", string("p1")), ("items", Value::from(doc(vec![("$id", string("i1"))])))]),
            )
            .await
            .unwrap_err();
        assert_eq!(
            error.message(),
            "Invalid relationship value. Must be either an array of documents or document IDs, document given."
        );

        let error = hook
            .after_document_create(parents, doc(vec![("$id", string("p1")), ("tags", string("t1"))]))
            .await
            .unwrap_err();
        assert_eq!(
            error.message(),
            "Invalid relationship value. Must be either an array of documents or document IDs, document ID given."
        );

        let error = hook
            .after_document_create(parents, doc(vec![("$id", string("p1")), ("child", list([string("c1")]))]))
            .await
            .unwrap_err();
        assert_eq!(
            error.message(),
            "Invalid relationship value. Must be either a document ID or a document, array given."
        );

        let error = hook
            .after_document_create(parents, doc(vec![("$id", string("p1")), ("tags", list([Value::Int(1)]))]))
            .await
            .unwrap_err();
        assert_eq!(
            error.message(),
            "Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs."
        );
    });
}

#[test]
fn a_create_without_related_values_keeps_only_the_keys_it_stores() {
    block_on(async {
        let collections = relationship_collections();
        let (_db, hook) = attached(stub(collections.clone()));
        let created = hook
            .after_document_create(
                &collections[0],
                doc(vec![("$id", string("p1")), ("child", Value::Null), ("items", Value::Null), ("tags", list([]))]),
            )
            .await
            .unwrap();
        // The parent of a one-to-one stores its key; one-to-many parents and
        // many-to-many relationships are stored on the other side.
        assert!(created.has("child"));
        assert!(!created.has("items"));
        assert!(!created.has("tags"));
    });
}

#[test]
fn an_update_leaves_out_relationships_it_does_not_change() {
    block_on(async {
        let collections = relationship_collections();
        let (_db, hook) = attached(stub(collections.clone()));
        let parents = &collections[0];
        let child = doc(vec![("$id", string("c1")), ("name", string("same"))]);
        let old = doc(vec![("$id", string("p1")), ("child", Value::from(child.clone())), ("items", list([]))]);
        let update = doc(vec![("$id", string("p1")), ("child", Value::from(child)), ("items", list([]))]);
        let updated = hook.after_document_update(parents, &old, update).await.unwrap();
        assert_eq!(updated.get("child"), Some(&string("c1")));
        assert!(!updated.has("items"));
        assert!(!updated.has("tags"));

        let error = hook
            .after_document_update(parents, &old, doc(vec![("$id", string("p1")), ("items", string("i1"))]))
            .await
            .unwrap_err();
        assert_eq!(
            error.message(),
            "Invalid relationship value. Must be either an array of documents or document IDs, string given."
        );

        let error = hook
            .after_document_update(
                &collections[1],
                &doc(vec![("$id", string("c1"))]),
                doc(vec![("$id", string("c1")), ("parent", string("p1"))]),
            )
            .await
            .unwrap_err();
        assert_eq!(error.kind(), Kind::Relationship);
    });
}

// ----- prepareNested and grants -----

/// A nested hook marking every related document it prepares.
struct Marking;

#[async_trait]
impl Nested for Marking {
    async fn prepare(&self, collection: &Collection, mut document: Document) -> Result<Document> {
        document.put("preparedIn", string(collection.id()));
        Ok(document)
    }
}

fn nested_collections() -> Vec<Collection> {
    let parents = collection(
        "parents",
        1,
        vec![relation("items", Relationship::one_to_many("items").two_way_key("parent"), RelationshipSide::Parent)],
        any_permissions(),
    );
    let items = collection(
        "items",
        2,
        vec![relation("tags", Relationship::many_to_many("tags").two_way_key("items"), RelationshipSide::Parent)],
        any_permissions(),
    );
    let tags = collection("tags", 3, Vec::new(), vec![Permission::read(&Role::any())]);
    vec![parents, items, tags]
}

#[test]
fn prepare_nested_gives_every_nested_document_to_the_hooks() {
    block_on(async {
        let collections = nested_collections();
        let (_db, hook) = attached(stub(collections.clone()));
        let preparation = Preparation { hooks: vec![Arc::new(Marking)], ..Preparation::default() };

        let tag: value::Array = [(value::ArrayKey::from("$id"), string("t1"))].into_iter().collect();
        let item = doc(vec![("$id", string("i1")), ("tags", list([Value::Array(tag), string("t2")]))]);
        let parent = doc(vec![("$id", string("p1")), ("items", list([Value::from(item), string("i2")]))]);

        let prepared = hook.prepare_nested(&collections[0], parent.clone(), &preparation).await.unwrap();
        let Some(Value::Array(items)) = prepared.get("items") else { panic!("a list") };
        let item = value::as_document(items.values().next().unwrap()).unwrap();
        assert_eq!(item.get("preparedIn"), Some(&string("items")));
        assert_eq!(items.values().nth(1), Some(&string("i2")));
        let Some(Value::Array(tags)) = item.get("tags") else { panic!("a list") };
        let tag = value::as_document(tags.values().next().unwrap()).unwrap();
        assert_eq!(tag.get("preparedIn"), Some(&string("tags")));

        // A write nested in another relationship write is left as given.
        let nested = hook.in_write("parents", hook.prepare_nested(&collections[0], parent.clone(), &preparation)).await;
        assert_eq!(nested.unwrap(), parent);
    });
}

#[test]
fn a_new_nested_document_may_only_grant_roles_the_caller_holds() {
    block_on(async {
        let collections = nested_collections();
        let (_db, hook) = attached(stub(collections.clone()));
        let preparation = Preparation { check: true, ..Preparation::default() };

        let granting = |permission: &str| {
            let item = doc(vec![("$permissions", list([string(permission)]))]);
            doc(vec![("$id", string("p1")), ("items", list([Value::from(item)]))])
        };

        let allowed = hook.prepare_nested(&collections[0], granting("read(\"any\")"), &preparation).await;
        assert!(allowed.is_ok());

        let error = hook.prepare_nested(&collections[0], granting("read(\"user:x\")"), &preparation).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Authorization);
        assert_eq!(error.message(), "Permissions must be one of: (any)");

        let error = hook.prepare_nested(&collections[0], granting("read(\"user\")"), &preparation).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Structure);
        assert_eq!(error.message(), "Role \"user\" must have an ID value.");
    });
}

// ----- authorize -----

#[test]
fn authorize_judges_staged_related_documents() {
    block_on(async {
        let collections = nested_collections();
        let (_db, hook) = attached(stub(collections.clone()));
        let (parents, items) = (&collections[0], &collections[1]);

        // Every related document is new: create on the related collection.
        let gone: Resolve = Arc::new(|_, _| Some(Document::empty()));
        let item = doc(vec![("$id", string("i1")), ("tags", list([Value::from(doc(vec![("$id", string("t1"))]))]))]);
        let parent = doc(vec![("$id", string("p1")), ("items", list([Value::from(item)]))]);
        let error = hook.authorize(parents, &parent, None, Some(gone.clone())).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Authorization, "tags grants no create");

        let creatable =
            doc(vec![("$id", string("p1")), ("items", list([Value::from(doc(vec![("$id", string("i1"))]))]))]);
        hook.authorize(parents, &creatable, None, Some(gone)).await.unwrap();

        // A staged document the caller cannot read is refused.
        let hidden: Resolve = Arc::new(|_, id| Some(doc(vec![("$id", string(id)), ("$permissions", list([]))])));
        let mut secret_items = items.clone();
        secret_items.document_mut().put(document::PERMISSIONS, list([]));
        let (_db, hook) = attached(stub(vec![parents.clone(), secret_items, collections[2].clone()]));
        let linking = doc(vec![("$id", string("p1")), ("items", list([string("i9")]))]);
        let error = hook.authorize(parents, &linking, None, Some(hidden.clone())).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Authorization);

        // A related document the old document links already is not linked again.
        let old = doc(vec![("$id", string("p1")), ("items", list([string("i9")]))]);
        hook.authorize(parents, &linking, Some(&old), Some(hidden)).await.unwrap();
    });
}

#[test]
fn authorize_refuses_an_invalid_relationship_value() {
    block_on(async {
        let collections = nested_collections();
        let (_db, hook) = attached(stub(collections.clone()));
        let parent = doc(vec![("$id", string("p1")), ("items", list([Value::Int(3)]))]);
        let error = hook.authorize(&collections[0], &parent, None, None).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Relationship);
    });
}
