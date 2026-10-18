//! The abuse counter reset (PHP: `app/controllers/shared/api.php`, the `api`
//! group's shutdown hook reading `abuse-reset`; hooks.json `abuse-reset`).

use utopia_abuse::TimeLimit;
use utopia_abuse::time_limit::{Redis, Store};

use crate::datetime;
use crate::platform::{Labels, State};

/// The `api` group's abuse reset (after the action): a response whose status
/// the route lists in `abuse-reset` clears its limits.
pub(super) async fn abuse_reset(state: &State, labels: Labels, status: u16, params: &[(String, String)]) {
    if !state.config.abuse {
        return;
    }
    let now = datetime::now().and_utc().timestamp();
    reset_limits(labels, status, params, now, Redis::new(state.abuse.clone())).await;
}

/// Sets the count of each of the route's `abuse-key`s (with the request's
/// parameters) to zero in the window `now` falls in, when `status` is one of
/// its `abuse-reset` codes.
async fn reset_limits<S: Store + Clone>(labels: Labels, status: u16, params: &[(String, String)], now: i64, store: S) {
    if !labels.abuse_reset.contains(&status) {
        return;
    }
    for key in labels.abuse_key {
        let Ok(time_limit) = TimeLimit::new(*key, labels.abuse_limit, labels.abuse_time, store.clone()) else {
            continue;
        };
        if let Err(e) = time_limit.with_params(params.to_vec()).reset_at(now).await {
            tracing::warn!(error = %e, "abuse reset failed");
        }
    }
}

#[cfg(test)]
mod abuse_reset_tests {
    use std::sync::Arc;

    use utopia_abuse::TimeLimit;
    use utopia_abuse::time_limit::{Database, Memory};

    use super::reset_limits;
    use crate::platform::Labels;

    /// `POST /v1/account/sessions/email`'s labels.
    const LABELS: Labels =
        Labels { abuse_limit: 10, abuse_key: &["url:{url},email:{param-email}"], abuse_reset: &[201], ..Labels::NONE };

    /// A minute into a window.
    const NOW: i64 = 1_790_000_060;

    fn params(email: &str) -> Vec<(String, String)> {
        vec![
            ("{url}".to_owned(), "localhost/v1/account/sessions/email".to_owned()),
            ("{ip}".to_owned(), "127.0.0.1".to_owned()),
            ("{param-email}".to_owned(), email.to_owned()),
        ]
    }

    fn limit(store: &Arc<Memory>, email: &str) -> TimeLimit<Database<Arc<Memory>>> {
        TimeLimit::new(LABELS.abuse_key[0], LABELS.abuse_limit, LABELS.abuse_time, Database::new(store.clone()))
            .unwrap()
            .with_params(params(email))
    }

    async fn store() -> Arc<Memory> {
        let store = Arc::new(Memory::new());
        Database::new(store.clone()).setup().await.unwrap();
        store
    }

    async fn hit(store: &Arc<Memory>, email: &str, times: usize) {
        for _ in 0..times {
            limit(store, email).check_at(NOW).await.unwrap();
        }
    }

    async fn used(store: &Arc<Memory>, email: &str) -> i64 {
        use utopia_abuse::Adapter as _;
        use utopia_abuse::time_limit::Store as _;
        let limit = limit(store, email);
        limit.store().count(limit.key(), limit.window(NOW)).await.unwrap()
    }

    /// A status the route lists resets its key (with the request's
    /// parameters) in the current window; another status, or another
    /// request's parameters, leave the counts alone.
    #[utopia_async::test]
    async fn listed_statuses_reset_the_request_keys() {
        let store = store().await;
        hit(&store, "a@example.com", 3).await;
        hit(&store, "b@example.com", 2).await;

        reset_limits(LABELS, 401, &params("a@example.com"), NOW, Database::new(store.clone())).await;
        assert_eq!(used(&store, "a@example.com").await, 3);

        reset_limits(LABELS, 201, &params("a@example.com"), NOW, Database::new(store.clone())).await;
        assert_eq!(used(&store, "a@example.com").await, 0);
        assert_eq!(used(&store, "b@example.com").await, 2);
    }

    /// A route without `abuse-reset` codes never resets.
    #[utopia_async::test]
    async fn routes_without_reset_codes_keep_their_counts() {
        let store = store().await;
        hit(&store, "a@example.com", 4).await;
        let labels = Labels { abuse_reset: &[], ..LABELS };
        reset_limits(labels, 201, &params("a@example.com"), NOW, Database::new(store.clone())).await;
        assert_eq!(used(&store, "a@example.com").await, 4);
    }
}
