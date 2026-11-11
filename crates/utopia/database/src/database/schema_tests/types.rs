//! PHP: `ObjectAttribute\ObjectAttributeValidationTest`,
//! `Spatial\SpatialValidationTest`, `Vector\VectorValidationTest` and
//! `Schemaless\SchemalessValidationTest`, on stubs serving the collection
//! definitions as the PHP tests do.

use utopia_query::OrderDirection;
use utopia_query::schema::{ColumnType, IndexType};

use super::{Fixture, definition};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::database::testing::{block_on, limits};
use crate::document::{self, Document};
use crate::error::Kind;
use crate::index::Index;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::value::{Array, ArrayKey, Str, Value};

fn s(v: &str) -> Value {
    Value::Str(Str::from(v))
}

fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

fn assoc(pairs: Vec<(&str, Value)>) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
}

fn floats(items: &[f64]) -> Value {
    list(items.iter().map(|f| Value::Float(*f)).collect())
}

/// Floats as a query's values.
fn query_floats(items: &[f64]) -> utopia_query::Array {
    utopia_query::Array::from_list(items.iter().map(|f| utopia_query::Value::Float(*f)).collect())
}

/// A document readable by anyone with the given attributes.
fn readable(pairs: Vec<(&str, Value)>) -> Document {
    let mut d = Document::empty();
    d.set(document::PERMISSIONS, list(vec![s(&Permission::read(&Role::any()))])).unwrap();
    for (k, v) in pairs {
        d.set(k, v).unwrap();
    }
    d
}

fn fixture(id: &str, attributes: Vec<Attribute>, capabilities: &[Capability]) -> Fixture {
    let f = Fixture::new(vec![definition(id, attributes, Vec::new())]);
    for capability in capabilities {
        f.adapter.support(*capability);
    }
    f
}

// ---- ObjectAttributeValidationTest -------------------------------------

#[test]
fn object_attributes_refuse_scalars() {
    block_on(async {
        let f = fixture("objInvalid", vec![Attribute::object("meta")], &[Capability::Objects]);
        for value in [s("this is a string not an object"), Value::Int(12345), Value::Bool(true)] {
            let error = f.db.create_document("objInvalid", readable(vec![("meta", value.clone())])).await.unwrap_err();
            assert!(error.is_a(Kind::Structure), "{value:?}: {error:?}");
        }
    });
}

#[test]
fn object_attributes_take_their_defaults() {
    block_on(async {
        let attributes = vec![
            Attribute::object("metaDefaultEmpty").default(list(Vec::new())),
            Attribute::object("settings")
                .default(assoc(vec![("config", assoc(vec![("theme", s("light")), ("lang", s("en"))]))])),
            Attribute::object("profile").required(true),
            Attribute::object("profile2").default(assoc(vec![("name", s("anon"))])),
            Attribute::object("misc"),
        ];
        let f = fixture("objDefaults", attributes, &[Capability::Objects]);
        let error = f.db.create_document("objDefaults", readable(vec![(document::ID, s("def1"))])).await.unwrap_err();
        assert!(error.is_a(Kind::Structure), "{error:?}");

        let created =
            f.db.create_document(
                "objDefaults",
                readable(vec![(document::ID, s("def2")), ("profile", assoc(vec![("name", s("provided"))]))]),
            )
            .await
            .unwrap();
        assert_eq!(created.raw("metaDefaultEmpty"), Some(&list(Vec::new())));
        let settings = created.array("settings");
        let Some(Value::Array(config)) = settings.get_str("config") else { panic!("a config: {settings:?}") };
        assert_eq!(config.get_str("theme"), Some(&s("light")));
        assert_eq!(created.array("profile").get_str("name"), Some(&s("provided")));
        assert_eq!(created.array("profile2").get_str("name"), Some(&s("anon")));
        assert_eq!(created.get("misc"), None);
    });
}

// ---- SpatialValidationTest -----------------------------------------------

#[test]
fn invalid_spatial_values_are_refused() {
    block_on(async {
        let f = fixture(
            "test_invalid_spatial",
            vec![Attribute::point("pointAttr"), Attribute::line_string("lineAttr"), Attribute::polygon("polyAttr")],
            &[],
        );
        for (key, value) in [
            ("pointAttr", floats(&[10.0])),
            ("lineAttr", list(vec![floats(&[10.0, 20.0])])),
            ("polyAttr", floats(&[10.0, 20.0])),
        ] {
            let error = f.db.create_document("test_invalid_spatial", readable(vec![(key, value)])).await.unwrap_err();
            assert!(error.is_a(Kind::Structure), "{key}: {error:?}");
        }
    });
}

#[test]
fn invalid_coordinates_are_refused() {
    block_on(async {
        let f = fixture("test_invalid_coord", vec![Attribute::point("pointAttr").required(true)], &[]);
        let error =
            f.db.create_document(
                "test_invalid_coord",
                readable(vec![(document::ID, s("invalidDoc1")), ("pointAttr", floats(&[200.0, 20.0]))]),
            )
            .await
            .unwrap_err();
        assert!(error.is_a(Kind::Structure), "{error:?}");
    });
}

#[test]
fn a_distance_query_on_a_non_spatial_attribute_is_refused() {
    block_on(async {
        let f = fixture(
            "spatial_distance_error",
            vec![Attribute::string("name", 255).required(true), Attribute::point("loc").required(true)],
            &[],
        );
        let query = Query::distance_less_than("name", query_floats(&[0.0, 0.0]), 1000.0, false);
        let error = f.db.find("spatial_distance_error", vec![query], PermissionType::Read).await.unwrap_err();
        assert!(error.is_a(Kind::Query), "{error:?}");
        assert!(error.message().to_lowercase().contains("spatial"), "{error:?}");
    });
}

#[test]
fn spatial_indexes_take_one_spatial_attribute_and_other_indexes_none() {
    block_on(async {
        let f = fixture(
            "spatial_idx",
            vec![
                Attribute::point("loc").required(true),
                Attribute::point("loc2").required(true),
                Attribute::string("name", 4).required(true),
            ],
            &[],
        );
        let multi = Index::new(
            "idx_multi",
            IndexType::Spatial,
            vec!["loc".into(), "loc2".into()],
            Vec::new(),
            Vec::new(),
            None,
        );
        for index in [
            multi.unwrap(),
            Index::spatial("idx_name_spatial", "name", None).unwrap(),
            Index::key("idx_loc_key", ["loc"], Vec::new(), Vec::new()).unwrap(),
        ] {
            let key = index.key.clone();
            let error = f.db.create_index("spatial_idx", index).await.unwrap_err();
            assert!(error.is_a(Kind::Index), "{key}: {error:?}");
        }
    });
}

// ---- VectorValidationTest ------------------------------------------------

fn vectors(id: &str, extra: Vec<Attribute>) -> Fixture {
    let mut attributes = vec![Attribute::vector("embedding", 3).required(true)];
    attributes.extend(extra);
    fixture(id, attributes, &[Capability::Vectors])
}

#[test]
fn vector_attributes_need_valid_dimensions() {
    block_on(async {
        let f = fixture("vectorError", Vec::new(), &[Capability::Vectors]);
        let key = |k: &str| ArrayKey::Str(Str::from(k));
        let data: Array =
            [(key("key"), s("bad_embedding")), (key("type"), s("vector")), (key("required"), Value::Bool(true))]
                .into_iter()
                .collect();
        let error = f.db.create_attribute("vectorError", Attribute::from_array(&data).unwrap()).await.unwrap_err();
        assert_eq!(error.message(), "Vector dimensions must be a positive integer");
        let error =
            f.db.create_attribute("vectorError", Attribute::vector("huge", 16_001).required(true)).await.unwrap_err();
        assert_eq!(error.message(), "Vector dimensions cannot exceed 16000");
    });
}

#[test]
fn vector_queries_are_validated() {
    block_on(async {
        let f = vectors("vectorValidation", vec![Attribute::string("text", 255).required(true)]);
        let find = |query: Query| f.db.find("vectorValidation", vec![query], PermissionType::Read);
        let error = find(Query::vector_dot("text", query_floats(&[1.0, 0.0, 0.0]))).await.unwrap_err();
        assert!(error.message().to_lowercase().contains("vector"), "{error:?}");
        let error = find(Query::vector_cosine("embedding", query_floats(&[1.0, 0.0]))).await.unwrap_err();
        assert!(error.message().to_lowercase().contains("elements"), "{error:?}");
    });
}

#[test]
fn vector_values_must_be_lists_of_numbers_of_the_dimensions() {
    block_on(async {
        let f = vectors("vectorValues", Vec::new());
        let create = |value: Value| f.db.create_document("vectorValues", readable(vec![("embedding", value)]));
        let error = create(floats(&[1.0, 0.0])).await.unwrap_err();
        assert!(error.is_a(Kind::Database), "{error:?}");
        let mut sparse = Array::new();
        sparse.set(ArrayKey::Int(0), Value::Float(1.0));
        sparse.set(ArrayKey::Int(2), Value::Float(1.0));
        for value in [
            list(vec![s("one"), s("two"), s("three")]),
            list(vec![Value::Float(1.0), s("two"), Value::Float(3.0)]),
            assoc(vec![("x", Value::Float(1.0)), ("y", Value::Float(0.0)), ("z", Value::Float(0.0))]),
            Value::Array(sparse),
            list(vec![floats(&[1.0]), floats(&[0.0]), floats(&[0.0])]),
            list(vec![Value::Bool(true), Value::Bool(false), Value::Bool(true)]),
            list(vec![s("1.0"), s("2.0"), s("3.0")]),
            list(vec![Value::Float(1.0), Value::Null, Value::Float(0.0)]),
        ] {
            let error = create(value.clone()).await.unwrap_err();
            assert!(error.message().to_lowercase().contains("numeric"), "{value:?}: {error:?}");
        }
        let error = create(Value::Null).await.unwrap_err();
        assert!(error.is_a(Kind::Database), "{error:?}");
    });
}

#[test]
fn valid_vectors_are_stored() {
    block_on(async {
        let f = vectors("vectorOk", vec![Attribute::integer("score")]);
        for (embedding, permission) in [
            (floats(&[0.0, 0.0, 0.0]), Permission::read(&Role::any())),
            (floats(&[1.0, 0.0, 0.0]), Permission::read(&Role::user("user1", ""))),
        ] {
            let mut d = readable(vec![("embedding", embedding), ("score", Value::Int(4))]);
            d.set(document::PERMISSIONS, list(vec![s(&permission)])).unwrap();
            assert!(!f.db.create_document("vectorOk", d).await.unwrap().id().is_empty());
        }
    });
}

#[test]
fn a_vector_index_on_a_non_vector_attribute_is_refused() {
    block_on(async {
        let f = vectors("vectorIdxFail", vec![Attribute::string("text", 255).required(true)]);
        let error = f.db.create_index("vectorIdxFail", Index::hnsw_cosine("bad_idx", "text")).await.unwrap_err();
        assert!(error.message().to_lowercase().contains("vector"), "{error:?}");
    });
}

// ---- SchemalessValidationTest --------------------------------------------

fn schemaless(id: &str) -> Fixture {
    let f = Fixture::new(vec![definition(id, Vec::new(), Vec::new())]);
    f.adapter.set_capabilities(&[Capability::IndexKey, Capability::IndexTtl]);
    f.adapter.set_limits(crate::adapter::Limits { id_type: ColumnType::Integer, ..limits() });
    f
}

#[test]
fn schemaless_documents_still_validate_their_internal_attributes() {
    // PHP's documents refuse these when built (`new Document()`), before
    // `createDocuments()` sees them.
    let build = |key: &str, value: Value| {
        let data: Array = [(ArrayKey::Str(Str::from(key)), value), (ArrayKey::Str(Str::from("freeA")), s("doc1"))]
            .into_iter()
            .collect();
        Document::new(data)
    };
    let error = build(document::ID, Value::Bool(true)).unwrap_err();
    assert_eq!((error.kind(), error.message()), (Kind::Structure, "$id must be of type string"));
    let error = build(document::PERMISSIONS, Value::Int(12)).unwrap_err();
    assert_eq!((error.kind(), error.message()), (Kind::Structure, "$permissions must be of type array"));
}

#[test]
fn schemaless_indexes_refuse_duplicates_and_a_second_ttl_index() {
    block_on(async {
        let f = schemaless("sl_idx_dup");
        f.db.create_document("sl_idx_dup", readable(vec![(document::ID, s("a")), ("name", s("x"))])).await.unwrap();
        let index = || Index::key("duplicate", ["name"], vec![Some(0)], vec![Some(OrderDirection::Asc)]).unwrap();
        assert_eq!(f.db.create_index("sl_idx_dup", index()).await.unwrap().key, "duplicate");
        assert!(f.db.create_index("sl_idx_dup", index()).await.unwrap_err().is_a(Kind::Duplicate));

        assert_eq!(
            f.db.create_index("sl_idx_dup", Index::ttl("idx_ttl_expires", "expiresAt", 3600).unwrap())
                .await
                .unwrap()
                .key,
            "idx_ttl_expires"
        );
        let error =
            f.db.create_index("sl_idx_dup", Index::ttl("idx_ttl_expires_duplicate", "expiresAt", 7200).unwrap())
                .await
                .unwrap_err();
        assert!(error.message().contains("There can be only one TTL index in a collection"), "{error:?}");
    });
}

#[test]
fn schemaless_documents_carry_their_internal_attributes() {
    block_on(async {
        let f = schemaless("sl_internal");
        let any = Role::any();
        let mut d = readable(vec![(document::ID, s("i1")), ("name", s("alpha"))]);
        let permissions =
            [Permission::read(&any), Permission::create(&any), Permission::update(&any), Permission::delete(&any)];
        d.set(document::PERMISSIONS, list(permissions.iter().map(|p| s(p)).collect())).unwrap();
        let created = f.db.create_document("sl_internal", d).await.unwrap();
        assert_eq!(created.id(), "i1");
        assert_eq!(created.collection(), "sl_internal");
        assert!(created.created_at().is_some_and(|c| !c.is_empty()));
        assert!(created.updated_at().is_some_and(|c| !c.is_empty()));
        let stored = created.permissions().unwrap();
        for permission in [Permission::read(&any), Permission::update(&any), Permission::delete(&any)] {
            assert!(stored.contains(&permission), "{stored:?}");
        }
    });
}
