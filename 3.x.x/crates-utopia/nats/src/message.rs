//! NATS message (PHP `Utopia\NATS\Message`).

use crate::headers::Headers;

#[derive(Clone, Debug, PartialEq)]
pub struct Message {
    pub subject: String,
    pub data: Vec<u8>,
    pub reply_to: Option<String>,
    pub headers: Option<Headers>,
    pub sid: Option<String>,
}

impl Message {
    pub fn new(subject: impl Into<String>, data: impl AsRef<[u8]>) -> Self {
        Self {
            subject: subject.into(),
            data: data.as_ref().to_vec(),
            reply_to: None,
            headers: None,
            sid: None,
        }
    }

    pub fn data_str(&self) -> String {
        String::from_utf8_lossy(&self.data).into_owned()
    }
}
