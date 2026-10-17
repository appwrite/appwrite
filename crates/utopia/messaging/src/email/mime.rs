//! An email as a MIME message on the wire, which the SMTP adapter hands
//! over whole and SES sends as a raw message per recipient.
//!
//! PHP: `Utopia\Messaging\Adapter\Email\Mime`.

use php_std::pcre::{self, StrOrArray};
use utopia_smtp::{Address, Attachment, Message};

use super::filesize;
use crate::error::Error;
use crate::message::{Email, Mailbox};

/// The message for `email`, addressed to `to` and `cc`, with `bcc` in the
/// envelope only, and `headers` of the adapter's own (`X-Mailer`), which
/// win over the email's headers of the same name in any case.
///
/// The text part is the content, or for HTML the content without style
/// blocks and tags.
///
/// PHP: `Mime::message()`.
pub fn message(
    email: &Email,
    to: &[Mailbox],
    cc: &[Mailbox],
    bcc: &[Mailbox],
    headers: &[(String, String)],
) -> Result<Message, Error> {
    let mut builder = Message::builder(Address::new(email.from_email(), email.from_name())?, email.subject());
    for address in addresses(to)? {
        builder = builder.to(address);
    }
    for address in addresses(cc)? {
        builder = builder.cc(address);
    }
    for address in addresses(bcc)? {
        builder = builder.bcc(address);
    }
    if email.has_reply_to() {
        builder = builder.reply_to(Address::new(email.reply_to_email(), email.reply_to_name())?);
    }
    builder = builder.text(text(email));
    if email.is_html() {
        builder = builder.html(email.content());
    }
    for attachment in attachments(email)? {
        builder = builder.attachment(attachment);
    }
    for (name, value) in email.headers() {
        if !headers.iter().any(|(own, _)| own.eq_ignore_ascii_case(name)) {
            builder = builder.header(name.as_str(), value.as_str());
        }
    }
    for (name, value) in headers {
        builder = builder.header(name.as_str(), value.as_str());
    }
    Ok(builder.build()?)
}

/// The recipients as addresses (a missing name is empty).
///
/// PHP: `Mime::addresses()`.
pub fn addresses(recipients: &[Mailbox]) -> Result<Vec<Address>, Error> {
    recipients
        .iter()
        .map(|r| Address::new(r.email.as_str(), r.name.as_deref().unwrap_or("")).map_err(Error::from))
        .collect()
}

/// The email's attachments: read from their path when written, or from the
/// content given.
///
/// PHP: `Mime::attachments()`.
pub fn attachments(email: &Email) -> Result<Vec<Attachment>, Error> {
    email
        .attachments()
        .unwrap_or_default()
        .iter()
        .map(|a| {
            match a.content() {
                None => Attachment::from_path(a.path(), Some(a.name()), Some(a.mime_type())),
                Some(content) => Attachment::from_bytes(content, a.name(), Some(a.mime_type())),
            }
            .map_err(Error::from)
        })
        .collect()
}

/// What the attachments weigh before they are encoded.
///
/// Fails with [`Error::Exception`] for a file that cannot be read.
///
/// PHP: `Mime::size()`.
pub fn size(email: &Email) -> Result<u64, Error> {
    let mut size = 0u64;
    for attachment in email.attachments().unwrap_or_default() {
        size += match attachment.content() {
            Some(content) => content.len() as u64,
            None => filesize(attachment.path())
                .ok_or_else(|| Error::Exception(format!("Failed to read attachment file: {}", attachment.path())))?,
        };
    }
    Ok(size)
}

/// The text a client without markup reads.
fn text(email: &Email) -> Vec<u8> {
    if !email.is_html() {
        return email.content().as_bytes().to_vec();
    }
    let styled = email.content().as_bytes();
    let unstyled = pcre::preg_replace(
        &StrOrArray::Str(br"/<style\b[^>]*>(.*?)<\/style>/is"),
        &StrOrArray::Str(b""),
        &StrOrArray::Str(styled),
        -1,
    )
    .ok()
    .and_then(|r| match r.value.result {
        pcre::Value::Str(s) => Some(s),
        _ => None,
    })
    .unwrap_or_default();
    php_std::string::trim(&php_std::string::strip_tags(&unstyled), b" \t\n\r\0\x0B").to_vec()
}
