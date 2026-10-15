//! Ported from utopia-php/database 8.1.3 `tests/unit/DocumentAdvancedTest.php`,
//! `tests/unit/DocumentTrimTest.php` and the pure tests of
//! `tests/unit/DocumentFromStorageTest.php`.
//!
//! Not ported from DocumentFromStorageTest (they need a `Database`, a cache
//! or a custom document class over the Memory adapter):
//! `testJsonFilterDecodesADocumentShapedValueWithANonStringPermission`,
//! `testQueryCacheRebuildsCachedDocumentsWithANonStringPermission`,
//! `testAMappedTypeReadsAStoredJsonValueWithANonStringPermission`,
//! `testAStorageRebuildKeepsTheMappedTypeAndDropsNonStringPermissions`.

mod common;

use common::{arr, assert_error, document, list, v};
use utopia_database::document::{self as d, SetType};
use utopia_database::value::{Array, Value, as_document, as_document_mut};
use utopia_database::{Document, Kind, Permission, PermissionType, Role};

// DocumentAdvancedTest

#[test]
fn test_deep_clone_with_nested_documents() {
    let inner = document! {"$id" => "inner", "value" => "original"};
    let middle = document! {"$id" => "middle", "child" => inner.clone()};
    let outer = document! {"$id" => "outer", "child" => middle};

    let mut cloned = outer.clone();
    let cloned_middle = as_document_mut(cloned.get_mut("child").unwrap()).unwrap();
    let cloned_inner = as_document_mut(cloned_middle.get_mut("child").unwrap()).unwrap();
    cloned_inner.set("value", v("modified")).unwrap();

    assert_eq!(inner.get("value"), Some(&v("original")));
    assert_eq!(cloned_inner.get("value"), Some(&v("modified")));
    let original_inner = as_document(as_document(outer.get("child").unwrap()).unwrap().get("child").unwrap()).unwrap();
    assert_eq!(original_inner.get("value"), Some(&v("original")));
}

#[test]
fn test_deep_clone_with_array_of_documents() {
    let doc = document! {
        "$id" => "parent",
        "items" => list![document! {"$id" => "a", "val" => 1}, document! {"$id" => "b", "val" => 2}],
    };
    let mut cloned = doc.clone();
    let Some(Value::Array(items)) = cloned.get_mut("items") else { panic!() };
    as_document_mut(items.get_mut(&0.into()).unwrap()).unwrap().set("val", v(99)).unwrap();

    assert_eq!(doc.documents("items").unwrap()[0].get("val"), Some(&v(1)));
    assert_eq!(cloned.documents("items").unwrap()[0].get("val"), Some(&v(99)));
}

#[test]
fn test_only_keeps_the_given_keys() {
    let doc = document! {"$id" => "test", "name" => "John", "email" => "john@example.com", "age" => 30};
    let copy = doc.only(&["name", "email"]);
    assert!(copy.get_str("name").is_some());
    assert!(copy.get_str("email").is_some());
    assert!(copy.get_str("$id").is_none());
    assert!(copy.get_str("age").is_none());
}

#[test]
fn test_except_drops_the_given_keys() {
    let doc = document! {"$id" => "test", "name" => "John", "secret" => "hidden", "password" => "12345"};
    let copy = doc.except(&["secret", "password"]);
    assert!(copy.get_str("$id").is_some());
    assert!(copy.get_str("name").is_some());
    assert!(copy.get_str("secret").is_none());
    assert!(copy.get_str("password").is_none());
}

#[test]
fn test_get_array_copy_with_nested_documents() {
    let doc = document! {"$id" => "parent", "child" => document! {"$id" => "child", "value" => "test"}};
    let copy = doc.array_copy();
    let Some(Value::Array(child)) = copy.get_str("child") else { panic!("not an array") };
    assert_eq!(child.get_str("$id"), Some(&v("child")));
    assert_eq!(child.get_str("value"), Some(&v("test")));
}

#[test]
fn test_get_array_copy_with_array_of_documents() {
    let doc = document! {"$id" => "parent", "children" => list![document! {"$id" => "a"}, document! {"$id" => "b"}]};
    let copy = doc.array_copy();
    assert_eq!(copy.get_str("children"), Some(&v(list![arr! {"$id" => "a"}, arr! {"$id" => "b"}])));
}

#[test]
fn test_is_empty_on_different_states() {
    assert!(Document::empty().is_empty());
    assert!(!document! {"$id" => "test"}.is_empty());
    assert!(!document! {"name" => "test"}.is_empty());
}

#[test]
fn test_get_attribute_with_default_value() {
    let doc = document! {"$id" => "test", "name" => "John"};
    assert_eq!(doc.get_or("name", &v("default")), &v("John"));
    assert_eq!(doc.get_or("missing", &v("default")), &v("default"));
    assert_eq!(doc.get("missing"), None);
    assert_eq!(doc.get_or("missing", &v(0)), &v(0));
    assert_eq!(doc.get_or("missing", &v(list![])), &v(list![]));
    assert_eq!(doc.get_or("missing", &v(false)), &v(false));
}

#[test]
fn test_remove_attribute() {
    let mut doc = document! {"$id" => "test", "name" => "John", "email" => "john@example.com"};
    doc.remove("name");
    assert_eq!(doc.get("name"), None);
    assert!(!doc.is_set("name"));
    assert_eq!(doc.get("email"), Some(&v("john@example.com")));
}

#[test]
fn test_remove_attribute_returns_self() {
    // PHP chains `removeAttribute()`; Rust's `remove` returns the removed value.
    let mut doc = document! {"$id" => "test", "a" => 1, "b" => 2};
    assert_eq!(doc.remove("a"), Some(v(1)));
    assert_eq!(doc.remove("b"), Some(v(2)));
    assert!(!doc.is_set("a"));
    assert!(!doc.is_set("b"));
}

#[test]
fn test_set_attributes_batch() {
    let mut doc = document! {"$id" => "test"};
    doc.set_many(arr! {"name" => "John", "email" => "john@example.com", "age" => 25}).unwrap();
    assert_eq!(doc.get("name"), Some(&v("John")));
    assert_eq!(doc.get("email"), Some(&v("john@example.com")));
    assert_eq!(doc.get("age"), Some(&v(25)));
}

#[test]
fn test_set_attributes_batch_overwrites() {
    let mut doc = document! {"$id" => "test", "name" => "Old"};
    doc.set_many(arr! {"name" => "New", "extra" => "added"}).unwrap();
    assert_eq!(doc.get("name"), Some(&v("New")));
    assert_eq!(doc.get("extra"), Some(&v("added")));
}

#[test]
fn test_set_attributes_batch_returns_self() {
    let mut doc = document! {"$id" => "test"};
    let result = doc.set_many(arr! {"a" => 1}).unwrap();
    assert_eq!(result.get("a"), Some(&v(1)));
}

#[test]
fn test_get_attributes_filters_internal_keys() {
    let doc = document! {
        "$id" => "test",
        "$collection" => "users",
        "$permissions" => list!["read(\"any\")"],
        "$createdAt" => "2024-01-01T00:00:00.000+00:00",
        "$updatedAt" => "2024-01-01T00:00:00.000+00:00",
        "name" => "John",
        "email" => "john@example.com",
    };
    assert_eq!(doc.attributes(), arr! {"name" => "John", "email" => "john@example.com"});
}

#[test]
fn test_set_type_append() {
    let mut doc = document! {"$id" => "test", "tags" => list!["php"]};
    doc.set_with("tags", v("laravel"), SetType::Append).unwrap();
    assert_eq!(doc.get("tags"), Some(&v(list!["php", "laravel"])));
}

#[test]
fn test_set_type_append_on_non_array() {
    let mut doc = document! {"$id" => "test", "value" => "scalar"};
    doc.set_with("value", v("item"), SetType::Append).unwrap();
    assert_eq!(doc.get("value"), Some(&v(list!["item"])));
}

#[test]
fn test_set_type_append_on_missing() {
    let mut doc = document! {"$id" => "test"};
    doc.set_with("newList", v("first"), SetType::Append).unwrap();
    assert_eq!(doc.get("newList"), Some(&v(list!["first"])));
}

#[test]
fn test_set_type_prepend() {
    let mut doc = document! {"$id" => "test", "tags" => list!["php"]};
    doc.set_with("tags", v("html"), SetType::Prepend).unwrap();
    assert_eq!(doc.get("tags"), Some(&v(list!["html", "php"])));
}

#[test]
fn test_set_type_prepend_on_non_array() {
    let mut doc = document! {"$id" => "test", "value" => "scalar"};
    doc.set_with("value", v("item"), SetType::Prepend).unwrap();
    assert_eq!(doc.get("value"), Some(&v(list!["item"])));
}

#[test]
fn test_set_type_prepend_on_missing() {
    let mut doc = document! {"$id" => "test"};
    doc.set_with("newList", v("first"), SetType::Prepend).unwrap();
    assert_eq!(doc.get("newList"), Some(&v(list!["first"])));
}

#[test]
fn test_set_type_assign() {
    let mut doc = document! {"$id" => "test", "name" => "old"};
    doc.set_with("name", v("new"), SetType::Assign).unwrap();
    assert_eq!(doc.get("name"), Some(&v("new")));
}

#[test]
fn test_set_type_keeps_string_keys_and_renumbers_integer_keys() {
    // `[...$current, $value]` / `[$value, ...$current]`.
    let mut doc = document! {"map" => arr! {5 => "a", "k" => "b"}};
    doc.set_with("map", v("c"), SetType::Append).unwrap();
    assert_eq!(doc.get("map"), Some(&v(arr! {0 => "a", "k" => "b", 1 => "c"})));
    let mut doc = document! {"map" => arr! {5 => "a", "k" => "b"}};
    doc.set_with("map", v("c"), SetType::Prepend).unwrap();
    assert_eq!(doc.get("map"), Some(&v(arr! {0 => "c", 1 => "a", "k" => "b"})));
}

#[test]
fn test_constructor_auto_converts_nested_arrays_to_documents() {
    let doc = document! {"$id" => "parent", "child" => arr! {"$id" => "child_id", "name" => "nested"}};
    let child = as_document(doc.get("child").unwrap()).expect("a document");
    assert_eq!(child.id(), "child_id");
}

#[test]
fn test_constructor_auto_converts_array_of_nested_documents() {
    let doc = document! {
        "$id" => "parent",
        "children" => list![arr! {"$id" => "a", "name" => "first"}, arr! {"$id" => "b", "name" => "second"}],
    };
    let children = doc.documents("children").unwrap();
    assert_eq!(children.len(), 2);
    assert_eq!(children[0].id(), "a");
    assert_eq!(children[1].id(), "b");
}

#[test]
fn test_get_array_copy_with_empty_array_values() {
    let doc = document! {"$id" => "test", "empty_list" => list![], "non_empty" => list!["a"]};
    let copy = doc.array_copy();
    assert_eq!(copy.get_str("empty_list"), Some(&v(list![])));
    assert_eq!(copy.get_str("non_empty"), Some(&v(list!["a"])));
}

// DocumentTrimTest

fn trim_document() -> Document {
    document! {
        d::ID => "parent",
        "name" => "Parent",
        "secret" => "hidden",
        "child" => document! {d::ID => "child", "name" => "Child", "secret" => "nested"},
        "children" => list![document! {d::ID => "first", "secret" => "first"}, "plain"],
        "tags" => list!["a", "b"],
    }
}

#[test]
fn test_get_array_copy_converts_nested_documents_recursively() {
    assert_eq!(
        trim_document().array_copy(),
        arr! {
            d::ID => "parent",
            "name" => "Parent",
            "secret" => "hidden",
            "child" => arr! {d::ID => "child", "name" => "Child", "secret" => "nested"},
            "children" => list![arr! {d::ID => "first", "secret" => "first"}, "plain"],
            "tags" => list!["a", "b"],
        }
    );
}

#[test]
fn test_get_array_copy_converts_documents_nested_in_nested_documents() {
    let document = document! {
        "child" => document! {"grandchildren" => list![document! {d::ID => "grandchild"}]},
    };
    assert_eq!(
        document.array_copy(),
        arr! {"child" => arr! {"grandchildren" => list![arr! {d::ID => "grandchild"}]}}
    );
}

#[test]
fn test_only_keeps_the_given_top_level_keys_in_document_order() {
    assert_eq!(
        trim_document().only(&["child", "name"]),
        arr! {"name" => "Parent", "child" => arr! {d::ID => "child", "name" => "Child", "secret" => "nested"}}
    );
}

#[test]
fn test_only_ignores_keys_the_document_lacks() {
    assert_eq!(trim_document().only(&["name", "missing"]), arr! {"name" => "Parent"});
}

#[test]
fn test_only_with_no_keys_keeps_nothing() {
    assert_eq!(trim_document().only(&[]), Array::new());
}

#[test]
fn test_except_drops_the_given_top_level_keys_only() {
    assert_eq!(
        trim_document().except(&["secret", "tags"]),
        arr! {
            d::ID => "parent",
            "name" => "Parent",
            "child" => arr! {d::ID => "child", "name" => "Child", "secret" => "nested"},
            "children" => list![arr! {d::ID => "first", "secret" => "first"}, "plain"],
        }
    );
}

#[test]
fn test_except_with_no_keys_equals_get_array_copy() {
    let document = trim_document();
    assert_eq!(document.array_copy(), document.except(&[]));
}

#[test]
fn test_trimming_leaves_the_document_untouched() {
    let document = trim_document();
    let mut only = document.only(&["child"]);
    if let Some(Value::Array(child)) = only.get_mut(&"child".into()) {
        child.set("name".into(), v("changed"));
    }
    let mut except = document.except(&["name"]);
    if let Some(Value::Array(children)) = except.get_mut(&"children".into())
        && let Some(Value::Array(first)) = children.get_mut(&0.into())
    {
        first.set("secret".into(), v("changed"));
    }

    assert_eq!(document.get("name"), Some(&v("Parent")));
    assert_eq!(document.document("child").unwrap().get("name"), Some(&v("Child")));
    assert_eq!(document.documents("children").unwrap()[0].get("secret"), Some(&v("first")));
}

// DocumentFromStorageTest (pure parts)

#[test]
fn test_drops_non_string_permissions_and_duplicates() {
    let document = Document::from_storage(arr! {
        d::ID => "legacy",
        d::PERMISSIONS => list![
            42,
            Permission::read(&Role::any()),
            (),
            1.5,
            true,
            list!["read(\"any\")"],
            Permission::read(&Role::any()),
            Permission::update(&Role::users("")),
        ],
    })
    .unwrap();

    assert_eq!(
        document.get(d::PERMISSIONS),
        Some(&v(list![Permission::read(&Role::any()), Permission::update(&Role::users(""))]))
    );
    assert_eq!(document.roles(PermissionType::Read).unwrap(), vec!["any".to_owned()]);
    assert_eq!(document.roles(PermissionType::Update).unwrap(), vec!["users".to_owned()]);
}

#[test]
fn test_the_constructor_still_rejects_non_string_permissions() {
    assert_error(
        Document::new(arr! {d::PERMISSIONS => list![Permission::read(&Role::any()), 42]}),
        Kind::Structure,
        "Every permission must be of type string",
    );
}

/// `describe()`: the document's values, documents as `[Document::class => ...]`.
fn describe(document: &Document) -> Value {
    fn value(x: &Value) -> Value {
        match x {
            Value::Ext(_) => match as_document(x) {
                Some(d) => v(arr! {d::CLASS => describe(d)}),
                None => x.clone(),
            },
            Value::Array(a) => Value::Array(a.iter().map(|(k, item)| (k.to_owned(), value(item))).collect()),
            other => other.clone(),
        }
    }
    Value::Array(document.iter().map(|(k, item)| (k.to_owned(), value(item))).collect())
}

#[test]
fn test_builds_nested_documents_like_the_constructor() {
    let data = arr! {
        d::ID => "parent",
        d::PERMISSIONS => list![Permission::read(&Role::any())],
        "author" => arr! {d::ID => "author", "name" => "Ada"},
        "category" => arr! {d::COLLECTION => "categories", "name" => "Books"},
        "comments" => list![
            arr! {d::ID => "first", "body" => "one"},
            arr! {d::COLLECTION => "comments", "body" => "two"},
            arr! {"body" => "three"},
            "plain",
        ],
        "tags" => list!["a", "b"],
        "settings" => arr! {"theme" => "dark", "nested" => arr! {"depth" => 2}},
        "count" => 3,
    };

    let document = Document::from_storage(data.clone()).unwrap();

    assert_eq!(describe(&Document::new(data).unwrap()), describe(&document));
    assert!(as_document(document.get("author").unwrap()).is_some());
    assert!(as_document(document.get("category").unwrap()).is_some());
    let comments = document.array("comments");
    assert!(as_document(comments.get(&0.into()).unwrap()).is_some());
    assert!(as_document(comments.get(&1.into()).unwrap()).is_some());
    assert_eq!(comments.get(&2.into()), Some(&v(arr! {"body" => "three"})));
    assert_eq!(comments.get(&3.into()), Some(&v("plain")));
}

#[test]
fn test_drops_non_string_permissions_of_nested_documents() {
    let document = Document::from_storage(arr! {
        d::ID => "parent",
        "author" => arr! {
            d::ID => "author",
            d::PERMISSIONS => list![42, Permission::read(&Role::any())],
            "publisher" => arr! {d::ID => "publisher", d::PERMISSIONS => list![false, Permission::read(&Role::users(""))]},
        },
        "comments" => list![arr! {d::ID => "first", d::PERMISSIONS => list![(), Permission::delete(&Role::any())]}],
    })
    .unwrap();

    let author = document.document("author").unwrap();
    assert_eq!(author.permissions().unwrap(), vec![Permission::read(&Role::any())]);
    assert_eq!(author.document("publisher").unwrap().permissions().unwrap(), vec![Permission::read(&Role::users(""))]);
    assert_eq!(document.documents("comments").unwrap()[0].permissions().unwrap(), vec![Permission::delete(&Role::any())]);
}

#[test]
fn test_rejects_what_the_constructor_rejects_besides_permission_entries() {
    assert_error(Document::from_storage(arr! {d::ID => 42}), Kind::Structure, "$id must be of type string");
    assert_error(
        Document::from_storage(arr! {d::PERMISSIONS => Permission::read(&Role::any())}),
        Kind::Structure,
        "$permissions must be of type array",
    );
}
