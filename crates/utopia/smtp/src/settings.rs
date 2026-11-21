//! How a client connects: encryption, certificate checks and deadlines.

use std::path::PathBuf;
use std::time::Duration;

use php_std::Value;

use crate::Error;

/// Whether and how the session is encrypted.
///
/// PHP: `Utopia\SMTP\Encryption`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Encryption {
    /// Plaintext, and never upgraded.
    None,
    /// STARTTLS when the server advertises it, plaintext when it does not.
    #[default]
    Opportunistic,
    /// STARTTLS, and a [`Error::Capability`] when the server does not offer it.
    StartTls,
    /// TLS from the first byte, as RFC 8314 asks for on port 465.
    Implicit,
}

/// How much of the certificate to believe.
///
/// PHP: `Utopia\SMTP\Verification`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Verification {
    /// A chain the trust store accepts, presented by the host we dialled.
    #[default]
    Full,
    /// Any issuer, but still the host we dialled. For a private authority or
    /// a test rig.
    SelfSigned,
    /// Nothing is checked.
    None,
}

/// How the TLS handshake is checked.
///
/// PHP: `Utopia\SMTP\Tls`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Tls {
    /// How much of the certificate to believe.
    pub verify: Verification,
    /// The name the certificate must match; the host being dialled when unset.
    pub peer_name: Option<String>,
    /// A PEM bundle of certificate authorities to trust instead of the
    /// built-in roots (Mozilla's, as `webpki-roots` ships them).
    pub ca_file: Option<PathBuf>,
    /// An OpenSSL cipher list. Kept for parity with PHP and not applied:
    /// rustls offers only its own modern suites.
    pub ciphers: Option<String>,
}

/// How long each kind of wait may last. Reaching a server and hearing back
/// from it deserve different patience; each bounds one operation, not a
/// session.
///
/// PHP: `Utopia\SMTP\Timeouts`.
///
/// ```
/// use std::time::Duration;
/// use utopia_smtp::Timeouts;
///
/// let timeouts = Timeouts::new(1.5, 90.0, 45.0)?;
/// assert_eq!(timeouts.connect(), Duration::from_millis(1500));
/// assert!(Timeouts::new(0.0, 30.0, 30.0).is_err());
/// assert!(Timeouts::default().connect() < Timeouts::default().read());
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Timeouts;
///
/// echo (new Timeouts(connect: 1.5, read: 90.0, write: 45.0))->connect, "\n"; // 1.5
/// ```
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Timeouts {
    connect: f64,
    read: f64,
    write: f64,
}

impl Default for Timeouts {
    /// 10 seconds to connect, 30 to read or write.
    fn default() -> Self {
        Timeouts { connect: 10.0, read: 30.0, write: 30.0 }
    }
}

impl Timeouts {
    /// Deadlines in seconds: opening the socket and any TLS handshake,
    /// waiting for one reply (or one block of one), handing over one command
    /// or one block of message data. Each must be finite and above zero.
    ///
    /// PHP: `new Timeouts($connect, $read, $write)`.
    pub fn new(connect: f64, read: f64, write: f64) -> Result<Timeouts, Error> {
        Ok(Timeouts {
            connect: seconds("connect", connect)?,
            read: seconds("read", read)?,
            write: seconds("write", write)?,
        })
    }

    /// Opening the socket, and any TLS handshake on it.
    ///
    /// PHP: `$timeouts->connect`.
    pub fn connect(&self) -> Duration {
        duration(self.connect)
    }

    /// Waiting for one reply, or one block of one.
    ///
    /// PHP: `$timeouts->read`.
    pub fn read(&self) -> Duration {
        duration(self.read)
    }

    /// Handing over one command, or one block of message data.
    ///
    /// PHP: `$timeouts->write`.
    pub fn write(&self) -> Duration {
        duration(self.write)
    }

    /// The three deadlines in seconds, exactly as given: connect, read, write.
    pub fn seconds(&self) -> (f64, f64, f64) {
        (self.connect, self.read, self.write)
    }
}

fn duration(seconds: f64) -> Duration {
    Duration::try_from_secs_f64(seconds).unwrap_or(Duration::MAX)
}

fn seconds(name: &str, seconds: f64) -> Result<f64, Error> {
    // NAN fails every comparison and INF passes them all.
    if !seconds.is_finite() || seconds <= 0.0 {
        let shown = php_std::serialize::var_export(&Value::<php_std::Never>::Float(seconds));
        return Err(Error::InvalidArgument(format!(
            "The {name} timeout must be a finite number greater than zero, got {}",
            String::from_utf8_lossy(&shown)
        )));
    }
    Ok(seconds)
}
