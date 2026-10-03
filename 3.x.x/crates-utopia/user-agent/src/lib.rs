//! User-agent parsing for Utopia.
//!
//! Rust port of [`utopia-php/user-agent`](https://github.com/utopia-php/user-agent).

mod bot;
mod client;
mod detection;
mod device;
mod operating_system;
mod user_agent;

pub use bot::{Bot, BotArray};
pub use client::{Client, ClientArray};
pub use device::{Device, DeviceArray};
pub use operating_system::{OperatingSystem, OperatingSystemArray};
pub use user_agent::{UserAgent, UserAgentArray};
