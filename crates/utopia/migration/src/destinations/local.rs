//! A backup in a local directory.

use async_trait::async_trait;
use indexmap::IndexMap;
use php_std::json::{Flags, encode};
use php_std::{Array, Value};
use tokio::io::AsyncWriteExt;

use crate::destination::{Callback, Destination};
use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::{Kind, ResourceRef, status, types};
use crate::source::ResourceIds;
use crate::target::Target;
use crate::transfer::Report;

/// A backup in a local directory: every resource's JSON in `backup.json`
/// (by group and type, rewritten after each resource), files under
/// `files/`, deployment archives in `deployments/`.
///
/// PHP: `Utopia\Migration\Destinations\Local`. Deployment archives go to
/// `<path>deployments/<id>.tar.gz` (PHP leaves out the `/`), next to the
/// directory rather than in it.
pub struct Local {
    target: Target,
    path: String,
    data: IndexMap<String, IndexMap<String, Vec<Value>>>,
}

impl std::fmt::Debug for Local {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Local").field("path", &self.path).finish_non_exhaustive()
    }
}

async fn mkdir(path: &str) {
    let mut b = tokio::fs::DirBuilder::new();
    b.recursive(true);
    #[cfg(unix)]
    b.mode(0o777);
    let _ = b.create(path).await;
}

async fn exists(path: &str) -> bool {
    tokio::fs::metadata(path).await.is_ok()
}

async fn append(path: &str, data: &[u8]) {
    if let Ok(mut f) = tokio::fs::OpenOptions::new().append(true).create(true).open(path).await {
        let _ = f.write_all(data).await;
    }
}

impl Local {
    /// A backup in `path`, creating it (with `files` and `deployments`)
    /// when it does not exist.
    ///
    /// PHP: `new Local($path)`.
    pub async fn new(path: impl Into<String>) -> Self {
        let path = path.into();
        if !exists(&path).await {
            mkdir(&path).await;
            mkdir(&format!("{path}/files")).await;
            mkdir(&format!("{path}/deployments")).await;
        }
        Self { target: Target::new(), path, data: IndexMap::new() }
    }

    async fn sync(&self) -> Result<()> {
        let mut root = Array::new();
        for (g, types) in &self.data {
            let mut by_type = Array::new();
            for (t, list) in types {
                by_type.set(t.as_str().into(), Value::Array(list.iter().cloned().collect()));
            }
            root.set(g.as_str().into(), Value::Array(by_type));
        }
        let json = encode(&Value::Array(root), Flags::PRETTY_PRINT, 512).map_err(|_| {
            Error::exception(
                "Unable to encode data to JSON, Are you accidentally encoding binary data?",
                Exception::CODE_VALIDATION,
            )
        })?;
        let _ = tokio::fs::write(format!("{}/backup.json", self.path), json).await;
        Ok(())
    }

    fn record(&mut self, resource: &ResourceRef) {
        let json = encode(&resource.json(), Flags::NONE, 512).unwrap_or_default();
        self.data
            .entry(resource.group().to_owned())
            .or_default()
            .entry(resource.name().to_owned())
            .or_default()
            .push(Value::from(json.as_str()));
    }
}

#[async_trait]
impl Destination for Local {
    fn name(&self) -> &str {
        "Local"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        vec![
            types::USER,
            types::TEAM,
            types::MEMBERSHIP,
            types::HASH,
            types::DATABASE,
            types::TABLE,
            types::COLUMN,
            types::INDEX,
            types::ROW,
            types::DOCUMENT,
            types::ATTRIBUTE,
            types::COLLECTION,
            types::BUCKET,
            types::FILE,
            types::FUNCTION,
            types::DEPLOYMENT,
            types::ENVIRONMENT_VARIABLE,
            types::PROVIDER,
            types::TOPIC,
            types::SUBSCRIBER,
            types::MESSAGE,
        ]
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    /// An error unless `backup.json` is writable (so always before the
    /// first import, which creates it).
    async fn report(&mut self, _resources: &[String], _resource_ids: &ResourceIds) -> Result<Report> {
        let file = format!("{}/backup.json", self.path);
        let writable = match tokio::fs::metadata(&file).await {
            Ok(m) => !m.permissions().readonly(),
            Err(_) => false,
        };
        if !writable {
            return Err(Error::exception(format!("Unable to write to file: {}", self.path), Exception::CODE_INTERNAL));
        }
        Ok(Report::new())
    }

    async fn import(&mut self, resources: &[ResourceRef], callback: &mut Callback<'_>) -> Result<()> {
        for resource in resources {
            match resource.kind() {
                Kind::Deployment(d) => {
                    let (start, data) = {
                        let c = d.chunk();
                        (c.start, c.data.clone())
                    };
                    if start == 0 {
                        self.record(resource);
                    }
                    append(&format!("{}deployments/{}.tar.gz", self.path, resource.id()), &data).await;
                    d.chunk_mut().data = bytes::Bytes::new();
                }
                Kind::File(f) => {
                    if f.name.contains('/') {
                        let mut folder = format!("{}/files", self.path);
                        for part in f.name.split('/') {
                            folder.push('/');
                            folder.push_str(part);
                            if !exists(&folder).await && !part.contains('.') {
                                mkdir(&folder).await;
                            }
                        }
                    }
                    let target = format!("{}/files/{}", self.path, f.name);
                    let (start, data) = {
                        let c = f.chunk();
                        (c.start, c.data.clone())
                    };
                    if start == 0 && exists(&target).await {
                        let _ = tokio::fs::remove_file(&target).await;
                    }
                    append(&target, &data).await;
                    f.chunk_mut().data = bytes::Bytes::new();
                }
                _ => self.record(resource),
            }
            resource.set_status(status::SUCCESS, "");
            self.target.cache()?.update(resource);
            self.sync().await?;
        }
        callback(resources)
    }
}
