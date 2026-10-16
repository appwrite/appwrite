//! PHP `Utopia\Messaging\Adapter\Email\SMTP`.

use std::net::{SocketAddr, TcpStream, ToSocketAddrs};
use std::time::Duration;

use lettre::address::Envelope;
use lettre::transport::smtp::authentication::{Credentials, Mechanism};
use lettre::transport::smtp::client::{Tls, TlsParameters};
use lettre::{Address, SmtpTransport, Transport};

use super::mime::Mime;
use super::TYPE;
use crate::adapter::{expect_email, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::Email;
use crate::php::php_empty_str;
use crate::response::{Response, ResponseData};

/// PHP `Utopia\SMTP\Encryption`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SMTPEncryption {
    /// PHP `Encryption::None`.
    None,
    /// PHP `Encryption::Implicit` (`ssl://`).
    Implicit,
    /// PHP `Encryption::StartTls` (`tls://`).
    StartTls,
    /// PHP `Encryption::Opportunistic` (`AutoTLS`).
    Opportunistic,
}

/// One parsed host entry: `(host, port, encryption)`.
pub type SMTPHost = (String, u16, SMTPEncryption);

/// PHP `Adapter\Email\SMTP`.
pub struct SMTP {
    base: AdapterBase,
    host: String,
    port: u16,
    username: String,
    password: String,
    smtp_secure: String,
    smtp_auto_tls: bool,
    x_mailer: String,
    timeout: u64,
    keep_alive: bool,
    timelimit: u64,
    client: parking_lot::Mutex<Option<SmtpTransport>>,
}

impl std::fmt::Debug for SMTP {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SMTP")
            .field("host", &self.host)
            .field("port", &self.port)
            .finish_non_exhaustive()
    }
}

impl SMTP {
    /// PHP `__construct`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        host: impl Into<String>,
        port: u16,
        username: impl Into<String>,
        password: impl Into<String>,
        smtp_secure: impl Into<String>,
        smtp_auto_tls: bool,
        x_mailer: impl Into<String>,
        timeout: u64,
        keep_alive: bool,
        timelimit: u64,
    ) -> Result<Self, MessagingError> {
        let smtp_secure = smtp_secure.into();
        if !matches!(smtp_secure.as_str(), "" | "ssl" | "tls") {
            return Err(MessagingError::invalid_argument(
                "Invalid SMTP secure prefix. Must be \"\", \"ssl\" or \"tls\"",
            ));
        }
        Ok(Self {
            base: AdapterBase::default(),
            host: host.into(),
            port: if port == 0 { 25 } else { port },
            username: username.into(),
            password: password.into(),
            smtp_secure,
            smtp_auto_tls,
            x_mailer: x_mailer.into(),
            timeout: if timeout == 0 { 30 } else { timeout },
            keep_alive,
            timelimit: if timelimit == 0 { 30 } else { timelimit },
            client: parking_lot::Mutex::new(None),
        })
    }

    /// Convenience constructor with PHP defaults (`port=25`, empty credentials).
    pub fn with_host_port(host: impl Into<String>, port: u16) -> Result<Self, MessagingError> {
        Self::new(host, port, "", "", "", false, "", 30, false, 30)
    }

    /// PHP `disconnect()`.
    pub fn disconnect(&self) {
        *self.client.lock() = None;
    }

    /// PHP `hosts()` - public so unit tests can assert parsing without SMTP.
    #[must_use]
    pub fn hosts(&self) -> Vec<SMTPHost> {
        let mut hosts = Vec::new();
        for entry in self.host.split(';') {
            let entry = entry.trim();
            if entry.is_empty() {
                continue;
            }
            let mut encryption = self.encryption();
            let mut rest = entry.to_string();
            let lower = rest.to_ascii_lowercase();
            if let Some(stripped) = lower.strip_prefix("ssl://") {
                encryption = SMTPEncryption::Implicit;
                let prefix_len = entry.len() - stripped.len();
                rest = entry[prefix_len..].to_string();
            } else if let Some(stripped) = lower.strip_prefix("tls://") {
                encryption = SMTPEncryption::StartTls;
                let prefix_len = entry.len() - stripped.len();
                rest = entry[prefix_len..].to_string();
            }
            let (host, port) = parse_host_port(&rest, self.port);
            hosts.push((host, port, encryption));
        }
        if hosts.is_empty() {
            vec![(self.host.clone(), self.port, self.encryption())]
        } else {
            hosts
        }
    }

    fn encryption(&self) -> SMTPEncryption {
        match self.smtp_secure.as_str() {
            "ssl" => SMTPEncryption::Implicit,
            "tls" => SMTPEncryption::StartTls,
            _ if self.smtp_auto_tls => SMTPEncryption::Opportunistic,
            _ => SMTPEncryption::None,
        }
    }

    fn has_credentials(&self) -> bool {
        !php_empty_str(&self.username) && !php_empty_str(&self.password)
    }

    fn process_email(&self, message: &Email) -> Result<ResponseData, MessagingError> {
        let mut response = Response::new(TYPE);
        let recipients = envelope_recipients(message);

        let transport = match self.connect() {
            Ok(t) => t,
            Err(error) => {
                for email in &recipients {
                    response.add_result(email, error.to_string());
                }
                return Ok(response.to_array());
            }
        };

        let headers = if self.x_mailer.is_empty() {
            Vec::new()
        } else {
            vec![("X-Mailer", self.x_mailer.as_str())]
        };

        let size = Mime::size(message)?;
        if size > 25 * 1024 * 1024 {
            return Err(MessagingError::message(
                "Attachments size exceeds the maximum allowed size of 25MB",
            ));
        }

        let mime = Mime::message(
            message,
            message.get_to(),
            message.get_cc().unwrap_or(&[]),
            message.get_bcc().unwrap_or(&[]),
            &headers,
        );

        let envelope = build_envelope(message)?;
        match transport.send_raw(&envelope, mime.as_bytes()) {
            Ok(_) => {
                response.set_delivered_to(recipients.len() as i64);
                for email in &recipients {
                    response.add_result(email, "");
                }
            }
            Err(error) => {
                for email in &recipients {
                    response.add_result(email, error.to_string());
                }
            }
        }

        if !self.keep_alive {
            self.disconnect();
        }

        Ok(response.to_array())
    }

    fn connect(&self) -> Result<SmtpTransport, MessagingError> {
        if self.keep_alive {
            if let Some(existing) = self.client.lock().clone() {
                return Ok(existing);
            }
        }

        let timeout = Duration::from_secs(self.timeout);
        let mut failures = Vec::new();
        for (host, port, encryption) in self.hosts() {
            if let Err(error) = probe_host(&host, port, timeout) {
                failures.push(format!("{host}:{port} ({error})"));
                continue;
            }
            match build_transport(
                &host,
                port,
                encryption,
                self,
                timeout,
                Duration::from_secs(self.timelimit),
            ) {
                Ok(transport) => {
                    if self.keep_alive {
                        *self.client.lock() = Some(transport.clone());
                    }
                    return Ok(transport);
                }
                Err(error) => {
                    failures.push(format!("{host}:{port} ({error})"));
                }
            }
        }
        Err(MessagingError::message(format!(
            "No SMTP host answered: {}",
            failures.join("; ")
        )))
    }
}

impl Adapter for SMTP {
    fn get_name(&self) -> &'static str {
        "SMTP"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::Email
    }
    fn get_max_messages_per_request(&self) -> usize {
        1000
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(
            self.process_email(expect_email(message)?)?,
        ))
    }
}

fn parse_host_port(entry: &str, default_port: u16) -> (String, u16) {
    // PHP: `/^(\[[^\]]+\]|[^:]+):(\d+)$/`
    if let Some(caps) = split_host_port(entry) {
        return caps;
    }
    (entry.to_string(), default_port)
}

fn split_host_port(entry: &str) -> Option<(String, u16)> {
    if let Some(rest) = entry.strip_prefix('[') {
        let close = rest.find(']')?;
        let host = format!("[{}]", &rest[..close]);
        let after = &rest[close + 1..];
        if let Some(port_str) = after.strip_prefix(':') {
            let port: u16 = port_str.parse().ok()?;
            if after[1..].chars().all(|c| c.is_ascii_digit()) {
                return Some((host, port));
            }
        }
        return None;
    }
    if !entry.contains(':') {
        return None;
    }
    // `[^:]+):(\d+)$` - host with no colon, then :port
    let (host, port) = entry.rsplit_once(':')?;
    if host.contains(':') {
        return None;
    }
    let port: u16 = port.parse().ok()?;
    Some((host.to_string(), port))
}

fn probe_host(host: &str, port: u16, timeout: Duration) -> Result<(), String> {
    let target = format!("{host}:{port}");
    let addrs: Vec<SocketAddr> = target
        .to_socket_addrs()
        .map_err(|e| e.to_string())?
        .collect();
    if addrs.is_empty() {
        return Err("name resolution failed".into());
    }
    TcpStream::connect_timeout(&addrs[0], timeout).map_err(|e| e.to_string())?;
    Ok(())
}

fn build_transport(
    host: &str,
    port: u16,
    encryption: SMTPEncryption,
    smtp: &SMTP,
    timeout: Duration,
    read_timeout: Duration,
) -> Result<SmtpTransport, MessagingError> {
    let host_clean = host.trim_matches(['[', ']']);
    let mut builder = match encryption {
        SMTPEncryption::None => SmtpTransport::builder_dangerous(host_clean).port(port),
        SMTPEncryption::Implicit => {
            let tls = TlsParameters::new(host_clean.to_string())
                .map_err(|e| MessagingError::message(e.to_string()))?;
            SmtpTransport::relay(host_clean)
                .map_err(|e| MessagingError::message(e.to_string()))?
                .port(port)
                .tls(Tls::Wrapper(tls))
        }
        SMTPEncryption::StartTls => {
            let tls = TlsParameters::new(host_clean.to_string())
                .map_err(|e| MessagingError::message(e.to_string()))?;
            SmtpTransport::relay(host_clean)
                .map_err(|e| MessagingError::message(e.to_string()))?
                .port(port)
                .tls(Tls::Required(tls))
        }
        SMTPEncryption::Opportunistic => {
            let tls = TlsParameters::new(host_clean.to_string())
                .map_err(|e| MessagingError::message(e.to_string()))?;
            SmtpTransport::builder_dangerous(host_clean)
                .port(port)
                .tls(Tls::Opportunistic(tls))
        }
    };
    let timeout = timeout.max(read_timeout);
    builder = builder.timeout(Some(timeout));
    if smtp.has_credentials() {
        builder = builder
            .credentials(Credentials::new(
                smtp.username.clone(),
                smtp.password.clone(),
            ))
            .authentication(vec![Mechanism::Plain, Mechanism::Login]);
    }
    Ok(builder.build())
}

fn envelope_recipients(message: &Email) -> Vec<String> {
    let mut seen = Vec::new();
    for list in [
        message.get_to(),
        message.get_cc().unwrap_or(&[]),
        message.get_bcc().unwrap_or(&[]),
    ] {
        for r in list {
            if !seen.contains(&r.email) {
                seen.push(r.email.clone());
            }
        }
    }
    seen
}

fn build_envelope(message: &Email) -> Result<Envelope, MessagingError> {
    let from: Address = message
        .get_from_email()
        .parse()
        .map_err(|e: lettre::address::AddressError| MessagingError::message(e.to_string()))?;
    let to: Vec<Address> = envelope_recipients(message)
        .iter()
        .filter_map(|e| e.parse().ok())
        .collect();
    Envelope::new(Some(from), to).map_err(|e| MessagingError::message(e.to_string()))
}
