//! What a send reports: how many recipients it reached, and a result per
//! recipient.

use indexmap::IndexMap;

/// Whether a recipient was reached.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Status {
    /// Delivered to the provider.
    Success,
    /// Not delivered; the result's error says why.
    Failure,
}

impl Status {
    /// `success` or `failure`, as PHP writes it.
    pub fn as_str(self) -> &'static str {
        match self {
            Status::Success => "success",
            Status::Failure => "failure",
        }
    }
}

/// The result for one recipient.
///
/// PHP: an entry of `Response::getDetails()`, `{recipient, status, error,
/// permanent}`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Delivery {
    /// The recipient: an address, a phone number, a device token.
    pub recipient: String,
    /// Whether it was reached.
    pub status: Status,
    /// Why not, as the provider said it (empty on success).
    pub error: String,
    /// Whether sending the same message again gets the same answer (an SMTP
    /// 5xx). Only a failure can be permanent.
    pub permanent: bool,
}

/// The outcome of one send through one adapter.
///
/// PHP: `Utopia\Messaging\Response`, whose `toArray()` is what `send()`
/// returns.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Response {
    kind: String,
    delivered_to: i64,
    results: Vec<Delivery>,
}

impl Response {
    /// An empty response for messages of `kind` (`email`, `sms`, `push`,
    /// `chat`).
    ///
    /// PHP: `new Response($type)`.
    pub fn new(kind: impl Into<String>) -> Response {
        Response { kind: kind.into(), delivered_to: 0, results: Vec::new() }
    }

    /// Sets how many recipients were reached.
    ///
    /// PHP: `setDeliveredTo()`.
    pub fn set_delivered_to(&mut self, delivered_to: i64) {
        self.delivered_to = delivered_to;
    }

    /// Counts one more recipient reached.
    ///
    /// PHP: `incrementDeliveredTo()`.
    pub fn increment_delivered_to(&mut self) {
        self.delivered_to += 1;
    }

    /// How many recipients were reached.
    ///
    /// PHP: `getDeliveredTo()`.
    pub fn delivered_to(&self) -> i64 {
        self.delivered_to
    }

    /// Changes the kind of message.
    ///
    /// PHP: `setType()`.
    pub fn set_kind(&mut self, kind: impl Into<String>) {
        self.kind = kind.into();
    }

    /// The kind of message.
    ///
    /// PHP: `getType()`.
    pub fn kind(&self) -> &str {
        &self.kind
    }

    /// The result per recipient, in the order they were added.
    ///
    /// PHP: `getDetails()`.
    pub fn details(&self) -> &[Delivery] {
        &self.results
    }

    /// Adds the result for `recipient`: a success when `error` is empty (or
    /// `"0"`, which PHP's check also reads as empty, keeping it as the
    /// error), a failure otherwise. `permanent` holds only for a failure.
    ///
    /// PHP: `addResult($recipient, $error, $permanent)`.
    pub fn add_result(&mut self, recipient: impl Into<String>, error: impl Into<String>, permanent: bool) {
        let error = error.into();
        let failed = !(error.is_empty() || error == "0");
        self.results.push(Delivery {
            recipient: recipient.into(),
            status: if failed { Status::Failure } else { Status::Success },
            error,
            permanent: failed && permanent,
        });
    }

    /// Adds a success for `recipient`.
    pub fn add_success(&mut self, recipient: impl Into<String>) {
        self.add_result(recipient, "", false);
    }

    /// Adds a failure (unless `error` is empty) that is worth retrying.
    pub fn add_failure(&mut self, recipient: impl Into<String>, error: impl Into<String>) {
        self.add_result(recipient, error, false);
    }

    /// Adds a result as the provider reported it.
    pub fn add_delivery(&mut self, delivery: Delivery) {
        self.results.push(delivery);
    }

    /// The results, without the counts.
    pub fn into_details(self) -> Vec<Delivery> {
        self.results
    }
}

/// What `send()` returns.
///
/// PHP: the array `send()` returns: one response's `toArray()`, or, from
/// `GEOSMS`, the responses of the adapters it routed to, by adapter name.
#[derive(Debug, Clone, PartialEq)]
pub enum Sent {
    /// One adapter's response.
    Response(Response),
    /// The responses of several adapters, by name, in the order they were
    /// first sent through.
    Grouped(IndexMap<String, Batch>),
}

impl Sent {
    /// The single response, when this is one.
    pub fn response(&self) -> Option<&Response> {
        match self {
            Sent::Response(r) => Some(r),
            Sent::Grouped(_) => None,
        }
    }

    /// The single response, when this is one.
    pub fn into_response(self) -> Option<Response> {
        match self {
            Sent::Response(r) => Some(r),
            Sent::Grouped(_) => None,
        }
    }
}

/// What one adapter of a [`Sent::Grouped`] did with its share.
#[derive(Debug, Clone, PartialEq)]
pub enum Batch {
    /// It sent them.
    Sent(Sent),
    /// It threw: the message. PHP: `{type: "error", message}`.
    Failed(String),
}
