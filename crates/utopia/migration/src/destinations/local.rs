//! A backup in a local directory.

use async_trait::async_trait;
use indexmap::IndexMap;
use php_std::json::{Flags, encode};
use php_std::{Array, Value};
use utopia_async::io::AsyncWriteExt;

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
/// Every file and archive is written inside its directory: a name that
/// leaves it (`..`, an absolute path, a backslash, a NUL byte) or passes
/// through a symlink is refused, and only that resource fails.
///
/// PHP: `Utopia\Migration\Destinations\Local`.
pub struct Local {
    target: Target,
    path: String,
    files_root: String,
    deployments_root: String,
    data: IndexMap<String, IndexMap<String, Vec<Value>>>,
}

impl std::fmt::Debug for Local {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Local").field("path", &self.path).finish_non_exhaustive()
    }
}

async fn mkdir(path: &str) {
    let mut b = utopia_async::fs::DirBuilder::new();
    b.recursive(true);
    #[cfg(unix)]
    b.mode(0o777);
    let _ = b.create(path).await;
}

async fn exists(path: &str) -> bool {
    utopia_async::fs::metadata(path).await.is_ok()
}

async fn is_link(path: &str) -> bool {
    utopia_async::fs::symlink_metadata(path).await.is_ok_and(|m| m.file_type().is_symlink())
}

async fn is_dir(path: &str) -> bool {
    utopia_async::fs::metadata(path).await.is_ok_and(|m| m.is_dir())
}

/// `realpath()`: `None` for a path that does not resolve.
async fn realpath(path: &str) -> Option<String> {
    let resolved = utopia_async::fs::canonicalize(path).await.ok()?;
    Some(resolved.to_string_lossy().into_owned())
}

/// `file_put_contents($path, $data, FILE_APPEND)` writing every byte.
async fn append(path: &str, data: &[u8]) -> bool {
    match utopia_async::fs::OpenOptions::new().append(true).create(true).open(path).await {
        Ok(mut f) => f.write_all(data).await.is_ok(),
        Err(_) => false,
    }
}

fn beneath(root: &str, path: &str) -> bool {
    path == root || path.strip_prefix(root).is_some_and(|rest| rest.starts_with('/'))
}

fn invalid() -> Error {
    Error::exception("Invalid export path", Exception::CODE_VALIDATION)
}

/// Resolves a relative name under a canonical export root, creating parent
/// directories as needed. Refuses names that leave the root or follow a
/// symlink.
///
/// PHP: `Local::confinedPath()`.
async fn confined(root: &str, name: &str) -> Result<String> {
    if name.is_empty() || name.starts_with('/') || name.contains('\\') || name.contains('\0') {
        return Err(invalid());
    }
    let segments: Vec<&str> = name.split('/').collect();
    if segments.iter().any(|s| s.is_empty() || *s == "." || *s == "..") {
        return Err(invalid());
    }
    let mut current = root.to_owned();
    let last = segments.len() - 1;
    for (index, segment) in segments.into_iter().enumerate() {
        let next = format!("{current}/{segment}");
        if is_link(&next).await {
            return Err(invalid());
        }
        if index < last {
            if !exists(&next).await {
                let mut b = utopia_async::fs::DirBuilder::new();
                #[cfg(unix)]
                b.mode(0o777);
                if b.create(&next).await.is_err() && !is_dir(&next).await {
                    return Err(Error::exception("Unable to create export directory", Exception::CODE_INTERNAL));
                }
            } else if !is_dir(&next).await {
                return Err(invalid());
            }
            match realpath(&next).await {
                Some(resolved) if beneath(root, &resolved) => current = resolved,
                _ => return Err(invalid()),
            }
            continue;
        }
        if exists(&next).await {
            return match realpath(&next).await {
                Some(resolved) if beneath(root, &resolved) => Ok(resolved),
                _ => Err(invalid()),
            };
        }
        return Ok(next);
    }
    Err(invalid())
}

impl Local {
    /// A backup in `path`, creating it, its `files` and its `deployments`
    /// when they do not exist. Fails when they do not resolve, or when
    /// `files` or `deployments` resolves outside `path` (a symlink).
    ///
    /// PHP: `new Local($path)`.
    pub async fn new(path: impl Into<String>) -> Result<Self> {
        let path = path.into();
        let files = format!("{path}/files");
        let deployments = format!("{path}/deployments");
        for dir in [&path, &files, &deployments] {
            if !exists(dir).await {
                mkdir(dir).await;
            }
        }
        // realpath() follows a symlinked files/ or deployments/ directory,
        // which would otherwise become the trusted root. Keep those roots
        // only when the resolved path is still inside the export directory.
        let unresolved = || Error::exception("Unable to resolve export directories", Exception::CODE_INTERNAL);
        let (Some(export_root), Some(files_root), Some(deployments_root)) =
            (realpath(&path).await, realpath(&files).await, realpath(&deployments).await)
        else {
            return Err(unresolved());
        };
        if !beneath(&export_root, &files_root) || !beneath(&export_root, &deployments_root) {
            return Err(unresolved());
        }
        Ok(Self { target: Target::new(), path, files_root, deployments_root, data: IndexMap::new() })
    }

    /// Writes one resource: a deployment's archive chunk, a file's chunk, or
    /// its JSON.
    async fn write(&mut self, resource: &ResourceRef) -> Result<()> {
        let unwritable = || {
            Error::migration(Exception::new(
                resource.name(),
                resource.group(),
                Some(resource.id()),
                "Unable to write export file",
                Exception::CODE_INTERNAL,
                None,
            ))
        };
        match resource.kind() {
            Kind::Deployment(d) => {
                let (start, data) = {
                    let c = d.chunk();
                    (c.start, c.data.clone())
                };
                if start == 0 {
                    self.record(resource);
                }
                let path = confined(&self.deployments_root, &format!("{}.tar.gz", resource.id())).await?;
                if !append(&path, &data).await {
                    return Err(unwritable());
                }
                d.chunk_mut().data = bytes::Bytes::new();
            }
            Kind::File(f) => {
                let path = confined(&self.files_root, &f.name).await?;
                let (start, data) = {
                    let c = f.chunk();
                    (c.start, c.data.clone())
                };
                if start == 0 && exists(&path).await {
                    let _ = utopia_async::fs::remove_file(&path).await;
                }
                if !append(&path, &data).await {
                    return Err(unwritable());
                }
                f.chunk_mut().data = bytes::Bytes::new();
            }
            _ => self.record(resource),
        }
        Ok(())
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
        let _ = utopia_async::fs::write(format!("{}/backup.json", self.path), json).await;
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
        let writable = match utopia_async::fs::metadata(&file).await {
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
            match self.write(resource).await {
                Ok(()) => resource.set_status(status::SUCCESS, ""),
                Err(e) => {
                    resource.set_status(status::ERROR, e.message());
                    let code = e.code();
                    self.target.add_error(Exception::new(
                        resource.name(),
                        resource.group(),
                        Some(resource.id()),
                        e.message(),
                        code,
                        Some(e),
                    ));
                }
            }
            self.target.cache()?.update(resource);
            self.sync().await?;
        }
        callback(resources)
    }
}
