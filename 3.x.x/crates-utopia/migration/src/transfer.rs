use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};

use crate::cache::{Cache, CacheEntry};
use crate::destination::Destination;
use crate::exception::Exception;
use crate::resource::{
    AnyResource, Resource, STATUS_ERROR, STATUS_PENDING, STATUS_PROCESSING, STATUS_SKIPPED,
    STATUS_SUCCESS, STATUS_WARNING, TYPE_API_KEY, TYPE_ATTRIBUTE, TYPE_AUTH_METHODS,
    TYPE_BACKUP_POLICY, TYPE_BUCKET, TYPE_COLLECTION, TYPE_COLUMN, TYPE_DATABASE,
    TYPE_DATABASE_DOCUMENTSDB, TYPE_DATABASE_VECTORSDB, TYPE_DEPLOYMENT, TYPE_DOCUMENT,
    TYPE_ENVIRONMENT_VARIABLE, TYPE_FILE, TYPE_FUNCTION, TYPE_HASH, TYPE_INDEX, TYPE_MEMBERSHIP,
    TYPE_MESSAGE, TYPE_OAUTH2_PROVIDER, TYPE_PLATFORM, TYPE_POLICIES, TYPE_PROJECT_EMAIL_TEMPLATE,
    TYPE_PROJECT_LABELS, TYPE_PROJECT_PROTOCOLS, TYPE_PROJECT_SERVICES, TYPE_PROJECT_VARIABLE,
    TYPE_PROVIDER, TYPE_ROW, TYPE_RULE, TYPE_SITE, TYPE_SITE_DEPLOYMENT, TYPE_SITE_VARIABLE,
    TYPE_SMTP, TYPE_SUBSCRIBER, TYPE_TABLE, TYPE_TEAM, TYPE_TOPIC, TYPE_USER, TYPE_WEBHOOK,
};
use crate::resource_selector::ResourceSelector;
use crate::source::Source;

pub const GROUP_GENERAL: &str = "general";
pub const GROUP_AUTH: &str = "auth";
pub const GROUP_STORAGE: &str = "storage";
pub const GROUP_FUNCTIONS: &str = "functions";
pub const GROUP_SITES: &str = "sites";
pub const GROUP_DATABASES: &str = "databases";
pub const GROUP_DATABASES_TABLES_DB: &str = "tablesdb";
pub const GROUP_DATABASES_DOCUMENTS_DB: &str = "documentsdb";
pub const GROUP_DATABASES_VECTOR_DB: &str = "vectorsdb";
pub const GROUP_INTEGRATIONS: &str = "integrations";
pub const GROUP_MESSAGING: &str = "messaging";
pub const GROUP_BACKUPS: &str = "backups";
pub const GROUP_PROJECTS: &str = "projects";
pub const GROUP_DOMAINS: &str = "domains";

pub const ROOT_RESOURCES: &[&str] = &[
    TYPE_BUCKET,
    TYPE_DATABASE,
    TYPE_DATABASE_DOCUMENTSDB,
    TYPE_DATABASE_VECTORSDB,
    TYPE_FUNCTION,
    TYPE_SITE,
    TYPE_USER,
    TYPE_TEAM,
    TYPE_PLATFORM,
    TYPE_API_KEY,
    TYPE_PROVIDER,
    TYPE_TOPIC,
    TYPE_MESSAGE,
];

pub const GROUP_AUTH_RESOURCES: &[&str] = &[
    TYPE_USER,
    TYPE_TEAM,
    TYPE_MEMBERSHIP,
    TYPE_HASH,
    TYPE_AUTH_METHODS,
    TYPE_POLICIES,
    TYPE_OAUTH2_PROVIDER,
];
pub const GROUP_STORAGE_RESOURCES: &[&str] = &[TYPE_FILE, TYPE_BUCKET];
pub const GROUP_FUNCTIONS_RESOURCES: &[&str] =
    &[TYPE_FUNCTION, TYPE_ENVIRONMENT_VARIABLE, TYPE_DEPLOYMENT];
pub const GROUP_SITES_RESOURCES: &[&str] = &[TYPE_SITE, TYPE_SITE_VARIABLE, TYPE_SITE_DEPLOYMENT];
pub const GROUP_TABLESDB_RESOURCES: &[&str] =
    &[TYPE_DATABASE, TYPE_TABLE, TYPE_INDEX, TYPE_COLUMN, TYPE_ROW];
pub const GROUP_INTEGRATIONS_RESOURCES: &[&str] =
    &[TYPE_PLATFORM, TYPE_API_KEY, TYPE_WEBHOOK, TYPE_SMTP];
pub const GROUP_DOCUMENTSDB_RESOURCES: &[&str] = &[
    TYPE_DATABASE_DOCUMENTSDB,
    TYPE_COLLECTION,
    TYPE_INDEX,
    TYPE_DOCUMENT,
];
pub const GROUP_VECTORSDB_RESOURCES: &[&str] = &[
    TYPE_DATABASE_VECTORSDB,
    TYPE_COLLECTION,
    TYPE_ATTRIBUTE,
    TYPE_INDEX,
    TYPE_DOCUMENT,
];
pub const GROUP_DATABASES_RESOURCES: &[&str] = &[
    TYPE_DATABASE,
    TYPE_DATABASE_DOCUMENTSDB,
    TYPE_DATABASE_VECTORSDB,
    TYPE_TABLE,
    TYPE_INDEX,
    TYPE_COLUMN,
    TYPE_ROW,
    TYPE_DOCUMENT,
    TYPE_COLLECTION,
    TYPE_ATTRIBUTE,
];
pub const GROUP_PROJECTS_RESOURCES: &[&str] = &[
    TYPE_PROJECT_VARIABLE,
    TYPE_PROJECT_PROTOCOLS,
    TYPE_PROJECT_LABELS,
    TYPE_PROJECT_SERVICES,
    TYPE_PROJECT_EMAIL_TEMPLATE,
];
pub const GROUP_BACKUPS_RESOURCES: &[&str] = &[TYPE_BACKUP_POLICY];
pub const GROUP_DOMAINS_RESOURCES: &[&str] = &[TYPE_RULE];
pub const GROUP_MESSAGING_RESOURCES: &[&str] =
    &[TYPE_PROVIDER, TYPE_TOPIC, TYPE_SUBSCRIBER, TYPE_MESSAGE];

/// [`Utopia\Migration\Transfer`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Transfer.php).
pub struct Transfer<S: Source, D: Destination> {
    source: S,
    destination: D,
    current_resource: String,
    cache: Arc<Mutex<Cache>>,
    resources: Vec<String>,
    resource_selector: Option<ResourceSelector>,
}

impl<S: Source, D: Destination> Transfer<S, D> {
    pub const GROUP_GENERAL: &'static str = "general";
    pub const GROUP_AUTH: &'static str = "auth";
    pub const GROUP_STORAGE: &'static str = "storage";
    pub const GROUP_FUNCTIONS: &'static str = "functions";
    pub const GROUP_SITES: &'static str = "sites";
    pub const GROUP_DATABASES: &'static str = "databases";
    pub const GROUP_DATABASES_TABLES_DB: &'static str = "tablesdb";
    pub const GROUP_DATABASES_DOCUMENTS_DB: &'static str = "documentsdb";
    pub const GROUP_DATABASES_VECTOR_DB: &'static str = "vectorsdb";
    pub const GROUP_INTEGRATIONS: &'static str = "integrations";
    pub const GROUP_MESSAGING: &'static str = "messaging";
    pub const GROUP_BACKUPS: &'static str = "backups";
    pub const GROUP_PROJECTS: &'static str = "projects";
    pub const GROUP_DOMAINS: &'static str = "domains";

    pub const GROUP_AUTH_RESOURCES: &'static [&'static str] = &[
        TYPE_USER,
        TYPE_TEAM,
        TYPE_MEMBERSHIP,
        TYPE_HASH,
        TYPE_AUTH_METHODS,
        TYPE_POLICIES,
        TYPE_OAUTH2_PROVIDER,
    ];
    pub const GROUP_STORAGE_RESOURCES: &'static [&'static str] = &[TYPE_FILE, TYPE_BUCKET];
    pub const GROUP_FUNCTIONS_RESOURCES: &'static [&'static str] =
        &[TYPE_FUNCTION, TYPE_ENVIRONMENT_VARIABLE, TYPE_DEPLOYMENT];
    pub const GROUP_SITES_RESOURCES: &'static [&'static str] =
        &[TYPE_SITE, TYPE_SITE_VARIABLE, TYPE_SITE_DEPLOYMENT];
    pub const GROUP_TABLESDB_RESOURCES: &'static [&'static str] =
        &[TYPE_DATABASE, TYPE_TABLE, TYPE_INDEX, TYPE_COLUMN, TYPE_ROW];
    pub const GROUP_INTEGRATIONS_RESOURCES: &'static [&'static str] =
        &[TYPE_PLATFORM, TYPE_API_KEY, TYPE_WEBHOOK, TYPE_SMTP];
    pub const GROUP_DOCUMENTSDB_RESOURCES: &'static [&'static str] = &[
        TYPE_DATABASE_DOCUMENTSDB,
        TYPE_COLLECTION,
        TYPE_INDEX,
        TYPE_DOCUMENT,
    ];
    pub const GROUP_VECTORSDB_RESOURCES: &'static [&'static str] = &[
        TYPE_DATABASE_VECTORSDB,
        TYPE_COLLECTION,
        TYPE_ATTRIBUTE,
        TYPE_INDEX,
        TYPE_DOCUMENT,
    ];
    pub const GROUP_DATABASES_RESOURCES: &'static [&'static str] = &[
        TYPE_DATABASE,
        TYPE_DATABASE_DOCUMENTSDB,
        TYPE_DATABASE_VECTORSDB,
        TYPE_TABLE,
        TYPE_INDEX,
        TYPE_COLUMN,
        TYPE_ROW,
        TYPE_DOCUMENT,
        TYPE_COLLECTION,
        TYPE_ATTRIBUTE,
    ];
    pub const GROUP_PROJECTS_RESOURCES: &'static [&'static str] = &[
        TYPE_PROJECT_VARIABLE,
        TYPE_PROJECT_PROTOCOLS,
        TYPE_PROJECT_LABELS,
        TYPE_PROJECT_SERVICES,
        TYPE_PROJECT_EMAIL_TEMPLATE,
    ];
    pub const GROUP_BACKUPS_RESOURCES: &'static [&'static str] = &[TYPE_BACKUP_POLICY];
    pub const GROUP_DOMAINS_RESOURCES: &'static [&'static str] = &[TYPE_RULE];
    pub const GROUP_MESSAGING_RESOURCES: &'static [&'static str] =
        &[TYPE_PROVIDER, TYPE_TOPIC, TYPE_SUBSCRIBER, TYPE_MESSAGE];

    pub const ALL_PUBLIC_RESOURCES: &'static [&'static str] = &[
        TYPE_USER,
        TYPE_TEAM,
        TYPE_MEMBERSHIP,
        TYPE_AUTH_METHODS,
        TYPE_POLICIES,
        TYPE_OAUTH2_PROVIDER,
        TYPE_FILE,
        TYPE_BUCKET,
        TYPE_FUNCTION,
        TYPE_ENVIRONMENT_VARIABLE,
        TYPE_DEPLOYMENT,
        TYPE_SITE,
        TYPE_SITE_VARIABLE,
        TYPE_SITE_DEPLOYMENT,
        TYPE_DATABASE,
        TYPE_TABLE,
        TYPE_INDEX,
        TYPE_COLUMN,
        TYPE_ROW,
        TYPE_PROVIDER,
        TYPE_TOPIC,
        TYPE_SUBSCRIBER,
        TYPE_MESSAGE,
        TYPE_BACKUP_POLICY,
        TYPE_PLATFORM,
        TYPE_API_KEY,
        TYPE_WEBHOOK,
        TYPE_SMTP,
        TYPE_PROJECT_VARIABLE,
        TYPE_PROJECT_PROTOCOLS,
        TYPE_PROJECT_LABELS,
        TYPE_PROJECT_SERVICES,
        TYPE_PROJECT_EMAIL_TEMPLATE,
        TYPE_RULE,
        TYPE_DOCUMENT,
        TYPE_ATTRIBUTE,
        TYPE_COLLECTION,
    ];

    pub const ROOT_RESOURCES: &'static [&'static str] = &[
        TYPE_BUCKET,
        TYPE_DATABASE,
        TYPE_DATABASE_DOCUMENTSDB,
        TYPE_DATABASE_VECTORSDB,
        TYPE_FUNCTION,
        TYPE_SITE,
        TYPE_USER,
        TYPE_TEAM,
        TYPE_PLATFORM,
        TYPE_API_KEY,
        TYPE_PROVIDER,
        TYPE_TOPIC,
        TYPE_MESSAGE,
    ];

    pub const STORAGE_MAX_CHUNK_SIZE: usize = 1024 * 1024 * 5;

    pub fn new(mut source: S, mut destination: D) -> Self {
        let cache = Arc::new(Mutex::new(Cache::new()));
        source.register_cache(Arc::clone(&cache));
        destination.register_cache(Arc::clone(&cache));
        Self {
            source,
            destination,
            current_resource: String::new(),
            cache,
            resources: Vec::new(),
            resource_selector: None,
        }
    }

    pub fn source(&self) -> &S {
        &self.source
    }
    pub fn source_mut(&mut self) -> &mut S {
        &mut self.source
    }
    pub fn destination(&self) -> &D {
        &self.destination
    }
    pub fn destination_mut(&mut self) -> &mut D {
        &mut self.destination
    }

    pub fn get_cache(&self) -> std::sync::MutexGuard<'_, Cache> {
        self.cache.lock().expect("cache lock")
    }

    #[must_use]
    pub fn get_current_resource(&self) -> &str {
        &self.current_resource
    }

    pub fn get_status_counters(&self) -> BTreeMap<String, BTreeMap<String, i64>> {
        let mut status: BTreeMap<String, BTreeMap<String, i64>> = BTreeMap::new();
        for resource in &self.resources {
            let mut row = BTreeMap::new();
            for key in [
                STATUS_PENDING,
                STATUS_SUCCESS,
                STATUS_ERROR,
                STATUS_SKIPPED,
                STATUS_PROCESSING,
                STATUS_WARNING,
            ] {
                row.insert(key.to_owned(), 0);
            }
            status.insert(resource.clone(), row);
        }

        {
            let source = &self.source;
            for (resource, data) in source.previous_report() {
                if resource != "size" && resource != "version" {
                    if let Some(slot) = status.get_mut(resource) {
                        slot.insert(STATUS_PENDING.to_owned(), *data);
                    }
                }
            }
        }

        let cache = self.cache.lock().expect("cache lock");
        for (resource_type, resources) in cache.get_all() {
            for (k, entry) in resources {
                if (resource_type == TYPE_ROW || resource_type == TYPE_DOCUMENT)
                    && matches!(entry, CacheEntry::Counter(_))
                {
                    if !status.contains_key(resource_type) {
                        continue;
                    }
                    let count = entry
                        .as_counter()
                        .and_then(|s| s.parse::<i64>().ok())
                        .unwrap_or(0);
                    if let Some(slot) = status.get_mut(resource_type) {
                        slot.insert(k.clone(), count);
                        let pending = *slot.get(STATUS_PENDING).unwrap_or(&0);
                        if pending > 0 {
                            slot.insert(STATUS_PENDING.to_owned(), pending - pending.min(count));
                        }
                    }
                    continue;
                }
                if let Some(resource) = entry.as_resource() {
                    if let Some(slot) = status.get_mut(resource.get_name()) {
                        *slot.entry(resource.get_status().to_owned()).or_insert(0) += 1;
                        let pending = *slot.get(STATUS_PENDING).unwrap_or(&0);
                        if pending > 0 {
                            slot.insert(STATUS_PENDING.to_owned(), pending - 1);
                        }
                    }
                }
            }
        }
        drop(cache);

        for error in self.destination.get_errors() {
            if let Some(slot) = status.get_mut(error.get_resource_group()) {
                *slot.entry(STATUS_ERROR.to_owned()).or_insert(0) += 1;
            }
        }
        for error in self.source.get_errors() {
            if let Some(slot) = status.get_mut(error.get_resource_group()) {
                *slot.entry(STATUS_ERROR.to_owned()).or_insert(0) += 1;
            }
        }

        status.retain(|_, data| data.values().any(|c| *c > 0));
        status
    }

    pub fn run(
        &mut self,
        resources: &[&str],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        root_resource_id: Option<&str>,
        root_resource_type: Option<&str>,
    ) -> Result<(), Exception> {
        let computed: Vec<String> = resources.iter().map(|r| r.to_lowercase()).collect();
        let root_resource_id = root_resource_id.unwrap_or("").to_owned();
        let root_resource_type = root_resource_type.unwrap_or("").to_owned();

        if !root_resource_id.is_empty() {
            if root_resource_type.is_empty() {
                return Err(Exception::message_only(
                    "Resource type must be set when resource ID is set.",
                ));
            }
            if !Self::ROOT_RESOURCES.contains(&root_resource_type.as_str()) {
                return Err(Exception::message_only(format!(
                    "Got {root_resource_type} Resource type must be one of {}",
                    Self::ROOT_RESOURCES.join(", ")
                )));
            }
            let root_resources: Vec<_> = computed
                .iter()
                .filter(|r| Self::ROOT_RESOURCES.contains(&r.as_str()))
                .cloned()
                .collect();
            if root_resources.len() > 1 {
                return Err(Exception::message_only(
                    "Multiple root resources found. Only one root resource can be transferred at a time if using $rootResourceId.",
                ));
            }
            if root_resources.is_empty() {
                return Err(Exception::message_only("No root resources found."));
            }
        }

        self.resources.clone_from(&computed);

        if let Some(selector) = self.resource_selector.clone() {
            self.destination.run_with_resource_selector(
                &mut self.source,
                &computed,
                callback,
                &selector.resource_id,
                &selector.resource_internal_id,
                &selector.resource_type,
                &selector.parent_resource_id,
                &selector.parent_resource_internal_id,
                &selector.parent_resource_type,
            );
            return Ok(());
        }

        self.destination.run(
            &mut self.source,
            &computed,
            callback,
            &root_resource_id,
            &root_resource_type,
        );
        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    pub fn run_with_resource_selector(
        &mut self,
        resources: &[&str],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        resource_id: impl Into<String>,
        resource_internal_id: impl Into<String>,
        resource_type: impl Into<String>,
        parent_resource_id: impl Into<String>,
        parent_resource_internal_id: impl Into<String>,
        parent_resource_type: impl Into<String>,
    ) -> Result<(), Exception> {
        let previous = self.resource_selector.clone();
        self.resource_selector = Some(ResourceSelector::new(
            resource_id,
            resource_internal_id,
            resource_type,
            parent_resource_id,
            parent_resource_internal_id,
            parent_resource_type,
        ));
        let selector = self.resource_selector.clone().expect("just set");
        let scope_id = selector.get_scope_id().to_owned();
        let scope_type = selector.get_scope_type().to_owned();
        let result = self.run(resources, callback, Some(&scope_id), Some(&scope_type));
        self.resource_selector = previous;
        result
    }

    pub fn get_report(&self, status_level: &str) -> Vec<BTreeMap<String, String>> {
        let mut report = Vec::new();
        let cache = self.cache.lock().expect("cache lock");
        for (type_, resources) in cache.get_all() {
            for (id, entry) in resources {
                if (type_ == TYPE_ROW || type_ == TYPE_DOCUMENT)
                    && matches!(entry, CacheEntry::Counter(_))
                {
                    let status = entry.as_counter().unwrap_or("").to_owned();
                    if !status_level.is_empty() && status != status_level {
                        continue;
                    }
                    let mut row = BTreeMap::new();
                    row.insert("resource".into(), type_.clone());
                    row.insert("id".into(), id.clone());
                    row.insert("status".into(), status);
                    row.insert("message".into(), String::new());
                    report.push(row);
                    continue;
                }
                if let Some(resource) = entry.as_resource() {
                    if !status_level.is_empty() && resource.get_status() != status_level {
                        continue;
                    }
                    let mut row = BTreeMap::new();
                    row.insert("resource".into(), type_.clone());
                    row.insert("id".into(), resource.get_id().to_owned());
                    row.insert("status".into(), resource.get_status().to_owned());
                    row.insert("message".into(), resource.get_message().to_owned());
                    report.push(row);
                }
            }
        }
        report
    }

    pub fn extract_services(services: &[&str]) -> Result<Vec<&'static str>, Exception> {
        let mut resources = Vec::new();
        for service in services {
            let extra: &[&str] = match *service {
                Self::GROUP_FUNCTIONS => Self::GROUP_FUNCTIONS_RESOURCES,
                Self::GROUP_SITES => Self::GROUP_SITES_RESOURCES,
                Self::GROUP_STORAGE => Self::GROUP_STORAGE_RESOURCES,
                Self::GROUP_GENERAL => &[],
                Self::GROUP_AUTH => Self::GROUP_AUTH_RESOURCES,
                Self::GROUP_DATABASES => Self::GROUP_DATABASES_RESOURCES,
                Self::GROUP_INTEGRATIONS => Self::GROUP_INTEGRATIONS_RESOURCES,
                Self::GROUP_DATABASES_TABLES_DB => Self::GROUP_TABLESDB_RESOURCES,
                Self::GROUP_DATABASES_DOCUMENTS_DB => Self::GROUP_DOCUMENTSDB_RESOURCES,
                Self::GROUP_DATABASES_VECTOR_DB => Self::GROUP_VECTORSDB_RESOURCES,
                Self::GROUP_MESSAGING => Self::GROUP_MESSAGING_RESOURCES,
                Self::GROUP_BACKUPS => Self::GROUP_BACKUPS_RESOURCES,
                Self::GROUP_PROJECTS => Self::GROUP_PROJECTS_RESOURCES,
                Self::GROUP_DOMAINS => Self::GROUP_DOMAINS_RESOURCES,
                _ => return Err(Exception::message_only("No service group found")),
            };
            resources.extend_from_slice(extra);
        }
        Ok(resources)
    }
}
