//! PHP `Utopia\Messaging\Response`.

use serde::Serialize;

/// One per-recipient row in [`ResponseData::results`].
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct ResultRow {
    /// Destination address, device token, or webhook id.
    pub recipient: String,
    /// `"success"` or `"failure"`.
    pub status: String,
    /// Empty on success; provider error otherwise.
    pub error: String,
}

/// PHP `Response::toArray()` shape.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct ResponseData {
    /// PHP `deliveredTo`.
    #[serde(rename = "deliveredTo")]
    pub delivered_to: i64,
    /// Adapter type (`sms`, `email`, `push`, `chat`).
    #[serde(rename = "type")]
    pub type_name: String,
    /// Per-recipient outcomes.
    pub results: Vec<ResultRow>,
}

/// Accumulates per-recipient send results (PHP `Response`).
#[derive(Debug, Clone)]
pub struct Response {
    delivered_to: i64,
    type_name: String,
    results: Vec<ResultRow>,
}

impl Response {
    /// PHP `__construct(string $type)`.
    #[must_use]
    pub fn new(type_name: impl Into<String>) -> Self {
        Self {
            delivered_to: 0,
            type_name: type_name.into(),
            results: Vec::new(),
        }
    }

    /// PHP `setDeliveredTo`.
    pub fn set_delivered_to(&mut self, delivered_to: i64) {
        self.delivered_to = delivered_to;
    }

    /// PHP `incrementDeliveredTo`.
    pub fn increment_delivered_to(&mut self) {
        self.delivered_to += 1;
    }

    /// PHP `getDeliveredTo`.
    #[must_use]
    pub fn get_delivered_to(&self) -> i64 {
        self.delivered_to
    }

    /// PHP `setType`.
    pub fn set_type(&mut self, type_name: impl Into<String>) {
        self.type_name = type_name.into();
    }

    /// PHP `getType`.
    #[must_use]
    pub fn get_type(&self) -> &str {
        &self.type_name
    }

    /// PHP `getDetails`.
    #[must_use]
    pub fn get_details(&self) -> &[ResultRow] {
        &self.results
    }

    /// PHP `addResult($recipient, $error = '')`.
    ///
    /// Empty or `'0'` error ⇒ success (PHP `empty()` / explicit `'0'` check).
    pub fn add_result(&mut self, recipient: impl Into<String>, error: impl Into<String>) {
        let error = error.into();
        let status = if error.is_empty() || error == "0" {
            "success"
        } else {
            "failure"
        };
        self.results.push(ResultRow {
            recipient: recipient.into(),
            status: status.to_string(),
            error,
        });
    }

    /// PHP `toArray()`.
    #[must_use]
    pub fn to_array(&self) -> ResponseData {
        ResponseData {
            delivered_to: self.delivered_to,
            type_name: self.type_name.clone(),
            results: self.results.clone(),
        }
    }
}
