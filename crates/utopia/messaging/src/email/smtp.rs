use std::time::Duration;

use indexmap::IndexMap;
use utopia_async::sync::Mutex;
use utopia_smtp::auth::{Login, Plain};
use utopia_smtp::{Client, Encryption, Outcome, TcpTransport, Timeouts, Tls};

use super::{MAX_ATTACHMENT_BYTES, TYPE, email, mime};
use crate::adapter::{Adapter, Base, configure};
use crate::error::Error;
use crate::http::{BoxFuture, Http};
use crate::message::{Email, Message, MessageType};
use crate::response::{Response, Sent};

/// A server to try: host (an address literal stays bracketed), port and
/// encryption.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Host {
    /// The host name or address.
    pub host: String,
    /// The port.
    pub port: i64,
    /// How the session is encrypted.
    pub encryption: Encryption,
}

/// Every host in the list failed before a session was open: the message
/// names each host's failure, and `permanent` says whether trying the list
/// again can go differently (only when every host refused for good). Its
/// `code` is the first reply code a host refused with, or 0 when none replied.
///
/// PHP: `Utopia\Messaging\Adapter\Email\SMTP\NoHostAnswered`, a
/// `Utopia\SMTP\Exception\ConnectionException`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct NoHostAnswered {
    /// The message.
    pub message: String,
    /// Whether every host refused for good.
    pub permanent: bool,
    /// The first reply code a host refused with, or 0 when none replied.
    ///
    /// PHP: `getCode()`.
    pub code: i64,
}

impl NoHostAnswered {
    /// The PHP class.
    pub const CLASS: &'static str = "Utopia\\Messaging\\Adapter\\Email\\SMTP\\NoHostAnswered";

    /// PHP: `new NoHostAnswered($message, $permanent, $code)`.
    pub fn new(message: impl Into<String>, permanent: bool, code: i64) -> NoHostAnswered {
        NoHostAnswered { message: message.into(), permanent, code }
    }
}

/// Why a session could not be opened, or a send failed on the wire.
enum Failure {
    Smtp(utopia_smtp::Error),
    NoHost(NoHostAnswered),
}

impl Failure {
    fn message(&self) -> String {
        match self {
            Failure::Smtp(e) => e.to_string(),
            Failure::NoHost(e) => e.message.clone(),
        }
    }

    /// Whether the server said, with a 5xx reply, that sending again cannot
    /// change its answer (a capability it lacks is final too).
    fn permanent(&self) -> bool {
        match self {
            Failure::NoHost(e) => e.permanent,
            Failure::Smtp(utopia_smtp::Error::Transaction(refusal)) => refusal.is_permanent(),
            Failure::Smtp(utopia_smtp::Error::Capability(_)) => true,
            Failure::Smtp(utopia_smtp::Error::Authentication { code, .. }) => {
                *code != 0 && Outcome::from_code(*code).is_ok_and(|o| o == Outcome::Permanent)
            }
            Failure::Smtp(_) => false,
        }
    }

    /// The reply code the server refused with, or 0 when no reply stands
    /// behind the failure.
    fn code(&self) -> i64 {
        match self {
            Failure::NoHost(e) => e.code,
            Failure::Smtp(e) => i64::from(e.code()),
        }
    }
}

/// An SMTP relay: one or several hosts tried in order, with STARTTLS or
/// implicit TLS, PLAIN or LOGIN authentication, and optionally a session
/// kept open across sends. Every recipient gets the server's own answer.
///
/// PHP: `Utopia\Messaging\Adapter\Email\SMTP`.
pub struct Smtp {
    name: &'static str,
    host: String,
    port: i64,
    username: String,
    password: String,
    secure: String,
    auto_tls: bool,
    x_mailer: String,
    timeout: i64,
    keep_alive: bool,
    time_limit: i64,
    ping_threshold: i64,
    restart_threshold: i64,
    addresses: IndexMap<String, String>,
    client: Mutex<Option<Client<TcpTransport>>>,
    base: Base,
}

impl std::fmt::Debug for Smtp {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Smtp").field("host", &self.host).field("port", &self.port).finish_non_exhaustive()
    }
}

impl Smtp {
    /// Hosts separated by `;`, each optionally with an encryption prefix
    /// (`tls://`, `ssl://`) and a port (`smtp.example.com:587`,
    /// `[::1]:587`), the others on `port`.
    ///
    /// PHP: `new SMTP($host, $port)`.
    pub fn new(host: impl Into<String>, port: i64) -> Smtp {
        Smtp {
            name: "SMTP",
            host: host.into(),
            port,
            username: String::new(),
            password: String::new(),
            secure: String::new(),
            auto_tls: false,
            x_mailer: String::new(),
            timeout: 30,
            keep_alive: false,
            time_limit: 30,
            ping_threshold: 30,
            restart_threshold: 100,
            addresses: IndexMap::new(),
            client: Mutex::new(None),
            base: Base::new(Http::new("SMTP")),
        }
    }

    /// Authenticates with PLAIN or LOGIN (neither when either is empty or
    /// `"0"`).
    pub fn with_credentials(mut self, username: impl Into<String>, password: impl Into<String>) -> Smtp {
        self.username = username.into();
        self.password = password.into();
        self
    }

    /// The encryption of hosts without a prefix: `ssl` (implicit TLS),
    /// `tls` (STARTTLS) or `` (see [`Smtp::with_auto_tls`]).
    ///
    /// Fails with [`Error::InvalidArgument`] for anything else.
    pub fn with_secure(mut self, secure: impl Into<String>) -> Result<Smtp, Error> {
        let secure = secure.into();
        if !matches!(secure.as_str(), "" | "ssl" | "tls") {
            return Err(Error::InvalidArgument("Invalid SMTP secure prefix. Must be \"\", \"ssl\" or \"tls\"".into()));
        }
        self.secure = secure;
        Ok(self)
    }

    /// Without a prefix, whether an offered STARTTLS is taken.
    pub fn with_auto_tls(mut self, auto_tls: bool) -> Smtp {
        self.auto_tls = auto_tls;
        self
    }

    /// The `X-Mailer` header (none when empty).
    pub fn with_x_mailer(mut self, x_mailer: impl Into<String>) -> Smtp {
        self.x_mailer = x_mailer.into();
        self
    }

    /// The connect timeout, in seconds.
    pub fn with_timeout(mut self, seconds: i64) -> Smtp {
        self.timeout = seconds;
        self
    }

    /// Whether the session is kept open across sends.
    pub fn with_keep_alive(mut self, keep_alive: bool) -> Smtp {
        self.keep_alive = keep_alive;
        self
    }

    /// The read and write timeout of each command, in seconds.
    pub fn with_time_limit(mut self, seconds: i64) -> Smtp {
        self.time_limit = seconds;
        self
    }

    /// Seconds a kept session may sit idle before it is probed with NOOP
    /// ahead of the next message (0 probes before every reuse).
    pub fn with_ping_threshold(mut self, seconds: i64) -> Smtp {
        self.ping_threshold = seconds;
        self
    }

    /// Messages a kept session carries before it is replaced (0 never).
    pub fn with_restart_threshold(mut self, messages: i64) -> Smtp {
        self.restart_threshold = messages;
        self
    }

    /// Host => address to dial it on, as each host appears in the host
    /// string (`smtp1.example.com` => `203.0.113.10`, `[2001:db8::1]` for
    /// IPv6), so the connection lands on an address the caller checked. The
    /// certificate is still verified against the host. A host not listed is
    /// dialled by name.
    pub fn with_addresses(mut self, addresses: IndexMap<String, String>) -> Smtp {
        self.addresses = addresses;
        self
    }

    /// The servers to try, in order.
    ///
    /// PHP: the private `hosts()`.
    pub fn hosts(&self) -> Vec<Host> {
        let mut hosts = Vec::new();
        for entry in self.host.split(';') {
            let mut entry = php_std::string::trim(entry.as_bytes(), b" \t\n\r\0\x0B");
            if entry.is_empty() {
                continue;
            }
            let mut encryption = self.encryption();
            if entry.len() >= 6 && entry[3..6] == *b"://" {
                let scheme = &entry[..3];
                if scheme.eq_ignore_ascii_case(b"ssl") {
                    encryption = Encryption::Implicit;
                    entry = &entry[6..];
                } else if scheme.eq_ignore_ascii_case(b"tls") {
                    encryption = Encryption::StartTls;
                    entry = &entry[6..];
                }
            }
            let mut port = self.port;
            if let Some((host, digits)) = split_port(entry) {
                port = php_std::value::numeric_str(&String::from_utf8_lossy(digits))
                    .map(|n| match n {
                        php_std::value::Number::Int(i) => i,
                        php_std::value::Number::Float(_) => i64::MAX,
                    })
                    .unwrap_or(0);
                entry = host;
            }
            hosts.push(Host { host: String::from_utf8_lossy(entry).into_owned(), port, encryption });
        }
        if hosts.is_empty() {
            return vec![Host { host: self.host.clone(), port: self.port, encryption: self.encryption() }];
        }
        hosts
    }

    fn encryption(&self) -> Encryption {
        match self.secure.as_str() {
            "ssl" => Encryption::Implicit,
            "tls" => Encryption::StartTls,
            _ if self.auto_tls => Encryption::Opportunistic,
            _ => Encryption::None,
        }
    }

    /// Closes a kept session. Teardown only: closing under a send in
    /// flight desynchronises it.
    ///
    /// PHP: `disconnect()`.
    pub async fn disconnect(&self) {
        if let Some(mut client) = self.client.lock().await.take() {
            client.close().await;
        }
    }

    fn client_for(&self, host: &Host) -> Result<Client<TcpTransport>, Error> {
        let timeouts = Timeouts::new(self.timeout as f64, self.time_limit as f64, self.time_limit as f64)?;
        let port = u16::try_from(host.port).unwrap_or(0);
        let mut domain = php_std::system::gethostname();
        if domain.is_empty() {
            domain = "localhost".into();
        }
        let mut client = Client::new(match self.addresses.get(&host.host) {
            Some(address) => TcpTransport::new(
                address.clone(),
                port,
                Tls { peer_name: Some(host.host.trim_matches(['[', ']']).to_owned()), ..Tls::default() },
            ),
            None => TcpTransport::new(host.host.clone(), port, Tls::default()),
        })
        .with_domain(domain)
        .with_encryption(host.encryption)
        .with_timeouts(timeouts);
        let absent = |s: &str| matches!(s, "" | "0");
        if !absent(&self.username) && !absent(&self.password) {
            client = client
                .with_authenticator(Plain::new(self.username.clone(), self.password.clone()))
                .with_authenticator(Login::new(self.username.clone(), self.password.clone()));
        }
        Ok(client)
    }

    /// The first host that answers, tried in order.
    async fn dial(&self) -> Result<Result<Client<TcpTransport>, Failure>, Error> {
        let mut failures = Vec::new();
        let mut permanent = true;
        let mut code = 0;
        for host in self.hosts() {
            // A host the caller did not check is refused rather than dialled by name.
            if !self.addresses.is_empty() && !self.addresses.contains_key(&host.host) {
                failures.push(format!("{}:{} (no checked address)", host.host, host.port));
                continue;
            }
            let mut client = self.client_for(&host)?;
            match client.capabilities().await {
                Ok(_) => return Ok(Ok(client)),
                Err(e) if e.is_smtp() => {
                    let failure = Failure::Smtp(e);
                    failures.push(format!("{}:{} ({})", host.host, host.port, failure.message()));
                    permanent = permanent && failure.permanent();
                    if code == 0 {
                        code = failure.code();
                    }
                }
                Err(e) => return Err(e.into()),
            }
        }
        let message = format!("No SMTP host answered: {}", failures.join("; "));
        Ok(Err(Failure::NoHost(NoHostAnswered::new(message, !failures.is_empty() && permanent, code))))
    }

    async fn reusable(&self, client: &mut Client<TcpTransport>) -> Result<bool, Error> {
        if self.restart_threshold > 0 && client.transactions() as i64 >= self.restart_threshold {
            return Ok(false);
        }
        let idle = client.idle().map_or(f64::INFINITY, |d: Duration| d.as_secs_f64());
        if idle <= self.ping_threshold as f64 {
            return Ok(true);
        }
        Ok(client.ping().await?)
    }

    fn recipients(email: &Email) -> Vec<String> {
        let mut out: Vec<String> = Vec::new();
        let all = email.to().iter().chain(email.cc().unwrap_or_default()).chain(email.bcc().unwrap_or_default());
        for recipient in all {
            if !out.contains(&recipient.email) {
                out.push(recipient.email.clone());
            }
        }
        out
    }

    fn build(&self, email: &Email) -> Result<utopia_smtp::Message, Error> {
        let mut headers = Vec::new();
        if !self.x_mailer.is_empty() {
            headers.push(("X-Mailer".to_owned(), self.x_mailer.clone()));
        }
        if mime::size(email)? > MAX_ATTACHMENT_BYTES {
            return Err(Error::Exception("Attachments size exceeds the maximum allowed size of 25MB".into()));
        }
        mime::message(email, email.to(), email.cc().unwrap_or_default(), email.bcc().unwrap_or_default(), &headers)
    }

    async fn send_email(&self, message: &Message) -> Result<Sent, Error> {
        let (sent, stale) = self.deliver(message, true).await?;
        // The kept socket failed before this message was submitted. The dot
        // was not written, so this dials once more. A second failure stands.
        if stale {
            return Ok(self.deliver(message, false).await?.0);
        }
        Ok(sent)
    }

    /// Sends one message, and tells whether the kept session it was given
    /// died under it before the message was submitted (only when `retry`).
    ///
    /// PHP: `deliver($message, $retry)`.
    async fn deliver(&self, message: &Message, retry: bool) -> Result<(Sent, bool), Error> {
        let email = email(message);
        let mut response = Response::new(TYPE);
        let recipients = Smtp::recipients(email);

        let mut kept = self.client.lock().await;
        // A fresh dial is not a reuse: a failure there is the server just
        // reached, and sending the message again would not be the stale socket.
        let mut reused = None;
        if self.keep_alive
            && let Some(mut client) = kept.take()
        {
            if self.reusable(&mut client).await? {
                reused = Some(client);
            } else {
                client.close().await;
            }
        }
        let was_reused = reused.is_some();
        let opened = match reused {
            Some(client) => Ok(client),
            None => self.dial().await?,
        };
        let mut client = match opened {
            Ok(client) => client,
            Err(failure) => {
                for recipient in &recipients {
                    response.add_result(recipient, failure.message(), failure.permanent(), failure.code());
                }
                return Ok((Sent::Response(response), false));
            }
        };

        let mut keep = self.keep_alive;
        let mut stale = false;
        let outcome = match self.build(email) {
            Ok(built) => Ok(client.send(&built).await),
            Err(e) => Err(e),
        };
        let result = match outcome {
            Err(e) => Err(e),
            Ok(Ok(delivery)) => {
                response.set_delivered_to(delivery.accepted.len() as i64);
                for accepted in &delivery.accepted {
                    response.add_success(accepted);
                }
                for (address, reply) in &delivery.rejected {
                    response.add_result(
                        address,
                        reply.to_string(),
                        reply.outcome() == Outcome::Permanent,
                        i64::from(reply.code()),
                    );
                }
                Ok(())
            }
            Ok(Err(utopia_smtp::Error::Transaction(refusal))) => {
                for recipient in &recipients {
                    let reply = refusal.rejected.get(recipient).unwrap_or(&refusal.reply);
                    response.add_result(
                        recipient,
                        reply.to_string(),
                        reply.outcome() == Outcome::Permanent,
                        i64::from(reply.code()),
                    );
                }
                // A 421 during RCPT ends the session with the transaction.
                // It is also how a kept socket answers the first command
                // after the server has closed it, before the message is
                // submitted.
                keep = keep && client.idle().is_some();
                stale = retry && was_reused && refusal.reply.code() == 421;
                Ok(())
            }
            Ok(Err(e)) if e.is_smtp() => {
                // A kept socket that died before the dot. An unconfirmed
                // send means the dot was written and the server may have
                // the message.
                stale = retry && was_reused && (e.is_connection() || matches!(e, utopia_smtp::Error::Protocol(_)));
                let failure = Failure::Smtp(e);
                for recipient in &recipients {
                    response.add_result(recipient, failure.message(), failure.permanent(), failure.code());
                }
                keep = false;
                Ok(())
            }
            Ok(Err(e)) => Err(e.into()),
        };
        if keep {
            *kept = Some(client);
        } else {
            client.close().await;
        }
        result.map(|()| (Sent::Response(response), stale))
    }
}

/// `/^(\[[^\]]+\]|[^:]+):(\d+)$/`: a bracketed address literal or a name
/// without colons, then a port.
fn split_port(entry: &[u8]) -> Option<(&[u8], &[u8])> {
    let at = |colon: usize| -> Option<(&[u8], &[u8])> {
        if colon == 0 || entry.get(colon) != Some(&b':') {
            return None;
        }
        let digits = &entry[colon + 1..];
        (!digits.is_empty() && digits.iter().all(u8::is_ascii_digit)).then(|| (&entry[..colon], digits))
    };
    // The bracketed alternative first, then a name without colons.
    let bracketed = (entry.first() == Some(&b'['))
        .then(|| entry.iter().position(|&b| b == b']'))
        .flatten()
        .filter(|&close| close >= 2)
        .and_then(|close| at(close + 1));
    bracketed.or_else(|| entry.iter().position(|&b| b == b':').and_then(at))
}

configure!(Smtp, |smtp| Http::new(smtp.name));

impl Adapter for Smtp {
    fn name(&self) -> &str {
        self.name
    }

    fn kind(&self) -> &str {
        TYPE
    }

    fn message_type(&self) -> MessageType {
        MessageType::Email
    }

    fn max_messages_per_request(&self) -> i64 {
        1000
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.send_email(message))
    }
}

/// The SMTP adapter pointed at a mail catcher: no credentials, no
/// encryption, `X-Mailer: Utopia Mailer`, and named `Mock`.
///
/// PHP: `Utopia\Messaging\Adapter\Email\Mock`.
#[derive(Debug)]
pub struct Mock;

impl Mock {
    /// The adapter for the catcher at `host:port` (PHP's defaults are
    /// `maildev` and `1025`).
    ///
    /// PHP: `new Mock($host, $port)`.
    #[allow(clippy::new_ret_no_self)]
    pub fn new(host: impl Into<String>, port: i64) -> Smtp {
        let mut smtp = Smtp::new(host, port).with_x_mailer("Utopia Mailer");
        smtp.name = "Mock";
        smtp.base = Base::new(Http::new("Mock"));
        smtp
    }
}
