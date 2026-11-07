//! Ported from utopia-php/database 8.1.3 `tests/unit/Model/RelationshipTest.php`,
//! `Model/RelationshipFactoryTest.php` and `Model/RelationshipUpdateTest.php`.
//!
//! Not ported: `RelationshipTest::testConstructorIsNotCallableFromOutside`
//! (PHP's private constructor), and the cases that store PHP enum cases as
//! values (`testEnumValuesAreAcceptedInPlaceOfStrings`,
//! `testSupportedForeignKeyActionIsAccepted`, the "unrelated enum as delete
//! action" row of `invalidRows`): a Rust [`Value`] cannot hold an enum case.

mod common;

use common::{arr, assert_error, document};
use utopia_database::relationship::{
    self, Relationship, RelationshipDeleteAction, RelationshipSide, RelationshipType, Update,
};
use utopia_database::value::{ArrayKey, Value};
use utopia_database::{Document, Kind};
use utopia_query::schema::ForeignKeyAction;

#[track_caller]
fn assert_same_relationship(expected: &Relationship, actual: &Relationship) {
    assert_eq!(expected.related_collection, actual.related_collection);
    assert_eq!(expected.kind, actual.kind);
    assert_eq!(expected.two_way, actual.two_way);
    assert_eq!(expected.key, actual.key);
    assert_eq!(expected.two_way_key, actual.two_way_key);
    assert_eq!(expected.on_delete, actual.on_delete);
}

// RelationshipTest

#[test]
fn test_inverse_swaps_keys_and_points_at_the_given_collection() {
    let r = Relationship::one_to_many("comments")
        .key("comments")
        .two_way(true)
        .two_way_key("post")
        .on_delete(RelationshipDeleteAction::Cascade);
    let inverse = r.inverse("posts");
    assert_eq!(inverse.related_collection, "posts");
    assert_eq!(inverse.key.as_deref(), Some("post"));
    assert_eq!(inverse.two_way_key.as_deref(), Some("comments"));
    assert_eq!(inverse.kind, RelationshipType::OneToMany);
    assert!(inverse.two_way);
    assert_eq!(inverse.on_delete, RelationshipDeleteAction::Cascade);
}

#[test]
fn test_inverse_leaves_the_original_untouched() {
    let r = Relationship::many_to_many("tags").key("tags").two_way(true).two_way_key("posts");
    let _ = r.inverse("posts");
    assert_eq!(r.related_collection, "tags");
    assert_eq!(r.key.as_deref(), Some("tags"));
    assert_eq!(r.two_way_key.as_deref(), Some("posts"));
}

#[test]
fn test_inverse_of_inverse_restores_the_keys() {
    let r = Relationship::many_to_one("users")
        .key("author")
        .two_way(true)
        .two_way_key("posts")
        .on_delete(RelationshipDeleteAction::SetNull);
    assert_same_relationship(&r, &r.inverse("posts").inverse("users"));
}

#[test]
fn test_inverse_with_unresolved_keys_keeps_them_unresolved() {
    let inverse = Relationship::one_to_one("profiles").key("profile").inverse("users");
    assert_eq!(inverse.key, None);
    assert_eq!(inverse.two_way_key.as_deref(), Some("profile"));
}

#[test]
fn test_to_document_writes_the_stored_options_shape() {
    let s = Relationship::one_to_many("comments")
        .key("comments")
        .two_way(true)
        .two_way_key("post")
        .on_delete(RelationshipDeleteAction::SetNull)
        .to_document();
    assert_eq!(
        s.array_copy(),
        arr! {
            "relatedCollection" => "comments",
            "relationType" => "oneToMany",
            "twoWay" => true,
            "key" => "comments",
            "twoWayKey" => "post",
            "onDelete" => "setNull",
        }
    );
}

fn relationships() -> Vec<Relationship> {
    vec![
        Relationship::one_to_one("profiles"),
        Relationship::one_to_many("comments")
            .key("comments")
            .two_way(true)
            .two_way_key("post")
            .on_delete(RelationshipDeleteAction::Cascade),
        Relationship::many_to_one("users")
            .key("author")
            .two_way_key("posts")
            .on_delete(RelationshipDeleteAction::SetNull),
        Relationship::many_to_many("tags").key("tags").two_way(true).two_way_key("posts"),
    ]
}

#[test]
fn test_document_round_trip() {
    for r in relationships() {
        assert_same_relationship(&r, &Relationship::from_document(&r.to_document()).unwrap());
    }
}

#[test]
fn test_array_round_trip() {
    for r in relationships() {
        assert_same_relationship(&r, &Relationship::from_array(&r.to_document().array_copy()).unwrap());
    }
}

#[test]
fn test_seven_x_stored_options_hydrate() {
    let r = Relationship::from_document(&document! {
        "relatedCollection" => "comments",
        "relationType" => "oneToMany",
        "twoWay" => true,
        "twoWayKey" => "post",
        "onDelete" => "cascade",
        "side" => "parent",
    })
    .unwrap();
    assert_eq!(r.related_collection, "comments");
    assert_eq!(r.kind, RelationshipType::OneToMany);
    assert!(r.two_way);
    assert_eq!(r.key, None);
    assert_eq!(r.two_way_key.as_deref(), Some("post"));
    assert_eq!(r.on_delete, RelationshipDeleteAction::Cascade);
}

#[test]
fn test_side_is_ignored() {
    let data =
        arr! {"relatedCollection" => "users", "relationType" => "manyToOne", "key" => "author", "twoWayKey" => "posts"};
    let mut with_side = data.clone();
    with_side.set(ArrayKey::from(relationship::SIDE), Value::from(RelationshipSide::Child.as_str()));
    assert_same_relationship(&Relationship::from_array(&data).unwrap(), &Relationship::from_array(&with_side).unwrap());
    assert!(Relationship::from_array(&with_side).unwrap().to_document().array_copy().get_str("side").is_none());
}

#[test]
fn test_missing_optional_keys_fall_back_to_defaults() {
    let r = Relationship::from_array(&arr! {"relatedCollection" => "users", "relationType" => "oneToOne"}).unwrap();
    assert!(!r.two_way);
    assert_eq!(r.key, None);
    assert_eq!(r.two_way_key, None);
    assert_eq!(r.on_delete, RelationshipDeleteAction::Restrict);
}

#[test]
fn test_empty_stored_keys_hydrate_as_unresolved() {
    let r = Relationship::from_array(
        &arr! {"relatedCollection" => "users", "relationType" => "oneToOne", "key" => "", "twoWayKey" => ""},
    )
    .unwrap();
    assert_eq!(r.key, None);
    assert_eq!(r.two_way_key, None);
}

#[test]
fn test_to_options_is_the_stored_shape_without_the_key_plus_the_side() {
    let r = Relationship::many_to_many("tags")
        .key("tags")
        .two_way(true)
        .two_way_key("posts")
        .on_delete(RelationshipDeleteAction::SetNull);
    assert_eq!(
        r.to_options(RelationshipSide::Child),
        arr! {
            "relatedCollection" => "tags",
            "relationType" => "manyToMany",
            "twoWay" => true,
            "twoWayKey" => "posts",
            "onDelete" => "setNull",
            "side" => "child",
        }
    );
}

#[test]
fn test_to_options_hydrates_back_with_the_key() {
    let r = Relationship::one_to_many("comments").key("comments").two_way_key("post");
    let mut options = r.to_options(RelationshipSide::Parent);
    options.remove(&ArrayKey::from(relationship::SIDE));
    options.set(ArrayKey::from("key"), Value::from("comments"));
    assert_same_relationship(&r, &Relationship::from_array(&options).unwrap());
}

#[test]
fn test_invalid_array_is_rejected() {
    let rows = [
        (arr! {"relationType" => "oneToOne"}, "Relationship has no related collection"),
        (arr! {"relatedCollection" => "", "relationType" => "oneToOne"}, "Relationship has no related collection"),
        (arr! {"relatedCollection" => 7, "relationType" => "oneToOne"}, "Relationship has no related collection"),
        (arr! {"relatedCollection" => "users"}, "Unknown relationship type \"null\""),
        (arr! {"relatedCollection" => "users", "relationType" => "oneToFew"}, "Unknown relationship type \"oneToFew\""),
        (arr! {"relatedCollection" => "users", "relationType" => 1}, "Unknown relationship type \"int\""),
        (
            arr! {"relatedCollection" => "users", "relationType" => "oneToOne", "key" => 5},
            "Relationship key must be a string, got int",
        ),
        (
            arr! {"relatedCollection" => "users", "relationType" => "oneToOne", "twoWayKey" => common::list!["posts"]},
            "Relationship twoWayKey must be a string, got array",
        ),
        (
            arr! {"relatedCollection" => "users", "relationType" => "oneToOne", "onDelete" => "explode"},
            "Unsupported relationship onDelete action \"explode\"; expected one of: cascade, restrict, setNull",
        ),
    ];
    for (data, message) in rows {
        assert_error(Relationship::from_array(&data), Kind::Relationship, message);
        assert_error(Relationship::from_document(&Document::new(data).unwrap()), Kind::Relationship, message);
    }
}

#[test]
fn test_delete_action_maps_to_a_foreign_key_action() {
    assert_eq!(RelationshipDeleteAction::Cascade.to_foreign_key_action(), ForeignKeyAction::Cascade);
    assert_eq!(RelationshipDeleteAction::Restrict.to_foreign_key_action(), ForeignKeyAction::Restrict);
    assert_eq!(RelationshipDeleteAction::SetNull.to_foreign_key_action(), ForeignKeyAction::SetNull);
}

// RelationshipFactoryTest

type Factory = fn(String) -> Relationship;

fn factories() -> [(Factory, RelationshipType); 4] {
    [
        (Relationship::one_to_one, RelationshipType::OneToOne),
        (Relationship::one_to_many, RelationshipType::OneToMany),
        (Relationship::many_to_one, RelationshipType::ManyToOne),
        (Relationship::many_to_many, RelationshipType::ManyToMany),
    ]
}

#[test]
fn test_factory_carries_every_argument() {
    for (factory, kind) in factories() {
        let r = factory("comments".into())
            .key("comments")
            .two_way(true)
            .two_way_key("post")
            .on_delete(RelationshipDeleteAction::Cascade);
        assert_eq!(r.related_collection, "comments");
        assert_eq!(r.kind, kind);
        assert!(r.two_way);
        assert_eq!(r.key.as_deref(), Some("comments"));
        assert_eq!(r.two_way_key.as_deref(), Some("post"));
        assert_eq!(r.on_delete, RelationshipDeleteAction::Cascade);
    }
}

#[test]
fn test_factory_defaults_to_one_way_restrict_with_derived_keys() {
    for (factory, kind) in factories() {
        let r = factory("users".into());
        assert_eq!(r.related_collection, "users");
        assert_eq!(r.kind, kind);
        assert!(!r.two_way);
        assert_eq!(r.key, None);
        assert_eq!(r.two_way_key, None);
        assert_eq!(r.on_delete, RelationshipDeleteAction::Restrict);
    }
}

#[test]
fn test_factories_accept_named_arguments() {
    let r = Relationship::many_to_many("tags")
        .key("tags")
        .two_way(true)
        .two_way_key("posts")
        .on_delete(RelationshipDeleteAction::SetNull);
    assert_eq!(r.related_collection, "tags");
    assert_eq!(r.kind, RelationshipType::ManyToMany);
    assert!(r.two_way);
    assert_eq!(r.key.as_deref(), Some("tags"));
    assert_eq!(r.two_way_key.as_deref(), Some("posts"));
    assert_eq!(r.on_delete, RelationshipDeleteAction::SetNull);
}

#[test]
fn test_one_way_relationship_may_name_its_two_way_key() {
    let r = Relationship::many_to_one("users").key("author").two_way_key("posts");
    assert!(!r.two_way);
    assert_eq!(r.two_way_key.as_deref(), Some("posts"));
}

// RelationshipUpdateTest

#[test]
fn test_defaults_change_nothing() {
    let u = Update::default();
    assert_eq!(u.key, None);
    assert_eq!(u.two_way_key, None);
    assert_eq!(u.two_way, None);
    assert_eq!(u.on_delete, None);
}

#[test]
fn test_empty_update_keeps_every_field() {
    let r = Relationship::one_to_many("comments")
        .key("comments")
        .two_way(true)
        .two_way_key("post")
        .on_delete(RelationshipDeleteAction::Cascade);
    assert_same_relationship(&r, &r.apply(&Update::default()));
}

#[test]
fn test_apply_changes_only_the_given_fields() {
    let r = Relationship::many_to_one("users").key("author").two_way(true).two_way_key("posts");
    let u = r.apply(&Update {
        key: Some("writer".into()),
        on_delete: Some(RelationshipDeleteAction::SetNull),
        ..Default::default()
    });
    assert_eq!(u.key.as_deref(), Some("writer"));
    assert_eq!(u.on_delete, RelationshipDeleteAction::SetNull);
    assert_eq!(u.two_way_key.as_deref(), Some("posts"));
    assert!(u.two_way);
    assert_eq!(u.related_collection, "users");
    assert_eq!(u.kind, RelationshipType::ManyToOne);
}

#[test]
fn test_apply_changes_every_field() {
    let r = Relationship::one_to_one("profiles")
        .key("profile")
        .two_way(true)
        .two_way_key("user")
        .on_delete(RelationshipDeleteAction::Cascade);
    let u = r.apply(&Update {
        key: Some("avatar".into()),
        two_way_key: Some("owner".into()),
        two_way: Some(false),
        on_delete: Some(RelationshipDeleteAction::Restrict),
    });
    assert_eq!(u.key.as_deref(), Some("avatar"));
    assert_eq!(u.two_way_key.as_deref(), Some("owner"));
    assert!(!u.two_way);
    assert_eq!(u.on_delete, RelationshipDeleteAction::Restrict);
    assert_eq!(u.related_collection, "profiles");
    assert_eq!(u.kind, RelationshipType::OneToOne);
}

#[test]
fn test_false_two_way_is_a_change() {
    let u =
        Relationship::many_to_many("tags").two_way(true).apply(&Update { two_way: Some(false), ..Default::default() });
    assert!(!u.two_way);
}

#[test]
fn test_apply_resolves_an_unresolved_key() {
    let u = Relationship::many_to_one("users").apply(&Update { key: Some("author".into()), ..Default::default() });
    assert_eq!(u.key.as_deref(), Some("author"));
    assert_eq!(u.two_way_key, None);
}

#[test]
fn test_apply_leaves_the_original_untouched() {
    let r = Relationship::one_to_many("comments").key("comments").two_way_key("post");
    let _ = r.apply(&Update {
        key: Some("replies".into()),
        two_way_key: Some("thread".into()),
        two_way: Some(true),
        on_delete: Some(RelationshipDeleteAction::Cascade),
    });
    assert_eq!(r.key.as_deref(), Some("comments"));
    assert_eq!(r.two_way_key.as_deref(), Some("post"));
    assert!(!r.two_way);
    assert_eq!(r.on_delete, RelationshipDeleteAction::Restrict);
}

#[test]
fn test_enum_backing_values() {
    assert_eq!(RelationshipType::try_from_value("oneToFew"), None);
    assert_eq!(RelationshipSide::from_value("child").unwrap(), RelationshipSide::Child);
    assert_eq!(
        RelationshipSide::from_value("x").unwrap_err().message(),
        "\"x\" is not a valid backing value for enum Utopia\\Database\\RelationshipSide"
    );
}
