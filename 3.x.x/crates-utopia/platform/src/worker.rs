use utopia_servers::Hook;

use crate::action::{Action, ActionType};
use crate::error::Result;
use crate::hook_meta::{apply_action_metadata, resolve_sync_callback};
use crate::SyncCallback;

/// Registered worker lifecycle hook or job with metadata and callback.
#[derive(Clone)]
pub struct RegisteredWorkerHook {
    pub meta: Hook,
    pub callback: SyncCallback,
}

impl std::fmt::Debug for RegisteredWorkerHook {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RegisteredWorkerHook")
            .field("meta", &self.meta)
            .field("callback", &"SyncCallback")
            .finish()
    }
}

impl RegisteredWorkerHook {
    pub fn invoke(&self) {
        (self.callback)();
    }

    pub fn get_action(&self) -> &SyncCallback {
        &self.callback
    }
}

/// Portable in-memory worker registrar (mirrors PHP queue `Server` hook API without Swoole).
#[derive(Debug, Default, Clone)]
pub struct GenericWorker {
    init: Vec<RegisteredWorkerHook>,
    error: Vec<RegisteredWorkerHook>,
    shutdown: Vec<RegisteredWorkerHook>,
    worker_start: Vec<RegisteredWorkerHook>,
    worker_stop: Vec<RegisteredWorkerHook>,
    job: Option<RegisteredWorkerHook>,
}

impl GenericWorker {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn init_hooks(&self) -> &[RegisteredWorkerHook] {
        &self.init
    }

    pub fn error_hooks(&self) -> &[RegisteredWorkerHook] {
        &self.error
    }

    pub fn shutdown_hooks(&self) -> &[RegisteredWorkerHook] {
        &self.shutdown
    }

    pub fn worker_start_hooks(&self) -> &[RegisteredWorkerHook] {
        &self.worker_start
    }

    pub fn get_worker_start(&self) -> &[RegisteredWorkerHook] {
        &self.worker_start
    }

    pub fn worker_stop_hooks(&self) -> &[RegisteredWorkerHook] {
        &self.worker_stop
    }

    pub fn get_worker_stop(&self) -> &[RegisteredWorkerHook] {
        &self.worker_stop
    }

    pub fn job_hook(&self) -> Option<&RegisteredWorkerHook> {
        self.job.as_ref()
    }

    pub fn init(&mut self) -> WorkerHookRegistrar<'_> {
        WorkerHookRegistrar {
            worker: self,
            kind: WorkerHookKind::Init,
            meta: None,
            callback: None,
        }
    }

    pub fn error(&mut self) -> WorkerHookRegistrar<'_> {
        WorkerHookRegistrar {
            worker: self,
            kind: WorkerHookKind::Error,
            meta: None,
            callback: None,
        }
    }

    pub fn shutdown(&mut self) -> WorkerHookRegistrar<'_> {
        WorkerHookRegistrar {
            worker: self,
            kind: WorkerHookKind::Shutdown,
            meta: None,
            callback: None,
        }
    }

    pub fn worker_start(&mut self) -> WorkerHookRegistrar<'_> {
        WorkerHookRegistrar {
            worker: self,
            kind: WorkerHookKind::WorkerStart,
            meta: None,
            callback: None,
        }
    }

    pub fn worker_stop(&mut self) -> WorkerHookRegistrar<'_> {
        WorkerHookRegistrar {
            worker: self,
            kind: WorkerHookKind::WorkerStop,
            meta: None,
            callback: None,
        }
    }

    pub fn job(&mut self) -> WorkerHookRegistrar<'_> {
        WorkerHookRegistrar {
            worker: self,
            kind: WorkerHookKind::Job,
            meta: None,
            callback: None,
        }
    }
}

/// Worker hook kinds mirroring PHP queue `Server` hook builders.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum WorkerHookKind {
    Init,
    Error,
    Shutdown,
    WorkerStart,
    WorkerStop,
    Job,
}

/// Fluent registrar for a single worker hook.
pub struct WorkerHookRegistrar<'a> {
    worker: &'a mut GenericWorker,
    kind: WorkerHookKind,
    meta: Option<Hook>,
    callback: Option<SyncCallback>,
}

impl std::fmt::Debug for WorkerHookRegistrar<'_> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("WorkerHookRegistrar")
            .field("kind", &self.kind)
            .finish_non_exhaustive()
    }
}

impl WorkerHookRegistrar<'_> {
    pub fn groups<I, S>(mut self, groups: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        let hook = self.meta.get_or_insert_with(Hook::new);
        hook.groups(groups);
        self
    }

    pub fn desc(mut self, description: impl Into<String>) -> Self {
        let hook = self.meta.get_or_insert_with(Hook::new);
        hook.desc(description);
        self
    }

    pub fn action(mut self, callback: SyncCallback) -> Self {
        self.callback = Some(callback);
        self
    }

    pub fn finish(self) -> Result<()> {
        let meta = self.meta.unwrap_or_default();
        let callback = self.callback.unwrap_or_else(|| std::sync::Arc::new(|| {}));
        let entry = RegisteredWorkerHook { meta, callback };
        match self.kind {
            WorkerHookKind::Init => self.worker.init.push(entry),
            WorkerHookKind::Error => self.worker.error.push(entry),
            WorkerHookKind::Shutdown => self.worker.shutdown.push(entry),
            WorkerHookKind::WorkerStart => self.worker.worker_start.push(entry),
            WorkerHookKind::WorkerStop => self.worker.worker_stop.push(entry),
            WorkerHookKind::Job => self.worker.job = Some(entry),
        }
        Ok(())
    }
}

/// Adapter trait for registering platform worker actions onto a worker runtime.
pub trait WorkerRegistrar {
    fn register_action(
        &mut self,
        action_key: &str,
        action: &Action,
        worker_name: Option<&str>,
    ) -> Result<()>;
}

impl WorkerRegistrar for GenericWorker {
    fn register_action(
        &mut self,
        action_key: &str,
        action: &Action,
        worker_name: Option<&str>,
    ) -> Result<()> {
        if action.action_type() == ActionType::Default {
            if let Some(name) = worker_name {
                if !action_key.eq_ignore_ascii_case(name) {
                    return Ok(());
                }
            }
        }

        let callback = resolve_sync_callback(action)?;
        let mut meta = Hook::new();
        apply_action_metadata(&mut meta, action)?;

        let entry = RegisteredWorkerHook { meta, callback };

        match action.action_type() {
            ActionType::Init => self.init.push(entry),
            ActionType::Error => self.error.push(entry),
            ActionType::Shutdown => self.shutdown.push(entry),
            ActionType::WorkerStart => self.worker_start.push(entry),
            ActionType::WorkerStop => self.worker_stop.push(entry),
            ActionType::Default | ActionType::Options => self.job = Some(entry),
        }
        Ok(())
    }
}
