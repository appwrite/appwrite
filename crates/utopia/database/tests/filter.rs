//! Ported from utopia-php/database 8.1.3 `tests/unit/FilterRegistryTest.php`
//! (pure tests) and the registry halves of `tests/unit/Filter/CodecTest.php`,
//! with the fixtures `Filter/Reversed.php`, `Filter/Rot13.php` and
//! `Filter/CodecTestPrefixed.php`.
//!
//! Not ported (they need a `Database`, its cache keys or the global filters
//! of `Database::addFilter()`): FilterRegistryTest
//! `testRegisteringOnARegistryLeavesTheGlobalFiltersUntouched`,
//! `testRegisteringAGlobalFilterStopsStaleEntriesBeingServed`,
//! `testRegisteringOnASharedRegistryStopsStaleEntriesBeingServed`,
//! `testOverridingABuiltInFilterBeforeTheFirstInstanceStillWins`,
//! `testInstancesSharingAConfigShareCachedDocuments`,
//! `testFilterEncodeFailureIsADatabaseExceptionWithTheOriginalAsPrevious`,
//! `testCodecEncodeFailureIsADatabaseExceptionWithTheOriginalAsPrevious`,
//! `testReplacingANonClosureFilterStopsStaleEntriesBeingServed`; and every
//! CodecTest test as written (`testRegisteredTypeEncodesAndDecodesOnItsHandle`,
//! `testHandlesWithoutTheRegistryCannotEncodeItsTypes`,
//! `testHandlesWithoutTheRegistryCannotDecodeItsTypes`,
//! `testABuiltInNameCannotBreakOtherHandles`,
//! `testARegisteredTypeShadowsAGlobalFilterOnlyOnItsHandle`,
//! `testRegisteringATypeChangesOnlyItsHandlesCacheKeys`,
//! `testCacheKeysFollowTheTypeClass`, `testCacheKeysFollowTheSignatureOfASignedCodec`,
//! `testConstructorFiltersEncodeAndDecodeOnTheirHandle`,
//! `testSetFiltersReplacesTheConstructorFilters`,
//! `testAConstructorFilterNamedAfterABuiltInIsRejected`). Their registry
//! behaviour (rejection of built-in names, signatures by class or by
//! `Signed::signature()`) is covered below.

mod common;

use std::sync::Arc;

use common::v;
use utopia_database::database::DEFAULT_FILTERS;
use utopia_database::filter::{Callback, Codec, Filter, FilterName, Registry};
use utopia_database::value::{Str, Value};
use utopia_database::{Kind, Result};

/// `Tests\Unit\Filter\Reversed`.
struct Reversed(String);

impl Reversed {
    fn new() -> Self {
        Reversed("reversed".into())
    }
}

impl Codec for Reversed {
    fn name(&self) -> &str {
        &self.0
    }
    fn encode(&self, value: Value) -> Result<Value> {
        Ok(match value {
            Value::Str(s) => Value::Str(Str::from(s.as_bytes().iter().rev().copied().collect::<Vec<u8>>())),
            other => other,
        })
    }
    fn decode(&self, value: Value) -> Result<Value> {
        self.encode(value)
    }
}

/// `Tests\Unit\Filter\Rot13`.
struct Rot13(String);

impl Codec for Rot13 {
    fn name(&self) -> &str {
        &self.0
    }
    fn encode(&self, value: Value) -> Result<Value> {
        let rot = |b: u8| match b {
            b'a'..=b'z' => (b - b'a' + 13) % 26 + b'a',
            b'A'..=b'Z' => (b - b'A' + 13) % 26 + b'A',
            other => other,
        };
        Ok(match value {
            Value::Str(s) => Value::Str(Str::from(s.as_bytes().iter().map(|b| rot(*b)).collect::<Vec<u8>>())),
            other => other,
        })
    }
    fn decode(&self, value: Value) -> Result<Value> {
        self.encode(value)
    }
}

/// `Tests\Unit\Filter\CodecTestPrefixed`: a `Signed` codec.
struct Prefixed(String);

impl Codec for Prefixed {
    fn name(&self) -> &str {
        "text"
    }
    fn encode(&self, value: Value) -> Result<Value> {
        Ok(match value {
            Value::Str(s) => Value::Str(Str::from([self.0.as_bytes(), s.as_bytes()].concat())),
            other => other,
        })
    }
    fn decode(&self, value: Value) -> Result<Value> {
        Ok(match value {
            Value::Str(s) if s.as_bytes().starts_with(self.0.as_bytes()) => {
                Value::Str(Str::from(&s.as_bytes()[self.0.len()..]))
            }
            other => other,
        })
    }
    fn signature(&self) -> String {
        format!("Tests\\Unit\\Filter\\CodecTestPrefixed:{}", self.0)
    }
}

fn signatures(registry: &Registry) -> Vec<(String, String)> {
    registry.signatures().map(|(n, s)| (n.to_owned(), s.to_owned())).collect()
}

// FilterRegistryTest

#[test]
fn test_registered_codecs_are_found_by_name() {
    let mut registry = Registry::new();
    let reversed: Arc<dyn Codec> = Arc::new(Reversed::new());
    let rot13: Arc<dyn Codec> = Arc::new(Rot13("rot13".into()));
    registry.register(reversed.clone()).unwrap().register(rot13.clone()).unwrap();
    assert!(Arc::ptr_eq(registry.get("reversed").unwrap(), &reversed));
    assert!(Arc::ptr_eq(registry.get("rot13").unwrap(), &rot13));
    assert!(registry.has("reversed"));
}

#[test]
fn test_an_unknown_name_is_neither_found_nor_held() {
    let mut registry = Registry::new();
    registry.register(Arc::new(Reversed::new())).unwrap();
    assert!(registry.get("rot13").is_none());
    assert!(!registry.has("rot13"));
}

#[test]
fn test_registering_a_name_again_replaces_the_codec() {
    let mut registry = Registry::new();
    let replacement: Arc<dyn Codec> = Arc::new(Rot13("reversed".into()));
    registry.register(Arc::new(Reversed::new())).unwrap().register(replacement.clone()).unwrap();
    assert!(Arc::ptr_eq(registry.get("reversed").unwrap(), &replacement));
    assert_eq!(signatures(&registry).len(), 1);
}

#[test]
fn test_built_in_filter_names_are_rejected() {
    for filter in Filter::CASES {
        let name = filter.as_str();
        let mut registry = Registry::new();
        let e = registry.register(Arc::new(Reversed(name.into()))).map(|_| ()).unwrap_err();
        assert_eq!(e.kind(), Kind::Duplicate);
        assert_eq!(e.message(), format!("Filter \"{name}\" collides with the built-in filter of the same name"));
        assert!(!registry.has(name));
    }
}

#[test]
fn test_default_filters_name_every_built_in_filter() {
    let mut expected: Vec<&str> = DEFAULT_FILTERS.to_vec();
    let mut cases: Vec<&str> = Filter::CASES.iter().map(|f| f.as_str()).collect();
    expected.sort_unstable();
    cases.sort_unstable();
    assert_eq!(expected, cases);
}

#[test]
fn test_callback_runs_its_closures() {
    let callback = Callback::new(
        "wrapped",
        |value: Value| {
            Ok(match value {
                Value::Str(s) => v(format!("[{}]", String::from_utf8_lossy(s.as_bytes()))),
                other => other,
            })
        },
        |value: Value| {
            Ok(match value {
                Value::Str(s) => v(String::from_utf8_lossy(s.as_bytes()).trim_matches(['[', ']']).to_owned()),
                other => other,
            })
        },
    );
    assert_eq!(callback.name(), "wrapped");
    assert_eq!(callback.encode(v("value")).unwrap(), v("[value]"));
    assert_eq!(callback.decode(v("[value]")).unwrap(), v("value"));
}

// CodecTest (registry halves)

#[test]
fn test_codecs_encode_and_decode() {
    let reversed = Reversed::new();
    assert_eq!(reversed.encode(v("hello")).unwrap(), v("olleh"));
    assert_eq!(reversed.decode(v("olleh")).unwrap(), v("hello"));
    assert_eq!(reversed.encode(v(5)).unwrap(), v(5));
    let prefixed = Prefixed("a:".into());
    assert_eq!(prefixed.encode(v("x")).unwrap(), v("a:x"));
    assert_eq!(prefixed.decode(v("a:x")).unwrap(), v("x"));
}

#[test]
fn test_a_built_in_name_is_rejected_and_leaves_the_registry_empty() {
    let mut registry = Registry::new();
    assert!(registry.register(Arc::new(Reversed("json".into()))).is_err());
    assert!(registry.is_empty());
}

#[test]
fn test_signatures_follow_the_codec_type() {
    let registry = |codec: Arc<dyn Codec>| {
        let mut r = Registry::new();
        r.register(codec).unwrap();
        signatures(&r)
    };
    let reversed = registry(Arc::new(Reversed("text".into())));
    let rot13 = registry(Arc::new(Rot13("text".into())));
    let same_class = registry(Arc::new(Reversed("text".into())));
    assert_ne!(reversed, rot13);
    assert_eq!(reversed, same_class);
}

#[test]
fn test_signatures_follow_the_signature_of_a_signed_codec() {
    let registry = |prefix: &str| {
        let mut r = Registry::new();
        r.register(Arc::new(Prefixed(prefix.into()))).unwrap();
        signatures(&r)
    };
    assert_ne!(registry("a:"), registry("b:"));
    assert_eq!(registry("a:"), registry("a:"));
    assert_eq!(registry("a:"), [("text".to_owned(), "Tests\\Unit\\Filter\\CodecTestPrefixed:a:".to_owned())]);
}

#[test]
fn test_callbacks_are_signed_by_where_they_are_built() {
    let make = || Callback::new("same", Ok, Ok);
    let first = make();
    let second = make();
    let elsewhere = Callback::new("same", Ok, Ok);
    assert_eq!(first.signature(), second.signature());
    assert_ne!(first.signature(), elsewhere.signature());
}

#[test]
fn test_filter_names() {
    assert_eq!(
        Filter::names([FilterName::from(Filter::Json), FilterName::from("encrypt")]),
        ["json".to_owned(), "encrypt".to_owned()]
    );
    assert_eq!(Filter::try_from_value("linestring"), Some(Filter::LineString));
}
