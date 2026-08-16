use std::collections::HashMap;
use std::sync::Arc;
use std::time::Instant;

use parking_lot::Mutex;
use serde_json::Value;
use utopia_di::{Container, Resource};
use utopia_servers::{ArgumentKind, Hook};
use utopia_telemetry::{
    Adapter as TelemetryAdapter, Attributes, Histogram, NoneAdapter, ObservableGauge,
};
use utopia_validators::Validator;

use crate::action::ActionArgs;
use crate::adapter::{Adapter, ErrorCallback, MessageCallback, SuccessCallback};
use crate::broker::redis::unix_now_f64;
use crate::error::QueueError;
use crate::job::{ActionFn, Job};
use crate::message::Message;
use crate::publisher::Publisher;

const WAIT_BUCKETS: &str = "0.005,0.01,0.025,0.05,0.075,0.1,0.25,0.5,0.75,1,2.5,5,7.5,10";

#[derive(Clone)]
pub struct HookEntry {
    hook: Hook,
    action: Arc<Mutex<Option<ActionFn>>>,
}

impl std::fmt::Debug for HookEntry {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("HookEntry")
            .field("hook", &self.hook)
            .finish_non_exhaustive()
    }
}

impl HookEntry {
    fn star() -> Self {
        let mut hook = Hook::new();
        hook.groups(["*"]);
        Self {
            hook,
            action: Arc::new(Mutex::new(None)),
        }
    }

    pub fn hook_meta(&self) -> &Hook {
        &self.hook
    }

    pub fn hook_meta_mut(&mut self) -> &mut Hook {
        &mut self.hook
    }

    pub fn desc(&mut self, desc: impl Into<String>) -> &mut Self {
        self.hook.desc(desc);
        self
    }

    pub fn groups<I, S>(&mut self, groups: I) -> &mut Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.hook.groups(groups);
        self
    }

    pub fn inject(
        &mut self,
        injection: impl Into<String>,
    ) -> Result<&mut Self, utopia_servers::HookError> {
        self.hook.inject(injection)?;
        Ok(self)
    }

    pub fn param(
        &mut self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
    ) -> &mut Self {
        self.hook
            .param(key, default, validator, description, optional);
        self
    }

    pub fn action<F>(&mut self, f: F) -> &mut Self
    where
        F: Fn(&ActionArgs) -> Result<(), QueueError> + Send + Sync + 'static,
    {
        *self.action.lock() = Some(Arc::new(f));
        self.hook.action_marker();
        self
    }

    pub fn get_action(&self) -> Option<ActionFn> {
        self.action.lock().clone()
    }
}

/// PHP `Utopia\Queue\Server`.
pub struct Server<A: Adapter> {
    adapter: A,
    job: Job,
    error_hooks: Vec<HookEntry>,
    init_hooks: Vec<HookEntry>,
    shutdown_hooks: Vec<HookEntry>,
    worker_start_hooks: Vec<HookEntry>,
    worker_stop_hooks: Vec<HookEntry>,
    job_wait_time: Arc<dyn Histogram>,
    process_duration: Arc<dyn Histogram>,
    #[allow(dead_code)]
    queue_depth: Arc<dyn ObservableGauge>,
    queue_depth_probe: Arc<Mutex<QueueDepthProbe>>,
}

type QueueDepthProbe = Arc<dyn Fn() -> Option<(f64, Attributes)> + Send + Sync>;

impl<A: Adapter + 'static> std::fmt::Debug for Server<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Server")
            .field("job", &self.job)
            .finish_non_exhaustive()
    }
}

impl<A: Adapter + 'static> Server<A> {
    pub fn new(adapter: A) -> Self {
        let mut server = Self {
            adapter,
            job: Job::new(),
            error_hooks: Vec::new(),
            init_hooks: Vec::new(),
            shutdown_hooks: Vec::new(),
            worker_start_hooks: Vec::new(),
            worker_stop_hooks: Vec::new(),
            job_wait_time: NoneAdapter::new().create_histogram(
                "messaging.process.wait.duration",
                Some("s"),
                None,
                HashMap::new(),
            ),
            process_duration: NoneAdapter::new().create_histogram(
                "messaging.process.duration",
                Some("s"),
                None,
                HashMap::new(),
            ),
            queue_depth: NoneAdapter::new().create_observable_gauge(
                "messaging.queue.depth",
                Some("{message}"),
                Some("Number of pending messages in the queue."),
                HashMap::new(),
            ),
            queue_depth_probe: Arc::new(Mutex::new(Arc::new(|| None))),
        };
        server.set_telemetry(&NoneAdapter::new());
        server
    }

    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    pub fn adapter_mut(&mut self) -> &mut A {
        &mut self.adapter
    }

    pub fn job(&mut self) -> &mut Job {
        self.job = Job::new();
        &mut self.job
    }

    pub fn resources(&self) -> &Container {
        self.adapter.resources()
    }

    pub fn context(&self) -> Container {
        self.adapter.context()
    }

    pub fn set_telemetry(&mut self, telemetry: &dyn TelemetryAdapter) {
        let mut advisory = HashMap::new();
        advisory.insert("ExplicitBucketBoundaries".into(), WAIT_BUCKETS.into());

        self.job_wait_time = telemetry.create_histogram(
            "messaging.process.wait.duration",
            Some("s"),
            None,
            advisory.clone(),
        );
        self.process_duration =
            telemetry.create_histogram("messaging.process.duration", Some("s"), None, advisory);
        self.queue_depth = telemetry.create_observable_gauge(
            "messaging.queue.depth",
            Some("{message}"),
            Some("Number of pending messages in the queue."),
            HashMap::new(),
        );

        let consumer = self.adapter.consumer().clone();
        let queue = self.adapter.queue().clone();
        let probe: QueueDepthProbe = Arc::new(move || {
            let publisher: &dyn Publisher = consumer.as_publisher()?;
            let size = publisher.get_queue_size(&queue, false).ok()?;
            let mut attrs = Attributes::new();
            attrs.insert("messaging.destination.name".into(), queue.name.clone());
            attrs.insert(
                "messaging.destination.namespace".into(),
                queue.namespace.clone(),
            );
            Some((size as f64, attrs))
        });
        *self.queue_depth_probe.lock() = probe.clone();
        self.queue_depth.observe(Box::new(move |observer| {
            if let Some((value, attrs)) = probe() {
                observer.observe(value, &attrs);
            }
        }));
    }

    /// Run the queue-depth probe (PHP Test adapter callback invocation).
    pub fn observe_queue_depth(&self) -> Vec<(f64, Attributes)> {
        match (self.queue_depth_probe.lock())() {
            Some(pair) => vec![pair],
            None => Vec::new(),
        }
    }

    pub fn shutdown(&mut self) -> &mut HookEntry {
        self.shutdown_hooks.push(HookEntry::star());
        self.shutdown_hooks.last_mut().expect("just pushed")
    }

    pub fn init(&mut self) -> &mut HookEntry {
        self.init_hooks.push(HookEntry::star());
        self.init_hooks.last_mut().expect("just pushed")
    }

    pub fn error(&mut self) -> &mut HookEntry {
        self.error_hooks.push(HookEntry::star());
        self.error_hooks.last_mut().expect("just pushed")
    }

    pub fn worker_start(&mut self) -> &mut HookEntry {
        self.worker_start_hooks.push(HookEntry::star());
        self.worker_start_hooks.last_mut().expect("just pushed")
    }

    pub fn worker_stop(&mut self) -> &mut HookEntry {
        self.worker_stop_hooks.push(HookEntry::star());
        self.worker_stop_hooks.last_mut().expect("just pushed")
    }

    pub fn get_worker_start(&self) -> &[HookEntry] {
        &self.worker_start_hooks
    }

    pub fn get_worker_stop(&self) -> &[HookEntry] {
        &self.worker_stop_hooks
    }

    pub fn stop(&mut self) -> Result<&mut Self, QueueError> {
        if let Err(error) = self.adapter.stop() {
            self.resources()
                .set_cached("error", Resource::new(error.clone()));
            let ctx = self.resources().clone();
            for hook in &self.error_hooks {
                let args = get_arguments(
                    &ctx,
                    hook.hook_meta(),
                    &Value::Object(serde_json::Map::default()),
                )?;
                if let Some(action) = hook.get_action() {
                    let _ = action(&args);
                }
            }
        }
        Ok(self)
    }

    pub fn start(&mut self) -> Result<&mut Self, QueueError> {
        let job = self.job.clone();
        let init_hooks = self.init_hooks.clone();
        let shutdown_hooks = self.shutdown_hooks.clone();
        let error_hooks = self.error_hooks.clone();
        let worker_start_hooks = self.worker_start_hooks.clone();
        let worker_stop_hooks = self.worker_stop_hooks.clone();
        let job_wait_time = self.job_wait_time.clone();
        let process_duration = self.process_duration.clone();
        let resources = self.adapter.resources().clone();

        let message_callback: MessageCallback = {
            let job = job.clone();
            let init_hooks = init_hooks.clone();
            let resources = resources.clone();
            let job_wait_time = job_wait_time.clone();
            let process_duration = process_duration.clone();
            Arc::new(move |message: &Message| {
                let received_at = Instant::now();
                let wait = (unix_now_f64() - message.get_timestamp() as f64).max(0.0);
                job_wait_time.record(wait, &Attributes::new());
                let outcome = (|| {
                    let context = {
                        // Adapter process() already installed a child context.
                        // Fall back to resources if called outside process().
                        let ctx = current_or_child(&resources);
                        ctx.set_cached("message", Resource::new(message.clone()));
                        ctx
                    };
                    let payload = message.get_payload();
                    if job.get_hook() {
                        run_group_hooks(&init_hooks, &["*"], &context, &payload)?;
                    }
                    run_group_hooks(&init_hooks, &job.get_groups(), &context, &payload)?;
                    let args = get_arguments(&context, job.hook_meta(), &payload)?;
                    if let Some(action) = job.get_action() {
                        action(&args)?;
                    }
                    Ok(())
                })();
                process_duration.record(received_at.elapsed().as_secs_f64(), &Attributes::new());
                outcome
            })
        };

        let success_callback: SuccessCallback = {
            let job = job.clone();
            let shutdown_hooks = shutdown_hooks.clone();
            let resources = resources.clone();
            Arc::new(move |message: &Message| {
                let context = current_or_child(&resources);
                context.set_cached("message", Resource::new(message.clone()));
                let payload = message.get_payload();
                if job.get_hook() {
                    run_group_hooks(&shutdown_hooks, &["*"], &context, &payload)?;
                }
                run_group_hooks(&shutdown_hooks, &job.get_groups(), &context, &payload)?;
                Ok(())
            })
        };

        let error_callback: ErrorCallback = {
            let error_hooks = error_hooks.clone();
            let resources = resources.clone();
            Arc::new(move |message: Option<&Message>, error: &QueueError| {
                let context = current_or_child(&resources);
                context.set_cached("error", Resource::new(error.clone()));
                if let Some(message) = message {
                    context.set_cached("message", Resource::new(message.clone()));
                }
                for hook in &error_hooks {
                    let args = get_arguments(
                        &context,
                        hook.hook_meta(),
                        &Value::Object(serde_json::Map::default()),
                    )?;
                    if let Some(action) = hook.get_action() {
                        action(&args)?;
                    }
                }
                Ok(())
            })
        };

        let adapter_for_start = self.adapter.clone();
        let worker_start_hooks2 = worker_start_hooks.clone();
        let resources2 = resources.clone();
        let msg_cb = message_callback.clone();
        let ok_cb = success_callback.clone();
        let err_cb = error_callback.clone();
        self.adapter.worker_start(Arc::new({
            let adapter_for_start = adapter_for_start.clone();
            let worker_start_hooks2 = worker_start_hooks2.clone();
            let resources2 = resources2.clone();
            let msg_cb = msg_cb.clone();
            let ok_cb = ok_cb.clone();
            let err_cb = err_cb.clone();
            move |worker_id: &str| {
                resources2.set_cached("workerId", Resource::string(worker_id));
                for hook in &worker_start_hooks2 {
                    if let Ok(args) = get_arguments(
                        &resources2,
                        hook.hook_meta(),
                        &Value::Object(serde_json::Map::default()),
                    ) {
                        if let Some(action) = hook.get_action() {
                            let _ = action(&args);
                        }
                    }
                }
                adapter_for_start.consume(msg_cb.clone(), ok_cb.clone(), err_cb.clone());
            }
        }));

        let worker_stop_hooks2 = worker_stop_hooks;
        let resources3 = resources.clone();
        let consumer = self.adapter.consumer().clone();
        self.adapter.worker_stop(Arc::new(move |worker_id: &str| {
            resources3.set_cached("workerId", Resource::string(worker_id));
            for hook in &worker_stop_hooks2 {
                if let Ok(args) = get_arguments(
                    &resources3,
                    hook.hook_meta(),
                    &Value::Object(serde_json::Map::default()),
                ) {
                    if let Some(action) = hook.get_action() {
                        let _ = action(&args);
                    }
                }
            }
            consumer.close();
        }));

        match self.adapter.start() {
            Ok(()) => Ok(self),
            Err(error) => {
                self.resources()
                    .set_cached("error", Resource::new(error.clone()));
                for hook in &self.error_hooks {
                    if let Ok(args) = get_arguments(
                        self.resources(),
                        hook.hook_meta(),
                        &Value::Object(serde_json::Map::default()),
                    ) {
                        if let Some(action) = hook.get_action() {
                            let _ = action(&args);
                        }
                    }
                }
                Err(error)
            }
        }
    }
}

fn current_or_child(resources: &Container) -> Container {
    crate::adapter::current_context().unwrap_or_else(|| Container::child(resources))
}

pub(crate) fn get_arguments(
    context: &Container,
    hook: &Hook,
    payload: &Value,
) -> Result<ActionArgs, QueueError> {
    let payload_obj: HashMap<String, Value> = match payload {
        Value::Object(map) => map.iter().map(|(k, v)| (k.clone(), v.clone())).collect(),
        _ => HashMap::new(),
    };

    let mut params = HashMap::new();
    for (kind, key, _order) in hook.argument_order() {
        match kind {
            ArgumentKind::Param => {
                let param = hook
                    .get_params()
                    .get(&key)
                    .ok_or_else(|| QueueError::Other(format!("missing param {key}")))?;
                let mut payload_key = key.as_str();
                if !payload_obj.contains_key(&key) && !param.aliases.is_empty() {
                    for alias in &param.aliases {
                        if payload_obj.contains_key(alias) {
                            payload_key = alias;
                            break;
                        }
                    }
                }
                let mut value = payload_obj
                    .get(payload_key)
                    .cloned()
                    .unwrap_or_else(|| param.default.clone());
                if is_empty_php_string_or_null(&value) {
                    value = param.default.clone();
                }
                validate(&key, param.optional, param.validator.as_ref(), &value)?;
                params.insert(key, value);
            }
            ArgumentKind::Injection => {
                // Resolved lazily via ActionArgs::inject / container.
                let _ = context.get(&key);
            }
        }
    }

    Ok(ActionArgs {
        params,
        container: context.clone(),
    })
}

fn is_empty_php_string_or_null(value: &Value) -> bool {
    match value {
        Value::Null => true,
        Value::String(s) if s.is_empty() => true,
        _ => false,
    }
}

fn validate(
    key: &str,
    optional: bool,
    validator: &dyn Validator,
    value: &Value,
) -> Result<(), QueueError> {
    if !is_empty_php_string_or_null(value) {
        if !validator.is_valid(value) {
            return Err(QueueError::invalid_param(key, &validator.description()));
        }
    } else if !optional {
        return Err(QueueError::param_not_optional(key));
    }
    Ok(())
}

fn run_group_hooks(
    hooks: &[HookEntry],
    groups: &[impl AsRef<str>],
    context: &Container,
    payload: &Value,
) -> Result<(), QueueError> {
    for group in groups {
        let group = group.as_ref();
        for hook in hooks {
            if hook.hook_meta().get_groups().iter().any(|g| g == group) {
                let args = get_arguments(context, hook.hook_meta(), payload)?;
                if let Some(action) = hook.get_action() {
                    action(&args)?;
                }
            }
        }
    }
    Ok(())
}
