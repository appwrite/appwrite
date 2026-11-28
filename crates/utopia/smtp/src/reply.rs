//! Server replies and the class each belongs to.

use std::fmt;

use crate::Error;

/// The four reply classes of RFC 5321 section 4.2.1. A reply is exactly one
/// of these, and 3yz is not a failure.
///
/// PHP: `Utopia\SMTP\Outcome`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Outcome {
    /// 2yz: the command worked.
    Success,
    /// 3yz: the command was understood and the server is waiting for more,
    /// like the 354 before message data.
    Intermediate,
    /// 4yz: it failed, but the same command may work later.
    Transient,
    /// 5yz: it failed, and sending it again changes nothing.
    Permanent,
}

impl Outcome {
    /// The class of a reply code, or a [`Error::Protocol`] for a code outside
    /// the four classes.
    ///
    /// PHP: `Outcome::fromCode()`.
    ///
    /// ```
    /// use utopia_smtp::Outcome;
    ///
    /// assert_eq!(Outcome::from_code(354)?, Outcome::Intermediate);
    /// assert!(Outcome::from_code(600).is_err());
    /// # Ok::<(), utopia_smtp::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\SMTP\Outcome;
    ///
    /// var_dump(Outcome::fromCode(354) === Outcome::Intermediate); // bool(true)
    /// ```
    pub fn from_code(code: u16) -> Result<Outcome, Error> {
        match code / 100 {
            2 => Ok(Outcome::Success),
            3 => Ok(Outcome::Intermediate),
            4 => Ok(Outcome::Transient),
            5 => Ok(Outcome::Permanent),
            _ => Err(Error::Protocol(format!("Not an SMTP reply code: {code}"))),
        }
    }

    /// The case name, as PHP spells it (`Success`, `Intermediate`, ...).
    pub fn name(self) -> &'static str {
        match self {
            Outcome::Success => "Success",
            Outcome::Intermediate => "Intermediate",
            Outcome::Transient => "Transient",
            Outcome::Permanent => "Permanent",
        }
    }
}

/// One server response: a three digit code, the text of each line, and the
/// enhanced status code of RFC 3463 when the server sends one.
///
/// PHP: `Utopia\SMTP\Reply`.
///
/// ```
/// use utopia_smtp::{Outcome, Reply};
///
/// let reply = Reply::new(250, ["2.1.5 Recipient ok"])?;
/// assert_eq!(reply.status(), Some("2.1.5"));
/// assert_eq!(reply.outcome(), Outcome::Success);
/// assert_eq!(reply.to_string(), "250 2.1.5 Recipient ok");
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Reply;
///
/// $reply = new Reply(250, ['2.1.5 Recipient ok']);
/// echo $reply->status, "\n"; // 2.1.5
/// echo $reply, "\n"; // 250 2.1.5 Recipient ok
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Reply {
    code: u16,
    lines: Vec<String>,
    outcome: Outcome,
    status: Option<usize>,
}

impl Reply {
    /// A reply from its code and the text of each line (code and separator
    /// stripped). A code outside the four classes is a [`Error::Protocol`].
    ///
    /// PHP: `new Reply($code, $lines)`.
    pub fn new<I, S>(code: u16, lines: I) -> Result<Reply, Error>
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        let outcome = Outcome::from_code(code)?;
        let lines: Vec<String> = lines.into_iter().map(Into::into).collect();
        let status = lines.first().and_then(|first| status_len(first.as_bytes()));
        Ok(Reply { code, lines, outcome, status })
    }

    /// The three digit code.
    ///
    /// PHP: `$reply->code`.
    pub fn code(&self) -> u16 {
        self.code
    }

    /// The text of each line.
    ///
    /// PHP: `$reply->lines`.
    pub fn lines(&self) -> &[String] {
        &self.lines
    }

    /// The reply class.
    ///
    /// PHP: `$reply->outcome`.
    pub fn outcome(&self) -> Outcome {
        self.outcome
    }

    /// The enhanced status code (`2.1.5`) opening the first line, if any.
    ///
    /// PHP: `$reply->status`.
    pub fn status(&self) -> Option<&str> {
        self.status.map(|len| &self.lines[0][..len])
    }

    /// The lines joined with spaces.
    ///
    /// PHP: `text()`.
    pub fn text(&self) -> String {
        self.lines.join(" ")
    }
}

impl fmt::Display for Reply {
    /// The code, a space and [`Reply::text`].
    ///
    /// PHP: `__toString()`.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{} ", self.code)?;
        for (i, line) in self.lines.iter().enumerate() {
            if i > 0 {
                f.write_str(" ")?;
            }
            f.write_str(line)?;
        }
        Ok(())
    }
}

/// The length of `[245].\d{1,3}.\d{1,3}` opening `line` when a space, the
/// end, or a final line feed follows it.
fn status_len(line: &[u8]) -> Option<usize> {
    if !matches!(line.first(), Some(b'2' | b'4' | b'5')) || line.get(1) != Some(&b'.') {
        return None;
    }
    let digits = |from: usize| line[from.min(line.len())..].iter().take_while(|b| b.is_ascii_digit()).count();
    let first = digits(2);
    if !(1..=3).contains(&first) || line.get(2 + first) != Some(&b'.') {
        return None;
    }
    let start = 3 + first;
    let second = digits(start);
    if !(1..=3).contains(&second) {
        return None;
    }
    let end = start + second;
    match &line[end..] {
        [] | [b' ', ..] | [b'\n'] => Some(end),
        _ => None,
    }
}
