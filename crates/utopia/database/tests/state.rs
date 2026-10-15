//! Ported from utopia-php/database 8.1.3 `tests/unit/State/ValueTest.php`.
//!
//! PHP's coroutines map to the Rust design documented in `src/state.rs`: an
//! override is scoped to the future `with()` runs; a child coroutine is a
//! task spawned with [`inherit`] (a task spawned without it starts outside
//! every override); "outside coroutines" is the test's own future, or
//! `with_sync()` around a runtime it starts.
//!
//! Not ported: the six tests of a coroutine "whose starter has returned"
//! (`testAWriteInACoroutineWhoseStarterHasReturnedStaysInThatCoroutine`,
//! `...EndsWithThatCoroutine`, `...KeepsItsValueAcrossItsOwnOverride`,
//! `testADetachedWriteAfterEveryOverrideEndedChangesTheSharedValueWhileAnOrphanKeepsItsOwn`,
//! `...UnderAnOverrideOpenedOutsideCoroutinesStaysInThatCoroutine`,
//! `...WithoutAnyOverrideChangesTheHandleWideValue`): Swoole cuts such a
//! coroutine off from its ancestors' scopes (`State\Group`); a Rust task
//! keeps the frames it inherited for as long as it runs, so the situation
//! does not arise.

use std::sync::Arc;

use tokio::sync::oneshot;
use utopia_database::state::{Scoped, inherit};

type Value = Arc<Scoped<&'static str>>;

fn value() -> Value {
    Arc::new(Scoped::new("handle"))
}

#[test]
fn test_an_override_outside_a_coroutine_lasts_for_its_callback() {
    let value = value();
    let inside = value.with_sync("override", || {
        let mut seen = vec![value.get()];
        value.set("changed");
        seen.push(value.get());
        seen
    });
    assert_eq!(inside, ["override", "changed"]);
    assert_eq!(value.get(), "handle");
}

#[test]
fn test_a_write_outside_an_override_changes_the_handle_wide_value() {
    let value = value();
    value.set("changed");
    assert_eq!(value.get(), "changed");
}

#[test]
fn test_an_override_is_removed_when_its_callback_throws() {
    let value = value();
    let thrown = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        value.with_sync("override", || -> () { panic!("failed") })
    }));
    assert!(thrown.is_err());
    assert_eq!(value.get(), "handle");
}

#[tokio::test]
async fn test_an_override_is_removed_when_its_future_fails() {
    let value = value();
    let result: Result<(), &str> = value.with("override", async { Err("failed") }).await;
    assert_eq!(result, Err("failed"));
    assert_eq!(value.get(), "handle");

    // A cancelled override ends too.
    let pending = value.with("override", std::future::pending::<()>());
    let _ = tokio::time::timeout(std::time::Duration::from_millis(1), pending).await;
    assert_eq!(value.get(), "handle");
}

#[test]
fn test_values_keep_their_own_overrides() {
    let first = Scoped::new("first");
    let second = Scoped::new("second");
    let seen = first.with_sync("override", || [first.get(), second.get()]);
    assert_eq!(seen, ["override", "second"]);
}

#[tokio::test]
async fn test_an_override_is_seen_by_the_coroutines_it_starts_but_not_by_siblings_or_the_parent() {
    let value = value();
    let (entered_tx, entered_rx) = oneshot::channel();
    let (released_tx, released_rx) = oneshot::channel::<()>();

    let owner = tokio::spawn(inherit({
        let value = value.clone();
        async move {
            value
                .with("override", async {
                    let child = value.clone();
                    let seen = tokio::spawn(inherit(async move {
                        tokio::time::sleep(std::time::Duration::from_millis(1)).await;
                        child.get()
                    }))
                    .await
                    .unwrap();
                    entered_tx.send(seen).unwrap();
                    released_rx.await.unwrap();
                })
                .await;
        }
    }));

    let child = entered_rx.await.unwrap();
    let parent = value.get();
    let sibling = tokio::spawn(inherit({
        let value = value.clone();
        async move { value.get() }
    }))
    .await
    .unwrap();
    released_tx.send(()).unwrap();
    owner.await.unwrap();

    assert_eq!((child, parent, sibling), ("override", "handle", "handle"));
}

#[tokio::test]
async fn test_a_write_inside_a_coroutines_override_stays_in_that_override() {
    let value = value();
    let inside = value
        .with("override", async {
            value.set("changed");
            value.get()
        })
        .await;
    assert_eq!((inside, value.get()), ("changed", "handle"));
}

#[tokio::test]
async fn test_a_write_in_a_coroutine_without_an_override_changes_the_handle_wide_value() {
    let value = value();
    let writer = value.clone();
    tokio::spawn(inherit(async move { writer.set("changed") })).await.unwrap();
    assert_eq!(value.get(), "changed");
    let writer = value.clone();
    tokio::spawn(async move { writer.set("again") }).await.unwrap();
    assert_eq!(value.get(), "again");
}

#[tokio::test]
async fn test_a_write_in_a_coroutine_started_inside_an_override_stays_in_that_coroutine() {
    let value = value();
    let (child, parent, sibling) = value
        .with("override", async {
            let (written_tx, written_rx) = oneshot::channel();
            let (released_tx, released_rx) = oneshot::channel::<()>();
            let writer = value.clone();
            let child = tokio::spawn(inherit(async move {
                writer.set("child");
                written_tx.send(writer.get()).unwrap();
                released_rx.await.unwrap();
            }));
            let child_seen = written_rx.await.unwrap();
            let parent = value.get();
            let reader = value.clone();
            let sibling = tokio::spawn(inherit(async move { reader.get() })).await.unwrap();
            released_tx.send(()).unwrap();
            child.await.unwrap();
            (child_seen, parent, sibling)
        })
        .await;
    let reader = value.clone();
    let unrelated = tokio::spawn(inherit(async move { reader.get() })).await.unwrap();

    assert_eq!((child, parent, sibling, unrelated), ("child", "override", "override", "handle"));
    assert_eq!(value.get(), "handle");
}

#[tokio::test]
#[allow(clippy::async_yields_async)] // the override returns the child task it started
async fn test_a_write_in_a_coroutine_started_inside_an_override_ends_with_that_override() {
    let value = value();
    let (written_tx, written_rx) = oneshot::channel();
    let (closed_tx, closed_rx) = oneshot::channel::<()>();
    let child = value
        .with("override", async {
            let writer = value.clone();
            let child = tokio::spawn(inherit(async move {
                writer.set("child");
                written_tx.send(()).unwrap();
                closed_rx.await.unwrap();
                writer.get()
            }));
            written_rx.await.unwrap();
            child
        })
        .await;
    closed_tx.send(()).unwrap();
    let after_scope = child.await.unwrap();
    assert_eq!((after_scope, value.get()), ("handle", "handle"));
}

#[tokio::test]
async fn test_a_write_in_a_coroutine_started_inside_an_override_is_seen_by_the_coroutines_it_starts() {
    let value = value();
    let (grandchild, grandchild_after, child, parent) = value
        .with("override", async {
            let writer = value.clone();
            let (grandchild, grandchild_after, child) = tokio::spawn(inherit(async move {
                writer.set("child");
                let inner = writer.clone();
                let (seen, after) = tokio::spawn(inherit(async move {
                    let seen = inner.get();
                    inner.set("grandchild");
                    (seen, inner.get())
                }))
                .await
                .unwrap();
                (seen, after, writer.get())
            }))
            .await
            .unwrap();
            (grandchild, grandchild_after, child, value.get())
        })
        .await;
    assert_eq!((grandchild, grandchild_after, child, parent), ("child", "grandchild", "child", "override"));
    assert_eq!(value.get(), "handle");
}

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap()
}

#[test]
fn test_a_write_in_a_coroutine_under_an_override_opened_outside_coroutines_stays_in_that_coroutine() {
    let value = value();
    let (child, parent, outside) = value.with_sync("override", || {
        let (child, parent) = runtime().block_on(inherit({
            let value = value.clone();
            async move {
                let writer = value.clone();
                let child = tokio::spawn(inherit(async move {
                    writer.set("child");
                    writer.get()
                }))
                .await
                .unwrap();
                (child, value.get())
            }
        }));
        (child, parent, value.get())
    });
    assert_eq!((child, parent, outside), ("child", "override", "override"));
    assert_eq!(value.get(), "handle");
}

#[tokio::test]
async fn test_a_null_write_in_a_coroutine_started_inside_an_override_is_kept() {
    let value: Arc<Scoped<Option<&'static str>>> = Arc::new(Scoped::new(Some("handle")));
    let seen = value
        .with(Some("override"), async {
            let writer = value.clone();
            tokio::spawn(inherit(async move {
                writer.set(None);
                writer.get()
            }))
            .await
            .unwrap()
        })
        .await;
    assert_eq!(seen, None);
}

#[tokio::test]
async fn test_a_childs_override_leaves_its_parent_unchanged() {
    let value = value();
    let (parent, child, parent_after) = value
        .with("parent", async {
            let inner = value.clone();
            let child = tokio::spawn(inherit(async move {
                inner
                    .with("child", async {
                        tokio::time::sleep(std::time::Duration::from_millis(1)).await;
                        inner.get()
                    })
                    .await
            }));
            let parent = value.get();
            let child = child.await.unwrap();
            (parent, child, value.get())
        })
        .await;
    assert_eq!((parent, child, parent_after), ("parent", "child", "parent"));
}

#[test]
fn test_an_override_opened_outside_coroutines_is_seen_inside_them() {
    let value = value();
    let seen = value.with_sync("override", || {
        runtime().block_on(inherit({
            let value = value.clone();
            async move { tokio::spawn(inherit(async move { value.get() })).await.unwrap() }
        }))
    });
    assert_eq!(seen, "override");
    assert_eq!(value.get(), "handle");
}

#[tokio::test]
async fn test_a_task_spawned_without_inherit_starts_outside_every_override() {
    let value = value();
    let seen = value
        .with("override", async {
            let reader = value.clone();
            tokio::spawn(async move { reader.get() }).await.unwrap()
        })
        .await;
    assert_eq!(seen, "handle");
}

#[tokio::test]
async fn test_sibling_futures_do_not_see_each_others_overrides() {
    let value = value();
    let (a, b) = futures_util::join!(
        value.with("override", async {
            tokio::task::yield_now().await;
            value.get()
        }),
        async {
            tokio::task::yield_now().await;
            value.get()
        }
    );
    assert_eq!((a, b), ("override", "handle"));
}
