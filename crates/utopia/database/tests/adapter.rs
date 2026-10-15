//! Ported from utopia-php/database 8.1.3 `tests/unit/Adapter/ProfileTest.php`
//! (pure part), with the capability set of `Utopia\Database\Capability`.
//!
//! Not ported:
//! - ProfileTest `testTheDatabaseProfileIsWhatItsAdapterSupports`,
//!   `testSharedTablesShrinkTheIndexKeyOnlyAfterTheyAreTurnedOn`,
//!   `testTheTenantPassesSelectValidationWithAndWithoutSharedTables`,
//!   `testASchemaColumnClashesOnlyUntilASharedTableMigrationStarts`,
//!   `testUndeclaredAttributesAreQueryableOnlyAfterTheSchemalessModeIsOn`,
//!   `testAnAdapterWithoutASchemalessModeOnlyKeepsItsSchema`: they need a
//!   `Database` and the MariaDB, Memory or Mongo adapters. The schema-mode
//!   probe they rely on is covered below.
//! - `Adapter/LimitsTest.php` (all 9 tests): every test reads the limits of a
//!   concrete adapter (MariaDB, MySQL, Postgres, SQLite, Mongo, Memory, Pool)
//!   or a `Database`.
//! - `Adapter/CapabilityIdiomTest.php` (all 3 tests): they scan the PHP
//!   sources for `instanceof Feature\…` and `method_exists($adapter…)`; a Rust
//!   adapter answers features through `features()` only, so the idiom has no
//!   counterpart to scan for.

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

use php_std::datetime::{DateTime, TzInfo};
use utopia_database::Capability;
use utopia_database::adapter::{Feature, Features, Limits, Profile};
use utopia_database::capability::Capabilities;
use utopia_query::schema::ColumnType;

fn limits() -> Arc<Limits> {
    let date = |s: &str| DateTime::new(s.as_bytes(), None, &TzInfo::utc()).unwrap();
    Arc::new(Limits {
        string: 4_294_967_295,
        varchar: 16_381,
        integer: 4_294_967_295,
        big_integer: i64::MAX,
        attributes: 1017,
        indexes: 64,
        default_attributes: 7,
        default_indexes: 6,
        index_length: 1024,
        uid_length: 255,
        document_size: 0,
        min_date_time: date("0001-01-01 00:00:00"),
        max_date_time: date("9999-12-31 23:59:59"),
        id_type: ColumnType::Integer,
        keywords: vec![],
        internal_index_keys: vec![],
    })
}

#[test]
fn test_a_profile_answers_for_the_capabilities_and_features_it_was_built_with() {
    let profile = Profile::new(
        limits(),
        Capabilities::of(&[Capability::Joins, Capability::IndexKey]),
        Features::of(&[Feature::Spatial]),
        true,
        false,
        None,
    );
    assert!(profile.supports(Capability::Joins));
    assert!(profile.supports(Capability::IndexKey));
    assert!(!profile.supports(Capability::Aggregations));
    assert!(profile.has_feature(Feature::Spatial));
    assert!(!profile.has_feature(Feature::Upserts));
    assert!(profile.shared_tables);
    assert!(!profile.migrating);
    assert_eq!(profile.limits.index_length, 1024);
}

#[test]
fn test_the_schema_mode_is_asked_on_every_check() {
    let defined = Arc::new(AtomicBool::new(true));
    let probe = defined.clone();
    let profile = Profile::new(
        limits(),
        Capabilities::EMPTY,
        Features::EMPTY,
        false,
        false,
        Some(Arc::new(move || probe.load(Ordering::SeqCst))),
    );
    assert!(profile.supports(Capability::DefinedAttributes));
    defined.store(false, Ordering::SeqCst);
    assert!(!profile.supports(Capability::DefinedAttributes));
}

#[test]
fn test_capability_cases_follow_php() {
    let names: Vec<&str> = Capability::CASES.iter().map(|c| c.name()).collect();
    assert_eq!(names.len(), 33);
    assert_eq!(names.first(), Some(&"Aggregations"));
    assert_eq!(names.last(), Some(&"Vectors"));
    assert_eq!(Capability::from_name("IndexTtl"), Some(Capability::IndexTtl));
    assert_eq!(Capability::from_name("indexTtl"), None);
    let all: Capabilities = Capability::CASES.into_iter().collect();
    assert_eq!(all.iter().count(), 33);
    assert!(!all.without(Capability::Vectors).contains(Capability::Vectors));
}

#[test]
fn test_features_name_their_php_interfaces() {
    assert_eq!(Feature::ALL.len(), 9);
    assert_eq!(Feature::Spatial.php_class(), "Utopia\\Database\\Adapter\\Feature\\Spatial");
    let set: Features = [Feature::Upserts, Feature::Casting].into_iter().collect();
    assert_eq!(set.iter().collect::<Vec<_>>(), [Feature::Casting, Feature::Upserts]);
}
