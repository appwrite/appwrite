//! Ported from utopia-php/database 8.1.3's MongoDB adapter unit tests
//! (`tests/unit/Mongo*Test.php`, and the MongoDB cases of
//! `UniqueViolationTest`, `EngineErrorMappingTest` and `BigIntegerTest`):
//! each runs the adapter over a [`fake::Fake`] driver that records the
//! commands and scripts the replies, as PHP's tests extend
//! `Utopia\Mongo\Client`.
//!
//! Not ported:
//! - `MongoCommitRetryTest::testADatabaseTransactionWhoseCommitDropsTheConnectionThrowsUnconfirmed`,
//!   `MongoDatabaseExistsTest::testARefusedRenameUnderSharedTablesLeavesNoDatabaseBehind`,
//!   `MongoFilteredMissCacheTest` (2 tests), `SharedCollectionCleanupTest::testAMongoCreateWhoseDefinitionIsNotStoredKeepsTheSharedCollection`:
//!   they run through a `Database`, which this layer does not have yet.
//! - `MongoFilterScopeTest::testReadsFilterByPermissionWithoutThePermissionsHook`,
//!   `testReadsWithoutThePermissionsHookSkipTheFilterWhileAuthorizationIsDisabled`:
//!   they register and remove the `Hook\Permissions` write hook, which the
//!   database layer provides; the reads they check are covered with no
//!   write hook registered.
//! - `MongoTestOverridesTest`: it checks PHP e2e test class overrides by
//!   reflection.
//! - `MongoResultDecodingTest::testCastAfterRewritesOnlyTheValuesItChanges`:
//!   it counts `setAttribute()` calls on a subclass of `Document`; the values
//!   it ends with are checked by `cast_after_casts_collection_and_internal_attributes`.
//! - `SwooleAbsentTest`: PHP extension loading.

#![cfg(feature = "mongo")]

#[path = "common/mod.rs"]
mod common;
#[path = "mongo/fake.rs"]
mod fake;

#[path = "mongo/casting.rs"]
mod casting;
#[path = "mongo/differential.rs"]
mod differential;
#[path = "mongo/filters.rs"]
mod filters;
#[path = "mongo/live.rs"]
mod live;
#[path = "mongo/paths.rs"]
mod paths;
#[path = "mongo/schema.rs"]
mod schema;
#[path = "mongo/transactions.rs"]
mod transactions;
#[path = "mongo/writes.rs"]
mod writes;
