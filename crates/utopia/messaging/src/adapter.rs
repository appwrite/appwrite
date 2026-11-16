//! The [`Adapter`] trait every provider implements, and what they share:
//! the checks and telemetry around a send ([`Adapter::send`]) and the
//! [`Base`] that holds the requester and the send counter.

use std::sync::{Arc, RwLock};

use utopia_telemetry::{Counter, NoTelemetry, Telemetry, Value as Attribute};

use crate::error::{Error, Reason};
use crate::http::{BoxFuture, Call, Http, Multi, OnResult, Reply, Requester, Transport};
use crate::message::{Message, MessageType};
use crate::response::{Sent, Status};

/// The name of the counter sends are recorded on.
pub const SEND_COUNTER: &str = "messaging.send";

/// What every adapter holds: how it makes requests, and the counter its
/// sends are recorded on.
///
/// PHP: the private state of `Utopia\Messaging\Adapter` (`$clientFactory`,
/// `$sendCounter`).
pub struct Base {
    requester: Arc<dyn Requester>,
    counter: RwLock<Counter>,
}

impl std::fmt::Debug for Base {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Base").finish_non_exhaustive()
    }
}

impl Base {
    /// State for an adapter making requests through `http`, recording
    /// nothing until telemetry is set.
    pub fn new(http: Http) -> Base {
        Base::with_requester(Arc::new(http))
    }

    /// State for an adapter making requests through `requester`.
    pub fn with_requester(requester: Arc<dyn Requester>) -> Base {
        Base { requester, counter: RwLock::new(NoTelemetry.counter(SEND_COUNTER, None, None)) }
    }

    /// The requester.
    pub fn requester(&self) -> &Arc<dyn Requester> {
        &self.requester
    }

    /// Replaces the requester.
    pub fn set_requester(&mut self, requester: Arc<dyn Requester>) {
        self.requester = requester;
    }

    /// Records sends on `telemetry`'s `messaging.send` counter.
    ///
    /// PHP: `setTelemetry()`.
    pub fn set_telemetry(&self, telemetry: &dyn Telemetry) {
        let counter = telemetry.counter(SEND_COUNTER, None, None);
        *self.counter.write().unwrap_or_else(|e| e.into_inner()) = counter;
    }

    fn counter(&self) -> Counter {
        self.counter.read().unwrap_or_else(|e| e.into_inner()).clone()
    }

    /// Sends one request.
    ///
    /// PHP: `request()`.
    pub async fn request(&self, call: Call) -> Result<Reply, Error> {
        self.requester.request(call).await
    }

    /// Sends requests concurrently: every reply in request order, or, with
    /// `on_result`, each handed to it as it completes and none returned
    /// (see [`Requester::request_multi`]).
    ///
    /// PHP: `requestMulti()`.
    pub async fn request_multi(&self, multi: Multi, on_result: Option<&OnResult<'_>>) -> Result<Vec<Reply>, Error> {
        self.requester.request_multi(multi, on_result).await
    }
}

/// A provider that sends one kind of message.
///
/// Implement it to add a provider: [`Adapter::process`] sends a message
/// that already passed the checks of [`Adapter::send`], which callers use.
///
/// PHP: `Utopia\Messaging\Adapter`, with `Adapter\Email`, `Adapter\SMS`
/// and `Adapter\Push` (the base classes fixing `getType()` and
/// `getMessageType()`).
pub trait Adapter: Send + Sync {
    /// The provider's name (`Twilio`).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The kind of messages: `email`, `sms`, `push` or `chat`.
    ///
    /// PHP: `getType()`.
    fn kind(&self) -> &str;

    /// The message the adapter sends.
    ///
    /// PHP: `getMessageType()`.
    fn message_type(&self) -> MessageType;

    /// The most recipients one send may name.
    ///
    /// PHP: `getMaxMessagesPerRequest()`.
    fn max_messages_per_request(&self) -> i64;

    /// The adapter's requester and counter.
    fn base(&self) -> &Base;

    /// Sends a message of the adapter's type, within its recipient limit.
    ///
    /// PHP: `process()`.
    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>>;

    /// Records sends on `telemetry`.
    ///
    /// PHP: `setTelemetry()`.
    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        self.base().set_telemetry(&*telemetry);
    }

    /// Sends a message: checks it is the adapter's type and within its
    /// recipient limit, processes it, and records how many recipients were
    /// reached and how many were not.
    ///
    /// Fails with [`Error::Invalid`] (`message_type`, `too_many_recipients`)
    /// before anything is sent, or with what processing raised, recorded as
    /// a failure for every recipient.
    ///
    /// PHP: `send()`.
    fn send<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(send(self, message))
    }
}

async fn send<A: Adapter + ?Sized>(adapter: &A, message: &Message) -> Result<Sent, Error> {
    if message.message_type() != adapter.message_type() {
        return Err(Error::invalid(
            Reason::MessageType,
            format!("{} cannot send {} messages.", adapter.name(), message.message_type().class()),
            None,
        ));
    }
    let max = adapter.max_messages_per_request();
    if let Some(count) = message.recipient_count()
        && i64::try_from(count).unwrap_or(i64::MAX) > max
    {
        return Err(Error::invalid(
            Reason::TooManyRecipients,
            format!("{} can only send {max} messages per request.", adapter.name()),
            None,
        ));
    }
    match adapter.process(message).await {
        Ok(sent) => {
            if let Sent::Response(response) = &sent
                && !response.details().is_empty()
            {
                let outcomes = response
                    .details()
                    .iter()
                    .map(|d| (Some(d.recipient.as_str()), d.status == Status::Success))
                    .collect::<Vec<_>>();
                record(adapter, message, &outcomes);
            }
            Ok(sent)
        }
        Err(error) => {
            let outcomes: Vec<(Option<&str>, bool)> = match message {
                Message::Email(m) => m.to().iter().map(|_| (None, false)).collect(),
                Message::Sms(m) => m.to().iter().map(|to| (Some(to.as_str()), false)).collect(),
                Message::Push(m) => m.to().iter().map(|to| (Some(to.as_str()), false)).collect(),
                Message::Discord(_) => vec![(None, false)],
            };
            record(adapter, message, &outcomes);
            Err(error)
        }
    }
}

/// Counts each attempt once, grouped by the attributes its recipient adds,
/// so a message to several recipients splits its successes and failures
/// between them. `outcomes` holds each recipient and whether it was
/// delivered.
///
/// PHP: `recordSend()`, with `telemetryAttributes()`.
fn record<A: Adapter + ?Sized>(adapter: &A, message: &Message, outcomes: &[(Option<&str>, bool)]) {
    let counter = adapter.base().counter();
    let lower = php_std::string::strtolower(adapter.name().as_bytes());
    let provider = String::from_utf8_lossy(&lower);
    // Groups in order of first appearance: (recipient attributes, successes, failures).
    let mut groups: Vec<(RecipientAttributes, usize, usize)> = Vec::new();
    for &(recipient, delivered) in outcomes {
        let attributes = recipient_attributes(adapter, recipient);
        let index = match groups.iter().position(|(a, _, _)| *a == attributes) {
            Some(index) => index,
            None => {
                groups.push((attributes, 0, 0));
                groups.len() - 1
            }
        };
        if delivered {
            groups[index].1 += 1;
        } else {
            groups[index].2 += 1;
        }
    }
    let template = match message {
        Message::Email(email) => email.template(),
        _ => None,
    };
    for (extra, success, failure) in &groups {
        for (result, count) in [("success", *success), ("failure", *failure)] {
            if count == 0 {
                continue;
            }
            let mut list: Vec<(&str, Attribute<'_>)> = Vec::with_capacity(6);
            list.push(("result", result.into()));
            if let Some(origin) = message.origin() {
                list.push(("origin", origin.into()));
            }
            list.push(("type", adapter.kind().into()));
            list.push(("provider", provider.as_ref().into()));
            // PHP: `Adapter\Email::telemetryAttributes()`.
            if let Some(template) = template {
                list.push(("template", template.into()));
            }
            list.extend(extra.iter().map(|&(key, value)| (key, value.into())));
            counter.add(count, &list);
        }
    }
}

/// The attributes one recipient adds to the attempts to it.
type RecipientAttributes = Vec<(&'static str, &'static str)>;

/// Attributes describing one recipient, recorded with each attempt to it,
/// kept low-cardinality: SMS adapters label the recipient's country calling
/// code (`91`, `1`; `unknown` when it matches none), others add nothing.
///
/// PHP: `recipientAttributes()`, overridden by `Adapter\SMS`.
fn recipient_attributes<A: Adapter + ?Sized>(adapter: &A, recipient: Option<&str>) -> RecipientAttributes {
    if adapter.message_type() != MessageType::Sms {
        return Vec::new();
    }
    let code = recipient.and_then(crate::sms::calling_code::from_phone_number).unwrap_or("unknown");
    vec![("country_code", code)]
}

/// Swaps how an adapter makes requests.
///
/// PHP: the `$clientFactory` some adapters take, and overriding
/// `request()`, `requestMulti()` or `destinations()` in a subclass.
pub trait Configure: Sized {
    /// The adapter's state, to change.
    fn base_mut(&mut self) -> &mut Base;

    /// The default requester of this adapter.
    fn http(&self) -> Http;

    /// Answers requests through `requester` (canned replies, a recorder).
    fn with_requester(mut self, requester: Arc<dyn Requester>) -> Self {
        self.base_mut().set_requester(requester);
        self
    }

    /// Sends requests through `transport` instead of the default client.
    ///
    /// PHP: `$clientFactory`.
    fn with_transport(self, transport: Arc<dyn Transport>) -> Self {
        let http = self.http().with_transport(transport);
        self.with_requester(Arc::new(http))
    }

    /// Records sends on `telemetry`.
    ///
    /// PHP: the `$telemetry` constructor argument.
    fn with_telemetry(mut self, telemetry: &dyn Telemetry) -> Self {
        self.base_mut().set_telemetry(telemetry);
        self
    }
}

/// Implements [`Configure`] and the shared parts of [`Adapter`] for a
/// provider struct with a `base: Base` field.
macro_rules! configure {
    ($type:ty, $http:expr) => {
        impl $crate::adapter::Configure for $type {
            fn base_mut(&mut self) -> &mut $crate::adapter::Base {
                &mut self.base
            }

            fn http(&self) -> $crate::http::Http {
                let make: fn(&Self) -> $crate::http::Http = $http;
                make(self)
            }
        }
    };
}

pub(crate) use configure;
