//! PHP 8.3.0's reads over rows, on SQLite in memory as PHP runs them:
//! `tests/unit/RowCountTest.php` (`count()` and `sum()` over grouped,
//! distinct and aggregate reads), `tests/unit/AggregateOverflowTest.php`
//! and `tests/unit/SumOverflowTest.php` (a floating aggregate that overflows
//! a double is refused), and `tests/unit/AggregationTest.php` (aggregate
//! rows typed by method and attribute). Each test names the PHP assertion
//! it ports.

use std::sync::Arc;

use super::super::Database;
use crate::adapter::sql::{Engine, Sql};
use crate::attribute::Attribute;
use crate::database::Hook;
use crate::database::testing::block_on;
use crate::database::testing_memory::{collection, database_on, doc};
use crate::error::Kind;
use crate::hook::permissions::Permissions;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::value::{Str, Value, list};

const PRODUCTS: &str = "products";
const REVIEWS: &str = "reviews";

fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

async fn sqlite(name: &str) -> Database {
    let adapter = Sql::connect(Engine::Sqlite, "sqlite::memory:", None, None).await.expect("SQLite");
    let db = database_on(Arc::new(adapter), name).await;
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    db
}

/// `RowCountTest::database()`: products and reviews, each readable by the
/// admin and, document by document, by the viewer.
async fn rows() -> Database {
    let db = sqlite("rows").await;
    let permissions = vec![Permission::create(&Role::any()), Permission::read(&Role::user("admin", ""))];
    collection(
        &db,
        PRODUCTS,
        vec![Attribute::string("category", 50).required(true), Attribute::integer("price").required(true)],
        permissions.clone(),
        true,
    )
    .await;
    collection(
        &db,
        REVIEWS,
        vec![Attribute::string("product", 36).required(true), Attribute::integer("score").required(true)],
        permissions,
        true,
    )
    .await;

    let viewer = list([s(&Permission::read(&Role::user("viewer", "")))]);
    let products = [
        ("p1", "electronics", 1200, true),
        ("p2", "electronics", 800, true),
        ("p3", "electronics", 500, false),
        ("p4", "clothing", 30, true),
        ("p5", "clothing", 50, false),
        ("p6", "clothing", 120, false),
        ("p7", "books", 15, true),
        ("p8", "books", 60, true),
        ("p9", "books", 10, false),
        ("p10", "toys", 25, false),
    ];
    for (id, category, price, visible) in products {
        let permissions = if visible { viewer.clone() } else { list([]) };
        let product = doc(vec![
            ("$id", s(id)),
            ("category", s(category)),
            ("price", Value::Int(price)),
            ("$permissions", permissions),
        ]);
        db.create_document(PRODUCTS, product).await.unwrap();
    }
    let reviews = [
        ("r1", "p1", 5, true),
        ("r2", "p1", 4, true),
        ("r3", "p4", 3, false),
        ("r4", "p7", 5, true),
        ("r5", "p3", 2, true),
        ("r6", "p10", 1, true),
    ];
    for (id, product, score, visible) in reviews {
        let permissions = if visible { viewer.clone() } else { list([]) };
        let review = doc(vec![
            ("$id", s(id)),
            ("product", s(product)),
            ("score", Value::Int(score)),
            ("$permissions", permissions),
        ]);
        db.create_document(REVIEWS, review).await.unwrap();
    }
    db
}

fn act_as(db: &Database, role: &str) {
    let authorization = db.authorization();
    authorization.clean_roles();
    authorization.add_role(&Role::user(role, "").to_string());
}

fn join() -> Query {
    Query::join(REVIEWS, "r", vec![Query::on("$id", "product", "=")]).unwrap()
}

// PHP: RowCountTest::testCountIsTheNumberOfRowsTheReadReturns, its `groupBy`, `distinct`,
// `join with groupBy` and `distinct joined attribute` sets:
// `$this->assertSame($expected, $database->count(self::PRODUCTS, $queries), $role)`.
#[test]
fn count_is_the_number_of_rows_the_read_returns() {
    block_on(async {
        let db = rows().await;
        let shapes: Vec<(Vec<Query>, i64, i64)> = vec![
            (vec![Query::count("*", "n"), Query::group_by(["category"])], 4, 3),
            (vec![Query::distinct(), Query::select(["category"])], 4, 3),
            (vec![Query::sum("price", "total")], 1, 1),
            (vec![join(), Query::count("*", "n"), Query::group_by(["category"])], 4, 2),
            (vec![join(), Query::distinct(), Query::select(["r.score"])], 5, 2),
        ];
        for (queries, admin, viewer) in shapes {
            for (role, expected) in [("admin", admin), ("viewer", viewer)] {
                act_as(&db, role);
                assert_eq!(db.count(PRODUCTS, queries.clone(), None).await.unwrap(), expected, "{role}: {queries:?}");
                let mut paged = queries.clone();
                paged.extend([Query::limit(1), Query::offset(1)]);
                assert_eq!(db.count(PRODUCTS, paged, None).await.unwrap(), expected, "{role}: the page is not counted");
                assert_eq!(db.count(PRODUCTS, queries.clone(), Some(2)).await.unwrap(), expected.min(2), "{role}: max");
            }
        }
    });
}

// PHP: RowCountTest::testSumAddsUpTheGroupedOrDistinctRows,
// `$this->assertSame($distinct, $database->sum(self::REVIEWS, 'score', [Query::count('*', 'n'), Query::groupBy(['score'])]), $role)`.
#[test]
fn sum_adds_up_the_grouped_or_distinct_rows() {
    block_on(async {
        let db = rows().await;
        for (role, every, distinct, joined) in [("admin", 20, 15, 15), ("viewer", 17, 12, 9)] {
            act_as(&db, role);
            assert_eq!(db.sum(REVIEWS, "score", Vec::new(), None).await.unwrap(), Value::Int(every));
            let grouped = vec![Query::count("*", "n"), Query::group_by(["score"])];
            assert_eq!(db.sum(REVIEWS, "score", grouped, None).await.unwrap(), Value::Int(distinct), "{role}");
            let selected = vec![Query::distinct(), Query::select(["score"])];
            assert_eq!(db.sum(REVIEWS, "score", selected, None).await.unwrap(), Value::Int(distinct), "{role}");
            let joined_rows = vec![join(), Query::distinct(), Query::select(["r.score"])];
            assert_eq!(db.sum(PRODUCTS, "r.score", joined_rows, None).await.unwrap(), Value::Int(joined), "{role}");
        }
    });
}

// PHP: RowCountTest::testSumOfAnAttributeTheGroupsDoNotHoldIsAnInvalidQuery,
// `$this->expectExceptionMessage('Invalid query: sum() over grouped rows adds up one of their groupBy attributes, and score is not one of them')`.
#[test]
fn a_sum_of_an_attribute_the_groups_do_not_hold_is_refused() {
    block_on(async {
        let db = rows().await;
        let error = db
            .sum(REVIEWS, "score", vec![Query::count("*", "n"), Query::group_by(["product"])], None)
            .await
            .unwrap_err();
        assert_eq!(error.kind(), Kind::Query);
        assert_eq!(
            error.message(),
            "Invalid query: sum() over grouped rows adds up one of their groupBy attributes, and score is not one of them"
        );
    });
}

/// `AggregateOverflowTest::database()`: `readings` holding the doubles.
async fn readings(values: &[f64]) -> Database {
    let db = sqlite("aggregate_overflow").await;
    let any = Role::any();
    collection(
        &db,
        "readings",
        vec![Attribute::double("reading").required(true)],
        vec![Permission::create(&any), Permission::read(&any)],
        false,
    )
    .await;
    for value in values {
        db.create_document("readings", doc(vec![("reading", Value::Float(*value))])).await.unwrap();
    }
    db
}

// PHP: AggregateOverflowTest::testAnAggregateThatOverflowsIsRefused and
// testAnAggregateThatOverflowsBelowTheRangeIsRefused, `$this->expectException(LimitException::class)`;
// SumOverflowTest::testASumThatOverflowsIsRefused.
#[test]
fn a_floating_aggregate_that_overflows_is_refused() {
    block_on(async {
        for values in [[1e308, 1e308], [-1e308, -1e308]] {
            let db = readings(&values).await;
            for aggregate in [Query::sum("reading", "total"), Query::avg("reading", "total"), Query::sum("reading", "")]
            {
                let error = db.aggregate("readings", vec![aggregate]).await.unwrap_err();
                assert_eq!((error.kind(), error.message()), (Kind::Limit, "Value out of range"));
            }
            let error = db.sum("readings", "reading", Vec::new(), None).await.unwrap_err();
            assert_eq!(error.kind(), Kind::Limit);
        }
    });
}

// PHP: AggregateOverflowTest::testTheLargestFiniteSumIsStillAnswered,
// `$this->assertSame(1e308, $rows[0]['total'])`.
#[test]
fn the_largest_finite_sum_is_still_answered() {
    block_on(async {
        let db = readings(&[1e308]).await;
        let rows = db
            .aggregate("readings", vec![Query::sum("reading", "total"), Query::avg("reading", "mean")])
            .await
            .unwrap();
        assert_eq!(rows[0].get_str("total"), Some(&Value::Float(1e308)));
        assert_eq!(rows[0].get_str("mean"), Some(&Value::Float(1e308)));
    });
}

// PHP: AggregationTest::testAggregatesAreTypedByMethodAndAttribute: counts are integers, averages floats,
// a sum over an integer attribute an integer, and `min()` typed as its attribute.
#[test]
fn aggregates_are_typed_by_method_and_attribute() {
    block_on(async {
        let db = rows().await;
        act_as(&db, "admin");
        let rows = db
            .aggregate(
                PRODUCTS,
                vec![
                    Query::count("*", "n"),
                    Query::avg("price", "mean"),
                    Query::sum("price", "total"),
                    Query::min("price", "lowest"),
                    Query::group_by(["category"]),
                    Query::order_asc("category", None),
                ],
            )
            .await
            .unwrap();
        let books = &rows[0];
        assert_eq!(books.get_str("category"), Some(&s("books")));
        assert_eq!(books.get_str("n"), Some(&Value::Int(3)));
        // A read fetches a REAL at the round-trip precision (8.3.6 SQL.php:249, `fetchExactly()`).
        assert_eq!(books.get_str("mean"), Some(&Value::Float(28.333333333333332)));
        assert_eq!(books.get_str("total"), Some(&Value::Int(85)));
        assert_eq!(books.get_str("lowest"), Some(&Value::Int(10)));
    });
}
