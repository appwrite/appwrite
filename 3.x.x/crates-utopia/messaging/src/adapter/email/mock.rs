//! PHP `Utopia\Messaging\Adapter\Email\Mock`.

use super::smtp::SMTP;
use super::TYPE;
use crate::adapter::{Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};

/// PHP `Adapter\Email\Mock` - SMTP pointed at maildev.
#[derive(Debug)]
pub struct Mock {
    inner: SMTP,
}

impl Mock {
    /// PHP `__construct($host = 'maildev', $port = 1025)`.
    pub fn new(host: impl Into<String>, port: u16) -> Result<Self, MessagingError> {
        Ok(Self {
            inner: SMTP::new(
                host,
                port,
                "",
                "",
                "",
                false,
                "Utopia Mailer",
                30,
                false,
                30,
            )?,
        })
    }
}

impl Adapter for Mock {
    fn get_name(&self) -> &'static str {
        "Mock"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::Email
    }
    fn get_max_messages_per_request(&self) -> usize {
        self.inner.get_max_messages_per_request()
    }
    fn base(&self) -> &AdapterBase {
        self.inner.base()
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        self.inner.process(message)
    }
}
