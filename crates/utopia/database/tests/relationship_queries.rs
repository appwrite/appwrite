//! Ported from utopia-php/database 8.2.0 and 8.3.0: relationship queries
//! (`tests/unit/QueryTest.php`'s relationship cases,
//! `tests/unit/Relationship/PageTest.php`,
//! `tests/unit/Validator/Query/RelationshipTest.php`,
//! `tests/unit/Validator/QueriesTest.php`'s relationship cases), and the
//! nested paths and orders 8.3.0 refuses
//! (`tests/unit/Validator/Query/FilterTest.php`,
//! `Validator/Query/SelectTest.php`, `RelationshipOrderTest.php`'s
//! validator half). Each test names the PHP assertion it ports.

mod support;

use serde_json::json;
use support::*;
use utopia_database::attribute::Attribute;
use utopia_database::relationship::page::Page;
use utopia_database::relationship::{Relationship, RelationshipSide};
use utopia_database::validator::queries::base::Queries;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::limit::Limit;
use utopia_database::validator::query::order::Order;
use utopia_database::validator::query::relationship::Relationship as RelationshipValidator;
use utopia_database::validator::query::select::Select;
use utopia_database::{Document, Query};
use utopia_query::Method;

fn comments() -> Attribute {
    Attribute::relationship(
        "comments",
        Relationship::one_to_many("comments").two_way(true).two_way_key("post"),
        RelationshipSide::Parent,
    )
    .unwrap()
}

fn author() -> Attribute {
    Attribute::relationship(
        "author",
        Relationship::many_to_one("authors").two_way(true).two_way_key("posts"),
        RelationshipSide::Parent,
    )
    .unwrap()
}

fn ids(documents: &[Document]) -> Vec<&str> {
    documents.iter().map(Document::id).collect()
}

fn documents(ids: &[&str]) -> Vec<Document> {
    ids.iter().map(|id| doc(json!({"$id": id}))).collect()
}

// ---- QueryTest ----------------------------------------------------------

// PHP: QueryTest::testRelationshipShapeIncludesItsQueries,
// `$this->assertSame('relationship:comments(equal:approved|limit:)', $relationship->shape())`.
#[test]
fn a_relationship_shape_includes_its_queries() {
    let relationship = Query::relationship("comments", [Query::equal("approved", [true]), Query::limit(2)]);
    assert_eq!(relationship.shape().unwrap(), "relationship:comments(equal:approved|limit:)");
    assert_ne!(
        Query::fingerprint_queries(&[relationship]).unwrap(),
        Query::fingerprint_queries(&[Query::relationship("comments", [Query::limit(2)])]).unwrap()
    );
}

// PHP: QueryTest::testRelationshipRoundTrip, `$this->assertSame(['c1'], $inner[4]->getValues())`.
#[test]
fn a_relationship_query_round_trips() {
    let query = Query::relationship(
        "comments",
        [
            Query::equal("approved", [true]),
            Query::order_desc("$createdAt", None),
            Query::limit(2),
            Query::offset(1),
            Query::cursor_after(&doc(json!({"$id": "c1"}))),
        ],
    );
    assert!(query.is_nested());

    let parsed = Query::parse(&query.to_json().unwrap(), false).unwrap();
    assert_eq!(parsed.method(), Method::Relationship);
    assert_eq!(parsed.attribute(), "comments");
    let inner: Vec<&utopia_query::Query> = parsed
        .values()
        .values()
        .map(|v| match v {
            utopia_query::Value::Ext(utopia_query::Object::Query(q)) => &**q,
            other => panic!("not a query: {other:?}"),
        })
        .collect();
    assert_eq!(inner.len(), 5);
    assert_eq!(inner[1].attribute(), "$createdAt");
    assert_eq!(inner[4].method(), Method::CursorAfter);
    assert_eq!(inner[4].value(), Some(&utopia_query::string("c1")));
}

// PHP: QueryTest::testRelationshipParsesStringChildren, `$this->assertSame(Method::Limit, $inner[0]->getMethod())`.
#[test]
fn a_relationship_query_parses_string_children() {
    let json =
        json!({"method": "relationship", "attribute": "comments", "values": [Query::limit(3).to_json().unwrap()]});
    let parsed = Query::parse(&json.to_string(), false).unwrap();
    match parsed.values().values().next() {
        Some(utopia_query::Value::Ext(utopia_query::Object::Query(q))) => assert_eq!(q.method(), Method::Limit),
        other => panic!("not a query: {other:?}"),
    }
}

// PHP: QueryTest::testGroupByTypeKeepsRelationshipQueriesApartFromFilters,
// `$this->assertCount(1, $grouped->relationships)`.
#[test]
fn group_by_type_keeps_relationship_queries_apart_from_filters() {
    let grouped = Query::group_by_type(&[
        Query::equal("title", ["a"]),
        Query::relationship("comments", [Query::limit(2)]),
        Query::limit(5),
    ])
    .unwrap();
    assert_eq!(grouped.filters.len(), 1);
    assert_eq!(grouped.filters[0].method(), Method::Equal);
    assert_eq!(grouped.relationships.len(), 1);
    assert_eq!(grouped.relationships[0].attribute(), "comments");
    assert_eq!(grouped.limit, Some(5));
}

// ---- PageTest -------------------------------------------------------------

// PHP: PageTest::testNoPaginationIsNoPage, `$this->assertNull($page)`.
#[test]
fn no_pagination_is_no_page() {
    let (page, rest) = Page::from(vec![Query::equal("approved", [true])]);
    assert!(page.is_none());
    assert_eq!(rest.len(), 1);
}

// PHP: PageTest::testLimitAndOffset, `$this->assertSame(['b', 'c'], $this->ids($page->slice(...)))`.
#[test]
fn a_page_slices_by_limit_and_offset() {
    let (page, rest) = Page::from(vec![Query::limit(2), Query::equal("approved", [true]), Query::offset(1)]);
    let page = page.unwrap();
    assert_eq!(rest.len(), 1);
    assert_eq!(ids(&page.slice(&documents(&["a", "b", "c", "d"]))), ["b", "c"]);
}

// PHP: PageTest::testCursorAfterWithOffset and testCursorBefore.
#[test]
fn a_page_slices_after_and_before_a_cursor() {
    let all = documents(&["a", "b", "c", "d", "e"]);
    let after =
        Page::from(vec![Query::cursor_after(&doc(json!({"$id": "b"}))), Query::offset(1), Query::limit(2)]).0.unwrap();
    assert_eq!(ids(&after.slice(&all)), ["d", "e"]);

    let before = Page::from(vec![Query::cursor_before_value(utopia_query::string("d")), Query::limit(2)]).0.unwrap();
    assert_eq!(ids(&before.slice(&all)), ["b", "c"]);

    // PHP: testCursorBeforeWithOffsetCountsBackFromTheCursor.
    let back = Page::from(vec![Query::cursor_before_value(utopia_query::string("d")), Query::offset(1)]).0.unwrap();
    assert_eq!(ids(&back.slice(&all)), ["a", "b"]);
}

// PHP: PageTest::testCursorTheParentDoesNotHoldIsEmpty and testFirstOfEachPaginationQueryWins.
#[test]
fn a_missing_cursor_is_an_empty_page_and_the_first_query_wins() {
    let all = documents(&["a", "b"]);
    let missing = Page::from(vec![Query::cursor_after_value(utopia_query::string("z"))]).0.unwrap();
    assert!(missing.slice(&all).is_empty());

    let first = Page::from(vec![Query::limit(1), Query::limit(5)]).0.unwrap();
    assert_eq!(first.limit, Some(1));
}

// ---- Validator\Query\RelationshipTest -------------------------------------

fn relationship_validator() -> RelationshipValidator {
    RelationshipValidator::new(&[Attribute::string("name", 64), comments(), author()], 36, true)
}

// PHP: RelationshipTest::testAcceptsInnerQueriesOnPluralRelationship.
#[test]
fn a_relationship_query_on_a_plural_relationship_is_accepted() {
    let validator = relationship_validator();
    let query = Query::relationship(
        "comments",
        [
            Query::equal("approved", [true]),
            Query::order_desc("$createdAt", None),
            Query::limit(2),
            Query::select(["*"]),
        ],
    );
    assert!(validator.check(&query), "{}", validator.description());
}

// PHP: RelationshipTest::testRejectsNonRelationshipAttribute, testRejectsRelationshipInRelationship,
// testRejectsQueriesThatReadSomethingOtherThanTheRelatedDocuments, testRejectsInvalidInnerLimit.
#[test]
fn a_relationship_query_is_refused_with_the_reason() {
    let validator = relationship_validator();
    let refused = |query: Query, message: &str| {
        assert!(!validator.check(&query));
        assert_eq!(validator.description(), message);
    };
    refused(
        Query::relationship("name", [Query::limit(1)]),
        "Relationship queries can only be used on relationship attributes: name",
    );
    refused(
        Query::relationship("comments", [Query::relationship("comments", [Query::limit(1)])]),
        "Relationship queries cannot contain relationship queries",
    );
    refused(
        Query::relationship("comments", [Query::or([Query::relationship("comments", [Query::limit(1)])])]),
        "Relationship queries cannot contain relationship queries",
    );
    refused(
        Query::relationship("comments", [Query::count("*", "n")]),
        "Relationship queries cannot contain count queries",
    );
    refused(
        Query::relationship("comments", [Query::limit(0)]),
        "Invalid limit: Value must be a valid range between 1 and 9,223,372,036,854,775,807",
    );
    refused(Query::relationship("", [Query::limit(1)]), "Relationship queries require a relationship attribute");
    refused(Query::limit(1), "Invalid query method: limit");
}

// PHP: RelationshipTest::testRejectsPaginationOnSingularRelationship, testAcceptsFiltersOnSingularRelationship.
#[test]
fn a_singular_relationship_is_filtered_but_not_paged() {
    let validator = relationship_validator();
    assert!(!validator.check(&Query::relationship("author", [Query::limit(1)])));
    assert_eq!(validator.description(), "Relationship pagination is not supported on a singular relationship: author");
    assert!(validator.check(&Query::relationship("author", [Query::equal("name", ["a"])])));
}

// PHP: RelationshipTest::testUnknownAttributeAcceptedWithoutAttributeSupport.
#[test]
fn without_attribute_support_any_attribute_is_accepted() {
    let validator = RelationshipValidator::new(&[], 36, false);
    assert!(validator.check(&Query::relationship("anything", [Query::limit(1)])));
}

// PHP: QueriesTest::test_relationship_query_is_validated_by_its_validator and
// test_logical_query_rejects_relationship_query.
#[test]
fn a_query_list_dispatches_relationship_queries_and_refuses_them_in_logical_queries() {
    let attributes = [Attribute::string("name", 64), comments()];
    let validator = Queries::new(
        vec![
            std::sync::Arc::new(Filter::with_options(&attributes, "integer", FilterOptions::default())),
            std::sync::Arc::new(RelationshipValidator::new(&attributes, 36, true)),
        ],
        0,
    );
    assert!(validator.check(&[Query::relationship("comments", [Query::limit(1)])]), "{}", validator.description());
    assert!(!validator.check(&[Query::relationship("name", [Query::limit(1)])]));
    assert_eq!(
        validator.description(),
        "Invalid query: Relationship queries can only be used on relationship attributes: name"
    );
    assert!(
        !Queries::new(vec![std::sync::Arc::new(Limit::default())], 0)
            .check(&[Query::relationship("comments", [Query::limit(1)])])
    );

    for logical in [Query::or as fn(Vec<Query>) -> Query, Query::and] {
        assert!(!validator.check(&[logical(vec![
            Query::relationship("comments", [Query::limit(1)]),
            Query::equal("name", ["value"]),
        ])]));
        assert!(validator.description().contains("queries can only contain filter queries"));
    }
}

// ---- 8.3.0: nested paths and orders on attributes that hold none ---------

// PHP: FilterTest::test_nested_path_on_a_plain_attribute_is_rejected,
// `$this->assertSame('Cannot query nested attribute on: name', $validator->getDescription())`,
// and test_nested_path_on_a_relationship_or_object_is_accepted.
#[test]
fn a_filter_refuses_a_nested_path_on_a_plain_attribute() {
    let attributes = [Attribute::string("name", 64), comments(), attr(json!({"key": "meta", "type": "object"}))];
    let validator = Filter::with_options(&attributes, "integer", FilterOptions::default());
    assert!(!validator.check(&Query::equal("name.first", ["a"])));
    assert_eq!(validator.description(), "Cannot query nested attribute on: name");
    assert!(validator.check(&Query::equal("comments.approved", [true])), "{}", validator.description());
    assert!(validator.check(&Query::equal("meta.key", ["a"])), "{}", validator.description());

    // PHP: test_nested_path_on_a_plain_attribute_is_left_to_a_schemaless_adapter.
    let schemaless = Filter::with_options(
        &attributes,
        "integer",
        FilterOptions { support_for_attributes: false, ..FilterOptions::default() },
    );
    assert!(schemaless.check(&Query::equal("name.first", ["a"])));
}

// PHP: SelectTest::testANestedPathOnAPlainAttributeIsRefused and testANestedPathOnAnObjectAttributeIsAccepted.
#[test]
fn a_select_refuses_a_nested_path_on_a_plain_attribute() {
    let attributes = [Attribute::string("name", 64), attr(json!({"key": "meta", "type": "object"}))];
    let select = Select::new(&attributes, true, false);
    assert!(!select.check(&Query::select(["name.first"])));
    assert_eq!(select.description(), "Cannot select nested attribute on: name");
    assert!(select.check(&Query::select(["meta.key"])), "{}", select.description());
}

// PHP: RelationshipOrderTest::testOrderByARelationshipWithoutAColumnIsAnInvalidQuery,
// `'Cannot order by relationship attribute: '.$attribute`.
#[test]
fn an_order_on_a_relationship_without_a_column_is_refused() {
    let order = Order::new(&[comments(), author()], true, true);
    assert!(!order.check(&Query::order_asc("comments", None)));
    assert_eq!(order.description(), "Cannot order by relationship attribute: comments");
    assert!(order.check(&Query::order_asc("author", None)), "{}", order.description());
}
