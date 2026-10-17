//! Mailboxes.

use std::fmt;

use php_std::filter::{FILTER_VALIDATE_EMAIL, Options, Value, filter_var};

use crate::Error;
use crate::mime::header;

/// RFC 5321 section 4.5.3.1.1.
const MAX_LOCAL: usize = 64;

/// RFC 5321 section 4.5.3.1.2.
const MAX_DOMAIN: usize = 255;

/// A mailbox: an address with an optional display name.
///
/// ASCII addresses are checked as PHP's `filter_var(FILTER_VALIDATE_EMAIL)`
/// checks them; non-ASCII local parts (RFC 6531) only against the length
/// limits of RFC 5321.
///
/// PHP: `Utopia\SMTP\Address`.
///
/// ```
/// use utopia_smtp::Address;
///
/// let jane = Address::new("jane@example.test", "Jane \"JD\" Doe")?;
/// assert_eq!(jane.to_string(), r#""Jane \"JD\" Doe" <jane@example.test>"#);
/// assert!(!jane.is_international());
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Address;
///
/// echo new Address('jane@example.test', 'Jane "JD" Doe'), "\n"; // "Jane \"JD\" Doe" <jane@example.test>
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Address {
    email: String,
    name: String,
}

impl Address {
    /// A mailbox; `name` may be empty. Refuses line breaks and NUL bytes, an
    /// address without a local part or domain, one over the RFC 5321 limits,
    /// and an ASCII address PHP's email filter refuses.
    ///
    /// PHP: `new Address($email, $name)`.
    pub fn new(email: impl Into<String>, name: impl Into<String>) -> Result<Address, Error> {
        let email = email.into();
        let name = name.into();
        if spans_lines(&email) || spans_lines(&name) {
            return Err(Error::InvalidArgument("An address must not span lines".into()));
        }
        let at = match email.rfind('@') {
            Some(at) if at != 0 && at != email.len() - 1 => at,
            _ => return Err(Error::InvalidArgument(format!("Not an address: {email}"))),
        };
        if at > MAX_LOCAL || email.len() - at - 1 > MAX_DOMAIN {
            return Err(Error::InvalidArgument(format!("Address is over the length limits of RFC 5321: {email}")));
        }
        if email.is_ascii() && !valid(&email) {
            return Err(Error::InvalidArgument(format!("Not an address: {email}")));
        }
        Ok(Address { email, name })
    }

    /// The address itself.
    ///
    /// PHP: `$address->email`.
    pub fn email(&self) -> &str {
        &self.email
    }

    /// The display name, possibly empty.
    ///
    /// PHP: `$address->name`.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// Whether sending this address needs the SMTPUTF8 extension of RFC 6531.
    ///
    /// PHP: `isInternational()`.
    pub fn is_international(&self) -> bool {
        !self.email.is_ascii()
    }
}

impl fmt::Display for Address {
    /// The header form, `<email>` or `phrase <email>` with the display name
    /// quoted or encoded.
    ///
    /// PHP: `__toString()`.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        if self.name.is_empty() {
            return write!(f, "<{}>", self.email);
        }
        write!(f, "{} <{}>", header::phrase(&self.name), self.email)
    }
}

/// Whether `value` holds CR, LF or NUL.
pub(crate) fn spans_lines(value: &str) -> bool {
    value.bytes().any(|b| matches!(b, b'\r' | b'\n' | 0))
}

fn valid(email: &str) -> bool {
    filter_var(&Value::Str(email.as_bytes().to_vec()), FILTER_VALIDATE_EMAIL, &Options::Flags(0))
        .is_ok_and(|f| !matches!(f.value, Value::Bool(false)))
}
