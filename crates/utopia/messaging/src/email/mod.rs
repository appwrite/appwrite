//! Email adapters: [`Smtp`] (and [`Mock`], SMTP to a catcher), [`Ses`],
//! [`Mailgun`], [`Sendgrid`] and [`Resend`], with [`mime`], the MIME
//! message SMTP and SES send.
//!
//! PHP: `Utopia\Messaging\Adapter\Email` and its subclasses.

mod mailgun;
pub mod mime;
mod resend;
mod sendgrid;
mod ses;
mod smtp;

pub use mailgun::Mailgun;
pub use resend::Resend;
pub use sendgrid::Sendgrid;
pub use ses::Ses;
pub use smtp::{Host, Mock, NoHostAnswered, Smtp};

use crate::message::{Email, Message};

/// The kind of every email adapter.
///
/// PHP: `Adapter\Email::TYPE`.
pub const TYPE: &str = "email";

/// The attachment limit of an email adapter, unless it says otherwise.
///
/// PHP: `Adapter\Email::MAX_ATTACHMENT_BYTES`.
pub const MAX_ATTACHMENT_BYTES: u64 = 25 * 1024 * 1024;

/// An address with an optional display name, the name quoted when it
/// carries RFC 5322 specials (`"Acme, Inc." <a@example.com>`).
///
/// PHP: `Adapter\Email::formatAddress()`.
pub fn format_address(email: &str, name: Option<&str>) -> String {
    let name = match name {
        None | Some("") | Some("0") => return email.to_owned(),
        Some(name) => name,
    };
    if name.bytes().any(|b| b",;:@<>()[]\\\".".contains(&b)) {
        let escaped = php_std::string::addcslashes(name.as_bytes(), b"\"\\");
        return format!("\"{}\" <{email}>", String::from_utf8_lossy(&escaped));
    }
    format!("{name} <{email}>")
}

/// The email inside a message an email adapter was given.
pub(crate) fn email(message: &Message) -> &Email {
    match message {
        Message::Email(email) => email,
        _ => unreachable!("send() checks the message type"),
    }
}

/// `filesize($path)`: the size, or `None` where PHP returns `false`.
pub(crate) fn filesize(path: &str) -> Option<u64> {
    if path.is_empty() {
        return None;
    }
    std::fs::metadata(path).ok().map(|m| m.len())
}

/// `file_get_contents($path)`, failing as PHP does: a `ValueError` for an
/// empty path, `false` (here `None`) for one that cannot be read.
pub(crate) fn file_contents(path: &str) -> Result<Option<Vec<u8>>, crate::Error> {
    if path.is_empty() {
        return Err(crate::Error::Value("Path must not be empty".into()));
    }
    if path.as_bytes().contains(&0) {
        return Err(crate::Error::Value(
            "file_get_contents(): Argument #1 ($filename) must not contain any null bytes".into(),
        ));
    }
    Ok(std::fs::read(path).ok())
}
