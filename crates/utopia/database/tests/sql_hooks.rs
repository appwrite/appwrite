//! The SQL adapters' builder hooks.
//!
//! PHP: `tests/unit/Adapter/SQL/Hook/**/*Test.php`, `tests/unit/Hook/JoinAliasQuotingTest.php`,
//! and the parts of `tests/unit/QueryBuilderTenancyTest.php` that only need
//! the hooks on a plain builder. Expected SQL was checked against PHP.
#![cfg(any(feature = "postgres", feature = "mariadb", feature = "sqlite"))]

use std::sync::Arc;

use utopia_database::adapter::sql::hook::{column, join, permission, tenant};
use utopia_database::{Kind, database, storage};
use utopia_query::builder::{Condition, JoinType};
use utopia_query::hook::{Filter as _, JoinCondition, JoinFilter as _, Placement};
use utopia_query::{Value, string};

/// Integer bindings.
fn ints(values: &[i64]) -> Vec<Value> {
    values.iter().map(|v| Value::Int(*v)).collect()
}

/// A join filter's result, which these hooks always give.
fn some(result: utopia_query::Result<Option<JoinCondition>>) -> JoinCondition {
    result.expect("filter_join").expect("a condition")
}

/// The database error a hook raised, carried through `utopia_query`.
fn database_error(error: &utopia_query::Error) -> utopia_database::Error {
    error
        .previous()
        .and_then(|previous| previous.downcast_ref::<utopia_database::Error>())
        .cloned()
        .expect("a database error")
}

/// Whether `alias` is only ever named quoted with `quote` in `expression`:
/// no whole-word occurrence without the quote on both sides.
fn only_quoted(alias: &str, expression: &str, quote: &str) -> bool {
    let word = |c: Option<char>| c.is_some_and(|c| c.is_ascii_alphanumeric() || c == '_');
    let mut found_quoted = false;
    for (at, _) in expression.match_indices(alias) {
        let before = expression[..at].chars().next_back();
        let after = expression[at + alias.len()..].chars().next();
        if word(before) || word(after) {
            continue;
        }
        let quoted = expression[..at].ends_with(quote) && expression[at + alias.len()..].starts_with(quote);
        if !quoted {
            return false;
        }
        found_quoted = true;
    }
    found_quoted && expression.contains(&format!("{quote}{alias}{quote}."))
}

mod tenant_filter {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Tenant\FilterTest`.

    use super::*;
    use tenant::Filter;

    #[test]
    fn metadata_permission_rows_may_be_tenantless() {
        let hook = Filter::new([989])
            .with_metadata_collection(database::METADATA)
            .with_collection(storage::permissions_table(database::METADATA));

        let condition = hook.filter("perms").unwrap();

        assert!(condition.expression.contains("IS NULL"), "The metadata permissions table must match tenantless rows");
        assert_eq!(condition.bindings, ints(&[989]));
    }

    #[test]
    fn a_non_metadata_permissions_table_stays_strictly_tenanted() {
        let hook = Filter::new([989])
            .with_metadata_collection(database::METADATA)
            .with_collection(storage::permissions_table("orders"));

        let condition = hook.filter("perms").unwrap();

        assert!(!condition.expression.contains("IS NULL"), "A project collection must not leak across tenants");
        assert_eq!(condition.bindings, ints(&[989]));
    }

    #[test]
    fn filter_does_not_allow_null_tenant_by_default() {
        let hook = Filter::new([7]).with_collection("orders");

        let condition = hook.filter("table_main").unwrap();

        assert_eq!(condition.expression, format!("`table_main`.{} IN (?)", storage::TENANT));
        assert_eq!(condition.bindings, ints(&[7]));
    }

    #[test]
    fn filter_allows_null_tenant_when_column_provided() {
        let hook = Filter::new([7])
            .with_collection("orders")
            .with_allow_null_column(format!("table_main.{}", storage::TENANT));

        let condition = hook.filter("table_main").unwrap();

        assert!(condition.expression.contains(&format!("`table_main`.{} IN (?)", storage::TENANT)));
        assert!(condition.expression.contains("IS NULL"));
        assert_eq!(condition.bindings, ints(&[7]));
    }

    #[test]
    fn filter_join_left_places_tenant_in_on_clause() {
        let result = some(Filter::new([7]).filter_join("j0", JoinType::Left));

        assert_eq!(result.placement, Placement::On);
        assert_eq!(result.condition.expression, format!("`j0`.{} IN (?)", storage::TENANT));
        assert_eq!(result.condition.bindings, ints(&[7]));
    }

    #[test]
    fn filter_join_right_places_tenant_in_where_clause() {
        let result = some(Filter::new([7]).filter_join("j0", JoinType::Right));

        assert_eq!(result.placement, Placement::Where);
        assert_eq!(result.condition.expression, format!("`j0`.{} IN (?)", storage::TENANT));
        assert_eq!(result.condition.bindings, ints(&[7]));
    }

    #[test]
    fn filter_join_full_outer_places_tenant_in_where_clause_and_allows_null() {
        let result = some(Filter::new([7]).filter_join("j0", JoinType::FullOuter));

        assert_eq!(result.placement, Placement::Where);
        assert!(result.condition.expression.contains(&format!("`j0`.{} IN (?)", storage::TENANT)));
        assert!(result.condition.expression.contains("IS NULL"));
        assert_eq!(result.condition.bindings, ints(&[7]));
    }

    #[test]
    fn filter_join_inner_places_tenant_in_on_clause() {
        let result = some(Filter::new([7]).filter_join("j0", JoinType::Inner));

        assert_eq!(result.placement, Placement::On);
        assert_eq!(result.condition.expression, format!("`j0`.{} IN (?)", storage::TENANT));
    }

    #[test]
    fn filter_join_matches_the_tenant_of_an_unindexed_join_as_a_range() {
        for join_type in [JoinType::Inner, JoinType::Left] {
            let result = some(Filter::new([7]).with_unindexed(["j0"]).filter_join("j0", join_type));

            assert_eq!(result.placement, Placement::On);
            assert_eq!(
                result.condition.expression,
                format!("(`j0`.{0} >= ? AND `j0`.{0} <= ?)", storage::TENANT),
                "{join_type:?}"
            );
            assert_eq!(result.condition.bindings, ints(&[7, 7]));
        }
    }

    #[test]
    fn filter_join_keeps_the_equality_of_an_indexed_join() {
        let result = some(Filter::new([7]).with_unindexed(["j1"]).filter_join("j0", JoinType::Inner));

        assert_eq!(result.condition.expression, format!("`j0`.{} IN (?)", storage::TENANT));
        assert_eq!(result.condition.bindings, ints(&[7]));
    }

    #[test]
    fn filter_join_keeps_the_equality_of_an_unindexed_join_placed_in_where() {
        for join_type in [JoinType::Right, JoinType::FullOuter, JoinType::Cross] {
            let result = some(Filter::new([7]).with_unindexed(["j0"]).filter_join("j0", join_type));

            assert_eq!(result.placement, Placement::Where);
            assert!(result.condition.expression.contains(&format!("`j0`.{} IN (?)", storage::TENANT)));
            assert!(!result.condition.expression.contains(">="), "{join_type:?}");
        }
    }

    #[test]
    fn filter_join_matches_several_tenants_of_an_unindexed_join_as_a_list() {
        let result = some(Filter::new([7, 8]).with_unindexed(["j0"]).filter_join("j0", JoinType::Inner));

        assert_eq!(result.condition.expression, format!("`j0`.{} IN (?, ?)", storage::TENANT));
        assert_eq!(result.condition.bindings, ints(&[7, 8]));
    }

    #[test]
    fn filter_join_cross_places_tenant_in_where_clause() {
        let result = some(Filter::new([7]).filter_join("j0", JoinType::Cross));

        assert_eq!(result.placement, Placement::Where);
        assert_eq!(result.condition.expression, format!("`j0`.{} IN (?)", storage::TENANT));
    }

    /// Checked against PHP: no tenant binds `null`, and `"0"` is an empty
    /// metadata collection.
    #[test]
    fn no_tenant_matches_no_tenants_rows() {
        let condition = Filter::new(Vec::<Value>::new()).filter("t").unwrap();
        assert_eq!(condition.expression, "`t`._tenant IN (?)");
        assert_eq!(condition.bindings, vec![Value::Null]);

        let condition =
            Filter::new([Value::Null]).with_metadata_collection("0").with_collection("0").filter("t").unwrap();
        assert_eq!(condition.expression, "`t`._tenant IN (?)");

        let condition = Filter::new([string("a"), Value::Null]).filter("t").unwrap();
        assert_eq!(condition.expression, "`t`._tenant IN (?, ?)");
        assert_eq!(condition.bindings, vec![string("a"), Value::Null]);
    }
}

mod tenant_raw {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Tenant\RawTest`.

    use super::*;
    use tenant::Raw;

    const TABLE: &str = "appwrite.ns_authors";
    const TENANT: i64 = 7;

    fn filter() -> Raw {
        Raw::new(TENANT, TABLE, false, "`")
    }

    #[test]
    fn inner_and_left_joins_meet_their_condition_in_on() {
        for join_type in [JoinType::Inner, JoinType::Left] {
            let result = some(filter().filter_join("Book", join_type));

            assert_eq!(result.placement, Placement::On, "A {join_type:?} only pairs the tenant's rows");
            assert_eq!(result.condition.expression, "`Book`._tenant IN (?)");
            assert_eq!(result.condition.bindings, ints(&[TENANT]));
        }
    }

    #[test]
    fn other_joins_meet_their_condition_in_where_letting_missing_rows_through() {
        for join_type in [JoinType::Right, JoinType::FullOuter, JoinType::Cross, JoinType::Natural] {
            let result = some(filter().filter_join("Book", join_type));

            assert_eq!(result.placement, Placement::Where, "ON cannot drop the rows a {join_type:?} keeps");
            assert_eq!(
                result.condition.expression, "(`Book`._tenant IN (?) OR `Book`.`_uid` IS NULL)",
                "A later outer join may leave the table missing from a row"
            );
            assert_eq!(result.condition.bindings, ints(&[TENANT]));
        }
    }

    #[test]
    fn the_main_table_is_named_as_the_builder_names_it() {
        let condition = filter().filter(TABLE).unwrap();

        assert_eq!(
            condition.expression, "`appwrite`.`ns_authors`._tenant IN (?)",
            "A bare column is ambiguous once a join is added"
        );
        assert_eq!(condition.bindings, ints(&[TENANT]));
        assert_eq!(
            filter().filter("author").unwrap().expression,
            "`author`._tenant IN (?)",
            "A main table the caller aliases is named by its alias"
        );
    }

    #[test]
    fn the_main_table_lets_missing_rows_through_only_after_a_right_or_full_outer_join() {
        for (join_type, preserving) in [
            (JoinType::Inner, false),
            (JoinType::Left, false),
            (JoinType::Cross, false),
            (JoinType::Natural, false),
            (JoinType::Right, true),
            (JoinType::FullOuter, true),
        ] {
            let filter = filter();
            filter.filter_join("Book", join_type).unwrap();

            assert_eq!(
                filter.filter(TABLE).unwrap().expression,
                if preserving {
                    "(`appwrite`.`ns_authors`._tenant IN (?) OR `appwrite`.`ns_authors`.`_uid` IS NULL)"
                } else {
                    "`appwrite`.`ns_authors`._tenant IN (?)"
                },
                "{join_type:?}"
            );
        }
    }

    #[test]
    fn metadata_keeps_the_definitions_a_pool_shares_without_a_tenant() {
        let filter = Raw::new(TENANT, TABLE, true, "`");

        assert_eq!(
            filter.filter(TABLE).unwrap().expression,
            "(`appwrite`.`ns_authors`._tenant IN (?) OR `appwrite`.`ns_authors`._tenant IS NULL)"
        );
        assert_eq!(
            some(filter.filter_join("Book", JoinType::Inner)).condition.expression,
            "`Book`._tenant IN (?)",
            "Only the main table is metadata"
        );
    }

    #[test]
    fn each_statement_learns_its_own_joins() {
        let filter = filter();
        filter.filter_join("Book", JoinType::Right).unwrap();
        filter.filter(TABLE).unwrap();

        assert_eq!(
            filter.filter(TABLE).unwrap().expression,
            "`appwrite`.`ns_authors`._tenant IN (?)",
            "An update after a read with a right join has no join of its own"
        );

        filter.filter_join("Book", JoinType::Right).unwrap();
        filter.reset();

        assert_eq!(
            filter.filter(TABLE).unwrap().expression,
            "`appwrite`.`ns_authors`._tenant IN (?)",
            "A build starts without the joins an abandoned one learned"
        );
    }

    #[test]
    fn a_right_or_full_outer_join_refuses_a_renamed_main_table() {
        for join_type in [JoinType::Right, JoinType::FullOuter] {
            let filter = filter();
            filter.filter_join("Book", join_type).unwrap();

            let error = filter.filter("author").expect_err("Database::from() names the main table in its ON");
            let error = database_error(&error);
            assert_eq!(error.kind(), Kind::Query);
            assert!(error.message().contains(TABLE));
        }

        let filter = filter();
        filter.filter_join("Book", JoinType::Inner).unwrap();
        assert_eq!(filter.filter("author").unwrap().expression, "`author`._tenant IN (?)");
    }

    #[test]
    fn a_statement_without_a_table_is_refused() {
        let error = filter().filter("").unwrap_err();
        assert_eq!(database_error(&error).kind(), Kind::Query);
    }

    #[test]
    fn a_right_or_full_outer_join_pairs_only_rows_the_tenant_could_read() {
        let filter = filter();
        filter.filter_join("Extra", JoinType::Cross).unwrap();
        filter.filter_join("Note", JoinType::Inner).unwrap();
        filter.filter_join("Review", JoinType::Right).unwrap();

        let condition = filter.outer_join("Review", JoinType::Right).unwrap();

        assert_eq!(
            condition.expression,
            "(`appwrite`.`ns_authors`._tenant IN (?) OR `appwrite`.`ns_authors`.`_uid` IS NULL) AND `Review`._tenant IN (?) AND (`Extra`._tenant IN (?) OR `Extra`.`_uid` IS NULL)",
            "The main table, the join itself and the earlier table whose condition sits in WHERE; the inner join met its own in ON"
        );
        assert_eq!(condition.bindings, ints(&[TENANT, TENANT, TENANT]));
    }

    #[test]
    fn postgres_quotes_every_table_it_names() {
        let filter = Raw::new(TENANT, "appwrite.ns_authors", false, "\"");
        filter.filter_join("Book", JoinType::FullOuter).unwrap();

        assert_eq!(
            some(filter.filter_join("Review", JoinType::Right)).condition.expression,
            "(\"Review\"._tenant IN (?) OR \"Review\".\"_uid\" IS NULL)"
        );
        assert_eq!(
            filter.outer_join("Review", JoinType::Right).unwrap().expression,
            "(\"appwrite\".\"ns_authors\"._tenant IN (?) OR \"appwrite\".\"ns_authors\".\"_uid\" IS NULL) AND \"Review\"._tenant IN (?) AND (\"Book\"._tenant IN (?) OR \"Book\".\"_uid\" IS NULL)"
        );
    }

    #[test]
    fn a_digit_leading_outer_join_alias_and_database_are_quoted() {
        let filter = Raw::new(TENANT, "1db.ns_authors", false, "`");

        assert_eq!(
            some(filter.filter_join("9x", JoinType::Right)).condition.expression,
            "(`9x`._tenant IN (?) OR `9x`.`_uid` IS NULL)"
        );
        assert_eq!(
            filter.filter("1db.ns_authors").unwrap().expression,
            "(`1db`.`ns_authors`._tenant IN (?) OR `1db`.`ns_authors`.`_uid` IS NULL)"
        );
    }
}

mod tenant_raw_outer_join {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Tenant\RawOuterJoinTest`.

    use super::*;
    use tenant::{Raw, RawOuterJoin};

    #[test]
    fn only_right_and_full_outer_joins_need_conditions_in_their_own_on() {
        let tenants = Arc::new(Raw::new(7, "ns_authors", false, "`"));
        let hook = RawOuterJoin::new(tenants.clone());

        for join_type in [JoinType::Inner, JoinType::Left, JoinType::Cross, JoinType::Natural] {
            tenants.filter_join("Book", join_type).unwrap();
            assert!(
                hook.filter_join("Book", join_type).unwrap().is_none(),
                "A {join_type:?} meets its condition where Raw places it"
            );
            tenants.reset();
        }

        for join_type in [JoinType::Right, JoinType::FullOuter] {
            tenants.filter_join("Book", join_type).unwrap();
            let result = some(hook.filter_join("Book", join_type));

            assert_eq!(result.placement, Placement::On, "Only ON decides which rows the join pairs");
            assert_eq!(tenants.outer_join("Book", join_type).unwrap().expression, result.condition.expression);
            assert_eq!(
                result.condition.expression,
                "(`ns_authors`._tenant IN (?) OR `ns_authors`.`_uid` IS NULL) AND `Book`._tenant IN (?)"
            );
            tenants.reset();
        }
    }
}

mod tenant_outer_join {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Tenant\OuterJoinTest`.

    use super::*;
    use tenant::{Filter, OuterJoin};

    const SOURCE: &str = "table_main";
    const ALIAS: &str = "j0";

    #[test]
    fn both_sides_are_scoped_to_the_tenant_inside_on() {
        for join_type in [JoinType::Right, JoinType::FullOuter] {
            let filter = Filter::new([7])
                .with_metadata_collection(database::METADATA)
                .with_collection("orders")
                .with_allow_null_column(format!("{SOURCE}.{}", storage::UID));

            let result = some(OuterJoin::new(filter, SOURCE).filter_join(ALIAS, join_type));

            assert_eq!(result.placement, Placement::On, "Only ON decides which rows the join pairs");
            assert_eq!(
                result.condition.expression,
                "(`table_main`._tenant IN (?) OR `table_main`.`_uid` IS NULL) AND `j0`._tenant IN (?)"
            );
            assert_eq!(result.condition.bindings, ints(&[7, 7]));
        }
    }

    #[test]
    fn joins_tenant_filter_already_scopes_are_left_to_it() {
        for join_type in [JoinType::Inner, JoinType::Left, JoinType::Cross, JoinType::Natural] {
            let filter = Filter::new([7]).with_metadata_collection(database::METADATA).with_collection("orders");

            assert!(OuterJoin::new(filter, SOURCE).filter_join(ALIAS, join_type).unwrap().is_none());
        }
    }

    #[test]
    fn a_tenantless_metadata_row_stays_matchable() {
        let filter = Filter::new([7]).with_metadata_collection(database::METADATA).with_collection(database::METADATA);

        let result = some(OuterJoin::new(filter, SOURCE).filter_join(ALIAS, JoinType::Right));

        assert_eq!(
            result.condition.expression,
            "(`table_main`._tenant IN (?) OR `table_main`._tenant IS NULL) AND `j0`._tenant IN (?)",
            "A shared pool defines its metadata once, with no tenant, for every tenant to read"
        );
    }

    #[test]
    fn every_tenant_of_a_cross_tenant_read_is_bound_on_both_sides() {
        let filter = Filter::new([1, 2]).with_metadata_collection(database::METADATA).with_collection("orders");

        let result = some(OuterJoin::new(filter, SOURCE).filter_join(ALIAS, JoinType::FullOuter));

        assert_eq!(result.condition.expression, "`table_main`._tenant IN (?, ?) AND `j0`._tenant IN (?, ?)");
        assert_eq!(result.condition.bindings, ints(&[1, 2, 1, 2]));
    }

    #[test]
    fn a_full_outer_join_treats_only_a_missing_joined_row_as_unmatched() {
        let result = some(Filter::new([7]).filter_join(ALIAS, JoinType::FullOuter));

        assert_eq!(result.placement, Placement::Where);
        assert_eq!(
            result.condition.expression, "(`j0`._tenant IN (?) OR `j0`.`_uid` IS NULL)",
            "A stored row without a tenant is not a missing row, so the tenant column cannot tell them apart"
        );
    }
}

mod join_chain {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Join\ChainTest`.

    use super::*;
    use join::Chain;
    use utopia_database::Query;

    #[test]
    fn joins_are_keyed_the_way_the_builder_hands_them_to_join_filters() {
        let chain = Chain::from_queries(&[
            Query::equal("name", ["a"]),
            Query::join("books", "book", vec![Query::on("$id", "authorId", "=")]).unwrap(),
            Query::cross_join("extras", "extra").unwrap(),
            Query::right_join("reviews", "review", vec![Query::on("$id", "authorId", "=")]).unwrap(),
            Query::full_outer_join("notes", "note", vec![Query::on("$id", "authorId", "=")]).unwrap(),
        ]);

        assert_eq!(chain.preceding("note"), ["extra", "review"], "Joins are keyed by their alias");
        assert!(chain.has(JoinType::Cross));
        assert!(!chain.has(JoinType::Left));
    }

    #[test]
    fn only_tables_whose_conditions_sit_in_where_precede_a_later_join() {
        let chain = Chain::new([
            ("inner", JoinType::Inner),
            ("left", JoinType::Left),
            ("right", JoinType::Right),
            ("full", JoinType::FullOuter),
            ("cross", JoinType::Cross),
            ("last", JoinType::Right),
        ]);

        assert_eq!(chain.preceding("last"), ["right", "full", "cross"]);
        assert_eq!(chain.preceding("full"), ["right"]);
        assert!(chain.preceding("right").is_empty(), "Inner and left joins meet their conditions in their own ON");
        assert!(chain.preceding("inner").is_empty());
    }

    #[test]
    fn an_alias_outside_the_chain_has_no_preceding_tables() {
        let chain = Chain::new([("right", JoinType::Right), ("last", JoinType::Right)]);

        assert!(
            chain.preceding("unknown").is_empty(),
            "Repeating conditions of tables the join may not follow would reference aliases it cannot see"
        );
    }

    #[test]
    fn only_right_and_full_outer_joins_can_leave_a_table_missing() {
        assert!(!Chain::default().has_preserving_outer_join());
        assert!(
            !Chain::new([("a", JoinType::Inner), ("b", JoinType::Left), ("c", JoinType::Cross)])
                .has_preserving_outer_join()
        );
        assert!(Chain::new([("a", JoinType::Inner), ("b", JoinType::Right)]).has_preserving_outer_join());
        assert!(Chain::new([("a", JoinType::FullOuter)]).has_preserving_outer_join());
    }
}

mod join_outer_chain {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Join\OuterChainTest`.

    use super::*;
    use join::{Chain, OuterChain};

    #[test]
    fn every_earlier_table_filtered_in_where_is_repeated_in_on_relaxed_for_missing_rows() {
        for join_type in [JoinType::Right, JoinType::FullOuter] {
            let chain = Chain::new([
                ("b", JoinType::Inner),
                ("c", JoinType::Right),
                ("x", JoinType::Cross),
                ("d", join_type),
                ("e", JoinType::Right),
            ]);
            let tenants = tenant::Filter::new([7]);
            let conditions: Vec<(&str, Condition)> =
                ["b", "c", "x", "d", "e"].into_iter().map(|alias| (alias, tenants.joined(alias))).collect();

            let result = some(OuterChain::new(chain, conditions, "`").filter_join("d", join_type));

            assert_eq!(result.placement, Placement::On, "Only ON decides which rows the join pairs");
            assert_eq!(
                result.condition.expression,
                "(`c`._tenant IN (?) OR `c`.`_uid` IS NULL) AND (`x`._tenant IN (?) OR `x`.`_uid` IS NULL)",
                "The inner-joined table meets its condition in its own ON; a table an earlier outer join left missing must not stop the pairing"
            );
            assert_eq!(result.condition.bindings, ints(&[7, 7]));
        }
    }

    #[test]
    fn joins_that_keep_no_unmatched_rows_of_earlier_tables_need_nothing() {
        for join_type in [JoinType::Inner, JoinType::Left, JoinType::Cross] {
            let chain = Chain::new([("c", JoinType::Right), ("d", join_type)]);

            assert!(
                OuterChain::new(chain, [("c", Condition::new("c.ok", vec![]))], "`")
                    .filter_join("d", join_type)
                    .unwrap()
                    .is_none()
            );
        }
    }

    #[test]
    fn the_first_outer_join_and_tables_without_conditions_add_nothing() {
        let chain = Chain::new([("c", JoinType::Right), ("d", JoinType::Right)]);

        assert!(
            OuterChain::new(chain.clone(), [("c", Condition::new("c.ok", vec![]))], "`")
                .filter_join("c", JoinType::Right)
                .unwrap()
                .is_none()
        );
        assert!(
            OuterChain::new(chain, [("d", Condition::new("d.ok", vec![]))], "`")
                .filter_join("d", JoinType::Right)
                .unwrap()
                .is_none()
        );
    }

    #[test]
    fn bindings_follow_the_conditions_in_order() {
        let chain = Chain::new([("c", JoinType::FullOuter), ("x", JoinType::Cross), ("d", JoinType::Right)]);

        let result = some(
            OuterChain::new(
                chain,
                [
                    ("x", Condition::new("x.role IN (?, ?)", vec![string("x1"), string("x2")])),
                    ("c", Condition::new("c.role = ?", vec![string("c1")])),
                ],
                "\"",
            )
            .filter_join("d", JoinType::Right),
        );

        assert_eq!(
            result.condition.expression,
            "(c.role = ? OR \"c\".\"_uid\" IS NULL) AND (x.role IN (?, ?) OR \"x\".\"_uid\" IS NULL)"
        );
        assert_eq!(result.condition.bindings, vec![string("c1"), string("x1"), string("x2")]);
    }
}

mod permission_join {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Permission\JoinTest`.

    use super::*;
    use permission::{Filter, Join, Options};

    fn permission_filter() -> Filter {
        Filter::with_options(
            vec!["any".to_owned()],
            |table: &str| format!("perms_{table}"),
            Options { document_column: format!("j0.{}", storage::UID), ..Options::default() },
        )
        .unwrap()
    }

    #[test]
    fn left_join_places_permission_in_on_clause() {
        let hook = Join::new(permission_filter(), "j0", "`", false);

        let result = some(hook.filter_join("j0", JoinType::Left));

        assert_eq!(result.placement, Placement::On);
        assert!(result.condition.expression.contains(&format!("`j0`.`{}`", storage::UID)));
        assert!(hook.filter_join("j1", JoinType::Left).unwrap().is_none());
    }

    #[test]
    fn inner_join_places_permission_in_on_clause() {
        let result = some(Join::new(permission_filter(), "j0", "`", false).filter_join("j0", JoinType::Inner));

        assert_eq!(result.placement, Placement::On);
    }

    #[test]
    fn right_join_places_permission_in_where_clause() {
        let result = some(Join::new(permission_filter(), "j0", "`", false).filter_join("j0", JoinType::Right));

        assert_eq!(result.placement, Placement::Where);
        assert!(result.condition.expression.contains(&format!("`j0`.`{}`", storage::UID)));
        assert!(!result.condition.expression.contains("IS NULL"));
    }

    #[test]
    fn full_outer_join_places_permission_in_where_clause_and_allows_null_uid() {
        let result = some(Join::new(permission_filter(), "j0", "`", false).filter_join("j0", JoinType::FullOuter));

        assert_eq!(result.placement, Placement::Where);
        assert!(result.condition.expression.contains(&format!("`j0`.`{}`", storage::UID)));
        assert!(result.condition.expression.contains("IS NULL"));
        assert_eq!(result.condition.bindings, permission_filter().filter("j0").unwrap().bindings);
    }

    #[test]
    fn cross_join_places_permission_in_where_clause() {
        let result = some(Join::new(permission_filter(), "j0", "`", false).filter_join("j0", JoinType::Cross));

        assert_eq!(result.placement, Placement::Where);
        assert!(!result.condition.expression.contains("IS NULL"));
    }
}

mod permission_outer_join {
    //! PHP: `Tests\Unit\Adapter\SQL\Hook\Permission\OuterJoinTest`.

    use super::*;
    use permission::{Filter, Join, Options, OuterJoin};

    const SOURCE: &str = "main";
    const ALIAS: &str = "j0";

    fn permission(alias: &str) -> Filter {
        Filter::with_options(
            vec!["any".to_owned()],
            |table: &str| format!("perms_{table}"),
            Options { document_column: format!("{alias}.{}", storage::UID), ..Options::default() },
        )
        .unwrap()
    }

    #[test]
    fn both_sides_are_checked_inside_on() {
        for join_type in [JoinType::Right, JoinType::FullOuter] {
            let source = permission(SOURCE).filter(SOURCE).unwrap();
            let alias = permission(ALIAS).filter(ALIAS).unwrap();

            let result = some(
                OuterJoin::new(SOURCE, [(SOURCE, source.clone()), (ALIAS, alias.clone())], "`")
                    .filter_join(ALIAS, join_type),
            );

            assert_eq!(result.placement, Placement::On, "Only ON decides which rows the join pairs");
            assert_eq!(
                result.condition.expression,
                format!("({} OR `main`.`_uid` IS NULL) AND {}", source.expression, alias.expression)
            );
            assert_eq!(result.condition.bindings, [source.bindings, alias.bindings].concat());
        }
    }

    #[test]
    fn joins_that_drop_unreadable_rows_in_their_own_placement_need_nothing() {
        for join_type in [JoinType::Inner, JoinType::Left, JoinType::Cross] {
            let hook = OuterJoin::new(
                SOURCE,
                [(SOURCE, Condition::new("main.ok", vec![])), (ALIAS, Condition::new("j0.ok", vec![]))],
                "`",
            );

            assert!(hook.filter_join(ALIAS, join_type).unwrap().is_none());
        }
    }

    #[test]
    fn a_table_read_through_its_collection_grant_is_not_checked() {
        let source_only = some(
            OuterJoin::new(SOURCE, [(SOURCE, Condition::new("main.ok = ?", ints(&[1])))], "\"")
                .filter_join(ALIAS, JoinType::Right),
        );
        let joined_only = some(
            OuterJoin::new(SOURCE, [(ALIAS, Condition::new("j0.ok = ?", ints(&[2])))], "`")
                .filter_join(ALIAS, JoinType::Right),
        );

        assert_eq!(source_only.condition.expression, "(main.ok = ? OR \"main\".\"_uid\" IS NULL)");
        assert_eq!(source_only.condition.bindings, ints(&[1]));
        assert_eq!(joined_only.condition.expression, "j0.ok = ?");
        assert_eq!(joined_only.condition.bindings, ints(&[2]));
        assert!(
            OuterJoin::new(SOURCE, Vec::<(String, Condition)>::new(), "`")
                .filter_join(ALIAS, JoinType::FullOuter)
                .unwrap()
                .is_none()
        );
    }

    /// Permission conditions are placed exactly where tenant conditions
    /// are, and let through the same missing rows.
    #[test]
    fn permission_join_filter_places_its_condition_where_tenant_filter_does() {
        for join_type in [JoinType::Inner, JoinType::Left, JoinType::Right, JoinType::FullOuter, JoinType::Cross] {
            for preserving in [false, true] {
                let permission =
                    some(Join::new(permission(ALIAS), ALIAS, "`", preserving).filter_join(ALIAS, join_type));
                let allow_null = if preserving { format!("{SOURCE}.{}", storage::UID) } else { String::new() };
                let tenant =
                    some(tenant::Filter::new([7]).with_allow_null_column(allow_null).filter_join(ALIAS, join_type));

                assert_eq!(tenant.placement, permission.placement, "{join_type:?} {preserving}");
                assert_eq!(
                    tenant.condition.expression.contains("`j0`.`_uid` IS NULL"),
                    permission.condition.expression.contains("`j0`.`_uid` IS NULL"),
                    "{join_type:?} {preserving}"
                );
            }
        }
    }
}

mod permission_filter {
    //! `Permission\Filter`, `Postgres::newPermissionHook()` and
    //! `Column\AllowNull`, which PHP tests only through the adapters;
    //! expectations taken from PHP.

    use super::*;
    use column::AllowNull;
    use permission::{Filter, Options};

    #[test]
    fn subquery_checks_roles_type_columns_and_the_subquery_filter() {
        let filter = Filter::with_options(
            vec!["any".to_owned(), "user:1".to_owned()],
            |table: &str| format!("{table}_perms"),
            Options {
                r#type: "update".to_owned(),
                columns: Some(vec!["a".to_owned(), "b".to_owned()]),
                document_column: "main._uid".to_owned(),
                permission_document_column: "_document".to_owned(),
                permission_role_column: "_permission".to_owned(),
                permission_type_column: "_type".to_owned(),
                scope_column: "_column".to_owned(),
                subquery_filter: Some(Arc::new(tenant::Filter::new([3]))),
                quote: "\"".to_owned(),
                semi_join: false,
            },
        )
        .unwrap();

        let condition = filter.filter("main").unwrap();
        assert_eq!(
            condition.expression,
            "\"main\".\"_uid\" IN (SELECT /*+ NO_SEMIJOIN() */ _document FROM \"main_perms\" WHERE _permission IN (?, ?) AND _type = ? AND (_column IS NULL OR _column IN (?, ?)) AND `main_perms`._tenant IN (?))"
        );
        assert_eq!(
            condition.bindings,
            vec![string("any"), string("user:1"), string("update"), string("a"), string("b"), Value::Int(3)]
        );

        assert_eq!(
            filter.collate("utf8mb4_bin").unwrap().filter("main").unwrap().expression,
            "\"main\".\"_uid\" COLLATE utf8mb4_bin IN (SELECT /*+ NO_SEMIJOIN() */ _document FROM \"main_perms\" WHERE _permission IN (?, ?) AND _type = ? AND (_column IS NULL OR _column IN (?, ?)) AND `main_perms`._tenant IN (?))"
        );
        let error = filter.collate("a-b").unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::InvalidArgument, "Invalid collation name: a-b"));
    }

    #[test]
    fn no_columns_match_only_unscoped_rows_and_no_roles_match_nothing() {
        let filter = Filter::with_options(
            vec!["x".to_owned()],
            |_: &str| "p".to_owned(),
            Options { columns: Some(vec![]), ..Options::default() },
        )
        .unwrap();
        let condition = filter.filter("t").unwrap();
        assert_eq!(
            condition.expression,
            "`id` IN (SELECT document_id FROM `p` WHERE role IN (?) AND type = ? AND column IS NULL)"
        );
        assert_eq!(condition.bindings, vec![string("x"), string("read")]);

        let condition = Filter::new(vec![], |_: &str| "p".to_owned()).filter("t").unwrap();
        assert_eq!((condition.expression.as_str(), condition.bindings.len()), ("1 = 0", 0));
    }

    #[test]
    fn invalid_names_are_refused() {
        let error = Filter::new(vec!["x".to_owned()], |_: &str| "p q".to_owned()).filter("t").unwrap_err();
        let error = database_error(&error);
        assert_eq!((error.kind(), error.message()), (Kind::Database, "Invalid permissions table name: p q"));

        let error = Filter::with_options(
            vec!["x".to_owned()],
            |_: &str| "p".to_owned(),
            Options { document_column: "1x".to_owned(), ..Options::default() },
        )
        .unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::InvalidArgument, "Invalid column name: 1x"));
    }

    #[test]
    fn postgres_matches_roles_against_the_jsonb_permissions() {
        let filter =
            Filter::jsonb(vec!["any".to_owned(), "user:a/b".to_owned(), "é\"x".to_owned()], "read", "main._uid");

        let condition = filter.filter("main").unwrap();
        assert_eq!(
            condition.expression,
            "(\"main\".\"_permissions\" @> ?::jsonb OR \"main\".\"_permissions\" @> ?::jsonb OR \"main\".\"_permissions\" @> ?::jsonb)"
        );
        assert_eq!(
            condition.bindings,
            vec![
                string("[\"read(\\\"any\\\")\"]"),
                string("[\"read(\\\"user:a\\/b\\\")\"]"),
                string("[\"read(\\\"\\u00e9\\\"x\\\")\"]"),
            ]
        );
        assert_eq!(filter.without_semi_join().filter("main").unwrap(), condition);

        let condition = Filter::jsonb(vec!["any".to_owned()], "update", storage::UID).filter("main").unwrap();
        assert_eq!(condition.expression, "(\"_permissions\" @> ?::jsonb)");
        assert_eq!(condition.bindings, vec![string("[\"update(\\\"any\\\")\"]")]);
        assert_eq!(Filter::jsonb(vec![], "read", storage::UID).filter("main").unwrap().expression, "1 = 0");
    }

    #[test]
    fn allow_null_relaxes_a_filter_for_a_null_column() {
        let hook = AllowNull::new(Arc::new(tenant::Filter::new([7])), "a.b-c", "\"").unwrap();
        let condition = hook.filter("t").unwrap();
        assert_eq!(condition.expression, "(`t`._tenant IN (?) OR \"a\".\"b-c\" IS NULL)");
        assert_eq!(condition.bindings, ints(&[7]));

        let error = AllowNull::new(Arc::new(tenant::Filter::new([7])), "a b", "`").err().unwrap();
        assert_eq!((error.kind(), error.message()), (Kind::InvalidArgument, "Invalid column name: a b"));
        let error = AllowNull::wrap(Condition::new("x", vec![]), ".a", "`").unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Database, "Invalid column name: .a"));
        assert_eq!(AllowNull::quote("a.b`c", "`"), "`a`.`b``c`");
    }
}

mod join_alias_quoting {
    //! PHP: `Tests\Unit\Hook\JoinAliasQuotingTest`. Expectations are written
    //! with PostgreSQL's quote and translated for each engine.

    use super::*;
    use column::AllowNull;
    use join::{Chain, OuterChain};
    use permission::{Filter, Join, Options, OuterJoin};

    const SOURCE: &str = "Main";
    const ALIAS: &str = "Book";
    const EARLIER: &str = "Extra";
    const QUOTES: [&str; 2] = ["\"", "`"];
    const JOINS: [JoinType; 5] =
        [JoinType::Inner, JoinType::Left, JoinType::Right, JoinType::FullOuter, JoinType::Cross];

    fn quoted(expression: &str, quote: &str) -> String {
        expression.replace('"', quote)
    }

    fn permission(alias: &str, quote: &str) -> Filter {
        Filter::with_options(
            vec!["any".to_owned()],
            |_: &str| "database.namespace_books_perms".to_owned(),
            Options {
                document_column: format!("{alias}.{}", storage::UID),
                permission_document_column: storage::PERMISSIONS_DOCUMENT.to_owned(),
                permission_role_column: storage::PERMISSIONS_PERMISSION.to_owned(),
                permission_type_column: storage::PERMISSIONS_TYPE.to_owned(),
                quote: quote.to_owned(),
                ..Options::default()
            },
        )
        .unwrap()
    }

    #[test]
    fn tenant_filter_quotes_the_table_it_qualifies() {
        for quote in QUOTES {
            let filter = tenant::Filter::new([7])
                .with_metadata_collection(database::METADATA)
                .with_collection("authors")
                .with_quote(quote);

            assert_eq!(filter.filter(SOURCE).unwrap().expression, quoted("\"Main\"._tenant IN (?)", quote));
            assert_eq!(filter.joined(ALIAS).expression, quoted("\"Book\"._tenant IN (?)", quote));
            assert_eq!(filter.joined(ALIAS).bindings, ints(&[7]));
        }
    }

    #[test]
    fn tenant_filter_quotes_the_table_of_a_tenantless_metadata_row() {
        for quote in QUOTES {
            let filter = tenant::Filter::new([7])
                .with_metadata_collection(database::METADATA)
                .with_collection(database::METADATA)
                .with_quote(quote);

            assert_eq!(
                filter.filter(SOURCE).unwrap().expression,
                quoted("(\"Main\"._tenant IN (?) OR \"Main\"._tenant IS NULL)", quote)
            );
        }
    }

    /// A table named with its database, or quoted already, is a raw table
    /// name rather than an alias.
    #[test]
    fn tenant_filter_does_not_qualify_with_a_raw_table_name() {
        for quote in QUOTES {
            let filter = tenant::Filter::new([7])
                .with_metadata_collection(database::METADATA)
                .with_collection("authors")
                .with_quote(quote);

            assert_eq!(filter.filter("database.namespace_authors").unwrap().expression, "_tenant IN (?)");
            assert_eq!(
                filter.filter(&format!("{quote}namespace_authors{quote}")).unwrap().expression,
                "_tenant IN (?)"
            );
        }
    }

    #[test]
    fn tenant_filter_quotes_the_alias_of_every_join() {
        for quote in QUOTES {
            for join_type in JOINS {
                let filter = tenant::Filter::new([7])
                    .with_allow_null_column(format!("{SOURCE}.{}", storage::UID))
                    .with_quote(quote);

                let result = some(filter.filter_join(ALIAS, join_type));

                let expected = match join_type {
                    JoinType::Inner | JoinType::Left => "\"Book\"._tenant IN (?)",
                    _ => "(\"Book\"._tenant IN (?) OR \"Book\".\"_uid\" IS NULL)",
                };
                assert_eq!(result.condition.expression, quoted(expected, quote), "{join_type:?}, {quote}");
            }
        }
    }

    #[test]
    fn outer_join_tenant_filter_quotes_both_tables() {
        for quote in QUOTES {
            let filter = tenant::Filter::new([7])
                .with_metadata_collection(database::METADATA)
                .with_collection("authors")
                .with_allow_null_column(format!("{SOURCE}.{}", storage::UID))
                .with_quote(quote);

            let result = some(tenant::OuterJoin::new(filter, SOURCE).filter_join(ALIAS, JoinType::Right));

            assert_eq!(
                result.condition.expression,
                quoted("(\"Main\"._tenant IN (?) OR \"Main\".\"_uid\" IS NULL) AND \"Book\"._tenant IN (?)", quote)
            );
        }
    }

    #[test]
    fn outer_join_chain_filter_quotes_every_earlier_table() {
        for quote in QUOTES {
            let chain = Chain::new([(EARLIER, JoinType::Cross), (ALIAS, JoinType::Right)]);
            let tenants = tenant::Filter::new([7]).with_quote(quote);
            let permission = permission(EARLIER, quote);

            let tenant = some(
                OuterChain::new(chain.clone(), [(EARLIER, tenants.joined(EARLIER))], quote)
                    .filter_join(ALIAS, JoinType::Right),
            );
            let permitted = some(
                OuterChain::new(chain, [(EARLIER, permission.filter(EARLIER).unwrap())], quote)
                    .filter_join(ALIAS, JoinType::Right),
            );

            assert_eq!(
                tenant.condition.expression,
                quoted("(\"Extra\"._tenant IN (?) OR \"Extra\".\"_uid\" IS NULL)", quote)
            );
            assert!(only_quoted(EARLIER, &permitted.condition.expression, quote), "{}", permitted.condition.expression);
        }
    }

    #[test]
    fn permission_filter_quotes_its_document_column() {
        for quote in QUOTES {
            assert_eq!(
                permission(ALIAS, quote).filter(ALIAS).unwrap().expression,
                quoted(
                    "\"Book\".\"_uid\" IN (SELECT _document FROM \"database\".\"namespace_books_perms\" WHERE _permission IN (?) AND _type = ?)",
                    quote
                )
            );
        }
    }

    #[test]
    fn permission_join_filter_quotes_the_alias_of_every_join() {
        for quote in QUOTES {
            for join_type in JOINS {
                let hook = Join::new(permission(ALIAS, quote), ALIAS, quote, true);

                let result = some(hook.filter_join(ALIAS, join_type));

                assert!(only_quoted(ALIAS, &result.condition.expression, quote), "{}", result.condition.expression);
            }
        }
    }

    #[test]
    fn allow_null_permission_quotes_the_main_table_on_both_sides() {
        for quote in QUOTES {
            let hook = AllowNull::new(Arc::new(permission(SOURCE, quote)), format!("{SOURCE}.{}", storage::UID), quote)
                .unwrap();

            assert_eq!(
                hook.filter(SOURCE).unwrap().expression,
                quoted(
                    "(\"Main\".\"_uid\" IN (SELECT _document FROM \"database\".\"namespace_books_perms\" WHERE _permission IN (?) AND _type = ?) OR \"Main\".\"_uid\" IS NULL)",
                    quote
                )
            );
        }
    }

    #[test]
    fn outer_join_permission_filter_quotes_both_tables() {
        for quote in QUOTES {
            let hook = OuterJoin::new(
                SOURCE,
                [
                    (SOURCE, permission(SOURCE, quote).filter(SOURCE).unwrap()),
                    (ALIAS, permission(ALIAS, quote).filter(ALIAS).unwrap()),
                ],
                quote,
            );

            let result = some(hook.filter_join(ALIAS, JoinType::FullOuter));

            assert!(only_quoted(SOURCE, &result.condition.expression, quote));
            assert!(only_quoted(ALIAS, &result.condition.expression, quote));
        }
    }
}

mod query_builder_tenancy {
    //! PHP: the parts of `Tests\Unit\QueryBuilderTenancyTest` that need only
    //! the hooks: `Scope\Raw` adds a `Tenant\Raw` and its `RawOuterJoin` to
    //! the builder `SQL::builder()` hands out, here added to a plain builder
    //! in the adapter's dialect (PHP's output is the same).

    use super::*;
    use tenant::{Raw, RawOuterJoin};
    use utopia_query::Array;
    use utopia_query::builder::{MariaDb, PostgreSql};

    /// The SQL of `build`, `update` or `delete`, for both dialects, with
    /// the hooks of a shared-tables builder on `database`.
    macro_rules! for_dialects {
        ($database:expr, |$builder:ident, $quote:ident| $body:block) => {{
            {
                let $quote = "\"";
                let raw = Arc::new(Raw::new(7, format!("{}.capture_authors", $database), false, $quote));
                let mut $builder = PostgreSql::new();
                $builder.from(format!("{}.capture_authors", $database), "");
                $builder.add_hook(raw.hook()).unwrap().add_hook(RawOuterJoin::new(raw.clone())).unwrap();
                $body
            }
            {
                let $quote = "`";
                let raw = Arc::new(Raw::new(7, format!("{}.capture_authors", $database), false, $quote));
                let mut $builder = MariaDb::new();
                $builder.from(format!("{}.capture_authors", $database), "");
                $builder.add_hook(raw.hook()).unwrap().add_hook(RawOuterJoin::new(raw.clone())).unwrap();
                $body
            }
        }};
    }

    fn quote_all(identifier: &str, quote: &str) -> String {
        identifier.split('.').map(|part| format!("{quote}{part}{quote}")).collect::<Vec<_>>().join(".")
    }

    #[test]
    fn every_tenant_condition_names_its_table_as_the_builder_declares_it() {
        for_dialects!("builder", |builder, quote| {
            let q = |s: &str| quote_all(s, quote);
            let authors = q("builder.capture_authors");
            let tenant = |table: &str| format!("{}._tenant IN (?)", q(table));
            let missing = |table: &str| format!("({} OR {} IS NULL)", tenant(table), q(&format!("{table}._uid")));

            builder
                .cross_join("builder.capture_extras", "Extra")
                .right_join("builder.capture_reviews", "Extra.authorId", "Review.authorId", "=", "Review")
                .join_where(
                    "builder.capture_books",
                    |join| {
                        join.on("Review.authorId", "Book.authorId", "=")?;
                        Ok(())
                    },
                    JoinType::FullOuter,
                    "Book",
                )
                .unwrap()
                .select(["builder.capture_authors.name", "Book.pages"]);
            let statement = builder.build().unwrap();

            let raw = "builder.capture_authors";
            assert_eq!(
                statement.query,
                format!(
                    "SELECT {authors}.{quote}name{quote}, {quote}Book{quote}.{quote}pages{quote} FROM {authors} CROSS JOIN {} AS {quote}Extra{quote} RIGHT JOIN {} AS {quote}Review{quote} ON {quote}Extra{quote}.{quote}authorId{quote} = {quote}Review{quote}.{quote}authorId{quote} AND {} AND {} AND {} FULL OUTER JOIN {} AS {quote}Book{quote} ON {quote}Review{quote}.{quote}authorId{quote} = {quote}Book{quote}.{quote}authorId{quote} AND {} AND {} AND {} AND {} WHERE {} AND {} AND {} AND {}",
                    q("builder.capture_extras"),
                    q("builder.capture_reviews"),
                    missing(raw),
                    tenant("Review"),
                    missing("Extra"),
                    q("builder.capture_books"),
                    missing(raw),
                    tenant("Book"),
                    missing("Extra"),
                    missing("Review"),
                    missing(raw),
                    missing("Extra"),
                    missing("Review"),
                    missing("Book"),
                )
            );
            assert_eq!(statement.bindings, ints(&[7; 11]));
            for alias in ["Book", "Review", "Extra"] {
                assert!(only_quoted(alias, &statement.query, quote), "Every alias must be quoted");
            }

            let mut row = Array::default();
            row.set("name".into(), string("renamed"));
            let update = builder.set(row).unwrap().update().unwrap();
            assert_eq!(update.query, format!("UPDATE {authors} SET {quote}name{quote} = ? WHERE {}", tenant(raw)));
            assert_eq!(update.bindings, vec![string("renamed"), Value::Int(7)]);
            assert_eq!(builder.delete().unwrap().query, format!("DELETE FROM {authors} WHERE {}", tenant(raw)));
        });
    }

    #[test]
    fn a_digit_leading_database_and_join_alias_are_quoted_in_the_tenant_conditions() {
        for_dialects!("1db", |builder, quote| {
            let q = |s: &str| quote_all(s, quote);
            let authors = q("1db.capture_authors");
            let tenant = |table: &str| format!("{}._tenant IN (?)", q(table));
            let missing = |table: &str| format!("({} OR {} IS NULL)", tenant(table), q(&format!("{table}._uid")));

            builder
                .right_join("1db.capture_reviews", "1db.capture_authors.authorId", "9x.authorId", "=", "9x")
                .select(["1db.capture_authors.name"]);
            let statement = builder.build().unwrap();

            assert_eq!(
                statement.query,
                format!(
                    "SELECT {authors}.{quote}name{quote} FROM {authors} RIGHT JOIN {} AS {quote}9x{quote} ON {authors}.{quote}authorId{quote} = {quote}9x{quote}.{quote}authorId{quote} AND {} AND {} WHERE {} AND {}",
                    q("1db.capture_reviews"),
                    missing("1db.capture_authors"),
                    tenant("9x"),
                    missing("1db.capture_authors"),
                    missing("9x"),
                )
            );
            assert_eq!(statement.bindings, ints(&[7; 4]));
        });
    }

    #[test]
    fn an_empty_database_is_refused_with_a_library_exception() {
        for_dialects!("", |builder, _quote| {
            builder.right_join(".capture_reviews", ".capture_authors.authorId", "Review.authorId", "=", "Review");

            let error = database_error(&builder.build().unwrap_err());
            assert_eq!((error.kind(), error.message()), (Kind::Database, "Invalid column name: .capture_authors._uid"));
        });
    }

    #[test]
    fn a_raw_join_alias_with_a_space_is_refused_with_a_library_exception() {
        for_dialects!("builder", |builder, _quote| {
            builder.right_join(
                "builder.capture_reviews",
                "builder.capture_authors.authorId",
                "x y.authorId",
                "=",
                "x y",
            );

            let error = database_error(&builder.build().unwrap_err());
            assert_eq!((error.kind(), error.message()), (Kind::Database, "Invalid column name: x y._uid"));
        });
    }

    #[test]
    fn a_renamed_main_table_is_refused_only_for_right_and_full_outer_joins() {
        let raw = Arc::new(Raw::new(7, "builder.capture_authors", false, "`"));
        let mut builder = MariaDb::new();
        builder.from("builder.capture_authors", "author");
        builder.add_hook(raw.hook()).unwrap().add_hook(RawOuterJoin::new(raw.clone())).unwrap();
        builder.right_join("builder.capture_reviews", "author.x", "Review.x", "=", "Review");
        let error = database_error(&builder.build().unwrap_err());
        assert_eq!(
            (error.kind(), error.message()),
            (
                Kind::Query,
                "A right or full outer join needs the main table named 'builder.capture_authors', as Database::from() names it, under shared tables"
            )
        );

        let raw = Arc::new(Raw::new(7, "author", false, "`"));
        let mut builder = MariaDb::new();
        builder.from("builder.capture_authors", "author");
        builder.add_hook(raw.hook()).unwrap().add_hook(RawOuterJoin::new(raw.clone())).unwrap();
        builder.left_join("builder.capture_reviews", "author.x", "Review.x", "=", "Review");
        assert_eq!(
            builder.build().unwrap().query,
            "SELECT * FROM `builder`.`capture_authors` AS `author` LEFT JOIN `builder`.`capture_reviews` AS `Review` ON `author`.`x` = `Review`.`x` AND `Review`._tenant IN (?) WHERE `author`._tenant IN (?)"
        );
    }
}
