//! PHP `Utopia\Messaging\Adapter\Email\Mime`.

use std::fmt::{Display, Formatter, Write};

use rand::Rng;

use crate::error::MessagingError;
use crate::messages::email::Attachment;
use crate::messages::{Email, Recipient};
use crate::php::php_empty;

/// Rendered RFC 5322 message (PHP `Utopia\SMTP\Message` stringified).
#[derive(Debug, Clone)]
pub struct MimeMessage {
    raw: String,
}

impl MimeMessage {
    /// Raw message bytes (`\r\n` lines).
    #[must_use]
    pub fn as_str(&self) -> &str {
        &self.raw
    }

    /// Raw bytes for SES `Content.Raw`.
    #[must_use]
    pub fn as_bytes(&self) -> &[u8] {
        self.raw.as_bytes()
    }
}

impl Display for MimeMessage {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.raw)
    }
}

/// PHP `Utopia\Messaging\Adapter\Email\Mime`.
#[derive(Debug, Clone, Copy)]
pub struct Mime;

impl Mime {
    /// PHP `Mime::message`.
    ///
    /// `bcc` is envelope-only and never written to a header.
    #[must_use]
    pub fn message(
        email: &Email,
        to: &[Recipient],
        cc: &[Recipient],
        _bcc: &[Recipient],
        headers: &[(&str, &str)],
    ) -> MimeMessage {
        let mut lines: Vec<String> = Vec::new();
        lines.push(format!(
            "From: {}",
            format_address(email.get_from_email(), Some(email.get_from_name()))
        ));
        lines.push(format!("To: {}", format_list(to)));
        if !cc.is_empty() {
            lines.push(format!("Cc: {}", format_list(cc)));
        }
        if !php_empty(Some(email.get_reply_to_email())) {
            lines.push(format!(
                "Reply-To: {}",
                format_address(email.get_reply_to_email(), Some(email.get_reply_to_name()))
            ));
        }
        lines.push(format!("Subject: {}", encode_subject(email.get_subject())));
        for (name, value) in headers {
            if !name.is_empty() {
                lines.push(format!("{name}: {value}"));
            }
        }
        lines.push("MIME-Version: 1.0".into());

        let attachments = email.get_attachments().unwrap_or(&[]);
        let body = if attachments.is_empty() {
            body_part(email)
        } else {
            mixed_part(email, attachments)
        };
        lines.push(body);

        MimeMessage {
            raw: lines.join("\r\n"),
        }
    }

    /// PHP `Mime::addresses`.
    #[must_use]
    pub fn addresses(recipients: &[Recipient]) -> Vec<(String, String)> {
        recipients
            .iter()
            .map(|r| (r.email.clone(), r.name.clone().unwrap_or_default()))
            .collect()
    }

    /// PHP `Mime::attachments` as `(name, type, bytes)`.
    pub fn attachments(email: &Email) -> Result<Vec<(String, String, Vec<u8>)>, MessagingError> {
        let mut out = Vec::new();
        for attachment in email.get_attachments().unwrap_or(&[]) {
            out.push((
                attachment.get_name().to_string(),
                attachment.get_type().to_string(),
                read_attachment(attachment)?,
            ));
        }
        Ok(out)
    }

    /// PHP `Mime::size` - attachment weight before encoding.
    pub fn size(email: &Email) -> Result<u64, MessagingError> {
        let mut size = 0u64;
        for attachment in email.get_attachments().unwrap_or(&[]) {
            if let Some(content) = attachment.get_content() {
                size += content.len() as u64;
                continue;
            }
            let meta = std::fs::metadata(attachment.get_path()).map_err(|_| {
                MessagingError::message(format!(
                    "Failed to read attachment file: {}",
                    attachment.get_path()
                ))
            })?;
            size += meta.len();
        }
        Ok(size)
    }
}

fn read_attachment(attachment: &Attachment) -> Result<Vec<u8>, MessagingError> {
    if let Some(content) = attachment.get_content() {
        return Ok(content.to_vec());
    }
    std::fs::read(attachment.get_path()).map_err(|_| {
        MessagingError::message(format!(
            "Failed to read attachment file: {}",
            attachment.get_path()
        ))
    })
}

fn format_list(recipients: &[Recipient]) -> String {
    recipients
        .iter()
        .map(|r| format_address(&r.email, r.name.as_deref()))
        .collect::<Vec<_>>()
        .join(", ")
}

fn format_address(email: &str, name: Option<&str>) -> String {
    if php_empty(name) {
        format!("<{email}>")
    } else {
        let name = name.unwrap_or("");
        format!("\"{name}\" <{email}>")
    }
}

fn encode_subject(subject: &str) -> String {
    if subject.bytes().all(|b| b.is_ascii() && b >= 32 && b != 127) {
        subject.to_string()
    } else {
        use base64::engine::general_purpose::STANDARD;
        use base64::Engine;
        format!("=?UTF-8?B?{}?=", STANDARD.encode(subject.as_bytes()))
    }
}

fn body_part(email: &Email) -> String {
    if email.is_html() {
        alternative_part(email)
    } else {
        text_part("text/plain", email.get_content())
    }
}

fn alternative_part(email: &Email) -> String {
    let boundary = random_boundary();
    let plain = html_to_text(email.get_content());
    let mut out = String::new();
    let _ = write!(
        out,
        "Content-Type: multipart/alternative; boundary=\"{boundary}\"\r\n\r\n"
    );
    let _ = write!(out, "--{boundary}\r\n");
    out.push_str(&text_part("text/plain", &plain));
    out.push_str("\r\n");
    let _ = write!(out, "--{boundary}\r\n");
    out.push_str(&text_part("text/html", email.get_content()));
    out.push_str("\r\n");
    let _ = write!(out, "--{boundary}--");
    out
}

fn mixed_part(email: &Email, attachments: &[Attachment]) -> String {
    let boundary = random_boundary();
    let mut out = String::new();
    let _ = write!(
        out,
        "Content-Type: multipart/mixed; boundary=\"{boundary}\"\r\n\r\n"
    );
    let _ = write!(out, "--{boundary}\r\n");
    out.push_str(&body_part(email));
    out.push_str("\r\n");
    for attachment in attachments {
        let data = attachment
            .get_content()
            .map(<[u8]>::to_vec)
            .or_else(|| std::fs::read(attachment.get_path()).ok())
            .unwrap_or_default();
        let _ = write!(out, "--{boundary}\r\n");
        let _ = write!(
            out,
            "Content-Type: {}; name=\"{}\"\r\n",
            attachment.get_type(),
            attachment.get_name()
        );
        out.push_str("Content-Transfer-Encoding: base64\r\n");
        let _ = write!(
            out,
            "Content-Disposition: attachment; filename=\"{}\"\r\n\r\n",
            attachment.get_name()
        );
        out.push_str(&wrap76(&base64_std(&data)));
        out.push_str("\r\n");
    }
    let _ = write!(out, "--{boundary}--");
    out
}

fn text_part(content_type: &str, body: &str) -> String {
    format!(
        "Content-Type: {content_type}; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n{}",
        quoted_printable(body)
    )
}

fn html_to_text(html: &str) -> String {
    let without_style = strip_style(html);
    strip_tags(&without_style).trim().to_string()
}

fn strip_style(html: &str) -> String {
    let lower = html.to_ascii_lowercase();
    let mut out = String::new();
    let bytes = html.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        if lower[i..].starts_with("<style") {
            if let Some(end) = lower[i..].find("</style>") {
                i += end + "</style>".len();
                continue;
            }
        }
        out.push(bytes[i] as char);
        i += 1;
    }
    out
}

fn strip_tags(html: &str) -> String {
    let mut out = String::new();
    let mut in_tag = false;
    for c in html.chars() {
        match c {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => out.push(c),
            _ => {}
        }
    }
    out
}

fn quoted_printable(input: &str) -> String {
    let mut line = String::new();
    let mut out = String::new();
    for &byte in input.as_bytes() {
        let encoded = if byte == b'\r' {
            continue;
        } else if byte == b'\n' {
            out.push_str(&line);
            out.push_str("\r\n");
            line.clear();
            continue;
        } else if (33..=60).contains(&byte)
            || (62..=126).contains(&byte)
            || byte == b' '
            || byte == b'\t'
        {
            String::from(byte as char)
        } else {
            format!("={byte:02X}")
        };
        if line.len() + encoded.len() >= 76 {
            out.push_str(&line);
            out.push_str("=\r\n");
            line.clear();
        }
        line.push_str(&encoded);
    }
    out.push_str(&line);
    out
}

fn wrap76(input: &str) -> String {
    let mut out = String::new();
    for (i, chunk) in input.as_bytes().chunks(76).enumerate() {
        if i > 0 {
            out.push_str("\r\n");
        }
        out.push_str(std::str::from_utf8(chunk).unwrap_or(""));
    }
    out
}

fn base64_std(data: &[u8]) -> String {
    use base64::engine::general_purpose::STANDARD;
    use base64::Engine;
    STANDARD.encode(data)
}

fn random_boundary() -> String {
    let n: u64 = rand::thread_rng().gen();
    format!("----=_Part_{n:016x}")
}
