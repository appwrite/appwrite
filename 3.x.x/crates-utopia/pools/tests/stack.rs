//! PHP `tests/Pools/Adapter/StackTest.php` plus shared scopes.

#[path = "harness/shared.rs"]
mod support;

use utopia_pools::adapter::Stack;

#[tokio::test]
async fn connection_id_is_namespaced() {
    support::connection_id_is_namespaced(Stack::new()).await;
}

#[tokio::test]
async fn connection_exposes_resource() {
    support::connection_exposes_resource(Stack::new()).await;
}

#[tokio::test]
async fn connection_reclaim() {
    support::connection_reclaim(Stack::new()).await;
}

#[tokio::test]
async fn connection_destroy() {
    support::connection_destroy(Stack::<String>::new).await;
}

#[tokio::test]
async fn dropping_a_pool_frees_idle_resources() {
    support::dropping_a_pool_frees_idle_resources(Stack::new()).await;
}

#[tokio::test]
async fn connection_outlives_pool() {
    support::connection_outlives_pool(Stack::new()).await;
}

#[tokio::test]
async fn group_add_get_remove() {
    support::group_add_get_remove(Stack::<String>::new);
}

#[tokio::test]
async fn group_reclaim() {
    support::group_reclaim(Stack::new()).await;
}

#[tokio::test]
async fn group_use() {
    support::group_use(Stack::<String>::new).await;
}

#[tokio::test]
async fn group_use_reclaims_when_later_missing() {
    support::group_use_reclaims_when_later_missing(Stack::new()).await;
}

#[tokio::test]
async fn group_use_records_use_duration() {
    support::group_use_records_use_duration(Stack::new()).await;
}

#[tokio::test]
async fn group_empty_names() {
    support::group_empty_names(Stack::new()).await;
}

#[tokio::test]
async fn pool_name_size() {
    support::pool_name_size(Stack::new());
}

#[tokio::test]
async fn pool_pop() {
    support::pool_pop(Stack::new()).await;
}

#[tokio::test]
async fn pool_use() {
    support::pool_use(Stack::new()).await;
}

#[tokio::test]
async fn pool_push_count() {
    support::pool_push_count(Stack::new()).await;
}

#[tokio::test]
async fn pool_reclaim() {
    support::pool_reclaim(Stack::new()).await;
}

#[tokio::test]
async fn pool_is_empty_full() {
    support::pool_is_empty_full(Stack::new()).await;
}

#[tokio::test]
async fn pool_destroy() {
    support::pool_destroy(Stack::<String>::new).await;
}

#[tokio::test]
async fn pop_timeout_then_throws() {
    support::pop_timeout_then_throws(Stack::new()).await;
}

#[tokio::test]
async fn creation_failure_surfaces() {
    support::creation_failure_surfaces(Stack::new()).await;
}

#[tokio::test]
async fn pop_releases_slot_on_type_error() {
    support::pop_releases_slot_on_type_error(Stack::new()).await;
}

#[tokio::test]
async fn double_destroy_does_not_inflate() {
    support::double_destroy_does_not_inflate(Stack::new()).await;
}

#[tokio::test]
async fn empty_error_includes_active() {
    support::empty_error_includes_active(Stack::new()).await;
}

#[tokio::test]
async fn use_destroys_when_recovery_fails() {
    support::use_destroys_when_recovery_fails(Stack::new()).await;
}

#[tokio::test]
async fn use_destroys_when_recovery_returns_false() {
    support::use_destroys_when_recovery_returns_false(Stack::new()).await;
}

#[tokio::test]
async fn use_recovers_when_reconnect_succeeds() {
    support::use_recovers_when_reconnect_succeeds(Stack::new()).await;
}

#[tokio::test]
async fn use_destroys_without_hooks() {
    support::use_destroys_without_hooks(Stack::new()).await;
}

#[tokio::test]
async fn use_forgets_when_destroy_sync_fails() {
    support::use_forgets_when_destroy_sync_fails().await;
}

#[tokio::test]
async fn use_preserves_callback_error() {
    support::use_preserves_callback_error(Stack::new()).await;
}

#[tokio::test]
async fn pool_use_duration_telemetry() {
    support::pool_use_duration_telemetry(Stack::new()).await;
}

#[tokio::test]
async fn pool_wait_time_telemetry() {
    support::pool_wait_time_telemetry(Stack::new()).await;
}

#[tokio::test]
async fn invalid_size() {
    support::invalid_size_timeout(Stack::new());
}

#[tokio::test]
async fn invalid_timeout() {
    support::invalid_timeout(Stack::<String>::new);
}
