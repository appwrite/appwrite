//! How a [`crate::Connection`] connects and behaves.

use std::fmt;
use std::sync::Arc;
use std::time::Duration;

use crate::error::{CallbackError, Error};
use crate::subscription::SubscriptionState;
use crate::transport::{TlsOptions, Transport};

/// A notification without arguments (`on_disconnect`, `on_reconnect`,
/// `on_close`, `on_lame_duck`).
pub type Hook = Arc<dyn Fn() -> Result<(), CallbackError> + Send + Sync>;
/// Receives the errors the server reports (`on_error`).
pub type ErrorHook = Arc<dyn Fn(&Error) -> Result<(), CallbackError> + Send + Sync>;
/// Receives a subscription that dropped messages over its pending limits.
pub type SlowConsumerHook = Arc<dyn Fn(&SubscriptionState) -> Result<(), CallbackError> + Send + Sync>;
/// Produces a credential at every (re)connect (`token_provider`,
/// `jwt_provider`).
pub type Provider = Arc<dyn Fn() -> Result<String, CallbackError> + Send + Sync>;
/// Builds the transport for a URL scheme (`nats`, `tls`, `ws`, `wss`).
pub type TransportFactory = Arc<dyn Fn(&str) -> Result<Box<dyn Transport>, CallbackError> + Send + Sync>;

/// Servers, credentials, TLS, reconnection, limits, timeouts and callbacks.
///
/// Build one with [`ConnectionOptions::new`] and set the fields that
/// differ from the defaults:
///
/// ```
/// use std::time::Duration;
/// use utopia_nats::ConnectionOptions;
///
/// let options = ConnectionOptions {
///     name: "billing".into(),
///     request_timeout: Duration::from_secs(2),
///     ..ConnectionOptions::new(["nats://10.0.0.1:4222", "nats://10.0.0.2:4222"])
/// };
/// assert_eq!(options.servers.len(), 2);
/// assert!(options.allow_reconnect);
/// ```
///
/// ```php
/// use Utopia\NATS\ConnectionOptions;
///
/// $options = new ConnectionOptions(
///     servers: ['nats://10.0.0.1:4222', 'nats://10.0.0.2:4222'],
///     name: 'billing',
///     requestTimeout: 2.0,
/// );
/// ```
///
/// PHP: `Utopia\NATS\ConnectionOptions` (constructor promoted properties;
/// float seconds are [`Duration`]s here).
#[derive(Clone)]
pub struct ConnectionOptions {
    /// Server URLs (`nats://`, `tls://`, `ws://`, `wss://`; a bare
    /// `host:port` means `nats://`).
    pub servers: Vec<String>,
    /// The client name the server shows (`CONNECT`'s `name`).
    pub name: String,
    /// User for user/password authentication.
    pub user: Option<String>,
    /// Password for user/password authentication.
    pub pass: Option<String>,
    /// Token authentication.
    pub token: Option<String>,
    /// Public NKey (with `nkey_seed`).
    pub nkey: Option<String>,
    /// NKey seed (with `nkey`).
    pub nkey_seed: Option<String>,
    /// A `.creds` file with a user JWT and NKey seed.
    pub credentials_file: Option<String>,
    /// TLS even for `nats://` URLs (when the server offers it).
    pub tls: bool,
    /// CA bundle to verify the server with.
    pub tls_ca_file: Option<String>,
    /// Client certificate, for mutual TLS.
    pub tls_cert_file: Option<String>,
    /// Client key, for mutual TLS.
    pub tls_key_file: Option<String>,
    /// Whether to verify the server's certificate and name.
    pub tls_verify: bool,
    /// The name to verify and send as SNI instead of the host.
    pub tls_server_name: Option<String>,
    /// A token resolved at every (re)connect, so it can refresh.
    pub token_provider: Option<Provider>,
    /// A user JWT resolved at every (re)connect.
    pub jwt_provider: Option<Provider>,
    /// Whether to reconnect after the connection is lost.
    pub allow_reconnect: bool,
    /// Rounds over the server pool before giving up.
    pub max_reconnect_attempts: i64,
    /// The first reconnect delay (doubling per round).
    pub reconnect_wait: Duration,
    /// The longest reconnect delay.
    pub max_reconnect_wait: Duration,
    /// Up to this much random delay is added to each round.
    pub reconnect_jitter: Duration,
    /// Bytes of publishes kept while reconnecting; the excess is dropped.
    pub reconnect_buf_size: i64,
    /// Messages a subscription without callback keeps before it drops.
    pub sub_pending_msgs_limit: i64,
    /// Bytes a subscription without callback keeps before it drops.
    pub sub_pending_bytes_limit: i64,
    /// How long connecting, the handshake and `flush` may take.
    pub connect_timeout: Duration,
    /// The default request timeout.
    pub request_timeout: Duration,
    /// The default `drain` timeout.
    pub drain_timeout: Duration,
    /// How often an idle connection sends a keepalive `PING`.
    pub ping_interval: Duration,
    /// Unanswered keepalives after which the connection is stale.
    pub max_pings_out: i64,
    /// `CONNECT`'s `verbose`: the server acknowledges each operation.
    pub verbose: bool,
    /// `CONNECT`'s `pedantic`.
    pub pedantic: bool,
    /// `CONNECT`'s `echo`: whether this connection receives its own
    /// publishes.
    pub echo: bool,
    /// Keep the server order instead of shuffling the pool.
    pub no_randomize: bool,
    /// The prefix of reply inboxes.
    pub inbox_prefix: String,
    /// Called when the connection is lost, before reconnecting.
    pub on_disconnect: Option<Hook>,
    /// Called once a reconnect succeeded.
    pub on_reconnect: Option<Hook>,
    /// Called when a connected connection closes.
    pub on_close: Option<Hook>,
    /// Called with each `-ERR` the server sends.
    pub on_error: Option<ErrorHook>,
    /// Called when a subscription starts dropping messages.
    pub on_slow_consumer: Option<SlowConsumerHook>,
    /// Called when the server announces lame-duck mode.
    pub on_lame_duck: Option<Hook>,
    /// Builds the transport for each connection attempt, instead of the
    /// TCP, TLS and WebSocket transports chosen by URL scheme.
    pub transport_factory: Option<TransportFactory>,
}

impl ConnectionOptions {
    /// The defaults, for these servers.
    ///
    /// PHP: `new ConnectionOptions($servers)`.
    pub fn new<I, S>(servers: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        ConnectionOptions { servers: servers.into_iter().map(Into::into).collect(), ..Self::default() }
    }

    /// The TLS options the transports use: the CA, client certificate and
    /// key, verification and server name.
    ///
    /// PHP: `Connection::tlsOptions()` (private).
    pub fn tls_options(&self) -> TlsOptions {
        TlsOptions {
            ca_file: self.tls_ca_file.as_ref().map(Into::into),
            cert_file: self.tls_cert_file.as_ref().map(Into::into),
            key_file: self.tls_key_file.as_ref().map(Into::into),
            verify_peer: self.tls_verify,
            verify_peer_name: self.tls_verify,
            peer_name: self.tls_server_name.clone(),
        }
    }
}

impl Default for ConnectionOptions {
    fn default() -> Self {
        ConnectionOptions {
            servers: vec!["nats://127.0.0.1:4222".into()],
            name: String::new(),
            user: None,
            pass: None,
            token: None,
            nkey: None,
            nkey_seed: None,
            credentials_file: None,
            tls: false,
            tls_ca_file: None,
            tls_cert_file: None,
            tls_key_file: None,
            tls_verify: true,
            tls_server_name: None,
            token_provider: None,
            jwt_provider: None,
            allow_reconnect: true,
            max_reconnect_attempts: 60,
            reconnect_wait: Duration::from_secs(2),
            max_reconnect_wait: Duration::from_secs(8),
            reconnect_jitter: Duration::from_millis(100),
            reconnect_buf_size: 8_388_608,
            sub_pending_msgs_limit: 65536,
            sub_pending_bytes_limit: 67_108_864,
            connect_timeout: Duration::from_secs(2),
            request_timeout: Duration::from_secs(5),
            drain_timeout: Duration::from_secs(30),
            ping_interval: Duration::from_secs(120),
            max_pings_out: 2,
            verbose: false,
            pedantic: false,
            echo: true,
            no_randomize: false,
            inbox_prefix: "_INBOX".into(),
            on_disconnect: None,
            on_reconnect: None,
            on_close: None,
            on_error: None,
            on_slow_consumer: None,
            on_lame_duck: None,
            transport_factory: None,
        }
    }
}

impl From<&str> for ConnectionOptions {
    fn from(server: &str) -> Self {
        ConnectionOptions::new([server])
    }
}

impl fmt::Debug for ConnectionOptions {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ConnectionOptions")
            .field("servers", &self.servers)
            .field("name", &self.name)
            .field("tls", &self.tls)
            .field("allow_reconnect", &self.allow_reconnect)
            .field("connect_timeout", &self.connect_timeout)
            .field("request_timeout", &self.request_timeout)
            .field("inbox_prefix", &self.inbox_prefix)
            .finish_non_exhaustive()
    }
}
