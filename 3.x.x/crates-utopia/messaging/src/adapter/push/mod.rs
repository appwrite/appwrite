//! PHP `Utopia\Messaging\Adapter\Push` and providers.

pub mod apns;
pub mod fcm;

pub use apns::APNS;
pub use fcm::FCM;

/// PHP `Adapter\Push::TYPE`.
pub const TYPE: &str = "push";

/// PHP `EXPIRED_MESSAGE`.
pub const EXPIRED_MESSAGE: &str = "Expired device token";
