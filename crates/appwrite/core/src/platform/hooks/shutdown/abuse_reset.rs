//! The failure limits' release (PHP: `app/controllers/shared/api.php`, the
//! `api` group's shutdown hook reading `abuse`; hooks.json `abuse-reset`).

use crate::datetime;
use crate::platform::hooks::init::abuse::release_limit;
use crate::platform::{Labels, State};

/// The `api` group's abuse release (after the action). Shutdown hooks run
/// only when the request did not fail, so the hits its failure limits
/// reserved on arrival are given back. Only the limits the init hook counted
/// for this caller reserved anything.
pub(super) async fn abuse_reset(state: &State, labels: Labels, trusted: bool, values: &[(String, String)]) {
    if !state.config.abuse {
        return;
    }
    let now = datetime::now().and_utc().timestamp();
    for limit in labels.abuse.iter().filter(|limit| limit.failures && limit.counts(trusted)) {
        release_limit(&state.abuse, limit, values, now).await;
    }
}
