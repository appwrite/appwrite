//! Header fields, from a name and a value to bytes a server will accept:
//! encoded words for anything outside ASCII, split so none exceeds its limit,
//! and folding so no line runs long.

use crate::Error;

/// RFC 5322 section 2.1.1: the length a line should keep to.
const SOFT: usize = 78;

/// RFC 5322 section 2.1.1: the length a line must not exceed.
const HARD: usize = 998;

/// RFC 2047 section 2 caps an encoded word at 75 characters. `=?UTF-8?B?` and
/// `?=` spend 12 of them, so 45 bytes of text is the most that fits.
const PAYLOAD: usize = 45;

/// One field, folded and terminated by CRLF. A value outside ASCII becomes
/// RFC 2047 encoded words. The value must already be free of line endings.
///
/// A space-free run longer than 998 octets cannot be folded and is an
/// [`Error::InvalidArgument`].
///
/// PHP: `Header::line()`.
///
/// ```
/// use utopia_smtp::mime::header;
///
/// assert_eq!(header::line("Subject", "Hellö")?, "Subject: =?UTF-8?B?SGVsbMO2?=\r\n");
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Mime\Header;
///
/// echo Header::line('Subject', 'Hellö'); // Subject: =?UTF-8?B?SGVsbMO2?=
/// ```
pub fn line(name: &str, value: &str) -> Result<String, Error> {
    let mut out = String::with_capacity(name.len() + value.len() + 8);
    line_into(name, value, &mut out)?;
    Ok(out)
}

/// [`line`], appended to `out`.
pub(crate) fn line_into(name: &str, value: &str, out: &mut String) -> Result<(), Error> {
    let start = out.len();
    if value.is_ascii() {
        fold(name, &[name, ": ", value], out).inspect_err(|_| out.truncate(start))?;
    } else {
        let encoded = encode(value);
        fold(name, &[name, ": ", &encoded], out).inspect_err(|_| out.truncate(start))?;
    }
    out.push_str("\r\n");
    Ok(())
}

/// A display name for use inside a structured value: quoted (with `"` and
/// `\` escaped) when ASCII, encoded words otherwise.
///
/// PHP: `Header::phrase()`.
///
/// ```
/// use utopia_smtp::mime::header;
///
/// assert_eq!(header::phrase("Jane Doe"), "\"Jane Doe\"");
/// assert_eq!(header::phrase("Jäne"), "=?UTF-8?B?SsOkbmU=?=");
/// ```
///
/// ```php
/// use Utopia\SMTP\Mime\Header;
///
/// echo Header::phrase('Jäne'), "\n"; // =?UTF-8?B?SsOkbmU=?=
/// ```
pub fn phrase(value: &str) -> String {
    if !value.is_ascii() {
        return encode(value);
    }
    let mut out = String::with_capacity(value.len() + 2);
    out.push('"');
    for c in value.chars() {
        if c == '"' || c == '\\' {
            out.push('\\');
        }
        out.push(c);
    }
    out.push('"');
    out
}

/// Non-ASCII text as encoded words, split on character boundaries so each
/// decodes on its own, separated by spaces.
fn encode(text: &str) -> String {
    let mut out = String::with_capacity(text.len() * 2);
    let mut chunk_start = 0;
    let mut chunk_end = 0;
    for (i, c) in text.char_indices() {
        let len = c.len_utf8();
        if chunk_end > chunk_start && chunk_end - chunk_start + len > PAYLOAD {
            word(&text[chunk_start..chunk_end], &mut out);
            chunk_start = i;
        }
        chunk_end = i + len;
    }
    if chunk_end > chunk_start {
        word(&text[chunk_start..chunk_end], &mut out);
    }
    out
}

fn word(text: &str, out: &mut String) {
    if !out.is_empty() {
        out.push(' ');
    }
    out.push_str("=?UTF-8?B?");
    out.push_str(&php_std::encoding::base64_encode(text.as_bytes()));
    out.push_str("?=");
}

/// Breaks the line at spaces, which unfolding puts back as they were.
/// `parts` concatenated are the line.
fn fold(name: &str, parts: &[&str], out: &mut String) -> Result<(), Error> {
    // `current` is the unfinished output line, kept as a range of `out`.
    let mut current = out.len();
    let mut empty = true;
    let line: String;
    let line = if parts.len() == 1 {
        parts[0]
    } else {
        line = parts.concat();
        &line
    };
    for word in line.split(' ') {
        if word.len() > HARD {
            return Err(Error::InvalidArgument(format!(
                "The {name} header has an unbreakable run of {} octets",
                word.len()
            )));
        }
        let candidate = if empty { word.len() } else { out.len() - current + 1 + word.len() };
        if !empty && candidate > SOFT {
            out.push_str("\r\n ");
            current = out.len();
            out.push_str(word);
            empty = word.is_empty();
            continue;
        }
        if !empty {
            out.push(' ');
        }
        out.push_str(word);
        empty = out.len() == current;
    }
    Ok(())
}
