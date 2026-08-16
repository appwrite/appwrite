//! PHP `Utopia\Messaging\Adapter\Email` constants and providers.

pub mod mailgun;
pub mod mime;
pub mod mock;
pub mod resend;
pub mod sendgrid;
pub mod ses;
pub mod smtp;

pub use mailgun::Mailgun;
pub use mime::{Mime, MimeMessage};
pub use mock::Mock;
pub use resend::Resend;
pub use sendgrid::Sendgrid;
pub use ses::SES;
pub use smtp::{SMTPEncryption, SMTPHost, SMTP};

/// PHP `Adapter\Email::TYPE`.
pub const TYPE: &str = "email";

/// PHP `MAX_ATTACHMENT_BYTES` (25MB).
pub const MAX_ATTACHMENT_BYTES: u64 = 25 * 1024 * 1024;
