use std::collections::BTreeMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::resource::{
    AnyResource, Resource, TYPE_COLLECTION, TYPE_COLUMN, TYPE_DEPLOYMENT, TYPE_DOCUMENT, TYPE_FILE,
    TYPE_ROW, TYPE_SITE_DEPLOYMENT, TYPE_TABLE,
};

static UNIQ: AtomicU64 = AtomicU64::new(1);

fn uniqid() -> String {
    let n = UNIQ.fetch_add(1, Ordering::Relaxed);
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_nanos())
        .unwrap_or(0);
    format!("{nanos:x}{n:x}")
}

/// Cache entry: a resource, or a row/document status counter stored as a string.
#[derive(Clone)]
pub enum CacheEntry {
    Resource(Box<AnyResource>),
    Counter(String),
}

/// [`Utopia\Migration\Cache`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Cache.php).
#[derive(Clone, Default)]
pub struct Cache {
    /// type → key → resource | status-counter string
    cache: BTreeMap<String, BTreeMap<String, CacheEntry>>,
}

impl Cache {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    pub fn resolve_resource_cache_key(&mut self, resource: &mut AnyResource) -> String {
        if resource.get_sequence().is_empty() {
            resource.set_sequence(uniqid());
        }
        let mut keys: Vec<String> = Vec::new();
        match resource {
            AnyResource::Table(t) => {
                keys.push(t.get_database().get_type().to_owned());
                keys.push(t.get_database().get_sequence().to_owned());
            }
            AnyResource::Collection(t) => {
                keys.push(t.get_database().get_type().to_owned());
                keys.push(t.get_database().get_sequence().to_owned());
            }
            AnyResource::Row(r) => {
                keys.push(r.get_table().get_database().get_sequence().to_owned());
                keys.push(r.get_table().get_sequence().to_owned());
            }
            AnyResource::Document(r) => {
                keys.push(r.get_table().get_database().get_sequence().to_owned());
                keys.push(r.get_table().get_sequence().to_owned());
            }
            AnyResource::Column(c) => {
                keys.push(c.get_table().get_database().get_sequence().to_owned());
                keys.push(c.get_table().get_sequence().to_owned());
            }
            AnyResource::Attribute(c) => {
                keys.push(c.get_table().get_database().get_sequence().to_owned());
                keys.push(c.get_table().get_sequence().to_owned());
            }
            AnyResource::Index(i) => {
                keys.push(i.get_table().get_database().get_sequence().to_owned());
                keys.push(i.get_table().get_sequence().to_owned());
            }
            AnyResource::File(f) => {
                keys.push(f.get_bucket().get_sequence().to_owned());
            }
            AnyResource::Deployment(d) => {
                keys.push(d.get_function().get_sequence().to_owned());
            }
            AnyResource::SiteDeployment(d) => {
                keys.push(d.get_site().get_sequence().to_owned());
            }
            _ => {}
        }
        keys.push(resource.get_sequence().to_owned());
        keys.join("_")
    }

    pub fn add(&mut self, resource: &mut AnyResource) {
        let name = resource.get_name().to_owned();
        if name == TYPE_ROW || name == TYPE_DOCUMENT {
            let status = resource.get_status().to_owned();
            let slot = self.cache.entry(name).or_default();
            let counter = match slot.get(&status) {
                Some(CacheEntry::Counter(s)) => s.parse::<i64>().unwrap_or(0),
                _ => 0,
            } + 1;
            slot.insert(status, CacheEntry::Counter(counter.to_string()));
            return;
        }
        if matches!(
            name.as_str(),
            TYPE_FILE | TYPE_DEPLOYMENT | TYPE_SITE_DEPLOYMENT
        ) {
            resource.clear_payload();
        }
        let key = self.resolve_resource_cache_key(resource);
        self.cache
            .entry(name)
            .or_default()
            .insert(key, CacheEntry::Resource(Box::new(resource.clone())));
    }

    pub fn add_all(&mut self, resources: &mut [AnyResource]) {
        for resource in resources.iter_mut() {
            self.add(resource);
        }
    }

    pub fn update(&mut self, resource: &mut AnyResource) {
        let name = resource.get_name().to_owned();
        if name == TYPE_ROW || name == TYPE_DOCUMENT {
            self.add(resource);
            return;
        }
        if !self.cache.contains_key(&name) {
            self.add(resource);
            return;
        }
        let key = self.resolve_resource_cache_key(resource);
        self.cache
            .entry(name)
            .or_default()
            .insert(key, CacheEntry::Resource(Box::new(resource.clone())));
    }

    pub fn update_all(&mut self, resources: &mut [AnyResource]) {
        for resource in resources.iter_mut() {
            self.update(resource);
        }
    }

    pub fn remove(&mut self, mut resource: AnyResource) -> Result<(), crate::exception::Exception> {
        let name = resource.get_name().to_owned();
        let key = self.resolve_resource_cache_key(&mut resource);
        if (name == TYPE_ROW || name == TYPE_DOCUMENT)
            && self.cache.get(&name).and_then(|m| m.get(&key)).is_none()
        {
            return Err(crate::exception::Exception::message_only(
                "Resource does not exist in cache",
            ));
        }
        let Some(slot) = self.cache.get_mut(&name) else {
            return Err(crate::exception::Exception::message_only(
                "Resource does not exist in cache",
            ));
        };
        if slot.remove(&key).is_none() {
            return Err(crate::exception::Exception::message_only(
                "Resource does not exist in cache",
            ));
        }
        Ok(())
    }

    /// PHP `get(string|Resource $resource)` - map of key → entry for that type.
    #[must_use]
    pub fn get(&self, resource_type: &str) -> BTreeMap<String, CacheEntry> {
        self.cache.get(resource_type).cloned().unwrap_or_default()
    }

    #[must_use]
    pub fn get_all(&self) -> &BTreeMap<String, BTreeMap<String, CacheEntry>> {
        &self.cache
    }

    pub fn wipe(&mut self) {
        self.cache.clear();
    }
}

impl CacheEntry {
    #[must_use]
    pub fn as_resource(&self) -> Option<&AnyResource> {
        match self {
            Self::Resource(r) => Some(r.as_ref()),
            Self::Counter(_) => None,
        }
    }

    #[must_use]
    pub fn as_counter(&self) -> Option<&str> {
        match self {
            Self::Counter(s) => Some(s),
            Self::Resource(_) => None,
        }
    }
}

/// Helpers used by tests that index cache maps like PHP.
impl Cache {
    #[must_use]
    pub fn get_type_count(&self, resource_type: &str) -> usize {
        self.cache.get(resource_type).map_or(0, BTreeMap::len)
    }
}

#[allow(dead_code)]
fn _legacy_type_aliases() -> [&'static str; 3] {
    [TYPE_TABLE, TYPE_COLLECTION, TYPE_COLUMN]
}
