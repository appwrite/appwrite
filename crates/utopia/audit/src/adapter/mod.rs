//! [`Adapter`]: where logs are stored, and the adapters that ship.

#[cfg(feature = "clickhouse")]
pub mod clickhouse;
#[cfg(feature = "database")]
pub mod database;

use std::sync::Arc;

use php_std::Array;

#[cfg(feature = "clickhouse")]
pub use clickhouse::ClickHouse;
#[cfg(feature = "database")]
pub use database::Database;

use crate::{DateTime, Log, Page, Query, Range, Result};

/// A store of audit logs. Implement it to keep logs anywhere else.
///
/// A log to create is a PHP array: `userId` (or `actorId`), `event`,
/// `resource`, `userAgent`, `ip`, `data`, an optional `time` (a date
/// string), and whatever else the store keeps.
///
/// PHP: `Utopia\Audit\Adapter`.
#[async_trait::async_trait]
pub trait Adapter: Send + Sync {
    /// The adapter's name (`Database`, `ClickHouse`).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// Creates what logs are stored in (a collection, a table, indexes).
    ///
    /// PHP: `setup()`.
    async fn setup(&self) -> Result<()>;

    /// The log `id`, if any.
    ///
    /// PHP: `getById($id)`.
    async fn get_by_id(&self, id: &str) -> Result<Option<Log>>;

    /// Stores one log and returns it as stored.
    ///
    /// PHP: `create($log)`.
    async fn create(&self, log: Array) -> Result<Log>;

    /// Stores several logs at once; `true`.
    ///
    /// PHP: `createBatch($logs)`.
    async fn create_batch(&self, logs: Vec<Array>) -> Result<bool>;

    /// The logs of a user in a time range, a page of them.
    ///
    /// PHP: `getByUser($userId, $after, $before, $limit, $offset, $ascending)`.
    async fn get_by_user(&self, user_id: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>>;

    /// How many logs a user has in a time range, counting up to `max`.
    ///
    /// PHP: `countByUser($userId, $after, $before, $max)`.
    async fn count_by_user(&self, user_id: &str, range: Range<'_>, max: Option<i64>) -> Result<i64>;

    /// The logs of a resource in a time range, a page of them.
    ///
    /// PHP: `getByResource($resource, $after, $before, $limit, $offset, $ascending)`.
    async fn get_by_resource(&self, resource: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>>;

    /// How many logs a resource has in a time range, counting up to `max`.
    ///
    /// PHP: `countByResource($resource, $after, $before, $max)`.
    async fn count_by_resource(&self, resource: &str, range: Range<'_>, max: Option<i64>) -> Result<i64>;

    /// The logs of a user with one of `events`, a page of them.
    ///
    /// PHP: `getByUserAndEvents($userId, $events, $after, $before, $limit, $offset, $ascending)`.
    async fn get_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>>;

    /// How many logs a user has with one of `events`, counting up to `max`.
    ///
    /// PHP: `countByUserAndEvents($userId, $events, $after, $before, $max)`.
    async fn count_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64>;

    /// The logs of a resource with one of `events`, a page of them.
    ///
    /// PHP: `getByResourceAndEvents($resource, $events, $after, $before, $limit, $offset, $ascending)`.
    async fn get_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>>;

    /// How many logs a resource has with one of `events`, counting up to `max`.
    ///
    /// PHP: `countByResourceAndEvents($resource, $events, $after, $before, $max)`.
    async fn count_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64>;

    /// Deletes every log older than `datetime`; `true`.
    ///
    /// PHP: `cleanup($datetime)`.
    async fn cleanup(&self, datetime: &DateTime) -> Result<bool>;

    /// The logs `queries` select.
    ///
    /// PHP: `find($queries)`.
    async fn find(&self, queries: &[Query]) -> Result<Vec<Log>>;

    /// How many logs the filters of `queries` select, counting up to `max`
    /// (inclusive) when given.
    ///
    /// PHP: `count($queries, $max)`.
    async fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64>;

    /// Whether the store answers; `false` on any failure.
    ///
    /// PHP: `ping()`.
    async fn ping(&self) -> bool;
}

macro_rules! forward {
    ($($ty:ty),*) => {$(
        #[async_trait::async_trait]
        impl<T: Adapter + ?Sized> Adapter for $ty {
            fn name(&self) -> &str {
                (**self).name()
            }
            async fn setup(&self) -> Result<()> {
                (**self).setup().await
            }
            async fn get_by_id(&self, id: &str) -> Result<Option<Log>> {
                (**self).get_by_id(id).await
            }
            async fn create(&self, log: Array) -> Result<Log> {
                (**self).create(log).await
            }
            async fn create_batch(&self, logs: Vec<Array>) -> Result<bool> {
                (**self).create_batch(logs).await
            }
            async fn get_by_user(&self, user_id: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
                (**self).get_by_user(user_id, range, page).await
            }
            async fn count_by_user(&self, user_id: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
                (**self).count_by_user(user_id, range, max).await
            }
            async fn get_by_resource(&self, resource: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
                (**self).get_by_resource(resource, range, page).await
            }
            async fn count_by_resource(&self, resource: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
                (**self).count_by_resource(resource, range, max).await
            }
            async fn get_by_user_and_events(
                &self,
                user_id: &str,
                events: &[&str],
                range: Range<'_>,
                page: Page,
            ) -> Result<Vec<Log>> {
                (**self).get_by_user_and_events(user_id, events, range, page).await
            }
            async fn count_by_user_and_events(
                &self,
                user_id: &str,
                events: &[&str],
                range: Range<'_>,
                max: Option<i64>,
            ) -> Result<i64> {
                (**self).count_by_user_and_events(user_id, events, range, max).await
            }
            async fn get_by_resource_and_events(
                &self,
                resource: &str,
                events: &[&str],
                range: Range<'_>,
                page: Page,
            ) -> Result<Vec<Log>> {
                (**self).get_by_resource_and_events(resource, events, range, page).await
            }
            async fn count_by_resource_and_events(
                &self,
                resource: &str,
                events: &[&str],
                range: Range<'_>,
                max: Option<i64>,
            ) -> Result<i64> {
                (**self).count_by_resource_and_events(resource, events, range, max).await
            }
            async fn cleanup(&self, datetime: &DateTime) -> Result<bool> {
                (**self).cleanup(datetime).await
            }
            async fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
                (**self).find(queries).await
            }
            async fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64> {
                (**self).count(queries, max).await
            }
            async fn ping(&self) -> bool {
                (**self).ping().await
            }
        }
    )*};
}

forward!(&T, Box<T>, Arc<T>);
