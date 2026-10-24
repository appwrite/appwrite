//! `MongoQueryFilterTest`, `MongoContainsFilterTest`,
//! `MongoDollarWordRegexTest`, `MongoArrayNotContainsTest`,
//! `MongoFilterScopeTest` and `MongoPermissionStringsTest`.

use std::sync::{Arc, Mutex};

use utopia_database::adapter::Find;
use utopia_database::adapter::mongo::Mongo;
use utopia_database::attribute::Attribute;
use utopia_database::change::Change;
use utopia_database::collection::Collection;
use utopia_database::hook::mongo::{Permission, Read};
use utopia_database::permission::PermissionType;
use utopia_database::validator::authorization::Authorization;
use utopia_database::value::{Array, Value};
use utopia_database::{Adapter, Document, Index, Kind, Query};
use utopia_mongo::bson::{Bson, Document as BsonDocument, doc};
use utopia_query::schema::ColumnType;
use utopia_query::{OrderDirection, Value as QValue};

use crate::common::{assert_error, doc, s};
use crate::fake::{Fake, at, batch, error, regex};

const COLLECTION: &str = "records";

fn collection(json: &str) -> Collection {
    Collection::from_document(&doc(json)).unwrap()
}

fn records() -> Collection {
    collection(r#"{"$id":"records"}"#)
}

fn values(items: &[&str]) -> utopia_query::Array {
    utopia_query::Array::from_list(items.iter().map(|i| QValue::Str((*i).into())).collect())
}

/// The adapter of `MongoQueryFilterTest`: finds answer `rows`, created
/// indexes are listed as built.
async fn query_filter_adapter(fake: &Arc<Fake>, rows: Vec<BsonDocument>) -> Mongo {
    fake.on("find", move |_| Ok(batch(rows.clone())));
    let created: Arc<Mutex<Vec<Bson>>> = Arc::default();
    let listed = created.clone();
    fake.on("createIndexes", move |args| {
        if let Bson::Array(indexes) = &args[1] {
            for index in indexes {
                created
                    .lock()
                    .unwrap()
                    .push(Bson::Document(doc! { "name": at(index, "name").cloned().unwrap_or(Bson::Null) }));
            }
        }
        Ok(Bson::Boolean(true))
    });
    fake.on("query", move |_| Ok(Bson::Document(doc! { "cursor": { "firstBatch": listed.lock().unwrap().clone() } })));
    crate::fake::adapter(fake, "query_filter", false).await
}

/// The `$and` conditions of the first find's filter.
fn conditions(fake: &Fake) -> Option<Bson> {
    fake.args("find").first().and_then(|a| at(&a[1], "$and").cloned())
}

/// The partial filter of every index created, by name.
fn partial_filters(fake: &Fake) -> Vec<(String, Option<Bson>)> {
    let mut filters = Vec::new();
    for args in fake.args("createIndexes") {
        if let Bson::Array(indexes) = &args[1] {
            for index in indexes {
                let name = at(index, "name").and_then(Bson::as_str).unwrap_or_default().to_owned();
                filters.push((name, at(index, "partialFilterExpression").cloned()));
            }
        }
    }
    filters
}

async fn find(queries: Vec<Query>) -> Arc<Fake> {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;
    adapter.find(&records(), queries, Find::default()).await.unwrap();
    fake
}

#[utopia_async::test]
async fn starts_with_is_anchored_at_the_start_without_flags() {
    let fake = find(vec![Query::starts_with("name", "a.b$")]).await;
    assert_eq!(conditions(&fake), Some(Bson::Array(vec![Bson::Document(doc! { "name": { "$regex": "^a\\.b\\$" } })])));
}

#[utopia_async::test]
async fn ends_with_is_anchored_at_the_end_without_flags() {
    let fake = find(vec![Query::ends_with("name", "a.b$")]).await;
    assert_eq!(conditions(&fake), Some(Bson::Array(vec![Bson::Document(doc! { "name": { "$regex": "a\\.b\\$$" } })])));
}

#[utopia_async::test]
async fn contains_all_keeps_the_all_operator_on_find() {
    let mut query = Query::contains_all("tags", ["a", "b"]);
    query.set_on_array(true);
    let fake = find(vec![query]).await;
    assert_eq!(
        conditions(&fake),
        Some(Bson::Array(vec![Bson::Document(doc! { "tags": { "$all": ["a", "b"] } })])),
        "find() must send $all as count() does, not the rewritten _all"
    );
}

#[utopia_async::test]
async fn count_rethrows_driver_errors() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;
    fake.on("aggregate", |_| Err(error("invalid pipeline", 2)));

    let failure = adapter.count(&records(), vec![], None).await.unwrap_err();
    assert_eq!(failure.kind(), Kind::Mongo);
    assert_eq!(failure.message(), "invalid pipeline");
}

#[utopia_async::test]
async fn null_tenant_is_read_as_the_tenant_attribute() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![doc! { "_uid": "first", "_tenant": Bson::Null }]).await;

    let documents = adapter.find(&records(), vec![], Find::default()).await.unwrap();

    assert_eq!(documents.len(), 1);
    assert!(!documents[0].has("_tenant"), "A null _tenant must not leak as a storage key");
    assert_eq!(documents[0].raw("$tenant"), Some(&Value::Null));
}

#[utopia_async::test]
async fn unique_partial_filters_match_the_attribute_types() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;
    let types: Vec<(String, ColumnType)> = [
        ("count", ColumnType::Integer),
        ("total", ColumnType::BigInteger),
        ("price", ColumnType::Float),
        ("active", ColumnType::Boolean),
        ("seenAt", ColumnType::Datetime),
        ("name", ColumnType::String),
    ]
    .into_iter()
    .map(|(a, t)| (a.to_owned(), t))
    .collect();

    for (attribute, _) in &types {
        let index = Index::unique(format!("{attribute}_key"), [attribute.as_str()], vec![], vec![]).unwrap();
        adapter.create_index(COLLECTION, &index, &types, &[]).await.unwrap();
    }
    let index = Index::unique("name_count", ["name", "count"], vec![], vec![]).unwrap();
    adapter.create_index(COLLECTION, &index, &types, &[]).await.unwrap();

    let int = Bson::Array(vec!["int".into(), "long".into()]);
    let expected = vec![
        ("count_key", doc! { "count": { "$exists": true, "$type": int.clone() } }),
        ("total_key", doc! { "total": { "$exists": true, "$type": int.clone() } }),
        ("price_key", doc! { "price": { "$exists": true, "$type": ["double", "int", "long"] } }),
        ("active_key", doc! { "active": { "$exists": true, "$type": "bool" } }),
        ("seenAt_key", doc! { "seenAt": { "$exists": true, "$type": "date" } }),
        ("name_key", doc! { "name": { "$exists": true, "$type": "string" } }),
        (
            "name_count",
            doc! { "name": { "$exists": true, "$type": "string" }, "count": { "$exists": true, "$type": int } },
        ),
    ];
    let expected: Vec<(String, Option<Bson>)> =
        expected.into_iter().map(|(n, d)| (n.to_owned(), Some(Bson::Document(d)))).collect();
    assert_eq!(partial_filters(&fake), expected);
}

#[utopia_async::test]
async fn key_index_partial_filters_require_only_that_the_leading_field_exists() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;
    let types: Vec<(String, ColumnType)> = [
        ("count", ColumnType::Integer),
        ("price", ColumnType::Float),
        ("active", ColumnType::Boolean),
        ("seenAt", ColumnType::Datetime),
        ("name", ColumnType::String),
    ]
    .into_iter()
    .map(|(a, t)| (a.to_owned(), t))
    .collect();

    adapter
        .create_index(COLLECTION, &Index::key("count_key", ["count"], vec![], vec![]).unwrap(), &types, &[])
        .await
        .unwrap();
    adapter
        .create_index(COLLECTION, &Index::key("name_seen", ["name", "seenAt"], vec![], vec![]).unwrap(), &types, &[])
        .await
        .unwrap();
    adapter
        .create_collection(
            "created",
            &[Attribute::integer("count"), Attribute::double("price"), Attribute::boolean("active")],
            &[
                Index::key("price_active", ["price", "active"], vec![], vec![]).unwrap(),
                Index::unique("count_unique", ["count"], vec![], vec![]).unwrap(),
            ],
        )
        .await
        .unwrap();

    let exists = |field: &str| {
        let mut d = BsonDocument::new();
        d.insert(field, doc! { "$exists": true });
        Some(Bson::Document(d))
    };
    let expected: Vec<(String, Option<Bson>)> = vec![
        ("count_key".into(), exists("count")),
        ("name_seen".into(), exists("name")),
        ("_uid".into(), None),
        ("_createdAt".into(), None),
        ("_updatedAt".into(), None),
        ("_permissions".into(), None),
        ("price_active".into(), exists("price")),
        ("count_unique".into(), Some(Bson::Document(doc! { "count": { "$exists": true, "$type": ["int", "long"] } }))),
    ];
    assert_eq!(
        partial_filters(&fake),
        expected,
        "A key index must be usable by any filter on a value of its leading field, which implies $exists but never $type"
    );
}

#[utopia_async::test]
async fn unique_index_on_an_integer_filters_on_the_integer_types() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;

    adapter
        .create_index(
            COLLECTION,
            &Index::unique("count_unique", ["count"], vec![], vec![]).unwrap(),
            &[("count".to_owned(), ColumnType::Integer)],
            &[],
        )
        .await
        .unwrap();

    assert_eq!(
        partial_filters(&fake),
        vec![(
            "count_unique".to_owned(),
            Some(Bson::Document(doc! { "count": { "$exists": true, "$type": ["int", "long"] } }))
        )]
    );
    assert_eq!(at(&fake.args("createIndexes")[0][1], "0.unique"), Some(&Bson::Boolean(true)));
}

#[utopia_async::test]
async fn unique_index_on_an_untyped_attribute_covers_every_value_but_null() {
    let any = doc! { "$exists": true, "$type": [
        "double", "string", "object", "array", "binData", "objectId", "bool", "date", "regex",
        "javascript", "int", "timestamp", "long", "decimal", "minKey", "maxKey",
    ] };
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;

    adapter
        .create_index(
            COLLECTION,
            &Index::unique("created_unique", ["$createdAt"], vec![], vec![]).unwrap(),
            &[("name".to_owned(), ColumnType::String)],
            &[],
        )
        .await
        .unwrap();
    adapter
        .create_index(COLLECTION, &Index::unique("free_unique", ["free"], vec![], vec![]).unwrap(), &[], &[])
        .await
        .unwrap();
    adapter
        .create_collection("created", &[], &[Index::unique("free_name", ["free", "name"], vec![], vec![]).unwrap()])
        .await
        .unwrap();

    let filters = partial_filters(&fake);
    let of = |name: &str| filters.iter().find(|(n, _)| n == name).and_then(|(_, f)| f.clone());
    assert_eq!(
        of("created_unique"),
        Some(Bson::Document(doc! { "_createdAt": any.clone() })),
        "An internal attribute has no declared type, so its unique index must cover every stored type"
    );
    assert_eq!(
        of("free_unique"),
        Some(Bson::Document(doc! { "free": any.clone() })),
        "A schemaless attribute has no declared type, so its unique index must cover every stored type"
    );
    assert_eq!(
        of("free_name"),
        Some(Bson::Document(doc! { "free": any.clone(), "name": any })),
        "createCollection() must map an undeclared attribute as createIndex() does"
    );
}

#[utopia_async::test]
async fn dotted_attributes_are_renamed_and_deleted_by_their_stored_name() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;

    adapter.rename_attribute(COLLECTION, "a.b", "c.d").await.unwrap();
    adapter.delete_attribute(COLLECTION, "a.b").await.unwrap();

    let updates: Vec<Bson> = fake.args("update").into_iter().map(|a| a[2].clone()).collect();
    assert_eq!(
        updates,
        vec![
            Bson::Document(doc! { "$rename": { "a__dot__b": "c__dot__d" } }),
            Bson::Document(doc! { "$unset": { "a__dot__b": "" } }),
        ],
        "Renames and deletes must address the __dot__ field name that documents are stored under"
    );
}

fn dotted() -> Collection {
    collection(r#"{"$id":"records","attributes":[{"$id":"a.b","key":"a.b","type":"integer"}]}"#)
}

#[utopia_async::test]
async fn sum_escapes_dotted_attributes() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;

    adapter.sum(&dotted(), "a.b", vec![Query::equal("a.b", [1])], None).await.unwrap();

    assert_eq!(
        fake.args("aggregate")[0][1],
        Bson::Array(vec![
            Bson::Document(doc! { "$match": { "$and": [{ "a__dot__b": { "$eq": 1 } }] } }),
            Bson::Document(doc! { "$group": { "_id": Bson::Null, "total": { "$sum": "$a__dot__b" } } }),
        ]),
        "sum() must filter and sum the stored field name of a dotted attribute, as find() and count() do"
    );
}

/// The field names of a filter: every key but operators and `_permissions`.
fn field_names(value: &Bson, names: &mut Vec<String>) {
    match value {
        Bson::Document(d) => {
            for (k, v) in d {
                if !k.is_empty() && !k.starts_with('$') && k != "_permissions" && !names.contains(k) {
                    names.push(k.clone());
                }
                field_names(v, names);
            }
        }
        Bson::Array(items) => items.iter().for_each(|i| field_names(i, names)),
        _ => {}
    }
}

#[utopia_async::test]
async fn dotted_attributes_inside_groups_and_exists_are_escaped() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;
    let queries = || {
        vec![
            Query::or([
                Query::equal("a.b", [1]),
                Query::and([Query::greater_than("a.b", 5), Query::less_than("a.b", 9)]),
            ]),
            Query::exists(values(&["a.b"])),
            Query::not_exists(QValue::Array(values(&["a.b"]))),
        ]
    };

    adapter.find(&dotted(), queries(), Find::default()).await.unwrap();
    adapter.count(&dotted(), queries(), None).await.unwrap();

    let mut names = Vec::new();
    field_names(&fake.args("find")[0][1], &mut names);
    assert_eq!(
        names,
        vec!["a__dot__b"],
        "find() must address the stored field name of a dotted attribute in every group and exists"
    );
    let mut names = Vec::new();
    field_names(&fake.args("aggregate")[0][1], &mut names);
    assert_eq!(
        names,
        vec!["a__dot__b"],
        "count() must address the stored field name of a dotted attribute in every group and exists"
    );
}

#[utopia_async::test]
async fn random_order_is_rejected_as_a_query_error() {
    let fake = Fake::new();
    let adapter = query_filter_adapter(&fake, vec![]).await;

    let options =
        Find { order_attributes: vec![String::new()], order_types: vec![OrderDirection::Random], ..Find::default() };
    let result = adapter.find(&records(), vec![], options).await;
    assert_error(result, Kind::Query, "Random order is not supported by this adapter");
}

// ---- MongoContainsFilterTest, MongoDollarWordRegexTest, MongoArrayNotContainsTest

/// The first condition of the filter a find with `query` sends.
async fn filter_of(query: Query, schemaless: bool) -> Bson {
    let fake = Fake::new();
    let adapter = crate::fake::adapter(&fake, "movies", false).await;
    if schemaless {
        adapter.schemaless().unwrap().set_schemaless(true);
    }
    adapter.find(&collection(r#"{"$id":"movies"}"#), vec![query], Find::default()).await.unwrap();
    at(&fake.args("find")[0][1], "$and.0").cloned().expect("a condition")
}

#[utopia_async::test]
async fn contains_on_an_array_matches_any_value() {
    let mut contains = Query::contains("genres", ["comics", "kids"]);
    contains.set_on_array(true);
    let mut contains_any = Query::contains_any("genres", ["comics", "kids"]);
    contains_any.set_on_array(true);

    let expected = Bson::Document(doc! { "genres": { "$in": ["comics", "kids"] } });
    assert_eq!(filter_of(contains, false).await, expected);
    assert_eq!(filter_of(contains_any, false).await, expected);
}

#[utopia_async::test]
async fn not_equal_to_several_values_excludes_each_of_them() {
    let query = Query::not_equal("name", QValue::Array(values(&["a", "b"])));
    assert_eq!(filter_of(query, false).await, Bson::Document(doc! { "name": { "$nin": ["a", "b"] } }));
}

#[utopia_async::test]
async fn dollar_prefixed_attribute_is_filtered_by_its_stored_name() {
    assert_eq!(
        filter_of(Query::equal("$meta.key", ["x"]), false).await,
        Bson::Document(doc! { "_meta__dot__key": { "$eq": "x" } })
    );
}

#[utopia_async::test]
async fn schemaless_unparsable_date_stays_a_string() {
    assert_eq!(
        filter_of(Query::equal("when", ["2026-13-45T99:99:99Z"]), true).await,
        Bson::Document(doc! { "when": { "$eq": "2026-13-45T99:99:99Z" } })
    );
}

#[utopia_async::test]
async fn contains_on_a_string_matches_any_of_several_substrings() {
    for query in [Query::contains("name", ["Captain", "Work"]), Query::contains_any("name", ["Captain", "Work"])] {
        let filter = filter_of(query, false).await;
        assert_eq!(
            at(&filter, "$or"),
            Some(&Bson::Array(vec![
                Bson::Document(doc! { "name": { "$regex": regex(".*Captain.*", "i") } }),
                Bson::Document(doc! { "name": { "$regex": regex(".*Work.*", "i") } }),
            ]))
        );
    }
}

#[utopia_async::test]
async fn not_contains_on_a_string_excludes_the_substring() {
    assert_eq!(
        filter_of(Query::not_contains("name", ["Captain"]), false).await,
        Bson::Document(doc! { "name": { "$not": regex(".*Captain.*", "i") } })
    );
}

#[utopia_async::test]
async fn a_dollar_word_is_matched_literally() {
    let cases = [
        (Query::contains("label", ["$USD"]), "$regex", ".*\\$USD.*"),
        (Query::not_contains("label", ["$USD"]), "$not", ".*\\$USD.*"),
        (Query::not_search("label", "$USD"), "$not", ".*\\$USD.*"),
        (Query::not_starts_with("label", "$USD"), "$not", "^\\$USD"),
        (Query::not_ends_with("label", "$USD"), "$not", "\\$USD$"),
        (Query::contains("label", ["a.b($x"]), "$regex", ".*a\\.b\\(\\$x.*"),
    ];
    for (query, operator, pattern) in cases {
        let filter = filter_of(query, false).await;
        assert_eq!(at(&filter, &format!("label.{operator}")), Some(&regex(pattern, "i")), "{pattern}");
    }
}

#[utopia_async::test]
async fn not_contains_on_an_array_excludes_missing_and_null_arrays() {
    let mut query = Query::not_contains("labels", ["a", "c"]);
    query.set_on_array(true);
    assert_eq!(
        filter_of(query, false).await,
        Bson::Document(doc! { "labels": { "$nin": ["a", "c"], "$ne": Bson::Null } }),
        "A document whose array is missing or null must not match notContains, as on MariaDB, MySQL and PostgreSQL"
    );
}

// ---- MongoFilterScopeTest

const TENANT: i32 = 7;

/// The adapter of `MongoFilterScopeTest` and the filters each operation
/// sends, by operation.
async fn scope_adapter() -> (Mongo, Arc<Fake>, Arc<Authorization>) {
    let fake = Fake::new();
    fake.on("query", |_| Ok(Bson::Int32(1)));
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();
    let authorization = Arc::new(Authorization::new(true));
    authorization.clean_roles();
    authorization.add_role("any");
    authorization.add_role("users");
    authorization.add_role("user:bob");
    adapter.set_authorization(authorization.clone());
    adapter.set_namespace("scope");
    adapter.set_shared_tables(true);
    adapter.set_tenant(Value::Int(i64::from(TENANT)));
    (adapter, fake, authorization)
}

/// The filters `operation` sent: update filters (and those of pipeline
/// updates, sent as commands), upsert filters, delete filters, find
/// filters, and the `$match` of aggregations.
fn scope_filters(fake: &Fake, operation: &str) -> Vec<Bson> {
    match operation {
        "update" => {
            let mut filters: Vec<Bson> = fake.args("update").into_iter().map(|a| a[1].clone()).collect();
            for args in fake.args("query") {
                if let Some(Bson::Array(updates)) = at(&args[0], "updates") {
                    filters.extend(updates.iter().filter_map(|u| at(u, "q").cloned()));
                }
            }
            filters
        }
        "upsert" => fake
            .args("upsert")
            .into_iter()
            .flat_map(|a| match &a[1] {
                Bson::Array(ops) => ops.iter().filter_map(|o| at(o, "filter").cloned()).collect::<Vec<_>>(),
                _ => vec![],
            })
            .collect(),
        "delete" => fake.args("delete").into_iter().map(|a| a[1].clone()).collect(),
        "find" => fake.args("find").into_iter().map(|a| a[1].clone()).collect(),
        "aggregate" => fake
            .args("aggregate")
            .into_iter()
            .map(|a| at(&a[1], "0.$match").cloned().unwrap_or(Bson::Document(BsonDocument::new())))
            .collect(),
        _ => vec![],
    }
}

fn permissions(kind: &str) -> Bson {
    Bson::Document(
        doc! { "$in": [format!("{kind}(\"any\")"), format!("{kind}(\"users\")"), format!("{kind}(\"user:bob\")")] },
    )
}

fn counters() -> Collection {
    collection(r#"{"$id":"counters"}"#)
}

fn assert_tenant_scope_only(fake: &Fake, operations: &[&str]) {
    for operation in operations {
        for filters in scope_filters(fake, operation) {
            assert!(
                at(&filters, "_permissions").is_none(),
                "The {operation} filter must not require read permission: Database authorizes writes before they reach the adapter"
            );
            assert_eq!(
                at(&filters, "_tenant"),
                Some(&Bson::Int32(TENANT)),
                "The {operation} filter must stay inside the tenant"
            );
        }
    }
}

#[utopia_async::test]
async fn writes_scope_by_tenant_without_read_permission() {
    let (adapter, fake, _) = scope_adapter().await;

    adapter.update_document(&counters(), "first", doc(r#"{"count":1}"#), true).await.unwrap();
    adapter
        .update_documents(
            &counters(),
            &doc(r#"{"count":42}"#),
            vec![doc(r#"{"$id":"first","$sequence":"sequence-first"}"#)],
            &[],
        )
        .await
        .unwrap();
    let new = doc(
        r#"{"$id":"second","$createdAt":"2026-01-01T00:00:00.000+00:00","$updatedAt":"2026-01-01T00:00:00.000+00:00","$permissions":[],"count":1}"#,
    );
    adapter
        .upserts()
        .unwrap()
        .upsert_documents(&counters(), vec![Change::new(Document::empty(), new)], None)
        .await
        .unwrap();
    adapter.delete_document(&counters(), "first").await.unwrap();
    adapter.delete_documents(&counters(), vec![s("sequence-first")], vec![]).await.unwrap();

    assert_eq!(scope_filters(&fake, "update").len(), 2);
    assert_eq!(scope_filters(&fake, "upsert").len(), 1);
    assert_eq!(scope_filters(&fake, "delete").len(), 2);
    assert_tenant_scope_only(&fake, &["update", "upsert", "delete"]);
}

#[utopia_async::test]
async fn increments_scope_by_tenant_without_read_permission() {
    let (adapter, fake, _) = scope_adapter().await;

    adapter
        .increase_document_attribute(
            &counters(),
            "first",
            "count",
            Value::Int(5),
            "2026-01-01 00:00:00.000",
            None,
            None,
        )
        .await
        .unwrap();
    adapter
        .increase_document_attribute(
            &counters(),
            "first",
            "count",
            Value::Int(-2),
            "2026-01-01 00:00:00.000",
            Some(Value::Int(0)),
            None,
        )
        .await
        .unwrap();

    assert_eq!(scope_filters(&fake, "update").len(), 2);
    assert_tenant_scope_only(&fake, &["update"]);
}

#[utopia_async::test]
async fn get_document_scopes_by_tenant_without_read_permission() {
    let (adapter, fake, _) = scope_adapter().await;

    adapter.get_document(&counters(), "first", vec![], false).await.unwrap();

    assert_eq!(scope_filters(&fake, "find"), vec![Bson::Document(doc! { "_uid": "first", "_tenant": TENANT })]);
}

#[utopia_async::test]
async fn find_filters_by_the_requested_permission() {
    let (adapter, fake, _) = scope_adapter().await;

    adapter
        .find(&counters(), vec![], Find { for_permission: PermissionType::Update, ..Find::default() })
        .await
        .unwrap();

    let filters = scope_filters(&fake, "find").remove(0);
    assert_eq!(at(&filters, "_tenant"), Some(&Bson::Int32(TENANT)));
    assert_eq!(at(&filters, "_permissions"), Some(&permissions("update")));
}

#[utopia_async::test]
async fn count_and_sum_filter_by_read_permission() {
    let (adapter, fake, _) = scope_adapter().await;

    adapter.count(&counters(), vec![], None).await.unwrap();
    adapter.sum(&counters(), "count", vec![], None).await.unwrap();

    let filters = scope_filters(&fake, "aggregate");
    assert_eq!(filters.len(), 2);
    for filters in filters {
        assert_eq!(at(&filters, "_tenant"), Some(&Bson::Int32(TENANT)));
        assert_eq!(at(&filters, "_permissions"), Some(&permissions("read")));
    }
}

#[utopia_async::test]
async fn metadata_reads_filter_definitions_by_read_permission() {
    let (adapter, fake, _) = scope_adapter().await;
    let metadata = collection(r#"{"$id":"_metadata"}"#);

    adapter.find(&metadata, vec![], Find::default()).await.unwrap();
    adapter.count(&metadata, vec![], None).await.unwrap();
    adapter.sum(&metadata, "count", vec![], None).await.unwrap();

    let mut reads = scope_filters(&fake, "find");
    reads.extend(scope_filters(&fake, "aggregate"));
    assert_eq!(reads.len(), 3);
    for filters in reads {
        assert_eq!(at(&filters, "_tenant"), Some(&Bson::Document(doc! { "$in": [TENANT, Bson::Null] })));
        assert_eq!(
            at(&filters, "_permissions"),
            Some(&permissions("read")),
            "Collection definitions are listed under their own read permissions"
        );
    }
}

#[utopia_async::test]
async fn reads_skip_the_permission_filter_while_authorization_is_disabled() {
    let (adapter, fake, authorization) = scope_adapter().await;

    authorization
        .skip(async {
            adapter.find(&counters(), vec![], Find::default()).await.unwrap();
            adapter.count(&counters(), vec![], None).await.unwrap();
        })
        .await;

    assert!(at(&scope_filters(&fake, "find")[0], "_permissions").is_none());
    assert!(at(&scope_filters(&fake, "aggregate")[0], "_permissions").is_none());
}

// ---- MongoPermissionStringsTest

fn permission_strings(roles: &[&str], kind: PermissionType) -> Vec<Value> {
    let authorization = Arc::new(Authorization::new(true));
    authorization.enable();
    authorization.clean_roles();
    for role in roles {
        authorization.add_role(role);
    }
    let filters = Permission::new(authorization).apply_filters(Array::new(), "documents", kind);
    match filters.get_str("_permissions") {
        Some(Value::Array(filter)) => match filter.get_str("$in") {
            Some(Value::Array(values)) => values.values().cloned().collect(),
            _ => vec![],
        },
        _ => vec![],
    }
}

#[test]
fn period_in_role_is_literal_not_regex_wildcard() {
    assert_eq!(permission_strings(&["user:alice."], PermissionType::Read), vec![s("read(\"user:alice.\")")]);
}

#[test]
fn mass_read_dot_padding_stays_exact() {
    let role = format!("user:a{}", ".".repeat(19));
    assert_eq!(permission_strings(&[&role], PermissionType::Read), vec![s(&format!("read(\"{role}\")"))]);
}

#[test]
fn matching_is_case_sensitive_and_uses_requested_type() {
    assert_eq!(permission_strings(&["user:alice"], PermissionType::Update), vec![s("update(\"user:alice\")")]);
}

#[test]
fn multiple_roles_map_to_exact_permission_strings() {
    assert_eq!(
        permission_strings(&["user:alice", "users"], PermissionType::Read),
        vec![s("read(\"user:alice\")"), s("read(\"users\")")]
    );
}

#[test]
fn empty_roles_produce_empty_list() {
    assert_eq!(permission_strings(&[], PermissionType::Read), Vec::<Value>::new());
}

#[test]
fn values_are_strings_not_regex() {
    for value in permission_strings(&["user:alice."], PermissionType::Read) {
        let Value::Str(text) = value else { panic!("Permission $in values must be strings") };
        let text = String::from_utf8_lossy(text.as_bytes()).into_owned();
        assert!(text.starts_with("read(\""));
        assert!(text.ends_with("\")"));
    }
}
