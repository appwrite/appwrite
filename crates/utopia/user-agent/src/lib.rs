//! User-agent detection: the operating system, client, device and bot
//! behind a user-agent string. The Rust port of `utopia-php/user-agent`
//! (`packages/user-agent`), with identical results for every input,
//! including bytes that are not UTF-8.
//!
//! ```
//! use utopia_user_agent::UserAgent;
//!
//! let agent = UserAgent::parse("Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0");
//! assert_eq!(agent.operating_system().version.as_deref(), Some(b"7".as_slice()));
//! assert_eq!(agent.client().name.as_deref(), Some(b"Firefox".as_slice()));
//! assert!(!agent.is_bot());
//! ```
//!
//! Detection runs on every request, so each regular expression is compiled
//! once per process. Patterns are the PHP library's, run by
//! [`php_std::pcre`] (a port of the PCRE2 PHP uses), so matching, limits
//! included, is PHP's.
//!
//! | PHP | Rust |
//! |---|---|
//! | `UserAgent::parse($value)` | [`UserAgent::parse`] |
//! | `UserAgent::raw()` | [`UserAgent::raw`] |
//! | `UserAgent::operatingSystem()` / `client()` / `device()` / `bot()` | [`UserAgent::operating_system`], [`UserAgent::client`], [`UserAgent::device`], [`UserAgent::bot`] |
//! | `UserAgent::isBot()` | [`UserAgent::is_bot`] |
//! | `UserAgent::toArray()`, `*::toArray()` | the public fields of [`OperatingSystem`], [`Client`], [`Device`], [`Bot`] |
//! | `OperatingSystem` (`code`, `name`, `version`, `isKnown()`) | [`OperatingSystem`], [`OperatingSystem::is_known`] |
//! | `Client` (`type`, ..., `engineVersion`, `isKnown()`, `isBrowser()`) | [`Client`] (`kind`, ..., `engine_version`), [`Client::is_known`], [`Client::is_browser`] |
//! | `Device` (`type`, `brand`, `model`, `isKnown()`) | [`Device`] (`kind`, ...), [`Device::is_known`] |
//! | `new Bot($name, $category = 'crawler')` | [`Bot::new`], [`Bot::with_category`] |
//! | `Detection\OperatingSystemDetector::detect()` | [`detection::operating_system`] |
//! | `Detection\ClientDetector::detect()` | [`detection::client`] |
//! | `Detection\DeviceDetector::detect()` | [`detection::device`] |
//! | `Detection\BotDetector::detect()` | [`detection::bot`] |
//!
//! Appwrite's session attributes (`Appwrite\Detector\Detector`) are in
//! `appwrite_core::detector`.

mod agent;
pub mod detection;
mod pattern;
mod values;
mod version;

pub use agent::UserAgent;
pub use values::{Bot, Client, Device, OperatingSystem, Text};
