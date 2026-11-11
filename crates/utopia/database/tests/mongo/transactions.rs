//! `MongoTransactionRetryTest` and `MongoCommitRetryTest`.

use std::collections::VecDeque;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use utopia_database::adapter::mongo::{Mongo, from_client};
use utopia_database::adapter::with_transaction;
use utopia_database::{Adapter, Error, Kind};
use utopia_mongo::bson::Bson;

use crate::fake::{Fake, error, labelled};

const TRANSIENT_TRANSACTION_ERROR: &str = "TransientTransactionError";
const UNKNOWN_TRANSACTION_COMMIT_RESULT: &str = "UnknownTransactionCommitResult";

// ---- MongoTransactionRetryTest

fn deterministic_failures() -> Vec<(&'static str, Error)> {
    vec![
        ("structure", Error::structure("Invalid document structure")),
        ("not found", Error::not_found("Collection not found")),
        ("duplicate", Error::duplicate("Document already exists")),
        ("timeout", Error::timeout("Query timed out")),
        ("database", Error::database("Missing ID")),
        ("invalid argument", Error::new(Kind::InvalidArgument, "Invalid argument")),
        ("server error", from_client(error("BadValue", 2))),
    ]
}

fn transient_failures() -> Vec<(&'static str, Error)> {
    vec![
        ("contention", Error::contention("Write conflict")),
        ("transaction", Error::transaction("Transaction aborted")),
        ("labelled transient", from_client(labelled("Transaction was aborted", 251, &[TRANSIENT_TRANSACTION_ERROR]))),
        ("network error", from_client(error("Socket error", 9001))),
        ("unsent", from_client(utopia_mongo::Error::unsent("Connection to MongoDB has been lost"))),
        (
            "failure wrapping a transient error",
            Error::database("Failed to commit transaction").with_previous(from_client(labelled(
                "Transaction was aborted",
                0,
                &[TRANSIENT_TRANSACTION_ERROR],
            ))),
        ),
    ]
}

async fn replica_set_adapter(replica_set: bool) -> (Mongo, Arc<Fake>) {
    let fake = if replica_set { Fake::replica_set() } else { Fake::new() };
    (Mongo::with_driver(fake.clone()).await.unwrap(), fake)
}

/// What the transaction failed with and how many times it ran the callback.
async fn attempt(adapter: &Mongo, failure: &Error) -> (Error, i64) {
    let attempts = AtomicI64::new(0);
    let thrown = with_transaction(adapter, || async {
        attempts.fetch_add(1, Ordering::Relaxed);
        Err::<(), _>(failure.clone())
    })
    .await
    .unwrap_err();
    (thrown, attempts.load(Ordering::Relaxed))
}

#[utopia_async::test]
async fn deterministic_failure_runs_once() {
    for (name, failure) in deterministic_failures() {
        let (adapter, fake) = replica_set_adapter(true).await;

        let (thrown, attempts) = attempt(&adapter, &failure).await;

        assert_eq!(thrown, failure, "{name}");
        assert_eq!(attempts, 1, "{name}: A deterministic failure must not run again");
        assert_eq!(fake.sessions.load(Ordering::Relaxed), 1, "{name}");
        assert_eq!(fake.aborts.load(Ordering::Relaxed), 1, "{name}");
        assert!(!adapter.in_transaction(), "{name}");
    }
}

#[utopia_async::test]
async fn transient_failure_runs_every_attempt() {
    for (name, failure) in transient_failures() {
        let (adapter, fake) = replica_set_adapter(true).await;

        let (thrown, attempts) = attempt(&adapter, &failure).await;

        assert_eq!(thrown, failure, "{name}");
        assert_eq!(attempts, 3, "{name}");
        assert_eq!(fake.sessions.load(Ordering::Relaxed), 3, "{name}");
        assert!(!adapter.in_transaction(), "{name}");
    }
}

#[utopia_async::test]
async fn a_write_conflict_runs_until_its_retries_for_write_conflicts_run_out() {
    let (adapter, _) = replica_set_adapter(true).await;
    let failure = from_client(error("WriteConflict", 112));

    let (thrown, attempts) = attempt(&adapter, &failure).await;

    assert_eq!(thrown, failure);
    assert_eq!(attempts, 21);
    assert!(!adapter.in_transaction());
}

#[utopia_async::test]
async fn transient_failure_succeeds_when_it_runs_again() {
    let (adapter, fake) = replica_set_adapter(true).await;
    let attempts = AtomicI64::new(0);

    let result = with_transaction(&adapter, || async {
        if attempts.fetch_add(1, Ordering::Relaxed) == 0 {
            return Err(from_client(labelled("WriteConflict", 112, &[TRANSIENT_TRANSACTION_ERROR])));
        }
        Ok("stored")
    })
    .await
    .unwrap();

    assert_eq!(result, "stored");
    assert_eq!(attempts.load(Ordering::Relaxed), 2);
    assert_eq!(fake.commits.load(Ordering::Relaxed), 1);
    assert!(!adapter.in_transaction());
}

/// A standalone server has no transactions, so the callback runs once and
/// nothing is retried: a caller that retries on its own must not take a
/// transient failure for one the transaction already retried.
#[utopia_async::test]
async fn a_standalone_server_retries_nothing() {
    let (adapter, _) = replica_set_adapter(false).await;
    let failure = Error::contention("Write conflict");

    let (thrown, attempts) = attempt(&adapter, &failure).await;

    assert_eq!(thrown, failure);
    assert_eq!(attempts, 1);
    assert!(!adapter.is_retryable(&failure));
    assert!(replica_set_adapter(true).await.0.is_retryable(&failure));
}

/// Without savepoints a nested call runs inside the caller's transaction,
/// so only the outermost call decides.
#[utopia_async::test]
async fn deterministic_failure_in_a_nested_call_runs_once() {
    let (adapter, fake) = replica_set_adapter(true).await;
    let failure = Error::structure("Invalid document structure");
    let outer = AtomicI64::new(0);
    let nested = AtomicI64::new(0);

    let thrown = with_transaction(&adapter, || async {
        outer.fetch_add(1, Ordering::Relaxed);
        with_transaction(&adapter, || async {
            nested.fetch_add(1, Ordering::Relaxed);
            Err::<(), _>(failure.clone())
        })
        .await
    })
    .await
    .unwrap_err();

    assert_eq!(thrown, failure);
    assert_eq!(outer.load(Ordering::Relaxed), 1);
    assert_eq!(nested.load(Ordering::Relaxed), 1);
    assert_eq!(fake.sessions.load(Ordering::Relaxed), 1);
    assert!(!adapter.in_transaction());
}

// ---- MongoCommitRetryTest

const DOCUMENT: &str = "document";
const RESULT: &str = "result";

/// How a scripted commit ends (`MongoCommitRetryOutcome`).
#[derive(Clone, Copy, Debug, PartialEq)]
enum Outcome {
    /// The commit applied and its reply arrived.
    Committed,
    /// The commit applied, then its reply was lost.
    AppliedThenLost,
    /// The commit did not apply.
    NotApplied,
    /// The server reports the transaction aborted.
    Aborted,
}

#[derive(Default)]
struct Server {
    connected: bool,
    staged: Vec<&'static str>,
    stored: Vec<&'static str>,
    script: VecDeque<(Outcome, Option<utopia_mongo::Error>)>,
    outage: Option<utopia_mongo::Error>,
}

type Shared = Arc<Mutex<Server>>;

fn primary_stepped_down() -> utopia_mongo::Error {
    error("E189 PrimarySteppedDown: primary stepped down while waiting for replication", 189)
}

fn no_such_transaction() -> utopia_mongo::Error {
    error("E251 NoSuchTransaction: Transaction with { txnNumber: 1 } has been aborted.", 251)
}

/// A replica-set driver that behaves as utopia-php/mongo 1.5.4 does: no
/// error labels, and a socket timeout or send failure drops the connection
/// with its sessions, after which every command is unsent and a commit or
/// abort reports an invalid session until the adapter reconnects. Each
/// commit follows the next scripted outcome; once the script is spent,
/// commits fail with the outage's error while there is one.
async fn commit_adapter(
    script: Vec<(Outcome, Option<utopia_mongo::Error>)>,
    outage: Option<utopia_mongo::Error>,
) -> (Mongo, Shared) {
    let server: Shared = Arc::new(Mutex::new(Server { script: script.into(), outage, ..Server::default() }));
    let fake = Fake::new();
    let unsent = || utopia_mongo::Error::unsent("Client is not connected to MongoDB");
    let s = server.clone();
    fake.on("connect", move |_| {
        s.lock().unwrap().connected = true;
        Ok(Bson::Null)
    });
    let s = server.clone();
    fake.on("isReplicaSet", move |_| if s.lock().unwrap().connected { Ok(Bson::Boolean(true)) } else { Err(unsent()) });
    let s = server.clone();
    fake.on("startSession", move |_| if s.lock().unwrap().connected { Ok(Bson::Null) } else { Err(unsent()) });
    let s = server.clone();
    fake.on("endSessions", move |_| if s.lock().unwrap().connected { Ok(Bson::Null) } else { Err(unsent()) });
    let s = server.clone();
    fake.on("startTransaction", move |_| {
        s.lock().unwrap().staged.clear();
        Ok(Bson::Boolean(true))
    });
    let s = server.clone();
    fake.on("abortTransaction", move |_| {
        let mut server = s.lock().unwrap();
        if !server.connected {
            return Err(error("Invalid session provided to abortTransaction", 0));
        }
        server.staged.clear();
        Ok(Bson::Null)
    });
    let s = server.clone();
    fake.on("commitTransaction", move |_| {
        let mut server = s.lock().unwrap();
        if !server.connected {
            return Err(error("Invalid session provided to commitTransaction", 0));
        }
        let (outcome, failure) = server.script.pop_front().unwrap_or_else(|| match &server.outage {
            Some(e) => (Outcome::NotApplied, Some(e.clone())),
            None => (Outcome::Committed, None),
        });
        let store = |server: &mut Server| {
            let staged = std::mem::take(&mut server.staged);
            server.stored.extend(staged);
        };
        match outcome {
            Outcome::Committed => {
                store(&mut server);
                Ok(Bson::Null)
            }
            Outcome::Aborted => {
                server.staged.clear();
                Err(failure.unwrap_or_else(no_such_transaction))
            }
            Outcome::AppliedThenLost | Outcome::NotApplied => {
                let failure = failure.unwrap_or_else(|| error("Commit failed", 0));
                if outcome == Outcome::AppliedThenLost {
                    store(&mut server);
                }
                if matches!(failure.code(), 9001 | 11601) {
                    server.connected = false;
                }
                Err(failure)
            }
        }
    });
    (Mongo::with_driver(fake).await.unwrap(), server)
}

/// The transaction of the work: one document staged, then the result.
async fn work(adapter: &Mongo, server: &Shared, attempts: &AtomicI64) -> Result<&'static str, Error> {
    with_transaction(adapter, || async {
        attempts.fetch_add(1, Ordering::Relaxed);
        server.lock().unwrap().staged.push(DOCUMENT);
        Ok(RESULT)
    })
    .await
}

fn stored(server: &Shared) -> Vec<&'static str> {
    server.lock().unwrap().stored.clone()
}

#[utopia_async::test]
async fn an_unknown_commit_result_that_applied_is_retried_without_running_the_callback_again() {
    let unknown = [
        primary_stepped_down(),
        error("E50 MaxTimeMSExpired: operation exceeded time limit", 50),
        error("E262 ExceededTimeLimit: operation exceeded time limit", 262),
        labelled("Commit failed", 0, &[UNKNOWN_TRANSACTION_COMMIT_RESULT]),
    ];
    for failure in unknown {
        let (adapter, server) =
            commit_adapter(vec![(Outcome::AppliedThenLost, Some(failure.clone())), (Outcome::Committed, None)], None)
                .await;
        let attempts = AtomicI64::new(0);

        let result = work(&adapter, &server, &attempts).await.unwrap();

        assert_eq!(result, RESULT);
        assert_eq!(
            attempts.load(Ordering::Relaxed),
            1,
            "{failure}: A commit whose result is unknown must not run the callback again"
        );
        assert_eq!(stored(&server), vec![DOCUMENT]);
        assert!(!adapter.in_transaction());
    }
}

#[utopia_async::test]
async fn a_commit_that_drops_the_connection_throws_unconfirmed_without_running_the_callback_again() {
    let disconnects = [
        (
            error("Receive timeout: no data received within reasonable time", 11601),
            Outcome::AppliedThenLost,
            vec![DOCUMENT],
        ),
        (error("Failed to send data to MongoDB after reconnection attempt", 9001), Outcome::NotApplied, vec![]),
    ];
    for (failure, outcome, expected) in disconnects {
        let (adapter, server) = commit_adapter(vec![(outcome, Some(failure.clone()))], None).await;
        let attempts = AtomicI64::new(0);

        let thrown = work(&adapter, &server, &attempts).await.unwrap_err();

        assert_eq!(thrown.kind(), Kind::Unconfirmed, "{}", thrown.message());
        assert_eq!(thrown.previous(), Some(&from_client(failure)));
        assert_eq!(
            attempts.load(Ordering::Relaxed),
            1,
            "A commit whose result is unknown must not run the callback again"
        );
        assert_eq!(stored(&server), expected);
        assert!(!adapter.is_retryable(&thrown), "Running an unconfirmed commit again could store its writes twice");
        assert!(!adapter.in_transaction());
    }
}

#[utopia_async::test]
async fn a_commit_that_stays_unconfirmed_throws_without_running_the_callback_again() {
    let unknown = primary_stepped_down();
    let (adapter, server) =
        commit_adapter(vec![(Outcome::AppliedThenLost, Some(unknown.clone()))], Some(primary_stepped_down())).await;
    let attempts = AtomicI64::new(0);

    let thrown = work(&adapter, &server, &attempts).await.unwrap_err();

    assert_eq!(attempts.load(Ordering::Relaxed), 1, "A commit whose result is unknown must not run the callback again");
    assert_eq!(stored(&server), vec![DOCUMENT]);
    assert_eq!(thrown.kind(), Kind::Unconfirmed);
    assert_eq!(thrown.previous(), Some(&from_client(unknown)));
    assert!(!adapter.is_retryable(&thrown), "Running an unconfirmed commit again could store its writes twice");
    assert!(!adapter.in_transaction());

    server.lock().unwrap().outage = None;
    let attempts = AtomicI64::new(0);

    assert_eq!(work(&adapter, &server, &attempts).await.unwrap(), RESULT);
    assert_eq!(attempts.load(Ordering::Relaxed), 1);
    assert_eq!(stored(&server), vec![DOCUMENT, DOCUMENT]);
    assert!(!adapter.in_transaction());
}

#[utopia_async::test]
async fn a_commit_time_limit_is_retried_then_throws_unconfirmed() {
    let expired = error("E50 MaxTimeMSExpired: operation exceeded time limit", 50);
    let (adapter, server) = commit_adapter(vec![], Some(expired.clone())).await;
    let attempts = AtomicI64::new(0);

    let thrown = work(&adapter, &server, &attempts).await.unwrap_err();

    assert_eq!(attempts.load(Ordering::Relaxed), 1, "A commit whose result is unknown must not run the callback again");
    assert!(stored(&server).is_empty());
    assert_eq!(thrown.kind(), Kind::Unconfirmed);
    assert_eq!(thrown.previous(), Some(&from_client(expired)));
    assert!(!adapter.in_transaction());
}

fn aborted_commits() -> Vec<utopia_mongo::Error> {
    vec![no_such_transaction(), error("E112 WriteConflict: write conflict during plan execution", 112)]
}

#[utopia_async::test]
async fn a_first_commit_the_server_reports_aborted_runs_the_callback_again() {
    for failure in aborted_commits() {
        let (adapter, server) =
            commit_adapter(vec![(Outcome::Aborted, Some(failure.clone())), (Outcome::Committed, None)], None).await;
        let attempts = AtomicI64::new(0);

        assert_eq!(work(&adapter, &server, &attempts).await.unwrap(), RESULT);
        assert_eq!(
            attempts.load(Ordering::Relaxed),
            2,
            "{failure}: An aborted transaction stored nothing, so the callback must run again"
        );
        assert_eq!(stored(&server), vec![DOCUMENT]);
        assert!(!adapter.in_transaction());
    }
}

#[utopia_async::test]
async fn a_retried_commit_that_reports_the_transaction_aborted_runs_the_callback_again() {
    for failure in aborted_commits() {
        let (adapter, server) = commit_adapter(
            vec![
                (Outcome::NotApplied, Some(primary_stepped_down())),
                (Outcome::Aborted, Some(failure.clone())),
                (Outcome::Committed, None),
            ],
            None,
        )
        .await;
        let attempts = AtomicI64::new(0);

        assert_eq!(work(&adapter, &server, &attempts).await.unwrap(), RESULT);
        assert_eq!(
            attempts.load(Ordering::Relaxed),
            2,
            "{failure}: An aborted transaction stored nothing, so the callback must run again"
        );
        assert_eq!(stored(&server), vec![DOCUMENT]);
        assert!(!adapter.in_transaction());
    }
}

#[utopia_async::test]
async fn a_labelled_transient_transaction_error_at_commit_runs_the_callback_again() {
    let (adapter, server) = commit_adapter(
        vec![
            (Outcome::NotApplied, Some(labelled("Transaction aborted", 0, &[TRANSIENT_TRANSACTION_ERROR]))),
            (Outcome::Committed, None),
        ],
        None,
    )
    .await;
    let attempts = AtomicI64::new(0);

    assert_eq!(work(&adapter, &server, &attempts).await.unwrap(), RESULT);
    assert_eq!(attempts.load(Ordering::Relaxed), 2);
    assert_eq!(stored(&server), vec![DOCUMENT]);
    assert!(!adapter.in_transaction());
}

#[utopia_async::test]
async fn an_unsent_commit_runs_the_callback_again() {
    let (adapter, server) = commit_adapter(
        vec![
            (Outcome::NotApplied, Some(utopia_mongo::Error::unsent("Connection to MongoDB has been lost"))),
            (Outcome::Committed, None),
        ],
        None,
    )
    .await;
    let attempts = AtomicI64::new(0);

    assert_eq!(work(&adapter, &server, &attempts).await.unwrap(), RESULT);
    assert_eq!(attempts.load(Ordering::Relaxed), 2);
    assert_eq!(stored(&server), vec![DOCUMENT]);
    assert!(!adapter.in_transaction());
}

#[utopia_async::test]
async fn a_commit_retry_that_was_never_sent_is_sent_again() {
    let (adapter, server) = commit_adapter(
        vec![
            (Outcome::NotApplied, Some(primary_stepped_down())),
            (Outcome::NotApplied, Some(utopia_mongo::Error::unsent("Failed to connect to MongoDB"))),
            (Outcome::Committed, None),
        ],
        None,
    )
    .await;
    let attempts = AtomicI64::new(0);

    assert_eq!(work(&adapter, &server, &attempts).await.unwrap(), RESULT);
    assert_eq!(attempts.load(Ordering::Relaxed), 1, "A commit whose result is unknown must not run the callback again");
    assert_eq!(stored(&server), vec![DOCUMENT]);
    assert!(!adapter.in_transaction());
}
