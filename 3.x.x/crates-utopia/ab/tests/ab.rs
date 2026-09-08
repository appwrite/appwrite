use std::collections::HashSet;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, MutexGuard, PoisonError};
use utopia_ab::{AbError, Test, VariationValue};

static TEST_LOCK: Mutex<()> = Mutex::new(());

fn serialize() -> MutexGuard<'static, ()> {
    TEST_LOCK.lock().unwrap_or_else(PoisonError::into_inner)
}

/// Port of `tests/AB/TestTest.php` (`testTest`).
#[test]
fn test_test_php_parity() {
    let _guard = serialize();
    Test::reset_results();

    let mut test = Test::new("unit-test");
    test.variation("title1", "Title: Hello World", Some(40))
        .variation("title2", "Title: Foo Bar", Some(30))
        .variation(
            "title3",
            VariationValue::callback(|| "Title: Title from a callback function".to_owned()),
            Some(30),
        );

    for _ in 0..100 {
        let value = test.run().unwrap();
        assert!(
            value.starts_with("Title:"),
            "expected Title: prefix, got {value:?}"
        );
    }

    test.variation("title1", "Title: Hello World", Some(100))
        .variation("title2", "Title: Foo Bar", Some(0))
        .variation(
            "title3",
            VariationValue::callback(|| "Title: Title from a callback function".to_owned()),
            Some(0),
        );

    for _ in 0..100 {
        let value = test.run().unwrap();
        assert_eq!(value, "Title: Hello World");
    }

    let mut another = Test::new("another-test");
    another
        .variation("option1", "title1", None)
        .variation("option2", "title2", None)
        .variation("option3", "title3", None);
    another.run().unwrap();

    let results = Test::results();
    assert!(results.contains_key("unit-test"));
    assert!(results.contains_key("another-test"));
}

#[test]
fn sum_over_100_returns_error() {
    let _guard = serialize();
    let mut test = Test::new("sum-over-100");
    test.variation("a", "A", Some(50))
        .variation("b", "B", Some(50))
        .variation("c", "C", Some(1));

    let err = test.run().unwrap_err();
    assert_eq!(err, AbError::ProbabilitiesExceed100);
    assert_eq!(
        err.to_string(),
        "Test Error: Total variation probabilities is bigger than 100%"
    );
}

#[test]
fn auto_probability_fills_omitted_values_equally() {
    let _guard = serialize();
    let mut test = Test::new("auto-probability");
    test.variation("option1", "title1", None)
        .variation("option2", "title2", None)
        .variation("option3", "title3", None);

    let mut seen = HashSet::new();
    for _ in 0..400 {
        seen.insert(test.run().unwrap());
    }
    assert_eq!(
        seen,
        HashSet::from([
            "title1".to_owned(),
            "title2".to_owned(),
            "title3".to_owned()
        ])
    );
}

#[test]
fn auto_probability_treats_zero_as_empty_like_php() {
    let _guard = serialize();
    // PHP `empty(0)` is true, so a 0% slot is auto-filled from the remainder
    // when the explicit sum is below 100.
    let mut test = Test::new("auto-zero-empty");
    test.variation("keep", "keep", Some(50))
        .variation("was-zero", "was-zero", Some(0));

    let mut seen = HashSet::new();
    for _ in 0..400 {
        seen.insert(test.run().unwrap());
    }
    assert!(seen.contains("keep"));
    assert!(
        seen.contains("was-zero"),
        "probability 0 must be treated as empty and share the remaining 50%"
    );
}

#[test]
fn zero_percent_never_selected_when_other_is_100() {
    let _guard = serialize();
    let mut test = Test::new("zero-never");
    test.variation("always", "always", Some(100))
        .variation("never-a", "never-a", Some(0))
        .variation("never-b", "never-b", Some(0));

    for _ in 0..1_000 {
        assert_eq!(test.run().unwrap(), "always");
    }
}

#[test]
fn callback_invoked_on_run_not_construction() {
    let _guard = serialize();
    let calls = Arc::new(AtomicUsize::new(0));
    let calls_for_cb = Arc::clone(&calls);

    let mut test = Test::new("callback-timing");
    test.variation(
        "cb",
        VariationValue::callback(move || {
            calls_for_cb.fetch_add(1, Ordering::SeqCst);
            "from-callback".to_owned()
        }),
        Some(100),
    );
    assert_eq!(calls.load(Ordering::SeqCst), 0);

    assert_eq!(test.run().unwrap(), "from-callback");
    assert_eq!(calls.load(Ordering::SeqCst), 1);

    assert_eq!(test.run().unwrap(), "from-callback");
    assert_eq!(calls.load(Ordering::SeqCst), 2);
}

#[test]
fn results_map_records_resolved_values_by_test_name() {
    let _guard = serialize();
    Test::reset_results();

    let mut first = Test::new("results-a");
    first.variation("only", "alpha", Some(100));
    first.run().unwrap();

    let mut second = Test::new("results-b");
    second.variation(
        "only",
        VariationValue::callback(|| "beta".to_owned()),
        Some(100),
    );
    second.run().unwrap();

    let results = Test::results();
    assert_eq!(results.get("results-a").map(String::as_str), Some("alpha"));
    assert_eq!(results.get("results-b").map(String::as_str), Some("beta"));
    assert_eq!(results.len(), 2);

    Test::reset_results();
    assert!(Test::results().is_empty());
}

#[test]
fn remainder_split_across_omitted_probabilities() {
    let _guard = serialize();
    let mut test = Test::new("remainder-split");
    test.variation("fixed", "fixed", Some(40))
        .variation("auto-a", "auto-a", None)
        .variation("auto-b", "auto-b", None);

    let mut seen = HashSet::new();
    for _ in 0..500 {
        seen.insert(test.run().unwrap());
    }
    assert!(seen.contains("fixed"));
    assert!(seen.contains("auto-a"));
    assert!(seen.contains("auto-b"));
}
