//! `Utopia\UserAgent\UserAgent`.

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
    /// `UserAgent::parse()`: nothing is detected yet. Accepts any bytes, as
    /// a header can carry them.
    pub fn parse(value: &'a (impl AsRef<[u8]> + ?Sized)) -> Self {
        Self {
            value: value.as_ref(),
            operating_system: OnceCell::new(),
            client: OnceCell::new(),
            device: OnceCell::new(),
            bot: OnceCell::new(),
        }
    }

    /// `raw()`: the user-agent string as given.
    pub fn raw(&self) -> &'a [u8] {
        self.value
    }

    /// `operatingSystem()`.
    pub fn operating_system(&self) -> &OperatingSystem<'a> {
        self.operating_system.get_or_init(|| detection::operating_system(self.value))
    }

    /// `client()`.
    pub fn client(&self) -> &Client<'a> {
        self.client.get_or_init(|| detection::client(self.value))
    }

    /// `device()`.
    pub fn device(&self) -> &Device<'a> {
        self.device.get_or_init(|| detection::device(self.value))
    }

    /// `bot()`: the bot, or `None` for a human client.
    pub fn bot(&self) -> Option<&Bot<'a>> {
        self.bot.get_or_init(|| detection::bot(self.value)).as_ref()
    }

    /// `isBot()`.
    pub fn is_bot(&self) -> bool {
        self.bot().is_some()
    }
}
