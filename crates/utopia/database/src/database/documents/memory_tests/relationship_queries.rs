//! PHP 8.2.0's relationship queries, from `tests/e2e/Adapter/Scopes/RelationshipTests.php` and
//! `Relationships/ManyToManyTests.php`, on Memory: a relationship query and a dotted filter
//! shape the related documents each document is populated with. Each test names the PHP
//! assertion it ports.

use std::sync::Arc;

use super::s;
use crate::attribute::Attribute;
use crate::database::testing::block_on;
use crate::database::testing_memory::{collection, crud, doc, memory};
use crate::database::{Database, Hook};
use crate::document::Document;
use crate::error::Kind;
use crate::hook::relationships::Relationships;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::Relationship;
use crate::value::{Value, list};

const POSTS: &str = "posts";
const COMMENTS: &str = "comments";
const TAGS: &str = "tags";

/// Posts with a one-to-many `comments` (two-way, `post`) and a many-to-many `tags`.
async fn blog() -> Database {
    let db = memory("relationship_queries").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::new(true)))).unwrap();
    collection(&db, POSTS, vec![Attribute::string("title", 64)], crud(), false).await;
    collection(&db, COMMENTS, vec![Attribute::string("text", 64), Attribute::boolean("approved")], crud(), false).await;
    collection(&db, TAGS, vec![Attribute::string("name", 64)], crud(), false).await;
    db.create_relationship(
        POSTS,
        Relationship::one_to_many(COMMENTS).key("comments").two_way(true).two_way_key("post"),
    )
    .await
    .unwrap();
    db.create_relationship(POSTS, Relationship::many_to_many(TAGS).key("tags").two_way(true).two_way_key("posts"))
        .await
        .unwrap();

    for (id, title) in [("p1", "first"), ("p2", "second")] {
        db.create_document(POSTS, doc(vec![("$id", s(id)), ("title", s(title))])).await.unwrap();
    }
    for (id, post, approved) in [("c1", "p1", true), ("c2", "p1", false), ("c3", "p1", true), ("c4", "p2", true)] {
        let comment =
            doc(vec![("$id", s(id)), ("text", s(id)), ("approved", Value::Bool(approved)), ("post", s(post))]);
        db.create_document(COMMENTS, comment).await.unwrap();
    }
    for (id, posts) in [("t1", vec!["p1"]), ("t2", vec!["p1", "p2"]), ("t3", vec!["p1"])] {
        let tag = doc(vec![("$id", s(id)), ("name", s(id)), ("posts", list(posts.into_iter().map(s)))]);
        db.create_document(TAGS, tag).await.unwrap();
    }
    db
}

/// The ids under each post's relationship `key`.
fn related(posts: &[Document], key: &str) -> Vec<(String, Vec<String>)> {
    posts
        .iter()
        .map(|post| {
            let ids = match post.get(key) {
                Some(Value::Array(items)) => items
                    .values()
                    .filter_map(|v| match v {
                        Value::Ext(crate::value::Ext::Document(d)) => Some(d.id().to_owned()),
                        _ => None,
                    })
                    .collect(),
                _ => Vec::new(),
            };
            (post.id().to_owned(), ids)
        })
        .collect()
}

fn pairs(expected: &[(&str, &[&str])]) -> Vec<(String, Vec<String>)> {
    expected.iter().map(|(id, ids)| (id.to_string(), ids.iter().map(|i| i.to_string()).collect())).collect()
}

// PHP: RelationshipTests::testNestedSliceLimitOffsetPerParent: a relationship query's limit and offset page each
// parent's related documents.
#[test]
fn a_relationship_query_pages_each_parents_related_documents() {
    block_on(async {
        let db = blog().await;
        let queries =
            vec![Query::relationship(COMMENTS, [Query::limit(1), Query::offset(1)]), Query::order_asc("$id", None)];
        let posts = db.find(POSTS, queries, PermissionType::Read).await.unwrap();
        assert_eq!(related(&posts, COMMENTS), pairs(&[("p1", &["c2"]), ("p2", &[])]));
    });
}

// PHP: RelationshipTests::testNestedFilterOneToManyConstrainsChildren: `comments.approved` keeps only the approved
// comments of each post it returns.
#[test]
fn a_dotted_filter_constrains_the_related_documents() {
    block_on(async {
        let db = blog().await;
        let queries = vec![Query::equal("comments.approved", [true]), Query::order_asc("$id", None)];
        let posts = db.find(POSTS, queries, PermissionType::Read).await.unwrap();
        assert_eq!(related(&posts, COMMENTS), pairs(&[("p1", &["c1", "c3"]), ("p2", &["c4"])]));
    });
}

// PHP: ManyToManyTests::testM2mNestedOrderDescending: a relationship query's order orders each parent's related
// documents across the junction.
#[test]
fn a_relationship_query_orders_many_to_many_documents() {
    block_on(async {
        let db = blog().await;
        let queries = vec![Query::relationship(TAGS, [Query::order_desc("name", None)]), Query::order_asc("$id", None)];
        let posts = db.find(POSTS, queries, PermissionType::Read).await.unwrap();
        assert_eq!(related(&posts, TAGS), pairs(&[("p1", &["t3", "t2", "t1"]), ("p2", &["t2"])]));
    });
}

// PHP: RelationshipTests::testNestedSkeletonCountIgnoresNested and the bulk writes' refusal
// (`withoutRelationshipQueries()`): `Invalid query: Invalid query method: relationship`.
#[test]
fn a_bulk_write_refuses_a_relationship_query() {
    block_on(async {
        let db = blog().await;
        let error = db
            .delete_documents(POSTS, vec![Query::relationship(COMMENTS, [Query::limit(1)])], 100, None)
            .await
            .unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Query, "Invalid query: Invalid query method: relationship"));
        assert_eq!(db.count(POSTS, Vec::new(), None).await.unwrap(), 2);
    });
}
