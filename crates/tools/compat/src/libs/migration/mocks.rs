//! The PHP suite's test doubles (`tests/Migration/Unit/Adapters/MockSource.php`,
//! `MockDestination.php`, and the subclasses tests declare inline), ported.

use async_trait::async_trait;
use indexmap::IndexMap;
use utopia_migration::destination::{Callback, Destination};
use utopia_migration::error::{Error, Result};
use utopia_migration::resource::{
    DATABASE_TYPE_RESOURCE_MAP, ResourceRef, database_resources, is_supported, status, types,
};
use utopia_migration::source::{self, Emitter, ResourceIds, Sink, Source};
use utopia_migration::target::Target;
use utopia_migration::transfer::{ROOT_RESOURCES, Report, ResourceSelector, group};

type Store = IndexMap<String, IndexMap<String, IndexMap<String, ResourceRef>>>;

/// `MockSource` and its inline subclasses.
#[derive(Default)]
pub struct MockSource {
    target: Target,
    mock_resources: Store,
    resource_child_id: Option<String>,
    /// `supportsDatabaseStatus()` returns true (AppwriteDatabaseStatusTest).
    pub status: bool,
    /// Counts `run()` calls and records the selector (TransferTest).
    pub counting: bool,
    pub run_count: i64,
    pub selector: Option<ResourceSelector>,
}

const SUPPORTED: &[&str] = &[
    types::COLUMN,
    types::BUCKET,
    types::TABLE,
    types::DATABASE,
    types::ROW,
    types::FILE,
    types::FUNCTION,
    types::DEPLOYMENT,
    types::SITE,
    types::SITE_DEPLOYMENT,
    types::SITE_VARIABLE,
    types::HASH,
    types::INDEX,
    types::USER,
    types::ENVIRONMENT_VARIABLE,
    types::TEAM,
    types::MEMBERSHIP,
    types::OAUTH2_PROVIDER,
    types::PLATFORM,
    types::API_KEY,
    types::SMTP,
    types::PROJECT_VARIABLE,
    types::WEBHOOK,
    types::AUTH_METHODS,
    types::POLICIES,
    types::PROJECT_PROTOCOLS,
    types::PROJECT_LABELS,
    types::PROJECT_SERVICES,
    types::PROJECT_EMAIL_TEMPLATE,
    types::PROVIDER,
    types::TOPIC,
    types::SUBSCRIBER,
    types::MESSAGE,
    types::BACKUP_POLICY,
    types::RULE,
];

impl MockSource {
    pub fn new() -> Self {
        Self { target: Target::new(), ..Self::default() }
    }

    pub fn push(&mut self, resource: ResourceRef) {
        self.mock_resources
            .entry(resource.group().to_owned())
            .or_default()
            .entry(resource.name().to_owned())
            .or_default()
            .insert(resource.id(), resource);
    }

    pub fn resources(&self) -> &Store {
        &self.mock_resources
    }

    fn by_id(&self, group: &str, t: &str, id: &str) -> Option<ResourceRef> {
        self.mock_resources.get(group).and_then(|g| g.get(t)).and_then(|m| m.get(id)).cloned()
    }

    async fn handle(&mut self, group: &str, t: &str, out: &mut Emitter<'_>) -> Result<()> {
        let root_id = self.target.root_resource_id.clone();
        let root_type = self.target.root_resource_type.clone();
        let child = self.resource_child_id.clone().unwrap_or_default();
        let single = |r: Option<ResourceRef>| -> Result<Vec<ResourceRef>> {
            r.map(|r| vec![r]).ok_or_else(|| Error::Other {
                class: "Error".into(),
                message: "Call to a member function getName() on null".into(),
                code: 0,
            })
        };
        if ROOT_RESOURCES.contains(&t) && !root_id.is_empty() {
            let batch = single(self.by_id(group, t, &root_id))?;
            return out.emit(batch).await;
        }
        let entity = database_resources(&root_type).map(|d| d.entity);
        if entity == Some(t) && !root_id.is_empty() && !child.is_empty() {
            let batch = single(self.by_id(group, t, &child))?;
            return out.emit(batch).await;
        }
        let Some(list) = self.mock_resources.get(group).and_then(|g| g.get(t)) else {
            return Err(Error::TypeError(
                "array_values(): Argument #1 ($array) must be of type array, null given".into(),
            ));
        };
        let batch: Vec<ResourceRef> = list.values().cloned().collect();
        out.emit(batch).await
    }
}

#[async_trait]
impl Source for MockSource {
    fn name(&self) -> &str {
        "MockSource"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        let mut v = SUPPORTED.to_vec();
        v.extend([types::DOCUMENT, types::ATTRIBUTE, types::COLLECTION]);
        v
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    fn supports_database_status(&self) -> bool {
        self.status
    }

    async fn report(&mut self, _resources: &[String], _ids: &ResourceIds) -> Result<Report> {
        Ok(Report::new())
    }

    async fn run(&mut self, resources: &[String], sink: &mut dyn Sink, root_id: &str, root_type: &str) -> Result<()> {
        if self.counting {
            self.run_count += 1;
        }
        let previous = self.resource_child_id.clone();
        let (mut root_id, root_type) = (root_id.to_owned(), root_type.to_owned());
        if self.resource_child_id.is_none() {
            self.resource_child_id = Some(String::new());
            if !root_id.is_empty()
                && DATABASE_TYPE_RESOURCE_MAP.iter().any(|d| d.database == root_type)
                && let Some((a, b)) = root_id.clone().split_once(':')
            {
                root_id = a.to_owned();
                self.resource_child_id = Some(b.to_owned());
            }
        }
        let result = source::run(self, resources, sink, &root_id, &root_type).await;
        self.resource_child_id = previous;
        result
    }

    async fn run_with_resource_selector(
        &mut self,
        resources: &[String],
        sink: &mut dyn Sink,
        selector: &ResourceSelector,
    ) -> Result<()> {
        if self.counting {
            self.selector = Some(selector.clone());
        }
        let previous = self.resource_child_id.clone();
        self.resource_child_id =
            Some(if selector.parent_resource_id.is_empty() { String::new() } else { selector.resource_id.clone() });
        let result = self.run(resources, sink, selector.scope_id(), selector.scope_type()).await;
        self.resource_child_id = previous;
        result
    }

    async fn export_group(&mut self, g: &str, _batch: i64, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        let list: &[&str] = match g {
            group::AUTH => group::AUTH_RESOURCES,
            group::DATABASES => group::DATABASES_RESOURCES,
            group::STORAGE => group::STORAGE_RESOURCES,
            group::FUNCTIONS => group::FUNCTIONS_RESOURCES,
            group::MESSAGING => group::MESSAGING_RESOURCES,
            group::SITES => group::SITES_RESOURCES,
            group::INTEGRATIONS => group::INTEGRATIONS_RESOURCES,
            group::BACKUPS => group::BACKUPS_RESOURCES,
            group::PROJECTS => group::PROJECTS_RESOURCES,
            group::DOMAINS => group::DOMAINS_RESOURCES,
            _ => &[],
        };
        for t in list {
            let wanted =
                if g == group::DATABASES { is_supported(&[*t], resources) } else { resources.iter().any(|r| r == t) };
            if wanted {
                self.handle(g, t, out).await?;
            }
        }
        Ok(())
    }
}

/// `MockDestination` and its counting subclass.
#[derive(Default)]
pub struct MockDestination {
    target: Target,
    pub data: Store,
    pub counting: bool,
    pub run_count: i64,
}

impl MockDestination {
    pub fn new() -> Self {
        Self { target: Target::new(), ..Self::default() }
    }

    /// `getResourceTypeData()`.
    pub fn ids(&self, group: &str, t: &str) -> Result<Vec<String>> {
        self.data.get(group).and_then(|g| g.get(t)).map(|m| m.keys().cloned().collect()).ok_or_else(|| {
            Error::TypeError("array_keys(): Argument #1 ($array) must be of type array, null given".into())
        })
    }

    /// `getResourceById()`.
    pub fn get(&self, group: &str, t: &str, id: &str) -> Option<ResourceRef> {
        self.data.get(group).and_then(|g| g.get(t)).and_then(|m| m.get(id)).cloned()
    }
}

#[async_trait]
impl Destination for MockDestination {
    fn name(&self) -> &str {
        "MockDestination"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        SUPPORTED.iter().copied().filter(|t| *t != types::BACKUP_POLICY).collect()
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    async fn report(&mut self, _resources: &[String], _ids: &ResourceIds) -> Result<Report> {
        Ok(Report::new())
    }

    async fn run(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut Callback<'_>,
        root_id: &str,
        root_type: &str,
    ) -> Result<()> {
        if self.counting {
            self.run_count += 1;
        }
        utopia_migration::destination::run(self, source, resources, callback, root_id, root_type).await
    }

    async fn import(&mut self, resources: &[ResourceRef], callback: &mut Callback<'_>) -> Result<()> {
        for r in resources {
            self.data
                .entry(r.group().to_owned())
                .or_default()
                .entry(r.name().to_owned())
                .or_default()
                .insert(r.id(), r.clone());
            r.set_status(status::SUCCESS, "");
            self.target.cache()?.update(r);
        }
        callback(resources)
    }
}
