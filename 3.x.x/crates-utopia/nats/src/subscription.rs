//! Subscription (PHP `Utopia\NATS\Subscription`).

use crate::message::Message;
use parking_lot::Mutex;
use std::collections::VecDeque;
use std::sync::Arc;
use std::time::{Duration, Instant};

pub type MessageCallback = Arc<dyn Fn(Message) + Send + Sync>;
pub type SlowConsumerCallback = Arc<dyn Fn(&Subscription) + Send + Sync>;
pub type ProcessCallback = Arc<dyn Fn(Option<f64>) + Send + Sync>;
pub type UnsubCallback = Arc<dyn Fn(&Subscription, Option<i64>) + Send + Sync>;

#[derive(Clone)]
pub struct Subscription {
    pub sid: String,
    pub subject: String,
    pub queue: Option<String>,
    inner: Arc<Mutex<SubInner>>,
}

struct SubInner {
    pending: VecDeque<Message>,
    active: bool,
    max_messages: Option<i64>,
    received: i64,
    pending_bytes: i64,
    slow_consumer_signaled: bool,
    callback: Option<MessageCallback>,
    pending_msgs_limit: i64,
    pending_bytes_limit: i64,
    on_slow_consumer: Option<SlowConsumerCallback>,
    process: Option<ProcessCallback>,
    unsub: Option<UnsubCallback>,
}

impl std::fmt::Debug for Subscription {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Subscription")
            .field("sid", &self.sid)
            .field("subject", &self.subject)
            .field("queue", &self.queue)
            .finish_non_exhaustive()
    }
}

impl Subscription {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        sid: impl Into<String>,
        subject: impl Into<String>,
        queue: Option<String>,
        callback: Option<MessageCallback>,
        pending_msgs_limit: i64,
        pending_bytes_limit: i64,
        on_slow_consumer: Option<SlowConsumerCallback>,
    ) -> Self {
        Self {
            sid: sid.into(),
            subject: subject.into(),
            queue,
            inner: Arc::new(Mutex::new(SubInner {
                pending: VecDeque::new(),
                active: true,
                max_messages: None,
                received: 0,
                pending_bytes: 0,
                slow_consumer_signaled: false,
                callback,
                pending_msgs_limit,
                pending_bytes_limit,
                on_slow_consumer,
                process: None,
                unsub: None,
            })),
        }
    }

    pub fn set_process(&self, process: ProcessCallback) {
        self.inner.lock().process = Some(process);
    }

    pub fn set_unsub(&self, unsub: UnsubCallback) {
        self.inner.lock().unsub = Some(unsub);
    }

    pub fn unsubscribe(&self, after_messages: Option<i64>) {
        let unsub = self.inner.lock().unsub.clone();
        if let Some(u) = unsub {
            u(self, after_messages);
        } else {
            self.set_inactive();
        }
    }

    pub fn next_message(&self, timeout: Option<f64>) -> Option<Message> {
        let deadline = timeout.map(|t| Instant::now() + Duration::from_secs_f64(t.max(0.0)));
        loop {
            {
                let mut inner = self.inner.lock();
                if let Some(msg) = inner.pending.pop_front() {
                    inner.pending_bytes -= msg.data.len() as i64;
                    if inner.pending_bytes < 0 {
                        inner.pending_bytes = 0;
                    }
                    inner.slow_consumer_signaled = false;
                    return Some(msg);
                }
                if !inner.active || inner.process.is_none() {
                    return None;
                }
            }
            if let Some(d) = deadline {
                if Instant::now() >= d {
                    return None;
                }
            }
            let remaining =
                deadline.map(|d| d.saturating_duration_since(Instant::now()).as_secs_f64());
            let process = self.inner.lock().process.clone();
            if let Some(p) = process {
                p(remaining);
            } else {
                return None;
            }
        }
    }

    pub fn is_active(&self) -> bool {
        self.inner.lock().active
    }

    pub fn deliver(&self, msg: Message) {
        let callback;
        let slow;
        let invoke_msg;
        {
            let mut inner = self.inner.lock();
            if inner.callback.is_some() {
                inner.received += 1;
                callback = inner.callback.clone();
                invoke_msg = Some(msg);
                slow = None;
            } else {
                callback = None;
                invoke_msg = None;
                let msg_bytes = msg.data.len() as i64;
                if inner.pending.len() as i64 >= inner.pending_msgs_limit
                    || inner.pending_bytes + msg_bytes > inner.pending_bytes_limit
                {
                    if inner.slow_consumer_signaled {
                        slow = None;
                    } else {
                        inner.slow_consumer_signaled = true;
                        slow = inner.on_slow_consumer.clone();
                    }
                } else {
                    inner.received += 1;
                    inner.pending_bytes += msg_bytes;
                    inner.pending.push_back(msg);
                    slow = None;
                }
            }
            if let Some(max) = inner.max_messages {
                if inner.received >= max {
                    inner.active = false;
                }
            }
        }
        if let (Some(cb), Some(msg)) = (callback, invoke_msg) {
            cb(msg);
        }
        if let Some(slow) = slow {
            slow(self);
        }
    }

    pub fn get_pending_count(&self) -> usize {
        self.inner.lock().pending.len()
    }

    pub fn get_pending_bytes(&self) -> i64 {
        self.inner.lock().pending_bytes
    }

    pub fn set_max_messages(&self, max: i64) {
        let mut inner = self.inner.lock();
        inner.max_messages = Some(max);
        if inner.received >= max {
            inner.active = false;
        }
    }

    pub fn set_inactive(&self) {
        self.inner.lock().active = false;
    }

    pub fn get_received(&self) -> i64 {
        self.inner.lock().received
    }

    pub fn has_callback(&self) -> bool {
        self.inner.lock().callback.is_some()
    }
}
