//! Where the bytes go.

use crate::Error;
use crate::Message;
use crate::address::spans_lines;

/// Where the bytes go, which is not the same question as what the headers
/// say: the reverse-path (`MAIL FROM`) and the forward-paths (`RCPT TO`).
///
/// PHP: `Utopia\SMTP\Envelope`.
///
/// ```
/// use utopia_smtp::Envelope;
///
/// let bounce = Envelope::new("", ["john@example.test"])?;
/// assert_eq!(bounce.sender(), "");
/// assert!(Envelope::new("jäne@example.test", ["john@example.test"])?.is_international());
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Envelope;
///
/// var_dump((new Envelope('jäne@example.test', ['john@example.test']))->isInternational()); // bool(true)
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Envelope {
    sender: String,
    recipients: Vec<String>,
    utf8: bool,
}

impl Envelope {
    /// The sender (empty for a bounce) and at least one recipient. Refuses a
    /// path holding CR, LF or NUL.
    ///
    /// PHP: `new Envelope($sender, $recipients)`.
    pub fn new<I, S>(sender: impl Into<String>, recipients: I) -> Result<Envelope, Error>
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        let sender = sender.into();
        let recipients: Vec<String> = recipients.into_iter().map(Into::into).collect();
        if recipients.is_empty() {
            return Err(Error::InvalidArgument("An envelope needs at least one recipient".into()));
        }
        if spans_lines(&sender) || recipients.iter().any(|r| spans_lines(r)) {
            return Err(Error::InvalidArgument("A path must not span lines".into()));
        }
        Ok(Envelope { sender, recipients, utf8: false })
    }

    /// Asks for SMTPUTF8 even when every path is ASCII, for a message whose
    /// headers still need it.
    ///
    /// PHP: the `$utf8` argument.
    pub fn with_utf8(mut self) -> Envelope {
        self.utf8 = true;
        self
    }

    /// The envelope of a message: its sender, and every `To`, `Cc` and blind
    /// recipient once, in that order.
    ///
    /// PHP: `Envelope::fromMessage()`.
    pub fn from_message(message: &Message) -> Envelope {
        let mut recipients: Vec<String> = Vec::new();
        for address in message.to().iter().chain(message.cc()).chain(message.bcc()) {
            if !recipients.iter().any(|r| r == address.email()) {
                recipients.push(address.email().to_owned());
            }
        }
        Envelope { sender: message.from().email().to_owned(), recipients, utf8: message.is_international() }
    }

    /// The reverse-path.
    ///
    /// PHP: `$envelope->sender`.
    pub fn sender(&self) -> &str {
        &self.sender
    }

    /// The forward-paths.
    ///
    /// PHP: `$envelope->recipients`.
    pub fn recipients(&self) -> &[String] {
        &self.recipients
    }

    /// Whether this transaction needs the SMTPUTF8 extension of RFC 6531.
    ///
    /// PHP: `isInternational()`.
    pub fn is_international(&self) -> bool {
        self.utf8 || !self.sender.is_ascii() || self.recipients.iter().any(|r| !r.is_ascii())
    }
}
