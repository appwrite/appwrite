//! The entry point: a user-agent string and what detection finds in it.
//!
//! PHP: `Utopia\UserAgent\UserAgent`.

use std::cell::OnceCell;

use crate::detection;
use crate::values::{Bot, Client, Device, OperatingSystem};

/// A lazily evaluated analysis of one user-agent string.
///
/// Each category is detected at most once, on first access. Bot detection
/// is independent from client and device detection: a bot never suppresses
/// the other results.
#[derive(Debug, Clone)]
pub struct UserAgent<'a> {
    value: &'a [u8],
    operating_system: OnceCell<OperatingSystem<'a>>,
    client: OnceCell<Client<'a>>,
    device: OnceCell<Device<'a>>,
    bot: OnceCell<Option<Bot<'a>>>,
}

impl<'a> UserAgent<'a> {
    /// Wraps a user-agent string without detecting anything yet.
    ///
    /// Accepts any bytes, as a header can carry them.
    ///
    /// PHP: `UserAgent::parse()`.
    pub fn parse(value: &'a (impl AsRef<[u8]> + ?Sized)) -> Self {
        Self {
            value: value.as_ref(),
            operating_system: OnceCell::new(),
            client: OnceCell::new(),
            device: OnceCell::new(),
            bot: OnceCell::new(),
        }
    }

    /// The user-agent string as given.
    ///
    /// PHP: `raw()`.
    pub fn raw(&self) -> &'a [u8] {
        self.value
    }

    /// The operating system, detected on first call.
    ///
    /// PHP: `operatingSystem()`.
    pub fn operating_system(&self) -> &OperatingSystem<'a> {
        self.operating_system.get_or_init(|| detection::operating_system(self.value))
    }

    /// The client (browser, HTTP library or native app), detected on first
    /// call.
    ///
    /// PHP: `client()`.
    pub fn client(&self) -> &Client<'a> {
        self.client.get_or_init(|| detection::client(self.value))
    }

    /// The device, detected on first call.
    ///
    /// PHP: `device()`.
    pub fn device(&self) -> &Device<'a> {
        self.device.get_or_init(|| detection::device(self.value))
    }

    /// The bot behind the string, or `None` for a human client. Detected on
    /// first call.
    ///
    /// PHP: `bot()`.
    pub fn bot(&self) -> Option<&Bot<'a>> {
        self.bot.get_or_init(|| detection::bot(self.value)).as_ref()
    }

    /// Whether the string belongs to a bot ([`UserAgent::bot`] is `Some`).
    ///
    /// PHP: `isBot()`.
    pub fn is_bot(&self) -> bool {
        self.bot().is_some()
    }
}
