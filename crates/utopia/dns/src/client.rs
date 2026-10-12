//! A DNS client over UDP or TCP.

use std::net::{IpAddr, SocketAddr};
use std::time::Duration;

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpStream, UdpSocket};
use utopia_validators::{Ip, IpVersion, Validator};

use crate::Error;
use crate::message::Message;

/// Sends queries to one DNS server and decodes the answers.
///
/// Over UDP the answer is read into 512 bytes (RFC 1035 §4.2.1); over TCP
/// it is length-prefixed and complete. An answer whose id differs from the
/// query's is refused.
///
/// PHP: `Utopia\DNS\Client`.
#[derive(Debug, Clone)]
pub struct Client {
    server: String,
    address: IpAddr,
    port: u16,
    timeout: Duration,
    tcp: bool,
}

impl Client {
    /// A client of the server at `server` (an IPv4 or IPv6 address) and
    /// `port`, waiting up to `timeout` for each send and receive.
    ///
    /// Fails with [`Error::Exception`] (`Server must be an IP address.`).
    ///
    /// PHP: `new Client($server, $port, $timeout, $useTcp)`.
    pub fn new(server: &str, port: u16, timeout: Duration, tcp: bool) -> Result<Client, Error> {
        let valid = Ip::new(IpVersion::All).is_valid(&serde_json::Value::String(server.to_owned()));
        let address = if valid { strip_zone(server).parse::<IpAddr>().ok() } else { None };
        let Some(address) = address else {
            return Err(Error::exception("Server must be an IP address."));
        };
        Ok(Client { server: server.to_owned(), address, port, timeout, tcp })
    }

    /// The server's address as given.
    pub fn server(&self) -> &str {
        &self.server
    }

    /// The server's port.
    pub fn port(&self) -> u16 {
        self.port
    }

    /// Sends `message` and returns the decoded answer.
    ///
    /// Fails with [`Error::Exception`] when the server cannot be reached,
    /// does not answer in time, or answers with another id, and with the
    /// errors of [`Message::decode`] for an answer that does not decode.
    ///
    /// PHP: `query()`.
    pub async fn query(&self, message: &Message) -> Result<Message, Error> {
        if self.tcp {
            return self.query_tcp(message).await;
        }
        let packet = message.encode(None)?;
        let socket = UdpSocket::bind(("0.0.0.0", 0))
            .await
            .map_err(|e| Error::exception(format!("Failed to create socket: {}", strerror(&e))))?;
        let target = SocketAddr::new(self.address, self.port);
        if !target.is_ipv4() {
            // PHP's client socket is AF_INET: an IPv6 server is a host it cannot look up.
            return Err(Error::exception("Failed to send data: No error information"));
        }
        match tokio::time::timeout(self.timeout, socket.send_to(&packet, target)).await {
            Ok(Ok(_)) => {}
            Ok(Err(e)) => return Err(Error::exception(format!("Failed to send data: {}", strerror(&e)))),
            Err(_) => return Err(Error::exception(format!("Failed to send data: {}", TIMED_OUT.0))),
        }
        let mut buffer = [0u8; 512];
        // Only the server's answer counts: a datagram from any other address
        // (a spoofed answer guessing the 16-bit id) is dropped and the wait
        // goes on until the same deadline. PHP's client takes whatever
        // arrives first; legitimate exchanges see no difference.
        let deadline = tokio::time::Instant::now() + self.timeout;
        let from_server = async {
            loop {
                let (n, from) = socket.recv_from(&mut buffer).await?;
                if from == target {
                    return Ok::<usize, std::io::Error>(n);
                }
            }
        };
        let received = match tokio::time::timeout_at(deadline, from_server).await {
            Ok(Ok(n)) => n,
            Ok(Err(e)) => {
                let (text, code) = errno(&e);
                return Err(Error::exception(format!(
                    "Failed to receive data from {}: {text} (Error code: {code})",
                    self.server
                )));
            }
            Err(_) => {
                return Err(Error::exception(format!(
                    "Failed to receive data from {}: {} (Error code: {})",
                    self.server, AGAIN.0, AGAIN.1
                )));
            }
        };
        if received == 0 || &buffer[..received] == b"0" {
            return Err(Error::exception(format!("Empty response received from {}:{}", self.server, self.port)));
        }
        decode_response(message, &buffer[..received])
    }

    async fn query_tcp(&self, message: &Message) -> Result<Message, Error> {
        let target = SocketAddr::new(self.address, self.port);
        let connect_error = |text: &str, code: i32| {
            Error::exception(format!("Failed to connect to {}:{} over TCP: {text} ({code})", self.server, self.port))
        };
        let mut stream = match tokio::time::timeout(self.timeout, TcpStream::connect(target)).await {
            Ok(Ok(stream)) => stream,
            Ok(Err(e)) => {
                let (text, code) = errno(&e);
                return Err(connect_error(text, code));
            }
            Err(_) => return Err(connect_error(TIMED_OUT.0, TIMED_OUT.1)),
        };
        let packet = message.encode(None)?;
        let mut frame = Vec::with_capacity(packet.len() + 2);
        frame.extend_from_slice(&(packet.len() as u16).to_be_bytes());
        frame.extend_from_slice(&packet);
        match tokio::time::timeout(self.timeout, stream.write_all(&frame)).await {
            Ok(Ok(())) => {}
            _ => return Err(Error::exception("Failed to send full TCP DNS query.")),
        }
        let prefix = read_bytes(&mut stream, 2, self.timeout).await;
        if prefix.len() != 2 {
            return Err(Error::exception("Failed to read DNS TCP length prefix."));
        }
        let length = usize::from(u16::from_be_bytes([prefix[0], prefix[1]]));
        if length == 0 {
            return Err(Error::exception("Received empty DNS TCP response."));
        }
        let payload = read_bytes(&mut stream, length, self.timeout).await;
        if payload.len() != length {
            return Err(Error::exception("Incomplete DNS TCP response received."));
        }
        decode_response(message, &payload)
    }
}

/// Up to `length` bytes, stopping early at the end of the stream or when a
/// read waits longer than `timeout`.
async fn read_bytes(stream: &mut TcpStream, length: usize, timeout: Duration) -> Vec<u8> {
    let mut data = vec![0u8; length];
    let mut filled = 0;
    while filled < length {
        match tokio::time::timeout(timeout, stream.read(&mut data[filled..])).await {
            Ok(Ok(0)) | Ok(Err(_)) | Err(_) => break,
            Ok(Ok(n)) => filled += n,
        }
    }
    data.truncate(filled);
    data
}

fn decode_response(query: &Message, payload: &[u8]) -> Result<Message, Error> {
    let response = Message::decode(payload)?;
    if response.header().id != query.header().id {
        return Err(Error::exception(format!(
            "Mismatched DNS transaction ID. Expected {}, got {}",
            query.header().id,
            response.header().id
        )));
    }
    Ok(response)
}

/// An IPv6 address without its `%zone`.
fn strip_zone(server: &str) -> &str {
    server.split('%').next().unwrap_or(server)
}

/// Linux's `EAGAIN`, which PHP reports for a receive timeout.
const AGAIN: (&str, i32) = ("Resource temporarily unavailable", 11);
/// Linux's `ETIMEDOUT`.
const TIMED_OUT: (&str, i32) = ("Connection timed out", 110);

/// The Linux `strerror()` text and number PHP reports for an I/O error.
fn errno(e: &std::io::Error) -> (&'static str, i32) {
    use std::io::ErrorKind::*;
    match e.kind() {
        ConnectionRefused => ("Connection refused", 111),
        ConnectionReset => ("Connection reset by peer", 104),
        TimedOut | WouldBlock => AGAIN,
        AddrNotAvailable => ("Cannot assign requested address", 99),
        NetworkUnreachable => ("Network is unreachable", 101),
        HostUnreachable => ("No route to host", 113),
        PermissionDenied => ("Permission denied", 13),
        _ => ("Unknown error", 0),
    }
}

fn strerror(e: &std::io::Error) -> &'static str {
    errno(e).0
}
