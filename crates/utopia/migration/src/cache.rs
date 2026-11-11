//! The transfer's record of every resource it moved.

use std::sync::Mutex;

use indexmap::IndexMap;
use php_std::{ArrayKey, Str};

use crate::error::{Error, Result};
use crate::resource::{Kind, ResourceRef, lock, types};

/// One cache entry: a resource, or, for rows and documents (which are
/// counted rather than kept), the number of them in one status.
#[derive(Debug, Clone)]
pub enum Entry {
    /// A resource.
    Resource(ResourceRef),
    /// A count of rows or documents, as a decimal string.
    Count(String),
}

impl Entry {
    /// The resource, when this is one.
    pub fn resource(&self) -> Option<&ResourceRef> {
        match self {
            Entry::Resource(r) => Some(r),
            Entry::Count(_) => None,
        }
    }
}

/// The entries of one resource type, by key (PHP array keys: numeric
/// strings are integers).
pub type Entries = IndexMap<ArrayKey, Entry>;

/// A record of the resources a transfer moved, by type and key, shared by
/// its source and destination: sources read the parents they exported
/// (tables of a database, ...) from it, destinations the status of what
/// they imported, and [`crate::Transfer`] its counters and report.
///
/// Rows and documents are counted per status instead of kept, so a large
/// table does not fill memory; files and deployments are kept without their
/// data.
///
/// PHP: `Utopia\Migration\Cache`.
#[derive(Debug, Default)]
pub struct Cache {
    entries: Mutex<IndexMap<String, Entries>>,
}

fn is_record(name: &str) -> bool {
    name == types::ROW || name == types::DOCUMENT
}

fn key_of(s: String) -> ArrayKey {
    ArrayKey::normalize(Str::from(s))
}

impl Cache {
    /// An empty cache.
    pub fn new() -> Self {
        Self::default()
    }

    /// The key of `resource`: its sequence prefixed by its parents'
    /// (`<database type>_<database>_<table>` for a table, ...). A resource
    /// without a sequence (or with `"0"`) first gets a `uniqid()` one.
    ///
    /// PHP: `resolveResourceCacheKey()`.
    pub fn resolve_key(&self, resource: &ResourceRef) -> String {
        let sequence = resource.sequence();
        if sequence.is_empty() || sequence == "0" {
            resource.set_sequence(php_std::system::uniqid("", false));
        }
        let mut keys: Vec<String> = Vec::new();
        match resource.kind() {
            Kind::Table(t) => {
                keys.push(t.database.as_database().map(|d| d.database_type.clone()).unwrap_or_default());
                keys.push(t.database.sequence());
            }
            Kind::Row(_) | Kind::Field(_) | Kind::Index(_) => {
                if let Some(table) = resource.table() {
                    keys.push(table.database().map(|d| d.sequence()).unwrap_or_default());
                    keys.push(table.sequence());
                }
            }
            Kind::File(f) => keys.push(f.bucket.sequence()),
            Kind::Deployment(d) => keys.push(d.func.sequence()),
            Kind::SiteDeployment(d) => keys.push(d.site.sequence()),
            _ => {}
        }
        keys.push(resource.sequence());
        keys.join("_")
    }

    /// Records `resource`: a row or document increments its status's
    /// count; a file or deployment drops its chunk data first.
    ///
    /// PHP: `add()`.
    pub fn add(&self, resource: &ResourceRef) {
        let key = self.resolve_key(resource);
        let name = resource.name().to_owned();
        let mut entries = lock(&self.entries);
        if is_record(&name) {
            let status = resource.status();
            let bucket = entries.entry(name).or_default();
            let k = key_of(status);
            let count = match bucket.get(&k) {
                Some(Entry::Count(c)) => php_std::format::str_to_int(c.as_bytes()),
                Some(Entry::Resource(_)) => 1,
                None => 0,
            };
            bucket.insert(k, Entry::Count((count + 1).to_string()));
            return;
        }
        if matches!(resource.kind(), Kind::File(_) | Kind::Deployment(_) | Kind::SiteDeployment(_)) {
            resource.clear_data();
        }
        entries.entry(name).or_default().insert(key_of(key), Entry::Resource(resource.clone()));
    }

    /// Records each of `resources`.
    ///
    /// PHP: `addAll()`.
    pub fn add_all(&self, resources: &[ResourceRef]) {
        for r in resources {
            self.add(r);
        }
    }

    /// Records `resource` again after a change (for rows and documents, one
    /// more count of its status).
    ///
    /// PHP: `update()`.
    pub fn update(&self, resource: &ResourceRef) {
        let key = self.resolve_key(resource);
        if is_record(resource.name()) {
            self.add(resource);
            return;
        }
        self.add(resource);
        lock(&self.entries)
            .entry(resource.name().to_owned())
            .or_default()
            .insert(key_of(key), Entry::Resource(resource.clone()));
    }

    /// Records each of `resources` again.
    ///
    /// PHP: `updateAll()`.
    pub fn update_all(&self, resources: &[ResourceRef]) {
        for r in resources {
            self.update(r);
        }
    }

    /// Forgets `resource`; an error when it is not cached.
    ///
    /// PHP: `remove()`. A resource is found by loose comparison (`==`), so
    /// an equal copy counts as the cached one.
    pub fn remove(&self, resource: &ResourceRef) -> Result<()> {
        let key = key_of(self.resolve_key(resource));
        let name = resource.name().to_owned();
        let mut entries = lock(&self.entries);
        if is_record(&name) && !entries.get(&name).is_some_and(|b| b.contains_key(&key)) {
            return Err(Error::exception("Resource does not exist in cache", 0));
        }
        let Some(bucket) = entries.get_mut(&name) else {
            return Err(Error::TypeError(
                "in_array(): Argument #2 ($haystack) must be of type array, null given".into(),
            ));
        };
        let found = bucket.values().any(|e| match e {
            Entry::Resource(r) => r.loose_eq(resource),
            Entry::Count(_) => false,
        });
        if !found {
            return Err(Error::exception("Resource does not exist in cache", 0));
        }
        bucket.shift_remove(&key);
        Ok(())
    }

    /// The entries of resource type `name`.
    ///
    /// PHP: `get($resource)` (a type name, or a resource whose type to read).
    pub fn get(&self, name: &str) -> Entries {
        lock(&self.entries).get(name).cloned().unwrap_or_default()
    }

    /// The cached resources of type `name` (counts left out).
    pub fn resources(&self, name: &str) -> Vec<ResourceRef> {
        lock(&self.entries)
            .get(name)
            .map(|b| b.values().filter_map(|e| e.resource().cloned()).collect())
            .unwrap_or_default()
    }

    /// Every entry, by type.
    ///
    /// PHP: `getAll()`.
    pub fn all(&self) -> IndexMap<String, Entries> {
        lock(&self.entries).clone()
    }

    /// Forgets everything.
    ///
    /// PHP: `wipe()`.
    pub fn wipe(&self) {
        lock(&self.entries).clear();
    }
}
