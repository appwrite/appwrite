//! `silent()`, the scoped toggles, and `snapshot()` / `withSnapshot()`.
//!
//! Each scope takes the future it runs: what it sets is seen by that future
//! and what it awaits, and is restored when the future completes or is
//! dropped. PHP's scopes take a callable; a Rust future is as lazy.

use std::future::Future;
use std::sync::Arc;

use php_std::datetime::DateTime;

use super::Database;
use crate::state::Snapshot;

impl Database {
    /// Runs `future` with every lifecycle hook silenced (`silent()`); a
    /// nested silence never narrows the one around it.
    ///
    /// A mirror silences itself and its source.
    pub async fn silent<F: Future>(&self, future: F) -> F::Output {
        match self.inner.mirror.get() {
            Some(parts) => {
                self.inner
                    .toggles
                    .silenced
                    .with(true, parts.source.inner.toggles.silenced.with(true, Box::pin(future)))
                    .await
            }
            None => self.inner.toggles.silenced.with(true, future).await,
        }
    }

    /// Runs `future` with the named lifecycle hooks `hooks` silenced
    /// (`silent($callback, $hooks)`), on top of those already silenced.
    ///
    /// A mirror silences them on itself and its source.
    pub async fn silent_hooks<F: Future, S: AsRef<str>>(&self, hooks: &[S], future: F) -> F::Output {
        let names = |db: &Database| {
            let mut names: Vec<String> = db.inner.toggles.silenced_listeners.get().as_ref().clone();
            for hook in hooks {
                if !names.iter().any(|n| n == hook.as_ref()) {
                    names.push(hook.as_ref().to_owned());
                }
            }
            Arc::new(names)
        };
        let own = &self.inner.toggles.silenced_listeners;
        match self.inner.mirror.get() {
            Some(parts) => {
                let source = &parts.source.inner.toggles.silenced_listeners;
                own.with(names(self), source.with(names(&parts.source), Box::pin(future))).await
            }
            None => own.with(names(self), future).await,
        }
    }

    /// Whether every lifecycle hook is silenced (`areEventsSilenced()`).
    pub(crate) fn are_events_silenced(&self) -> bool {
        self.inner.toggles.silenced.get()
    }

    /// The names of the silenced named hooks.
    pub(crate) fn silenced_listeners(&self) -> Arc<Vec<String>> {
        self.inner.toggles.silenced_listeners.get()
    }

    /// `setPreserveDates()`.
    pub fn set_preserve_dates(&self, preserve: bool) -> &Self {
        for db in self.mirrored() {
            db.set_preserve_dates(preserve);
        }
        self.inner.toggles.date_preservation.set(preserve);
        self
    }

    /// `isPreservingDates()`.
    pub fn is_preserving_dates(&self) -> bool {
        self.inner.toggles.date_preservation.get()
    }

    /// Runs `future` with date preservation on or off (`withPreserveDates()`).
    pub async fn with_preserve_dates<F: Future>(&self, preserve: bool, future: F) -> F::Output {
        match self.inner.mirror.get() {
            None => self.inner.toggles.date_preservation.with(preserve, future).await,
            Some(_) => {
                self.scope_mirrored(|db| &db.inner.toggles.date_preservation, preserve, true, Box::pin(future)).await
            }
        }
    }

    /// Runs `future` with the toggle `toggle` picks set to `value` on this
    /// database and, for a mirror, on its source and (with `destination`) its
    /// destination: the mirror's scope outermost.
    async fn scope_mirrored<T, F>(
        &self,
        toggle: impl Fn(&Database) -> &crate::state::Scoped<T>,
        value: T,
        destination: bool,
        future: F,
    ) -> F::Output
    where
        T: Clone + Send + Sync + 'static,
        F: Future,
    {
        let own = toggle(self);
        let Some(parts) = self.inner.mirror.get() else {
            return own.with(value, future).await;
        };
        // Only a mirror reaches here, with its future boxed.
        let source = toggle(&parts.source);
        match parts.destination.as_ref().filter(|_| destination) {
            Some(d) => own.with(value.clone(), source.with(value.clone(), toggle(d).with(value, future))).await,
            None => own.with(value.clone(), source.with(value, future)).await,
        }
    }

    /// Runs `future` with creates that hit an existing id or unique key
    /// skipping that document instead of failing (`ignoreDuplicates()`).
    pub async fn ignore_duplicates<F: Future>(&self, future: F) -> F::Output {
        self.inner.toggles.ignoring_duplicates.with(true, future).await
    }

    /// `isIgnoringDuplicates()`.
    pub(crate) fn is_ignoring_duplicates(&self) -> bool {
        self.inner.toggles.ignoring_duplicates.get()
    }

    /// `setPreserveSequence()`.
    pub fn set_preserve_sequence(&self, preserve: bool) -> &Self {
        for db in self.mirrored() {
            db.set_preserve_sequence(preserve);
        }
        self.inner.toggles.sequence_preservation.set(preserve);
        self
    }

    /// `isPreservingSequence()`.
    pub fn is_preserving_sequence(&self) -> bool {
        self.inner.toggles.sequence_preservation.get()
    }

    /// Runs `future` with sequence preservation on or off
    /// (`withPreserveSequence()`).
    pub async fn with_preserve_sequence<F: Future>(&self, preserve: bool, future: F) -> F::Output {
        match self.inner.mirror.get() {
            None => self.inner.toggles.sequence_preservation.with(preserve, future).await,
            Some(_) => {
                self.scope_mirrored(|db| &db.inner.toggles.sequence_preservation, preserve, true, Box::pin(future))
                    .await
            }
        }
    }

    /// Sets whether, while authorization is enabled, a related document
    /// given as a document may only add permissions for roles the caller
    /// holds (`setCheckGrants()`).
    ///
    /// A mirror sets it on its source too: the destination replays what
    /// the source allowed.
    pub fn set_check_grants(&self, check: bool) -> &Self {
        if let Some(parts) = self.inner.mirror.get() {
            parts.source.set_check_grants(check);
        }
        self.inner.toggles.grant_checking.set(check);
        self
    }

    /// `isCheckingGrants()`.
    pub fn is_checking_grants(&self) -> bool {
        self.inner.toggles.grant_checking.get()
    }

    /// Runs `future` with the grant check on or off (`withCheckGrants()`).
    pub async fn with_check_grants<F: Future>(&self, check: bool, future: F) -> F::Output {
        match self.inner.mirror.get() {
            None => self.inner.toggles.grant_checking.with(check, future).await,
            Some(_) => self.scope_mirrored(|db| &db.inner.toggles.grant_checking, check, false, Box::pin(future)).await,
        }
    }

    /// Whether grants are checked now: on, and authorization enabled
    /// (`checksGrants()`).
    pub(crate) fn checks_grants(&self) -> bool {
        self.inner.toggles.grant_checking.get() && self.authorization().status()
    }

    /// `setValidation()`.
    pub fn set_validation(&self, validation: bool) -> &Self {
        for db in self.mirrored() {
            db.set_validation(validation);
        }
        self.inner.toggles.validation.set(validation);
        self
    }

    /// Whether documents, queries and schema changes are validated
    /// (`isValidating()`).
    pub fn is_validating(&self) -> bool {
        self.inner.toggles.validation.get()
    }

    /// Runs `future` with validation on or off (`withValidation()`).
    pub async fn with_validation<F: Future>(&self, validation: bool, future: F) -> F::Output {
        match self.inner.mirror.get() {
            None => self.inner.toggles.validation.with(validation, future).await,
            Some(_) => self.scope_mirrored(|db| &db.inner.toggles.validation, validation, true, Box::pin(future)).await,
        }
    }

    /// Runs `future` without validation (`skipValidation()`).
    pub async fn skip_validation<F: Future>(&self, future: F) -> F::Output {
        self.with_validation(false, future).await
    }

    /// Runs `future` with no query hook rewriting the reads it makes
    /// (`skipQueryHooks()`, internal).
    pub async fn skip_query_hooks<F: Future>(&self, future: F) -> F::Output {
        self.inner.toggles.rewriting.with(false, future).await
    }

    /// Whether query hooks rewrite reads now.
    pub(crate) fn is_rewriting(&self) -> bool {
        self.inner.toggles.rewriting.get()
    }

    /// `setFiltering()`.
    pub fn set_filtering(&self, filtering: bool) -> &Self {
        for db in self.mirrored() {
            db.set_filtering(filtering);
        }
        self.inner.toggles.filtering.set(filtering);
        self
    }

    /// Whether attribute filters encode and decode values (`isFiltering()`).
    pub fn is_filtering(&self) -> bool {
        self.inner.toggles.filtering.get()
    }

    /// The names of the filters turned off (`$filterExclusions`), `None` for
    /// PHP's `null`.
    pub(crate) fn filter_exclusions(&self) -> Option<Arc<Vec<(String, bool)>>> {
        self.inner.toggles.filter_exclusions.get()
    }

    /// Runs `future` with filtering on or off (`withFiltering()`). Given
    /// filter names, filtering stays as it is and only the named filters are
    /// turned off (they become the ones turned off) or back on.
    ///
    /// A mirror opens the scope on its source, its destination and itself,
    /// in that order.
    pub async fn with_filtering<F: Future, S: AsRef<str>>(
        &self,
        filtering: bool,
        filters: Option<&[S]>,
        future: F,
    ) -> F::Output {
        let Some(parts) = self.inner.mirror.get() else {
            return self.filtering_scope(filtering, filters, future).await;
        };
        let own = Box::pin(self.filtering_scope(filtering, filters, Box::pin(future)));
        match &parts.destination {
            Some(d) => {
                parts.source.filtering_scope(filtering, filters, d.filtering_scope(filtering, filters, own)).await
            }
            None => parts.source.filtering_scope(filtering, filters, own).await,
        }
    }

    /// `withFiltering()` on this database alone.
    async fn filtering_scope<F: Future, S: AsRef<str>>(
        &self,
        filtering: bool,
        filters: Option<&[S]>,
        future: F,
    ) -> F::Output {
        let Some(filters) = filters.filter(|f| !f.is_empty()) else {
            return self.inner.toggles.filtering.with(filtering, future).await;
        };
        let named: Vec<String> = {
            let mut named: Vec<String> = Vec::new();
            for f in filters {
                if !named.iter().any(|n| n == f.as_ref()) {
                    named.push(f.as_ref().to_owned());
                }
            }
            named
        };
        let excluded: Vec<(String, bool)> = if filtering {
            self.filter_exclusions()
                .map(|e| e.iter().filter(|(n, _)| !named.contains(n)).cloned().collect())
                .unwrap_or_default()
        } else {
            named.into_iter().map(|n| (n, true)).collect()
        };
        let current = self.inner.toggles.filtering.get();
        self.inner
            .toggles
            .filtering
            .with(current, self.inner.toggles.filter_exclusions.with(Some(Arc::new(excluded)), future))
            .await
    }

    /// Runs `future` without filters, or without the named ones
    /// (`skipFilters()`).
    pub async fn skip_filters<F: Future, S: AsRef<str>>(&self, filters: Option<&[S]>, future: F) -> F::Output {
        self.with_filtering(false, filters, future).await
    }

    /// Runs `future` with the request timestamp set (`withRequestTimestamp()`):
    /// an update of a document changed after it conflicts.
    pub async fn with_request_timestamp<F: Future>(&self, timestamp: Option<DateTime>, future: F) -> F::Output {
        match self.inner.mirror.get() {
            None => self.inner.toggles.request_timestamp.with(timestamp, future).await,
            Some(_) => {
                self.scope_mirrored(|db| &db.inner.toggles.request_timestamp, timestamp, false, Box::pin(future)).await
            }
        }
    }

    /// The request timestamp the current future runs under.
    pub(crate) fn request_timestamp(&self) -> Option<DateTime> {
        self.inner.toggles.request_timestamp.get()
    }

    /// Runs `future` with relationships neither populated nor written
    /// (`skipRelationships()`).
    pub async fn skip_relationships<F: Future>(&self, future: F) -> F::Output {
        match self.inner.mirror.get() {
            Some(parts) => self.skip_relationships_scope(parts.source.skip_relationships_scope(Box::pin(future))).await,
            None => match self.relationship_hook() {
                Some(hook) => hook.with_enabled(false, future).await,
                None => future.await,
            },
        }
    }

    /// `skip_relationships()` on this database alone.
    async fn skip_relationships_scope<F: Future>(&self, future: F) -> F::Output {
        match self.relationship_hook() {
            Some(hook) => hook.with_enabled(false, future).await,
            None => future.await,
        }
    }

    /// Runs `future` without checking related documents exist before linking
    /// them (`skipRelationshipsExistCheck()`).
    pub async fn skip_relationships_exist_check<F: Future>(&self, future: F) -> F::Output {
        match self.inner.mirror.get() {
            Some(parts) => {
                self.skip_relationships_exist_check_scope(
                    parts.source.skip_relationships_exist_check_scope(Box::pin(future)),
                )
                .await
            }
            None => match self.relationship_hook() {
                Some(hook) => hook.with_check_exist(false, future).await,
                None => future.await,
            },
        }
    }

    /// `skip_relationships_exist_check()` on this database alone.
    async fn skip_relationships_exist_check_scope<F: Future>(&self, future: F) -> F::Output {
        match self.relationship_hook() {
            Some(hook) => hook.with_check_exist(false, future).await,
            None => future.await,
        }
    }

    /// The authorization, relationship, silence, tenant and toggle state the
    /// current future sees (`snapshot()`, internal).
    pub fn snapshot(&self) -> Snapshot {
        let authorization = self.authorization();
        let hook = self.relationship_hook();
        let toggles = &self.inner.toggles;
        Snapshot {
            authorization: authorization.status(),
            roles: authorization.role_names(),
            relationships: hook.as_ref().is_none_or(|h| h.is_enabled()),
            exist_check: hook.as_ref().is_none_or(|h| h.should_check_exist()),
            population: hook.as_ref().is_some_and(|h| h.is_in_batch_population()),
            silenced: toggles.silenced.get(),
            silenced_listeners: toggles.silenced_listeners.get().as_ref().clone(),
            tenant: self.inner.adapter.tenant(),
            filters: toggles.filtering.get(),
            disabled_filters: toggles.filter_exclusions.get().map(|e| e.as_ref().clone()),
            validation: toggles.validation.get(),
            preserve_dates: toggles.date_preservation.get(),
            preserve_sequence: toggles.sequence_preservation.get(),
            ignore_duplicates: toggles.ignoring_duplicates.get(),
            request_timestamp: toggles.request_timestamp.get().map(|d| crate::datetime::format(&d)),
            check_grants: toggles.grant_checking.get(),
            rewriting: toggles.rewriting.get(),
        }
    }

    /// Runs `future` under a snapshot's state (`withSnapshot()`, internal).
    pub async fn with_snapshot<F: Future>(&self, snapshot: &Snapshot, future: F) -> F::Output {
        let toggles = &self.inner.toggles;
        let timestamp = snapshot
            .request_timestamp
            .as_deref()
            .and_then(|t| DateTime::new(t.as_bytes(), None, &php_std::datetime::TzInfo::utc()).ok());
        let scoped = toggles.filtering.with(
            snapshot.filters,
            toggles.filter_exclusions.with(
                snapshot.disabled_filters.clone().map(Arc::new),
                toggles.validation.with(
                    snapshot.validation,
                    toggles.date_preservation.with(
                        snapshot.preserve_dates,
                        toggles.sequence_preservation.with(
                            snapshot.preserve_sequence,
                            toggles.ignoring_duplicates.with(
                                snapshot.ignore_duplicates,
                                toggles.request_timestamp.with(
                                    timestamp,
                                    toggles.grant_checking.with(
                                        snapshot.check_grants,
                                        toggles.rewriting.with(snapshot.rewriting, future),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
        let scoped = self.inner.adapter.state().tenant().with(snapshot.tenant.clone(), scoped);
        let scoped = toggles.silenced.with(
            snapshot.silenced,
            toggles.silenced_listeners.with(Arc::new(snapshot.silenced_listeners.clone()), scoped),
        );
        let authorization = self.authorization();
        match self.relationship_hook() {
            Some(hook) => {
                authorization
                    .restore(snapshot.authorization, &snapshot.roles, hook.with_snapshot(snapshot, scoped))
                    .await
            }
            None => authorization.restore(snapshot.authorization, &snapshot.roles, scoped).await,
        }
    }
}
