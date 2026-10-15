//! Ported from utopia-php/database 8.1.3 `tests/unit/Model/AttributeTest.php`,
//! `Model/AttributeFactoryTest.php`, `Model/AttributeStorageTest.php`,
//! `Model/AttributeLegacyHydrationTest.php`, `Model/AttributeUpdateTest.php`,
//! `Model/AttributeBits64BoundsTest.php` (bounds only), `Model/FormatTest.php`
//! and `Model/IntegerWidthTest.php`.
//!
//! Not ported:
//! - `AttributeTest::testConstructorIsNotCallableFromOutside`: PHP's private
//!   constructor (Rust builds attributes through the factories; the struct
//!   literal is the native equivalent of a public constructor).
//! - `AttributeTest::testIsRelationshipReadsTheRawStoredType` with a
//!   `ColumnType` case stored as the value, and
//!   `AttributeStorageTest::testColumnTypeInstanceInStorageIsTolerated` and the
//!   `ColumnType::Serial` case of `testTypesOutsideTheAttributeTypesAreRejected`:
//!   a Rust [`Value`] cannot hold a PHP enum case.
//! - `AttributeBits64BoundsTest::testIncreasingPastThe32BitMaximumSucceeds`,
//!   `testIncreasingPastThe64BitMaximumFails`,
//!   `testDecreasingAnUnsignedCounterBelowZeroFails`: need a `Database`.

mod common;

use common::{arr, assert_error, document, list, v};
use utopia_database::attribute::{self, Attribute, Format, IntegerWidth, Patch, Update};
use utopia_database::database::{self as db, LENGTH_KEY};
use utopia_database::filter::Filter;
use utopia_database::relationship::{Relationship, RelationshipDeleteAction, RelationshipSide, RelationshipType};
use utopia_database::value::{Array, Value};
use utopia_database::{Document, Kind};
use utopia_query::schema::ColumnType;

fn stored(attribute: &Attribute) -> Array {
    attribute.to_document().unwrap().array_copy()
}

fn stored_attr(attribute: &Attribute, key: &str) -> Value {
    attribute.to_document().unwrap().raw(key).cloned().unwrap_or(Value::Null)
}

fn strings(items: &[&str]) -> Vec<String> {
    items.iter().map(|s| s.to_string()).collect()
}

fn format(name: &str, options: Array) -> Format {
    Format::new(name, options).unwrap()
}

// AttributeTest

#[test]
fn test_types_lists_the_eighteen_attribute_types() {
    let hydrating: Vec<ColumnType> =
        ColumnType::CASES.iter().copied().filter(|t| Attribute::type_from_stored(t.as_str()).is_ok()).collect();
    assert_eq!(hydrating.len(), 18);
    let mut unique = attribute::TYPES.to_vec();
    unique.dedup();
    assert_eq!(unique, attribute::TYPES);
    assert!(attribute::TYPES.contains(&ColumnType::Relationship));
    assert!(!attribute::TYPES.contains(&ColumnType::Tuple));
    assert!(!attribute::TYPES.contains(&ColumnType::Uuid7));
}

#[test]
fn test_type_predicates() {
    let cases = [
        (Attribute::string("a", LENGTH_KEY), false, false, false),
        (Attribute::integer("a"), false, true, true),
        (Attribute::big_integer("a"), false, true, true),
        (Attribute::float("a"), false, true, false),
        (Attribute::double("a"), false, true, false),
        (Attribute::boolean("a"), false, false, false),
        (Attribute::datetime("a"), false, false, false),
        (Attribute::point("a"), true, false, false),
        (Attribute::line_string("a"), true, false, false),
        (Attribute::polygon("a"), true, false, false),
        (Attribute::vector("a", 3), false, false, false),
        (Attribute::object("a"), false, false, false),
        (Attribute::id("a"), false, false, false),
    ];
    for (attribute, spatial, numeric, integer) in cases {
        assert_eq!(attribute.is_spatial(), spatial, "{:?}", attribute.kind);
        assert_eq!(attribute.is_numeric(), numeric, "{:?}", attribute.kind);
        assert_eq!(attribute.is_integer(), integer, "{:?}", attribute.kind);
    }
}

#[test]
fn test_numeric_bounds() {
    let cases = [
        (Attribute::integer("a"), v(db::MIN_INT), v(db::MAX_INT)),
        (Attribute::integer("a").signed(false), v(0), v(db::MAX_INT)),
        (Attribute::big_integer("a"), v(i64::MIN), v(db::MAX_BIG_INT)),
        (Attribute::big_integer("a").signed(false), v(0), v("18446744073709551615")),
        (Attribute::float("a"), v(-db::MAX_DOUBLE), v(db::MAX_DOUBLE)),
        (Attribute::double("a").signed(false), v(0), v(db::MAX_DOUBLE)),
        // AttributeBits64BoundsTest
        (Attribute::integer("a").width(IntegerWidth::Bits64), v(i64::MIN), v(db::MAX_BIG_INT)),
        (Attribute::integer("a").signed(false).width(IntegerWidth::Bits64), v(0), v(db::MAX_BIG_INT)),
        (
            Attribute::from_array(&arr! {"key" => "a", "type" => "integer", "size" => 8, "signed" => true}).unwrap(),
            v(i64::MIN),
            v(db::MAX_BIG_INT),
        ),
    ];
    for (attribute, min, max) in cases {
        let bounds = attribute.bounds().expect("bounds");
        assert_eq!(bounds.min, min, "{:?}", attribute.kind);
        assert_eq!(bounds.max, max, "{:?}", attribute.kind);
    }
}

#[test]
fn test_non_numeric_types_have_no_bounds() {
    assert!(Attribute::string("a", LENGTH_KEY).bounds().is_none());
    assert!(Attribute::boolean("a").bounds().is_none());
    assert!(Attribute::datetime("a").bounds().is_none());
}

#[test]
fn test_resolved_size_uses_the_engine_maximum_for_sizeless_text() {
    assert_eq!(Attribute::text("a").resolved_size(), db::MAX_TEXT_BYTES);
    assert_eq!(Attribute::medium_text("a").resolved_size(), db::MAX_MEDIUMTEXT_BYTES);
    assert_eq!(Attribute::long_text("a").resolved_size(), db::MAX_LONGTEXT_BYTES);
}

#[test]
fn test_resolved_size_uses_the_declared_size() {
    assert_eq!(Attribute::text("a").size(Some(500)).resolved_size(), 500);
    assert_eq!(Attribute::varchar("a", 64).resolved_size(), 64);
    assert_eq!(Attribute::from_array(&arr! {"key" => "a", "type" => "integer", "size" => 8}).unwrap().resolved_size(), 8);
}

#[test]
fn test_resolved_size_is_zero_for_sizeless_non_text_types() {
    assert_eq!(Attribute::boolean("a").resolved_size(), 0);
    assert_eq!(Attribute::integer("a").resolved_size(), 0);
}

#[test]
fn test_width_is_only_defined_for_integers() {
    assert_eq!(Attribute::big_integer("a").integer_width(), None);
    assert_eq!(Attribute::string("a", LENGTH_KEY).integer_width(), None);
    assert_eq!(Attribute::double("a").integer_width(), None);
}

#[test]
fn test_with_filters_replaces_filters_only() {
    let original = Attribute::string("name", 32).required(true).default("x");
    let filtered = original.with_filters([Filter::Json.into(), "encrypt".into()] as [attribute_filter::Name; 2]);

    assert_eq!(filtered.filters, strings(&["json", "encrypt"]));
    assert!(original.filters.is_empty());
    assert_eq!(filtered.key, "name");
    assert_eq!(filtered.size, Some(32));
    assert!(filtered.required);
    assert_eq!(filtered.default, v("x"));
}

mod attribute_filter {
    pub type Name = utopia_database::filter::FilterName;
}

#[test]
fn test_with_filters_can_clear_filters() {
    let none: [&str; 0] = [];
    assert!(Attribute::string("a", LENGTH_KEY).filters(["encrypt"]).with_filters(none).filters.is_empty());
}

#[test]
fn test_with_filters_keeps_the_type_filter() {
    let none: [&str; 0] = [];
    assert_eq!(Attribute::datetime("a").with_filters(none).filters, strings(&["datetime"]));
    assert_eq!(Attribute::vector("a", 3).with_filters(["encrypt"]).filters, strings(&["vector", "encrypt"]));
    assert_eq!(
        Attribute::object("a").with_filters(["encrypt".into(), Filter::Object.into()] as [attribute_filter::Name; 2]).filters,
        strings(&["encrypt", "object"])
    );
}

#[test]
fn test_is_relationship_reads_the_raw_stored_type() {
    assert!(Attribute::is_relationship_document(&document! {"type" => "relationship"}));
    assert!(!Attribute::is_relationship_document(&document! {"type" => "string"}));
    assert!(!Attribute::is_relationship_document(&document! {"type" => "bigint"}));
    assert!(!Attribute::is_relationship_document(&Document::empty()));
    assert!(!Attribute::is_relationship_document(&Attribute::string("relationship", LENGTH_KEY).to_document().unwrap()));
}

#[test]
fn test_stored_type_uses_the_legacy_big_integer_spelling() {
    assert_eq!(Attribute::stored_type(ColumnType::BigInteger), "bigint");
    assert_eq!(Attribute::stored_type(ColumnType::Integer), "integer");
    assert_eq!(Attribute::stored_type(ColumnType::Linestring), "linestring");
}

#[test]
fn test_type_from_stored_maps_both_big_integer_spellings() {
    assert_eq!(Attribute::type_from_stored("bigint").unwrap(), ColumnType::BigInteger);
    assert_eq!(Attribute::type_from_stored("biginteger").unwrap(), ColumnType::BigInteger);
    assert_eq!(Attribute::type_from_stored("double").unwrap(), ColumnType::Double);
}

#[test]
fn test_every_type_round_trips_through_its_stored_spelling() {
    for t in attribute::TYPES {
        assert_eq!(Attribute::type_from_stored(Attribute::stored_type(t)).unwrap(), t);
    }
}

#[test]
fn test_type_from_stored_rejects_non_attribute_types() {
    assert_error(Attribute::type_from_stored("tuple"), Kind::Structure, "Unknown attribute type: tuple");
}

#[test]
fn test_type_from_stored_rejects_unknown_strings() {
    assert_error(Attribute::type_from_stored("BIGINT"), Kind::Structure, "Unknown attribute type: BIGINT");
}

// AttributeFactoryTest

#[test]
fn test_datetime_persists_unsigned_with_datetime_filter() {
    let s = Attribute::datetime("publishedAt").required(true).to_document().unwrap();
    assert_eq!(s.get("type"), Some(&v("datetime")));
    assert_eq!(s.get("signed"), Some(&v(false)));
    assert_eq!(s.get("filters"), Some(&v(list!["datetime"])));
    assert_eq!(s.get("size"), Some(&v(0)));
    assert_eq!(s.get("required"), Some(&v(true)));
}

#[test]
fn test_datetime_array_keeps_the_datetime_filter() {
    let a = Attribute::datetime("history").array(true).default(list!["2024-01-01T00:00:00.000+00:00"]);
    assert!(a.array);
    assert_eq!(a.filters, strings(&[Filter::Datetime.as_str()]));
    assert_eq!(a.default, v(list!["2024-01-01T00:00:00.000+00:00"]));
}

#[test]
fn test_spatial_persists_scalar_with_its_type_filter() {
    for (a, kind) in [
        (Attribute::point("location"), "point"),
        (Attribute::line_string("location"), "linestring"),
        (Attribute::polygon("location"), "polygon"),
    ] {
        let s = a.to_document().unwrap();
        assert_eq!(s.get("type"), Some(&v(kind)));
        assert_eq!(s.get("array"), Some(&v(false)));
        assert_eq!(s.get("filters"), Some(&v(list![kind])));
        assert_eq!(s.get("size"), Some(&v(0)));
        assert_eq!(s.get("signed"), Some(&v(true)));
    }
}

#[test]
fn test_spatial_keeps_its_default() {
    let a = Attribute::point("location").required(true).default(list![1.5, 2.5]);
    assert_eq!(a.default, v(list![1.5, 2.5]));
    assert!(a.required);
}

#[test]
fn test_vector_persists_dimensions_as_size() {
    let s = Attribute::vector("embedding", 1536).default(list![0.0, 1.0]).to_document().unwrap();
    assert_eq!(s.get("type"), Some(&v("vector")));
    assert_eq!(s.get("size"), Some(&v(1536)));
    assert_eq!(s.get("filters"), Some(&v(list!["vector"])));
    assert_eq!(s.get("array"), Some(&v(false)));
    assert_eq!(s.get("default"), Some(&v(list![0.0, 1.0])));
}

#[test]
fn test_object_persists_scalar_with_object_filter() {
    let s = Attribute::object("metadata").default(arr! {"a" => 1}).to_document().unwrap();
    assert_eq!(s.get("type"), Some(&v("object")));
    assert_eq!(s.get("filters"), Some(&v(list!["object"])));
    assert_eq!(s.get("array"), Some(&v(false)));
    assert_eq!(s.get("size"), Some(&v(0)));
    assert_eq!(s.get("default"), Some(&v(arr! {"a" => 1})));
}

#[test]
fn test_integer_defaults_to_thirty_two_bits_persisted_as_size_zero() {
    let a = Attribute::integer("count");
    assert_eq!(a.size, None);
    assert_eq!(a.integer_width(), Some(IntegerWidth::Bits32));
    assert_eq!(stored_attr(&a, "size"), v(0));
    assert!(a.signed);
}

#[test]
fn test_integer_sixty_four_bits_persists_as_size_eight() {
    let a = Attribute::integer("count").width(IntegerWidth::Bits64).signed(false).default(5);
    assert_eq!(a.size, Some(8));
    assert_eq!(a.integer_width(), Some(IntegerWidth::Bits64));
    assert_eq!(stored_attr(&a, "size"), v(8));
    assert!(!a.signed);
    assert_eq!(a.default, v(5));
}

#[test]
fn test_sizeless_types_persist_size_zero() {
    for (a, kind) in [
        (Attribute::boolean("flag"), "boolean"),
        (Attribute::double("score"), "double"),
        (Attribute::float("score"), "float"),
        (Attribute::id("reference"), "id"),
        (Attribute::big_integer("total"), "bigint"),
    ] {
        let s = a.to_document().unwrap();
        assert_eq!(a.size, None);
        assert_eq!(s.get("type"), Some(&v(kind)));
        assert_eq!(s.get("size"), Some(&v(0)));
        assert_eq!(s.get("filters"), Some(&v(list![])));
        assert_eq!(s.get("signed"), Some(&v(true)));
    }
}

#[test]
fn test_string_family_persists_signed() {
    for (a, kind, size) in [
        (Attribute::string("name", LENGTH_KEY), "string", LENGTH_KEY),
        (Attribute::varchar("name", 64), "varchar", 64),
        (Attribute::text("name"), "text", 0),
        (Attribute::medium_text("name"), "mediumtext", 0),
        (Attribute::long_text("name").size(Some(1024)), "longtext", 1024),
    ] {
        let s = a.to_document().unwrap();
        assert_eq!(s.get("type"), Some(&v(kind)));
        assert_eq!(s.get("signed"), Some(&v(true)));
        assert_eq!(s.get("size"), Some(&v(size)));
        assert_eq!(s.get("array"), Some(&v(false)));
        assert_eq!(s.get("filters"), Some(&v(list![])));
    }
}

#[test]
fn test_explicit_zero_size_means_no_size() {
    let a = Attribute::text("body").size(Some(0));
    assert_eq!(a.size, None);
    assert_eq!(stored_attr(&a, "size"), v(0));
}

#[test]
fn test_string_carries_every_argument() {
    let a = Attribute::string("email", 320)
        .required(true)
        .default("user@example.com")
        .array(true)
        .format(format("email", arr! {"allowPlus" => true}))
        .filters([attribute_filter::Name::from(Filter::Json), "lowercase".into()]);
    assert_eq!(a.key, "email");
    assert_eq!(a.kind, ColumnType::String);
    assert_eq!(a.size, Some(320));
    assert!(a.required);
    assert_eq!(a.default, v("user@example.com"));
    assert!(a.array);
    assert_eq!(a.format.as_ref().unwrap().name, "email");
    assert_eq!(a.format.as_ref().unwrap().options, arr! {"allowPlus" => true});
    assert_eq!(a.filters, strings(&["json", "lowercase"]));
    assert!(a.relationship.is_none());
    assert!(a.side.is_none());
}

#[test]
fn test_every_factory_produces_its_column_type() {
    let cases = [
        (Attribute::string("a", LENGTH_KEY), ColumnType::String),
        (Attribute::varchar("a", LENGTH_KEY), ColumnType::Varchar),
        (Attribute::text("a"), ColumnType::Text),
        (Attribute::medium_text("a"), ColumnType::MediumText),
        (Attribute::long_text("a"), ColumnType::LongText),
        (Attribute::integer("a"), ColumnType::Integer),
        (Attribute::big_integer("a"), ColumnType::BigInteger),
        (Attribute::float("a"), ColumnType::Float),
        (Attribute::double("a"), ColumnType::Double),
        (Attribute::boolean("a"), ColumnType::Boolean),
        (Attribute::datetime("a"), ColumnType::Datetime),
        (Attribute::point("a"), ColumnType::Point),
        (Attribute::line_string("a"), ColumnType::Linestring),
        (Attribute::polygon("a"), ColumnType::Polygon),
        (Attribute::vector("a", 3), ColumnType::Vector),
        (Attribute::object("a"), ColumnType::Object),
        (Attribute::id("a"), ColumnType::Id),
    ];
    for (a, kind) in cases {
        assert_eq!(a.kind, kind);
    }
}

#[test]
fn test_numeric_factories_keep_signed_and_default() {
    let double = Attribute::double("score").required(true).default(1.5).signed(false).array(true);
    assert!(!double.signed);
    assert_eq!(double.default, v(1.5));
    assert!(double.required);
    assert!(double.array);

    let big = Attribute::big_integer("total").default("9223372036854775808").signed(false);
    assert_eq!(big.default, v("9223372036854775808"));
    assert!(!big.signed);
}

#[test]
fn test_relationship_persists_seven_x_options_shape_plus_side() {
    let r = Relationship::one_to_many("comments")
        .key("comments")
        .two_way(true)
        .two_way_key("post")
        .on_delete(RelationshipDeleteAction::Cascade);
    let s = Attribute::relationship("comments", r, RelationshipSide::Parent).unwrap().to_document().unwrap();
    assert_eq!(s.get("type"), Some(&v("relationship")));
    assert_eq!(s.get("key"), Some(&v("comments")));
    assert_eq!(s.get("required"), Some(&v(false)));
    assert_eq!(s.get("default"), None);
    assert_eq!(
        s.get("options"),
        Some(&v(arr! {
            "relatedCollection" => "comments",
            "relationType" => "oneToMany",
            "twoWay" => true,
            "twoWayKey" => "post",
            "onDelete" => "cascade",
            "side" => "parent",
        }))
    );
}

#[test]
fn test_relationship_adopts_the_attribute_key_when_the_relationship_has_none() {
    let a = Attribute::relationship("author", Relationship::many_to_one("users"), RelationshipSide::Child).unwrap();
    assert_eq!(a.kind, ColumnType::Relationship);
    assert_eq!(a.relationship.as_ref().unwrap().key.as_deref(), Some("author"));
    assert_eq!(a.relationship.as_ref().unwrap().kind, RelationshipType::ManyToOne);
    assert_eq!(a.side, Some(RelationshipSide::Child));
    assert_eq!(a.size, None);
    assert!(a.filters.is_empty());
}

#[test]
fn test_relationship_rejects_a_mismatched_key() {
    assert_error(
        Attribute::relationship("author", Relationship::many_to_one("users").key("writer"), RelationshipSide::Parent),
        Kind::Structure,
        "Relationship key \"writer\" does not match attribute key \"author\"",
    );
}

// AttributeStorageTest

const STORED_KEYS: [&str; 11] =
    ["$id", "key", "type", "size", "required", "default", "signed", "array", "format", "formatOptions", "filters"];

fn keys(a: &Array) -> Vec<String> {
    a.keys().map(KeyText::to_owned_str).collect()
}

trait KeyText {
    fn to_owned_str(self) -> String;
}
impl KeyText for utopia_database::value::KeyRef<'_> {
    fn to_owned_str(self) -> String {
        match self {
            utopia_database::value::KeyRef::Int(i) => i.to_string(),
            utopia_database::value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        }
    }
}

#[test]
fn test_every_seven_four_stored_type_hydrates() {
    for (stored_type, kind) in [
        ("string", ColumnType::String),
        ("integer", ColumnType::Integer),
        ("bigint", ColumnType::BigInteger),
        ("double", ColumnType::Double),
        ("boolean", ColumnType::Boolean),
        ("datetime", ColumnType::Datetime),
        ("varchar", ColumnType::Varchar),
        ("text", ColumnType::Text),
        ("mediumtext", ColumnType::MediumText),
        ("longtext", ColumnType::LongText),
        ("id", ColumnType::Id),
        ("object", ColumnType::Object),
        ("vector", ColumnType::Vector),
        ("point", ColumnType::Point),
        ("linestring", ColumnType::Linestring),
        ("polygon", ColumnType::Polygon),
    ] {
        let a = Attribute::from_document(&document! {
            "$id" => "field",
            "key" => "field",
            "type" => stored_type,
            "size" => 0,
            "required" => false,
            "default" => (),
            "signed" => true,
            "array" => false,
            "format" => "",
            "formatOptions" => list![],
            "filters" => list![],
        })
        .unwrap();
        assert_eq!(a.kind, kind);
        assert_eq!(a.key, "field");
        assert_eq!(stored_attr(&a, "type"), v(stored_type));
    }
}

#[test]
fn test_float_stored_by_eight_zero_hydrates_as_float() {
    assert_eq!(Attribute::from_array(&arr! {"key" => "score", "type" => "float"}).unwrap().kind, ColumnType::Float);
}

#[test]
fn test_canonical_big_integer_spelling_hydrates_and_persists_as_legacy_spelling() {
    let a = Attribute::from_array(&arr! {"key" => "total", "type" => "biginteger"}).unwrap();
    assert_eq!(a.kind, ColumnType::BigInteger);
    assert_eq!(stored_attr(&a, "type"), v("bigint"));
}

#[test]
fn test_seven_four_relationship_shape_hydrates() {
    let a = Attribute::from_document(&document! {
        "$id" => "comments",
        "key" => "comments",
        "type" => "relationship",
        "required" => false,
        "default" => (),
        "options" => arr! {
            "relatedCollection" => "comments",
            "relationType" => "oneToMany",
            "twoWay" => true,
            "twoWayKey" => "post",
            "onDelete" => "cascade",
            "side" => "parent",
        },
    })
    .unwrap();
    assert_eq!(a.kind, ColumnType::Relationship);
    assert_eq!(a.side, Some(RelationshipSide::Parent));
    let r = a.relationship.unwrap();
    assert_eq!(r.related_collection, "comments");
    assert_eq!(r.kind, RelationshipType::OneToMany);
    assert!(r.two_way);
    assert_eq!(r.key.as_deref(), Some("comments"));
    assert_eq!(r.two_way_key.as_deref(), Some("post"));
    assert_eq!(r.on_delete, RelationshipDeleteAction::Cascade);
}

#[test]
fn test_relationship_round_trips_through_storage() {
    let r = Relationship::many_to_many("tags")
        .key("tags")
        .two_way(true)
        .two_way_key("posts")
        .on_delete(RelationshipDeleteAction::SetNull);
    let a = Attribute::relationship("tags", r, RelationshipSide::Child).unwrap();
    let s = a.to_document().unwrap();
    let hydrated = Attribute::from_document(&s).unwrap();
    assert_eq!(s.array_copy(), stored(&hydrated));
    assert_eq!(hydrated.side, Some(RelationshipSide::Child));
    assert_eq!(hydrated.relationship.as_ref().unwrap().kind, RelationshipType::ManyToMany);
    assert_eq!(hydrated.relationship.as_ref().unwrap().on_delete, RelationshipDeleteAction::SetNull);
}

#[test]
fn test_relationship_options_may_arrive_as_document() {
    let a = Attribute::from_array(&arr! {
        "key" => "author",
        "type" => "relationship",
        "options" => document! {
            "relatedCollection" => "users",
            "relationType" => "manyToOne",
            "twoWay" => false,
            "twoWayKey" => "posts",
            "onDelete" => "restrict",
            "side" => "child",
        },
    })
    .unwrap();
    assert_eq!(a.relationship.unwrap().related_collection, "users");
    assert_eq!(a.side, Some(RelationshipSide::Child));
}

#[test]
fn test_relationship_without_side_defaults_to_parent() {
    let a = Attribute::from_array(&arr! {
        "key" => "author",
        "type" => "relationship",
        "options" => arr! {
            "relatedCollection" => "users",
            "relationType" => "oneToOne",
            "twoWay" => false,
            "twoWayKey" => "post",
            "onDelete" => "restrict",
        },
    })
    .unwrap();
    assert_eq!(a.side, Some(RelationshipSide::Parent));
}

#[test]
fn test_relationship_without_options_is_rejected() {
    assert_error(
        Attribute::from_array(&arr! {"key" => "author", "type" => "relationship"}),
        Kind::Structure,
        "Relationship attribute \"author\" has no relationship options",
    );
}

#[test]
fn test_relationship_with_unknown_side_is_rejected() {
    assert_error(
        Attribute::from_array(&arr! {
            "key" => "author",
            "type" => "relationship",
            "options" => arr! {"relatedCollection" => "users", "relationType" => "oneToOne", "side" => "sideways"},
        }),
        Kind::Structure,
        "Relationship attribute \"author\" has an unknown side",
    );
}

#[test]
fn test_empty_stored_format_hydrates_as_no_format() {
    let a = Attribute::from_document(&document! {
        "$id" => "$createdAt",
        "type" => "datetime",
        "format" => "",
        "size" => 0,
        "signed" => false,
        "required" => false,
        "default" => (),
        "array" => false,
        "filters" => list!["datetime"],
    })
    .unwrap();
    assert!(a.format.is_none());
    assert_eq!(a.key, "$createdAt");
    assert_eq!(stored_attr(&a, "format"), Value::Null);
    assert_eq!(stored_attr(&a, "formatOptions"), v(list![]));
    assert!(!a.signed);
    assert_eq!(a.filters, strings(&["datetime"]));
}

#[test]
fn test_status_and_generic_options_are_ignored() {
    let a = Attribute::from_document(&document! {
        "$id" => "name",
        "key" => "name",
        "type" => "string",
        "size" => 128,
        "status" => "available",
        "options" => arr! {"precision" => 2},
        "error" => "",
    })
    .unwrap();
    assert_eq!(keys(&stored(&a)), STORED_KEYS);
    assert!(a.relationship.is_none());
    assert!(a.side.is_none());
    assert_eq!(a.size, Some(128));
}

#[test]
fn test_seven_four_internal_attribute_rows_round_trip() {
    let rows = [
        arr! {"$id" => "$id", "type" => "string", "size" => 255, "required" => true, "signed" => true, "array" => false, "filters" => list![]},
        arr! {"$id" => "$sequence", "type" => "id", "size" => 0, "required" => true, "signed" => true, "array" => false, "filters" => list![]},
        arr! {"$id" => "$tenant", "type" => "id", "size" => 0, "required" => false, "default" => (), "signed" => true, "array" => false, "filters" => list![]},
        arr! {"$id" => "$updatedAt", "type" => "datetime", "format" => "", "size" => 0, "signed" => false, "required" => false, "default" => (), "array" => false, "filters" => list!["datetime"]},
        arr! {"$id" => "$permissions", "type" => "string", "size" => 1_000_000, "signed" => true, "required" => false, "default" => list![], "array" => false, "filters" => list!["json"]},
    ];
    for row in rows {
        let s = Attribute::from_array(&row).unwrap().to_document().unwrap();
        for (k, value) in row.iter() {
            let k = k.to_owned_str();
            let got = s.raw(&k).cloned().unwrap_or(Value::Null);
            if k == "format" {
                assert_eq!(got, Value::Null, "An empty stored format persists as null");
                continue;
            }
            assert_eq!(&got, value, "Stored '{k}' changed in the round trip");
        }
    }
}

#[test]
fn test_missing_stored_fields_take_their_defaults() {
    let a = Attribute::from_array(&arr! {"$id" => "name", "type" => "string"}).unwrap();
    assert_eq!(a.key, "name");
    assert_eq!(a.size, None);
    assert!(!a.required);
    assert_eq!(a.default, Value::Null);
    assert!(a.signed);
    assert!(!a.array);
    assert!(a.format.is_none());
    assert!(a.filters.is_empty());
}

#[test]
fn test_key_is_preferred_over_id() {
    assert_eq!(Attribute::from_array(&arr! {"$id" => "id", "key" => "key", "type" => "string"}).unwrap().key, "key");
    assert_eq!(
        Attribute::from_document(&document! {"$id" => "id", "key" => "key", "type" => "string"}).unwrap().key,
        "key"
    );
}

#[test]
fn test_format_and_options_hydrate() {
    let a = Attribute::from_array(&arr! {
        "key" => "range",
        "type" => "integer",
        "format" => "intRange",
        "formatOptions" => arr! {"min" => 1, "max" => 10},
    })
    .unwrap();
    let f = a.format.unwrap();
    assert_eq!(f.name, "intRange");
    assert_eq!(f.options, arr! {"min" => 1, "max" => 10});
}

#[test]
fn test_format_options_may_arrive_as_document() {
    let a = Attribute::from_document(&document! {
        "$id" => "range",
        "type" => "integer",
        "format" => "intRange",
        "formatOptions" => document! {"min" => 1},
    })
    .unwrap();
    assert_eq!(a.format.unwrap().options, arr! {"min" => 1});
}

#[test]
fn test_stored_size_hydrates_and_numeric_strings_are_accepted() {
    assert_eq!(Attribute::from_array(&arr! {"key" => "count", "type" => "integer", "size" => 8}).unwrap().size, Some(8));
    assert_eq!(
        Attribute::from_array(&arr! {"key" => "count", "type" => "integer", "size" => "8"}).unwrap().integer_width(),
        Some(IntegerWidth::Bits64)
    );
}

fn factories() -> Vec<Attribute> {
    vec![
        Attribute::string("a", 10)
            .required(true)
            .default("x")
            .array(true)
            .format(format("email", arr! {"strict" => true}))
            .filters(["lowercase"]),
        Attribute::varchar("a", 20),
        Attribute::text("a"),
        Attribute::medium_text("a").size(Some(300)),
        Attribute::long_text("a"),
        Attribute::integer("a").default(3),
        Attribute::integer("a").signed(false).width(IntegerWidth::Bits64),
        Attribute::big_integer("a").default("42"),
        Attribute::float("a").default(1.25),
        Attribute::double("a").array(true).default(list![1.5]),
        Attribute::boolean("a").default(true),
        Attribute::datetime("a").required(true),
        Attribute::point("a").default(list![0.0, 0.0]),
        Attribute::line_string("a"),
        Attribute::polygon("a"),
        Attribute::vector("a", 3),
        Attribute::object("a"),
        Attribute::id("a").default(7),
    ]
}

#[test]
fn test_factory_output_round_trips_through_storage() {
    for a in factories() {
        let s = a.to_document().unwrap();
        assert_eq!(keys(&s.array_copy()), STORED_KEYS);
        let from_document = Attribute::from_document(&s).unwrap();
        let from_array = Attribute::from_array(&s.array_copy()).unwrap();
        assert_eq!(s.array_copy(), stored(&from_document));
        assert_eq!(s.array_copy(), stored(&from_array));
        assert_eq!(a.kind, from_document.kind);
        assert_eq!(a.size, from_document.size);
        assert_eq!(a.format.map(|f| f.name), from_document.format.map(|f| f.name));
    }
}

#[test]
fn test_types_outside_the_attribute_types_are_rejected() {
    for (kind, message) in [
        (v("tuple"), "Unknown attribute type: tuple"),
        (v("uuid7"), "Unknown attribute type: uuid7"),
        (v("json"), "Unknown attribute type: json"),
        (v("geometry"), "Unknown attribute type: geometry"),
        (v(""), "Unknown attribute type: "),
        (Value::Null, "Attribute type must be a string, null given"),
        (v(42), "Attribute type must be a string, int given"),
    ] {
        assert_error(Attribute::from_array(&arr! {"key" => "field", "type" => kind}), Kind::Structure, message);
    }
}

#[test]
fn test_from_document_rejects_types_outside_the_attribute_types() {
    assert_error(
        Attribute::from_document(&document! {"$id" => "field", "type" => "tuple"}),
        Kind::Structure,
        "Unknown attribute type: tuple",
    );
}

#[test]
fn test_from_array_rejects_tuple_without_any_other_field() {
    assert_error(Attribute::from_array(&arr! {"type" => "tuple"}), Kind::Structure, "Unknown attribute type: tuple");
}

// AttributeLegacyHydrationTest

fn seven_four(stored: Array) -> Array {
    let mut base = arr! {
        "$id" => "field",
        "key" => "field",
        "size" => 0,
        "required" => false,
        "default" => (),
        "signed" => true,
        "array" => false,
        "format" => "",
        "formatOptions" => list![],
        "filters" => list![],
    };
    for (k, value) in stored {
        base.set(k, value);
    }
    base
}

fn legacy_shapes() -> Vec<(&'static str, Array, Attribute)> {
    vec![
        (
            "signed datetime",
            seven_four(arr! {"type" => "datetime", "filters" => list!["datetime"]}),
            Attribute::datetime("field"),
        ),
        (
            "signed datetime array",
            seven_four(arr! {"type" => "datetime", "array" => true, "filters" => list!["datetime"]}),
            Attribute::datetime("field").array(true),
        ),
        ("datetime without its filter", seven_four(arr! {"type" => "datetime"}), Attribute::datetime("field")),
        ("sizeless signed integer", seven_four(arr! {"type" => "integer"}), Attribute::integer("field")),
        ("32-bit signed integer", seven_four(arr! {"type" => "integer", "size" => 4}), Attribute::integer("field")),
        (
            "32-bit unsigned integer",
            seven_four(arr! {"type" => "integer", "size" => 4, "signed" => false}),
            Attribute::integer("field").signed(false),
        ),
        (
            "64-bit signed integer",
            seven_four(arr! {"type" => "integer", "size" => 8}),
            Attribute::integer("field").width(IntegerWidth::Bits64),
        ),
        (
            "64-bit unsigned integer",
            seven_four(arr! {"type" => "integer", "size" => 8, "signed" => false}),
            Attribute::integer("field").signed(false).width(IntegerWidth::Bits64),
        ),
        (
            "oversized integer",
            seven_four(arr! {"type" => "integer", "size" => 16}),
            Attribute::integer("field").width(IntegerWidth::Bits64),
        ),
        (
            "unsigned bigint",
            seven_four(arr! {"type" => "bigint", "signed" => false}),
            Attribute::big_integer("field").signed(false),
        ),
        ("string", seven_four(arr! {"type" => "string", "size" => 255}), Attribute::string("field", 255)),
        (
            "unsigned string",
            seven_four(arr! {"type" => "string", "size" => 255, "signed" => false}),
            Attribute::string("field", 255),
        ),
        (
            "string array",
            seven_four(arr! {"type" => "string", "size" => 64, "array" => true}),
            Attribute::string("field", 64).array(true),
        ),
        ("sizeless text", seven_four(arr! {"type" => "text"}), Attribute::text("field")),
        ("varchar", seven_four(arr! {"type" => "varchar", "size" => 128}), Attribute::varchar("field", 128)),
        ("signed double", seven_four(arr! {"type" => "double"}), Attribute::double("field")),
        (
            "unsigned double",
            seven_four(arr! {"type" => "double", "signed" => false}),
            Attribute::double("field").signed(false),
        ),
        (
            "unsigned float",
            seven_four(arr! {"type" => "float", "signed" => false}),
            Attribute::float("field").signed(false),
        ),
        ("boolean", seven_four(arr! {"type" => "boolean"}), Attribute::boolean("field")),
        ("unsigned boolean", seven_four(arr! {"type" => "boolean", "signed" => false}), Attribute::boolean("field")),
        ("id", seven_four(arr! {"type" => "id", "signed" => false}), Attribute::id("field")),
        ("point", seven_four(arr! {"type" => "point", "filters" => list!["point"]}), Attribute::point("field")),
        (
            "unsigned point",
            seven_four(arr! {"type" => "point", "signed" => false, "filters" => list!["point"]}),
            Attribute::point("field"),
        ),
        (
            "linestring",
            seven_four(arr! {"type" => "linestring", "filters" => list!["linestring"]}),
            Attribute::line_string("field"),
        ),
        ("polygon", seven_four(arr! {"type" => "polygon", "filters" => list!["polygon"]}), Attribute::polygon("field")),
        (
            "vector",
            seven_four(arr! {"type" => "vector", "size" => 3, "filters" => list!["vector"]}),
            Attribute::vector("field", 3),
        ),
        ("object", seven_four(arr! {"type" => "object", "filters" => list!["object"]}), Attribute::object("field")),
        (
            "format",
            seven_four(arr! {
                "type" => "string",
                "size" => 64,
                "format" => "enum",
                "formatOptions" => arr! {"elements" => list!["a", "b"]},
            }),
            Attribute::string("field", 64).format(format("enum", arr! {"elements" => list!["a", "b"]})),
        ),
        (
            "relationship",
            arr! {
                "$id" => "field",
                "key" => "field",
                "type" => "relationship",
                "required" => false,
                "default" => (),
                "options" => arr! {
                    "relatedCollection" => "comments",
                    "relationType" => "oneToMany",
                    "twoWay" => true,
                    "twoWayKey" => "post",
                    "onDelete" => "cascade",
                    "side" => "parent",
                },
            },
            Attribute::relationship(
                "field",
                Relationship::one_to_many("comments")
                    .two_way(true)
                    .two_way_key("post")
                    .on_delete(RelationshipDeleteAction::Cascade),
                RelationshipSide::Parent,
            )
            .unwrap(),
        ),
        (
            "unsigned relationship",
            arr! {
                "$id" => "field",
                "key" => "field",
                "type" => "relationship",
                "size" => 0,
                "signed" => false,
                "array" => false,
                "filters" => list![],
                "options" => arr! {
                    "relatedCollection" => "users",
                    "relationType" => "manyToOne",
                    "twoWay" => false,
                    "twoWayKey" => "posts",
                    "onDelete" => "restrict",
                    "side" => "child",
                },
            },
            Attribute::relationship("field", Relationship::many_to_one("users").two_way_key("posts"), RelationshipSide::Child)
                .unwrap(),
        ),
    ]
}

#[test]
fn test_legacy_stored_shape_hydrates_as_the_eight_zero_attribute() {
    for (name, row, created) in legacy_shapes() {
        let hydrated = Attribute::from_document(&Document::new(row).unwrap()).unwrap();
        assert_eq!(created, hydrated, "{name}");
        assert_eq!(stored(&created), stored(&hydrated), "{name}");
    }
}

#[test]
fn test_legacy_stored_shape_hydrates_already_normalised() {
    for (name, row, created) in legacy_shapes() {
        let hydrated = Attribute::from_array(&row).unwrap();
        assert_eq!(hydrated.apply(&Update::default()).unwrap(), hydrated, "{name}");
        assert_eq!(created, hydrated, "{name}");
    }
}

#[test]
fn test_hydration_keeps_a_type_filter_in_its_stored_position() {
    let a = Attribute::from_document(
        &Document::new(seven_four(arr! {"type" => "datetime", "filters" => list!["custom", "datetime"]})).unwrap(),
    )
    .unwrap();
    assert_eq!(a.filters, strings(&["custom", "datetime"]));
}

#[test]
fn test_hydration_prepends_a_missing_type_filter_ahead_of_custom_filters() {
    let a = Attribute::from_document(&Document::new(seven_four(arr! {"type" => "point", "filters" => list!["custom"]})).unwrap())
        .unwrap();
    assert_eq!(a.filters, strings(&["point", "custom"]));
}

// AttributeUpdateTest

#[test]
fn test_defaults_leave_everything_unchanged() {
    let update = Update::default();
    assert!(update.is_empty());
    assert!(!update.changes_default());
    assert!(!update.changes_format());
    assert_eq!(update.default, Patch::Unchanged);
    assert_eq!(update.format, Patch::Unchanged);
}

#[test]
fn test_null_default_is_a_change() {
    let update = Update { default: Patch::Set(Value::Null), ..Default::default() };
    assert!(update.changes_default());
    assert!(!update.is_empty());
}

#[test]
fn test_any_single_field_makes_the_update_non_empty() {
    let updates = [
        Update { kind: Some(ColumnType::Text), ..Default::default() },
        Update { size: Some(10), ..Default::default() },
        Update { required: Some(false), ..Default::default() },
        Update { default: Patch::Set(v("x")), ..Default::default() },
        Update { signed: Some(false), ..Default::default() },
        Update { array: Some(false), ..Default::default() },
        Update { format: Patch::Set(Some(format("email", Array::new()))), ..Default::default() },
        Update { format: Patch::Set(None), ..Default::default() },
        Update { filters: Some(vec![]), ..Default::default() },
        Update { key: Some("renamed".into()), ..Default::default() },
    ];
    for update in updates {
        assert!(!update.is_empty(), "{update:?}");
    }
}

#[test]
fn test_empty_update_keeps_every_field() {
    let original = Attribute::string("name", 64)
        .required(true)
        .default("x")
        .array(true)
        .format(format("email", arr! {"a" => 1}))
        .filters(["lowercase"]);
    let updated = original.apply(&Update::default()).unwrap();
    assert_eq!(stored(&original), stored(&updated));
}

#[test]
fn test_apply_changes_only_the_given_fields() {
    let original = Attribute::string("name", 64).required(true).default("x").filters(["lowercase"]);
    let updated = original.apply(&Update { size: Some(128), required: Some(false), ..Default::default() }).unwrap();
    assert_eq!(updated.size, Some(128));
    assert!(!updated.required);
    assert_eq!(updated.default, v("x"));
    assert_eq!(updated.filters, strings(&["lowercase"]));
    assert_eq!(updated.key, "name");
    assert_eq!(updated.kind, ColumnType::String);
}

#[test]
fn test_zero_size_clears_the_size() {
    let a = Attribute::text("body").size(Some(500)).apply(&Update { size: Some(0), ..Default::default() }).unwrap();
    assert_eq!(a.size, None);
}

#[test]
fn test_apply_leaves_the_original_untouched() {
    let original = Attribute::string("name", 64);
    let _ = original.apply(&Update {
        size: Some(128),
        key: Some("title".into()),
        default: Patch::Set(v("y")),
        ..Default::default()
    });
    assert_eq!(original.size, Some(64));
    assert_eq!(original.key, "name");
    assert_eq!(original.default, Value::Null);
}

#[test]
fn test_null_default_clears_the_default() {
    let a = Attribute::integer("count").default(5).apply(&Update { default: Patch::Set(Value::Null), ..Default::default() });
    assert_eq!(a.unwrap().default, Value::Null);
}

#[test]
fn test_default_can_be_set() {
    let a = Attribute::integer("count").apply(&Update { default: Patch::Set(v(7)), ..Default::default() }).unwrap();
    assert_eq!(a.default, v(7));
}

#[test]
fn test_key_renames() {
    let a = Attribute::string("name", LENGTH_KEY).apply(&Update { key: Some("title".into()), ..Default::default() });
    assert_eq!(a.unwrap().key, "title");
}

#[test]
fn test_filters_accept_enum_cases_and_strings() {
    let a = Attribute::string("name", LENGTH_KEY)
        .apply(&Update { filters: Some(vec![Filter::Json.into(), "encrypt".into()]), ..Default::default() })
        .unwrap();
    assert_eq!(a.filters, strings(&["json", "encrypt"]));
}

#[test]
fn test_empty_filters_clear_the_filters() {
    let a = Attribute::string("name", LENGTH_KEY)
        .filters(["lowercase"])
        .apply(&Update { filters: Some(vec![]), ..Default::default() })
        .unwrap();
    assert!(a.filters.is_empty());
}

#[test]
fn test_clearing_filters_keeps_the_type_filter() {
    let a = Attribute::datetime("at").apply(&Update { filters: Some(vec![]), ..Default::default() }).unwrap();
    assert_eq!(a.filters, strings(&["datetime"]));
    let a = Attribute::point("at").apply(&Update { filters: Some(vec!["encrypt".into()]), ..Default::default() }).unwrap();
    assert_eq!(a.filters, strings(&["point", "encrypt"]));
}

#[test]
fn test_format_replaces_the_format() {
    let a = Attribute::string("name", LENGTH_KEY)
        .format(format("email", Array::new()))
        .apply(&Update { format: Patch::Set(Some(format("url", arr! {"scheme" => "https"}))), ..Default::default() })
        .unwrap();
    let f = a.format.unwrap();
    assert_eq!(f.name, "url");
    assert_eq!(f.options, arr! {"scheme" => "https"});
}

#[test]
fn test_null_format_is_a_change() {
    let update = Update { format: Patch::Set(None), ..Default::default() };
    assert!(update.changes_format());
    assert!(!update.is_empty());
}

#[test]
fn test_null_format_removes_the_format() {
    let a = Attribute::string("name", LENGTH_KEY)
        .format(format("email", arr! {"strict" => true}))
        .apply(&Update { format: Patch::Set(None), ..Default::default() })
        .unwrap();
    assert!(a.format.is_none());
    assert_eq!(stored_attr(&a, "format"), Value::Null);
    assert_eq!(stored_attr(&a, "formatOptions"), v(list![]));
}

#[test]
fn test_omitted_format_keeps_the_format() {
    let f = format("email", arr! {"strict" => true});
    let a = Attribute::string("name", LENGTH_KEY)
        .format(f.clone())
        .apply(&Update { size: Some(32), ..Default::default() })
        .unwrap();
    assert_eq!(a.format, Some(f));
}

#[test]
fn test_signed_and_array_change() {
    let a = Attribute::integer("count")
        .apply(&Update { signed: Some(false), array: Some(true), ..Default::default() })
        .unwrap();
    assert!(!a.signed);
    assert!(a.array);
}

#[test]
fn test_type_changes_to_another_attribute_type() {
    let a = Attribute::string("body", 100).apply(&Update { kind: Some(ColumnType::Text), ..Default::default() }).unwrap();
    assert_eq!(a.kind, ColumnType::Text);
    assert_eq!(stored_attr(&a, "type"), v("text"));
    assert_eq!(a.size, Some(100));
}

#[test]
fn test_type_change_produces_what_the_target_factory_produces() {
    let string = Attribute::string("field", 64);
    let integer = Attribute::integer("field").signed(false).array(true).width(IntegerWidth::Bits64);
    let datetime = Attribute::datetime("field").array(true);
    let point = Attribute::point("field");
    let cases = [
        (&string, ColumnType::Varchar, Attribute::varchar("field", 64)),
        (&string, ColumnType::Text, Attribute::text("field").size(Some(64))),
        (&string, ColumnType::MediumText, Attribute::medium_text("field").size(Some(64))),
        (&string, ColumnType::LongText, Attribute::long_text("field").size(Some(64))),
        (&string, ColumnType::Integer, Attribute::integer("field").width(IntegerWidth::Bits64)),
        (&string, ColumnType::BigInteger, Attribute::big_integer("field")),
        (&string, ColumnType::Float, Attribute::float("field")),
        (&string, ColumnType::Double, Attribute::double("field")),
        (&string, ColumnType::Boolean, Attribute::boolean("field")),
        (&string, ColumnType::Datetime, Attribute::datetime("field")),
        (&string, ColumnType::Id, Attribute::id("field")),
        (&string, ColumnType::Point, Attribute::point("field")),
        (&string, ColumnType::Linestring, Attribute::line_string("field")),
        (&string, ColumnType::Polygon, Attribute::polygon("field")),
        (&string, ColumnType::Object, Attribute::object("field")),
        (&string, ColumnType::Vector, Attribute::vector("field", 64)),
        (&integer, ColumnType::String, Attribute::string("field", 8).array(true)),
        (&Attribute::integer("field").signed(false), ColumnType::BigInteger, Attribute::big_integer("field").signed(false)),
        (&integer, ColumnType::Double, Attribute::double("field").signed(false).array(true)),
        (&integer, ColumnType::Boolean, Attribute::boolean("field").array(true)),
        (&integer, ColumnType::Id, Attribute::id("field").array(true)),
        (&integer, ColumnType::Datetime, Attribute::datetime("field").array(true)),
        (&integer, ColumnType::Point, Attribute::point("field")),
        (&integer, ColumnType::Vector, Attribute::vector("field", 8)),
        (&datetime, ColumnType::Integer, Attribute::integer("field").signed(false).array(true)),
        (&datetime, ColumnType::String, Attribute::string("field", 0).array(true)),
        (&point, ColumnType::Text, Attribute::text("field")),
        (&point, ColumnType::Polygon, Attribute::polygon("field")),
    ];
    for (source, kind, expected) in cases {
        let updated = source.apply(&Update { kind: Some(kind), ..Default::default() }).unwrap();
        assert_eq!(stored(&expected), stored(&updated), "{:?} to {kind:?}", source.kind);
    }
}

#[test]
fn test_type_change_keeps_user_filters_and_swaps_the_type_filter() {
    let a = Attribute::string("field", LENGTH_KEY)
        .filters(["encrypt"])
        .apply(&Update { kind: Some(ColumnType::Datetime), ..Default::default() })
        .unwrap()
        .apply(&Update { kind: Some(ColumnType::Polygon), ..Default::default() })
        .unwrap();
    assert_eq!(a.filters, strings(&["polygon", "encrypt"]));
}

#[test]
fn test_explicit_filters_on_a_type_change_are_kept() {
    let a = Attribute::datetime("field")
        .apply(&Update { kind: Some(ColumnType::String), filters: Some(vec!["datetime".into()]), ..Default::default() })
        .unwrap();
    assert_eq!(a.filters, strings(&["datetime"]));
}

#[test]
fn test_updates_cannot_break_the_type_invariants() {
    let up = |a: Attribute, u: Update| a.apply(&u).unwrap();
    assert!(up(Attribute::string("name", LENGTH_KEY), Update { signed: Some(false), ..Default::default() }).signed);
    assert!(!up(Attribute::datetime("at"), Update { signed: Some(true), ..Default::default() }).signed);
    assert!(!up(Attribute::point("at"), Update { array: Some(true), ..Default::default() }).array);
    assert_eq!(up(Attribute::boolean("flag"), Update { size: Some(4), ..Default::default() }).size, None);
    assert_eq!(up(Attribute::integer("count"), Update { size: Some(20), ..Default::default() }).size, Some(8));
    assert_eq!(
        up(Attribute::integer("count").width(IntegerWidth::Bits64), Update { size: Some(4), ..Default::default() }).size,
        None
    );
}

#[test]
fn test_type_outside_the_attribute_types_is_rejected() {
    assert_error(
        Attribute::string("body", LENGTH_KEY).apply(&Update { kind: Some(ColumnType::Tuple), ..Default::default() }),
        Kind::Structure,
        "Unknown attribute type: tuple",
    );
}

#[test]
fn test_type_cannot_become_relationship() {
    assert_error(
        Attribute::string("author", LENGTH_KEY).apply(&Update { kind: Some(ColumnType::Relationship), ..Default::default() }),
        Kind::Structure,
        "A relationship attribute cannot change type; use updateRelationship()",
    );
}

#[test]
fn test_same_type_is_accepted() {
    let a = Attribute::integer("count").apply(&Update { kind: Some(ColumnType::Integer), ..Default::default() });
    assert_eq!(a.unwrap().kind, ColumnType::Integer);
}

#[test]
fn test_relationship_attribute_cannot_change_type() {
    let a = Attribute::relationship("author", Relationship::many_to_one("users"), RelationshipSide::Parent).unwrap();
    assert_error(
        a.apply(&Update { kind: Some(ColumnType::String), ..Default::default() }),
        Kind::Structure,
        "A relationship attribute cannot change type; use updateRelationship()",
    );
}

#[test]
fn test_renaming_a_relationship_attribute_renames_its_relationship() {
    let a = Attribute::relationship("author", Relationship::many_to_one("users"), RelationshipSide::Parent).unwrap();
    let renamed = a.apply(&Update { key: Some("writer".into()), ..Default::default() }).unwrap();
    assert_eq!(renamed.key, "writer");
    assert_eq!(renamed.relationship.as_ref().unwrap().key.as_deref(), Some("writer"));
    assert_eq!(renamed.relationship.as_ref().unwrap().related_collection, "users");
    assert_eq!(renamed.side, Some(RelationshipSide::Parent));
}

// FormatTest

#[test]
fn test_holds_name_and_options() {
    let f = format("intRange", arr! {"min" => 1, "max" => 10});
    assert_eq!(f.name, "intRange");
    assert_eq!(f.options, arr! {"min" => 1, "max" => 10});
}

#[test]
fn test_options_default_to_empty() {
    assert_eq!(format("email", Array::new()).options, Array::new());
}

#[test]
fn test_empty_name_is_rejected() {
    assert_error(Format::new("", Array::new()), Kind::Structure, "Format name must not be empty");
}

#[test]
fn test_persists_as_format_and_format_options() {
    let s = Attribute::integer("range").format(format("intRange", arr! {"min" => 1})).to_document().unwrap();
    assert_eq!(s.get("format"), Some(&v("intRange")));
    assert_eq!(s.get("formatOptions"), Some(&v(arr! {"min" => 1})));
}

#[test]
fn test_no_format_persists_as_null_with_empty_options() {
    let s = Attribute::string("name", LENGTH_KEY).to_document().unwrap();
    assert_eq!(s.get("format"), None);
    assert_eq!(s.get("formatOptions"), Some(&v(list![])));
}

#[test]
fn test_options_without_a_format_name_are_dropped() {
    let a = Attribute::from_array(
        &arr! {"key" => "name", "type" => "string", "format" => (), "formatOptions" => arr! {"min" => 1}},
    )
    .unwrap();
    assert!(a.format.is_none());
    assert_eq!(stored_attr(&a, "formatOptions"), v(list![]));
}

#[test]
fn test_non_array_options_hydrate_as_empty() {
    let a = Attribute::from_array(
        &arr! {"key" => "name", "type" => "string", "format" => "email", "formatOptions" => "invalid"},
    )
    .unwrap();
    let f = a.format.unwrap();
    assert_eq!(f.name, "email");
    assert_eq!(f.options, Array::new());
}

// IntegerWidthTest

#[test]
fn test_sizes() {
    assert_eq!(IntegerWidth::Bits32.size(), None);
    assert_eq!(IntegerWidth::Bits64.size(), Some(8));
}

#[test]
fn test_engines_only_distinguish_sizes_of_eight_and_above() {
    for (size, width) in [
        (None, IntegerWidth::Bits32),
        (Some(0), IntegerWidth::Bits32),
        (Some(4), IntegerWidth::Bits32),
        (Some(7), IntegerWidth::Bits32),
        (Some(8), IntegerWidth::Bits64),
        (Some(16), IntegerWidth::Bits64),
    ] {
        assert_eq!(IntegerWidth::from_size(size), width);
        let a = Attribute::from_array(&arr! {"key" => "count", "type" => "integer", "size" => size}).unwrap();
        assert_eq!(a.integer_width(), Some(width));
    }
}

#[test]
fn test_each_width_round_trips_through_its_size() {
    for width in [IntegerWidth::Bits32, IntegerWidth::Bits64] {
        assert_eq!(IntegerWidth::from_size(width.size()), width);
    }
}

#[test]
fn test_integer_factory_width_survives_storage() {
    for width in [IntegerWidth::Bits32, IntegerWidth::Bits64] {
        let s = Attribute::integer("count").width(width).to_document().unwrap();
        assert_eq!(s.get("size"), Some(&v(width.size().unwrap_or(0))));
        assert_eq!(Attribute::from_document(&s).unwrap().integer_width(), Some(width));
    }
}
