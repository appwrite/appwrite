//! Ported from utopia-php/database 8.1.3 `tests/unit/DocumentTest.php`.
//!
//! Not ported: `testArrayCopyAndCloneDetachReferencedArrayElements` (PHP
//! references into an array; Rust values are owned, so nothing can write
//! through) and `testScalarArrayExportAvoidsReferenceAllocationOverhead`
//! (a PHP allocator measurement).

mod common;

use common::{arr, assert_error, document, list, v};
use utopia_database::document::{self as d, SetType};
use utopia_database::value::{Array, Value, as_document, as_document_mut};
use utopia_database::{Document, Kind, Permission, PermissionType, Role, id};

struct Fixture {
    document: Document,
    empty: Document,
    id: String,
    collection: String,
}

fn permissions() -> Vec<String> {
    vec![
        Permission::read(&Role::user(id::custom("123"), "")),
        Permission::read(&Role::team(id::custom("123"), "")),
        Permission::create(&Role::any()),
        Permission::create(&Role::user(id::custom("creator"), "")),
        Permission::update(&Role::any()),
        Permission::update(&Role::user(id::custom("updater"), "")),
        Permission::delete(&Role::any()),
        Permission::delete(&Role::user(id::custom("deleter"), "")),
    ]
}

fn set_up() -> Fixture {
    let id = id::unique_padded(0).unwrap();
    let collection = id::unique_padded(0).unwrap();
    let document = document! {
        d::ID => id::custom(id.clone()),
        d::COLLECTION => id::custom(collection.clone()),
        d::PERMISSIONS => permissions(),
        "title" => "This is a test.",
        "list" => list!["one"],
        "children" => list![
            document! {"name" => "x"},
            document! {"name" => "y"},
            document! {"name" => "z"},
        ],
    };
    Fixture { document, empty: Document::empty(), id, collection }
}

fn strings(items: &[&str]) -> Vec<String> {
    items.iter().map(|s| s.to_string()).collect()
}

#[test]
fn test_document_nulls() {
    let document = document! {"cat" => (), "dog" => ()};
    assert_eq!(document.raw("cat"), Some(&Value::Null));
    assert!(!document.is_set("cat"));
    assert_eq!(document.get_or("cat", &v("cat")), &v("cat"));
    assert_eq!(document.raw("dog"), Some(&Value::Null));
    assert!(!document.is_set("dog"));
    assert_eq!(document.get_or("dog", &v("dog")), &v("dog"));
}

#[test]
fn test_id() {
    let f = set_up();
    assert_eq!(f.document.id(), f.id);
    assert_eq!(f.empty.id(), "");
}

#[test]
fn test_non_string_id_throws() {
    assert_error(Document::new(arr! {d::ID => 123}), Kind::Structure, "$id must be of type string");
}

#[test]
fn test_from_row_coerces_null_id_to_empty_string() {
    let document = Document::from_row(arr! {d::ID => (), "name" => "unmatched"}).unwrap();
    assert_eq!(document.id(), "");
    assert_eq!(document.raw(d::ID), Some(&v("")));
    assert_eq!(document.get("name"), Some(&v("unmatched")));
}

#[test]
fn test_from_row_drops_pdo_column_indexes() {
    let document = Document::from_row(arr! {
        0 => 1,
        1 => "migration",
        d::ID => "migration",
        "state" => "pending",
        d::SEQUENCE => "1",
    })
    .unwrap();
    // Same keys, same order (array equality is ordered).
    assert_eq!(document.array_copy(), arr! {"$id" => "migration", "state" => "pending", "$sequence" => "1"});
    assert_eq!(document.id(), "migration");
    assert_eq!(document.get("state"), Some(&v("pending")));
    assert_eq!(document.get("0"), None);
}

#[test]
fn test_id_and_collection_accessors() {
    let document = document! {d::ID => "doc-1", d::COLLECTION => "users"};
    assert_eq!(document.id(), "doc-1");
    assert_eq!(document.collection(), "users");
}

#[test]
fn test_collection() {
    let f = set_up();
    assert_eq!(f.document.collection(), f.collection);
    assert_eq!(f.empty.collection(), "");
}

#[test]
fn test_get_create() {
    let f = set_up();
    assert_eq!(f.document.roles(PermissionType::Create).unwrap(), strings(&["any", "user:creator"]));
    assert!(f.empty.roles(PermissionType::Create).unwrap().is_empty());
}

#[test]
fn test_get_read() {
    let f = set_up();
    assert_eq!(f.document.roles(PermissionType::Read).unwrap(), strings(&["user:123", "team:123"]));
    assert!(f.empty.roles(PermissionType::Read).unwrap().is_empty());
}

#[test]
fn test_get_update() {
    let f = set_up();
    assert_eq!(f.document.roles(PermissionType::Update).unwrap(), strings(&["any", "user:updater"]));
    assert!(f.empty.roles(PermissionType::Update).unwrap().is_empty());
}

#[test]
fn test_get_delete() {
    let f = set_up();
    assert_eq!(f.document.roles(PermissionType::Delete).unwrap(), strings(&["any", "user:deleter"]));
    assert!(f.empty.roles(PermissionType::Delete).unwrap().is_empty());
}

#[test]
fn test_get_permission_by_type() {
    let f = set_up();
    for (kind, roles) in [
        (PermissionType::Create, ["any", "user:creator"]),
        (PermissionType::Read, ["user:123", "team:123"]),
        (PermissionType::Update, ["any", "user:updater"]),
        (PermissionType::Delete, ["any", "user:deleter"]),
    ] {
        assert_eq!(f.document.permissions_by_type(kind.as_str()).unwrap(), strings(&roles));
        assert!(f.empty.permissions_by_type(kind.as_str()).unwrap().is_empty());
    }
}

#[test]
fn test_get_permissions_by_custom_type() {
    let document = document! {
        "$permissions" => list!["execute(\"any\")", "execute(\"user:runner\")", Permission::read(&Role::any())],
    };
    assert_eq!(document.permissions_by_type("execute").unwrap(), strings(&["any", "user:runner"]));
    assert_eq!(document.permissions_by_type("read").unwrap(), strings(&["any"]));
    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["any"]));
    assert!(document.permissions_by_type("subscribe").unwrap().is_empty());
    assert!(document.permissions_by_type("exec").unwrap().is_empty());
}

#[test]
fn test_get_permissions() {
    let f = set_up();
    assert_eq!(f.document.permissions().unwrap(), permissions());
}

#[test]
fn test_get_attributes() {
    let f = set_up();
    assert_eq!(
        f.document.attributes(),
        arr! {
            "title" => "This is a test.",
            "list" => list!["one"],
            "children" => list![
                document! {"name" => "x"},
                document! {"name" => "y"},
                document! {"name" => "z"},
            ],
        }
    );
}

#[test]
fn test_get_attribute() {
    let f = set_up();
    assert_eq!(f.document.get_or("title", &v("")), &v("This is a test."));
    assert_eq!(f.document.get_or("titlex", &v("")), &v(""));
}

#[test]
fn test_set_attribute() {
    let mut f = set_up();
    let empty_list = v(list![]);
    assert_eq!(f.document.get_or("title", &v("")), &v("This is a test."));
    assert_eq!(f.document.get_or("list", &empty_list), &v(list!["one"]));
    assert_eq!(f.document.get_or("titlex", &v("")), &v(""));

    f.document.set("title", v("New title")).unwrap();
    assert_eq!(f.document.get_or("title", &v("")), &v("New title"));
    assert_eq!(f.document.get_or("titlex", &v("")), &v(""));

    f.document.set_with("list", v("two"), SetType::Append).unwrap();
    assert_eq!(f.document.get_or("list", &empty_list), &v(list!["one", "two"]));

    f.document.set_with("list", v("zero"), SetType::Prepend).unwrap();
    assert_eq!(f.document.get_or("list", &empty_list), &v(list!["zero", "one", "two"]));

    f.document.set_with("list", v(list!["one"]), SetType::Assign).unwrap();
    assert_eq!(f.document.get_or("list", &empty_list), &v(list!["one"]));
}

#[test]
fn test_set_attributes() {
    let mut document = document! {"$id" => id::custom(""), "$collection" => "users"};
    let other = document! {
        "$id" => id::custom("new"),
        "$permissions" => list![
            Permission::read(&Role::any()),
            Permission::update(&Role::user("new", "")),
            Permission::delete(&Role::user("new", "")),
        ],
        "email" => "joe@example.com",
        "prefs" => Value::Object(Array::new()),
    };

    document.set_many(other.array_copy()).unwrap();

    assert_eq!(document.id(), other.id());
    assert_eq!(document.collection(), "users");
    assert_eq!(document.permissions().unwrap(), other.permissions().unwrap());
    assert_eq!(document.get("email"), other.get("email"));
    assert_eq!(document.get("prefs"), other.get("prefs"));
}

#[test]
fn test_remove_attribute() {
    let mut f = set_up();
    f.document.remove("list");
    let empty = v(list![]);
    assert_eq!(f.document.get_or("list", &empty), &empty);
}

#[test]
fn test_is_empty() {
    let f = set_up();
    assert!(!f.document.is_empty());
    assert!(f.empty.is_empty());
}

#[test]
fn test_is_set() {
    let f = set_up();
    assert!(!f.document.is_set("titlex"));
    assert!(!f.empty.is_set("titlex"));
    assert!(f.document.is_set("title"));
}

fn child_mut<'a>(doc: &'a mut Document, key: &str) -> &'a mut Document {
    as_document_mut(doc.get_mut(key).unwrap()).unwrap()
}

fn first_child_mut<'a>(doc: &'a mut Document, key: &str) -> &'a mut Document {
    let Value::Array(items) = doc.get_mut(key).unwrap() else { panic!("not an array") };
    as_document_mut(items.get_mut(&0.into()).unwrap()).unwrap()
}

fn first_child<'a>(doc: &'a Document, key: &str) -> &'a Document {
    let Value::Array(items) = doc.get(key).unwrap() else { panic!("not an array") };
    as_document(items.get(&0.into()).unwrap()).unwrap()
}

fn child<'a>(doc: &'a Document, key: &str) -> &'a Document {
    as_document(doc.get(key).unwrap()).unwrap()
}

#[test]
fn test_clone() {
    let mut before = document! {
        "level" => 0,
        "name" => "_",
        "document" => document! {"name" => "zero"},
        "children" => list![document! {
            "level" => 1,
            "name" => "a",
            "document" => document! {"name" => "one"},
            "children" => list![document! {
                "level" => 2,
                "name" => "x",
                "document" => document! {"name" => "two"},
                "children" => list![document! {"level" => 3, "name" => "i"}],
            }],
        }],
    };

    let after = before.clone();

    before.set("name", v("before")).unwrap();
    child_mut(&mut before, "document").set("name", v("before_one")).unwrap();
    let a = first_child_mut(&mut before, "children");
    a.set("name", v("before_a")).unwrap();
    child_mut(a, "document").set("name", v("before_two")).unwrap();
    first_child_mut(a, "children").set("name", v("before_x")).unwrap();

    assert_eq!(after.get("name"), Some(&v("_")));
    assert_eq!(child(&after, "document").get("name"), Some(&v("zero")));
    let a = first_child(&after, "children");
    assert_eq!(a.get("name"), Some(&v("a")));
    assert_eq!(child(a, "document").get("name"), Some(&v("one")));
    assert_eq!(first_child(a, "children").get("name"), Some(&v("x")));
}

#[test]
fn test_get_array_copy() {
    let f = set_up();
    assert_eq!(
        f.document.array_copy(),
        arr! {
            "$id" => f.id.clone(),
            "$collection" => f.collection.clone(),
            "$permissions" => permissions(),
            "title" => "This is a test.",
            "list" => list!["one"],
            "children" => list![arr! {"name" => "x"}, arr! {"name" => "y"}, arr! {"name" => "z"}],
        }
    );
    assert_eq!(f.empty.array_copy(), Array::new());
}

#[test]
fn test_empty_document_sequence() {
    let empty = Document::empty();
    assert_eq!(empty.sequence(), None);
}

#[test]
fn test_construction_preserves_scalar_arrays_and_converts_only_documents() {
    let object = Value::Object(Array::new());
    let values = arr! {7 => "text", "null" => (), "bool" => false, "object" => object};
    let first = arr! {"$id" => "first"};
    let document = document! {
        "empty" => list![],
        "values" => values.clone(),
        "child" => arr! {"$id" => "child", "name" => "nested"},
        "children" => arr! {"first" => first.clone(), 9 => "plain"},
    };

    let child = as_document(document.get("child").unwrap()).unwrap();
    let children = document.array("children");

    assert_eq!(document.get("empty"), Some(&v(list![])));
    assert_eq!(document.get("values"), Some(&Value::Array(values)));
    assert_eq!(child.id(), "child");
    let first_doc = as_document(children.get(&"first".into()).unwrap()).unwrap();
    assert_eq!(first_doc.id(), "first");
    assert_eq!(children.get(&9.into()), Some(&v("plain")));
    assert_eq!(first_doc.array_copy(), first);
}

#[test]
fn test_except_preserves_keys_and_filters_top_level_keys_only() {
    let document = document! {
        "name" => "parent",
        "secret" => "hidden",
        "values" => arr! {7 => "seven", "null" => (), "empty" => list![]},
        "children" => arr! {"child" => document! {"name" => "nested", "secret" => "hidden"}},
    };
    let mut copy = document.except(&["secret"]);

    assert_eq!(
        copy,
        arr! {
            "name" => "parent",
            "values" => arr! {7 => "seven", "null" => (), "empty" => list![]},
            "children" => arr! {"child" => arr! {"name" => "nested", "secret" => "hidden"}},
        }
    );
    if let Some(Value::Array(values)) = copy.get_mut(&"values".into()) {
        values.set(7.into(), v("changed"));
    }

    let nested = document.array("children");
    let nested = as_document(nested.get(&"child".into()).unwrap()).unwrap();
    assert_eq!(document.array("values").get(&7.into()), Some(&v("seven")));
    assert_eq!(nested.get("name"), Some(&v("nested")));
}

#[test]
fn test_clone_preserves_scalar_keys_and_isolates_nested_documents() {
    let object = Value::Object(arr! {"p" => 1});
    let original = document! {
        "empty" => list![],
        "values" => arr! {7 => "seven", "object" => object.clone()},
        "children" => arr! {"child" => document! {"name" => "nested"}, 9 => "plain"},
    };
    let mut copy = original.clone();
    if let Some(Value::Array(values)) = copy.get_mut("values") {
        values.set(7.into(), v("changed"));
    }
    if let Some(Value::Array(children)) = copy.get_mut("children") {
        as_document_mut(children.get_mut(&"child".into()).unwrap()).unwrap().set("name", v("changed")).unwrap();
    }

    assert_eq!(copy.get("empty"), Some(&v(list![])));
    let keys: Vec<_> = copy.array("values").keys().map(|k| k.to_owned()).collect();
    assert_eq!(keys, vec![7.into(), "object".into()]);
    assert_eq!(copy.array("values").get(&"object".into()), Some(&object));
    assert_eq!(original.array("values").get(&7.into()), Some(&v("seven")));
    let original_child = original.array("children");
    assert_eq!(as_document(original_child.get(&"child".into()).unwrap()).unwrap().get("name"), Some(&v("nested")));
    assert_eq!(copy.array("children").get(&9.into()), Some(&v("plain")));
}

#[test]
fn test_array_access_permission_write_refreshes_roles() {
    let mut document = document! {d::PERMISSIONS => list![Permission::read(&Role::user("old", ""))]};
    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["user:old"]));

    document.as_array_mut().set(d::PERMISSIONS.into(), v(list![Permission::read(&Role::any())]));

    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["any"]));
    assert_eq!(document.permissions().unwrap(), vec![Permission::read(&Role::any())]);
}

#[test]
fn test_unset_permissions_clears_roles() {
    let mut document = document! {d::PERMISSIONS => list![Permission::read(&Role::any())]};
    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["any"]));

    document.remove(d::PERMISSIONS);

    assert!(document.roles(PermissionType::Read).unwrap().is_empty());
    assert!(document.permissions().unwrap().is_empty());
}

#[test]
fn test_exchange_array_refreshes_roles() {
    let mut document = document! {d::PERMISSIONS => list![Permission::read(&Role::any())]};
    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["any"]));

    *document.as_array_mut() = arr! {d::PERMISSIONS => list![Permission::read(&Role::user("new", ""))]};

    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["user:new"]));
}

#[test]
fn test_reference_write_to_permissions_refreshes_roles() {
    let mut document = document! {d::PERMISSIONS => list![Permission::read(&Role::user("old", ""))]};
    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["user:old"]));

    let Some(Value::Array(permissions)) = document.get_mut(d::PERMISSIONS) else { panic!("not an array") };
    permissions.push(v(Permission::update(&Role::any()))).unwrap();

    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["user:old"]));
    assert_eq!(document.roles(PermissionType::Update).unwrap(), strings(&["any"]));
}

#[test]
fn test_get_permissions_normalises_array_access_writes() {
    let mut document = Document::empty();
    document.as_array_mut().set(
        d::PERMISSIONS.into(),
        v(arr! {
            5 => Permission::read(&Role::any()),
            9 => Permission::read(&Role::any()),
            12 => Permission::update(&Role::user("editor", "")),
        }),
    );

    assert_eq!(
        document.permissions().unwrap(),
        vec![Permission::read(&Role::any()), Permission::update(&Role::user("editor", ""))]
    );
    assert_eq!(
        serde_json_list(&document.permissions().unwrap()),
        "[\"read(\\\"any\\\")\",\"update(\\\"user:editor\\\")\"]"
    );
    assert_eq!(document.roles(PermissionType::Update).unwrap(), strings(&["user:editor"]));
}

fn serde_json_list(items: &[String]) -> String {
    format!("[{}]", items.iter().map(|s| format!("\"{}\"", s.replace('"', "\\\""))).collect::<Vec<_>>().join(","))
}

#[test]
fn test_constructor_rejects_non_string_permissions() {
    assert_error(
        Document::new(arr! {d::PERMISSIONS => list![123, Permission::read(&Role::any())]}),
        Kind::Structure,
        "Every permission must be of type string",
    );
}

#[test]
fn test_constructor_deduplicates_permissions() {
    let document = document! {d::PERMISSIONS => arr! {
        2 => Permission::read(&Role::any()),
        4 => Permission::read(&Role::any()),
        6 => Permission::delete(&Role::user("owner", "")),
    }};
    assert_eq!(
        document.get(d::PERMISSIONS),
        Some(&v(list![Permission::read(&Role::any()), Permission::delete(&Role::user("owner", ""))]))
    );
}

#[test]
fn test_set_attribute_rejects_non_string_permissions() {
    let mut document = document! {d::PERMISSIONS => list![Permission::read(&Role::any())]};

    let writes = [(SetType::Assign, v(list![Permission::update(&Role::any()), 123])), (SetType::Append, v(123))];
    for (how, value) in writes {
        assert_error(
            document.set_with(d::PERMISSIONS, value, how).map(|_| ()),
            Kind::Structure,
            "Every permission must be of type string",
        );
    }

    assert_eq!(document.permissions().unwrap(), vec![Permission::read(&Role::any())]);
    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["any"]));
}

#[test]
fn test_set_attribute_rejects_permissions_that_are_not_an_array() {
    let mut document = document! {d::PERMISSIONS => list![Permission::read(&Role::any())]};

    assert_error(
        document.set(d::PERMISSIONS, v(Permission::update(&Role::any()))).map(|_| ()),
        Kind::Structure,
        "$permissions must be of type array",
    );
    assert_eq!(document.permissions().unwrap(), vec![Permission::read(&Role::any())]);

    document.set(d::PERMISSIONS, Value::Null).unwrap();
    assert!(document.permissions().unwrap().is_empty());
}

#[test]
fn test_from_row_filters_non_string_permissions_from_stored_rows() {
    let document = Document::from_row(arr! {
        d::ID => "legacy",
        d::PERMISSIONS => list![123, Permission::read(&Role::any()), (), Permission::read(&Role::any())],
    })
    .unwrap();
    assert_eq!(document.permissions().unwrap(), vec![Permission::read(&Role::any())]);
    assert_eq!(document.roles(PermissionType::Read).unwrap(), strings(&["any"]));
}

#[test]
fn test_from_row_rejects_a_non_string_id_and_non_array_permissions() {
    assert_error(Document::from_row(arr! {d::ID => 5}), Kind::Structure, "$id must be of type string");
    assert_error(
        Document::from_row(arr! {d::PERMISSIONS => "read(\"any\")"}),
        Kind::Structure,
        "$permissions must be of type array",
    );
}

#[test]
fn test_a_tenant_of_another_type_reads_as_no_tenant() {
    for tenant in [v(1.0), v(true), v(list![1]), Value::Object(Array::new())] {
        let document = document! {d::TENANT => tenant};
        assert_eq!(document.tenant(), None);
    }
}

#[test]
fn test_get_documents_wraps_array_items_and_skips_scalars() {
    let first = document! {"name" => "first"};
    let document = document! {
        "items" => list![first.clone(), arr! {"name" => "x", 0 => "y"}, "scalar", ()],
        "text" => "not a list",
    };

    let documents = document.documents("items").unwrap();
    assert_eq!(documents.len(), 2);
    assert_eq!(documents[0], first);
    assert_eq!(documents[1].array_copy(), arr! {"name" => "x"});
    assert!(document.documents("missing").unwrap().is_empty());
    assert!(document.documents("text").unwrap().is_empty());
}

#[test]
fn test_get_document_returns_an_empty_document_for_a_value_that_is_not_one() {
    for value in [Value::Null, v(list![]), v(list!["a", "b"]), v("x")] {
        let document = document! {"value" => value};
        assert!(document.document("value").unwrap().is_empty());
        assert!(document.document("missing").unwrap().is_empty());
    }
}

#[test]
fn test_get_document_wraps_the_string_keys_of_an_associative_array() {
    let child = document! {"name" => "child"};
    let document = document! {"map" => arr! {"name" => "x", 0 => "y"}, "child" => child.clone()};
    assert_eq!(document.document("map").unwrap().array_copy(), arr! {"name" => "x"});
    assert_eq!(document.document("child").unwrap(), child);
}
