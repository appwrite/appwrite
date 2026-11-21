//! PHP: `Transactions\NoRetryTest` (its Memory cases).

use std::sync::atomic::{AtomicUsize, Ordering};

use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::database::cache_fixtures::run;
use crate::error::{Error, Kind, Result};

#[test]
fn the_callback_runs_once_and_the_failure_propagates() {
    for failure in [Error::timeout("Query timed out"), Error::unconfirmed("Commit could not be confirmed")] {
        run(move || async move {
            let memory = Memory::new();
            let runs = AtomicUsize::new(0);
            let thrown = crate::adapter::with_transaction(&memory, || {
                let failure = failure.clone();
                let runs = &runs;
                async move {
                    runs.fetch_add(1, Ordering::SeqCst);
                    Err::<(), _>(failure)
                }
            })
            .await
            .unwrap_err();
            assert!(matches!(thrown.kind(), Kind::Timeout | Kind::Unconfirmed));
            assert_eq!(thrown.message(), failure.message());
            assert_eq!(runs.load(Ordering::SeqCst), 1);
            assert!(!memory.in_transaction());
            Result::<()>::Ok(())
        })
        .unwrap();
    }
}
