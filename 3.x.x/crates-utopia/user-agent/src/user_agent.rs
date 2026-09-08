use std::sync::OnceLock;

use serde::Serialize;

use crate::bot::Bot;
use crate::client::Client;
use crate::detection;
use crate::device::Device;
use crate::operating_system::OperatingSystem;

/// A lazily evaluated analysis of one user-agent string.
///
/// Each category is detected at most once. Bot detection is independent from
/// client and device detection, so bots never suppress the other results.
#[derive(Debug)]
pub struct UserAgent {
    value: String,
    operating_system: OnceLock<OperatingSystem>,
    client: OnceLock<Client>,
    device: OnceLock<Device>,
    bot: OnceLock<Option<Bot>>,
}

/// Nested `UserAgent::to_array()` output.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct UserAgentArray {
    pub os: crate::operating_system::OperatingSystemArray,
    pub client: crate::client::ClientArray,
    pub device: crate::device::DeviceArray,
    pub bot: Option<crate::bot::BotArray>,
}

impl UserAgent {
    /// Parse a user-agent string for lazy detection.
    pub fn parse(value: &str) -> Self {
        Self {
            value: value.to_string(),
            operating_system: OnceLock::new(),
            client: OnceLock::new(),
            device: OnceLock::new(),
            bot: OnceLock::new(),
        }
    }

    /// Original user-agent string.
    pub fn raw(&self) -> &str {
        &self.value
    }

    /// Detected operating system (memoized).
    pub fn operating_system(&self) -> OperatingSystem {
        self.operating_system
            .get_or_init(|| detection::detect_operating_system(&self.value))
            .clone()
    }

    /// Detected client (memoized).
    pub fn client(&self) -> Client {
        self.client
            .get_or_init(|| detection::detect_client(&self.value))
            .clone()
    }

    /// Detected device (memoized).
    pub fn device(&self) -> Device {
        self.device
            .get_or_init(|| detection::detect_device(&self.value))
            .clone()
    }

    /// Detected bot, if any (memoized).
    pub fn bot(&self) -> Option<Bot> {
        self.bot
            .get_or_init(|| detection::detect_bot(&self.value))
            .clone()
    }

    /// Whether the user-agent matches a known bot.
    pub fn is_bot(&self) -> bool {
        self.bot().is_some()
    }

    /// Nested serialization matching PHP `toArray()`.
    pub fn to_array(&self) -> UserAgentArray {
        UserAgentArray {
            os: self.operating_system().to_array(),
            client: self.client().to_array(),
            device: self.device().to_array(),
            bot: self.bot().map(|b| b.to_array()),
        }
    }
}
