//! Message types (PHP `Utopia\Messaging\Messages`).

pub mod discord;
pub mod email;
pub mod push;
pub mod sms;

pub use discord::Discord;
pub use email::{Email, Recipient, RecipientInput};
pub use push::Push;
pub use sms::SMS;
