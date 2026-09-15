use std::net::{IpAddr, SocketAddr, TcpStream, ToSocketAddrs, UdpSocket};
use std::time::Duration;

use serde_json::Value;
use utopia_validators::{Ip, Validator};

use crate::error::{Error, Result};
use crate::Message;

/// DNS client (UDP/TCP). PHP `Utopia\DNS\Client`.
#[derive(Debug)]
pub struct Client {
    server: String,
    port: u16,
    timeout: Duration,
    use_tcp: bool,
    socket: Option<UdpSocket>,
}

impl Client {
    /// PHP `Client::__construct`.
    pub fn new(
        server: impl Into<String>,
        port: u16,
        timeout_secs: u64,
        use_tcp: bool,
    ) -> Result<Self> {
        let server = server.into();
        let validator = Ip::new();
        if !validator.is_valid(&Value::String(server.clone())) {
            return Err(Error::other("Server must be an IP address."));
        }
        let timeout = Duration::from_secs(timeout_secs);
        let mut client = Self {
            server,
            port,
            timeout,
            use_tcp,
            socket: None,
        };
        if !use_tcp {
            let socket = UdpSocket::bind("0.0.0.0:0")
                .or_else(|_| UdpSocket::bind("[::]:0"))
                .map_err(|e| Error::other(format!("Failed to create socket: {e}")))?;
            socket
                .set_read_timeout(Some(timeout))
                .map_err(|e| Error::other(e.to_string()))?;
            socket
                .set_write_timeout(Some(timeout))
                .map_err(|e| Error::other(e.to_string()))?;
            client.socket = Some(socket);
        }
        Ok(client)
    }

    /// PHP `Client::query`.
    pub fn query(&self, message: &Message) -> Result<Message> {
        if self.use_tcp {
            return self.query_tcp(message);
        }
        let Some(socket) = self.socket.as_ref() else {
            return Err(Error::other("UDP socket not initialized."));
        };
        let packet = message.encode(None)?;
        let addr = self.target_addr()?;
        socket
            .send_to(&packet, addr)
            .map_err(|e| Error::other(format!("Failed to send data: {e}")))?;
        let mut buf = [0u8; 512];
        let (n, _) = socket.recv_from(&mut buf).map_err(|e| {
            Error::other(format!(
                "Failed to receive data from {}: {e} (Error code: {e})",
                self.server
            ))
        })?;
        if n == 0 {
            return Err(Error::other(format!(
                "Empty response received from {}:{}",
                self.server, self.port
            )));
        }
        self.decode_response(message, &buf[..n])
    }

    fn query_tcp(&self, message: &Message) -> Result<Message> {
        let addr = self.target_addr()?;
        let mut stream = TcpStream::connect_timeout(&addr, self.timeout).map_err(|e| {
            Error::other(format!(
                "Failed to connect to {}:{} over TCP: {e} (0)",
                self.server, self.port
            ))
        })?;
        stream
            .set_read_timeout(Some(self.timeout))
            .map_err(|e| Error::other(e.to_string()))?;
        stream
            .set_write_timeout(Some(self.timeout))
            .map_err(|e| Error::other(e.to_string()))?;

        let packet = message.encode(None)?;
        let mut frame = Vec::with_capacity(2 + packet.len());
        frame.extend_from_slice(&u16::try_from(packet.len()).unwrap_or(0).to_be_bytes());
        frame.extend_from_slice(&packet);
        std::io::Write::write_all(&mut stream, &frame)
            .map_err(|_| Error::other("Failed to send full TCP DNS query."))?;

        let mut len_buf = [0u8; 2];
        if std::io::Read::read_exact(&mut stream, &mut len_buf).is_err() {
            return Err(Error::other("Failed to read DNS TCP length prefix."));
        }
        let length = u16::from_be_bytes(len_buf) as usize;
        if length == 0 {
            return Err(Error::other("Received empty DNS TCP response."));
        }
        let mut payload = vec![0u8; length];
        if std::io::Read::read_exact(&mut stream, &mut payload).is_err() {
            return Err(Error::other("Incomplete DNS TCP response received."));
        }
        self.decode_response(message, &payload)
    }

    fn decode_response(&self, query: &Message, payload: &[u8]) -> Result<Message> {
        let response = Message::decode(payload)?;
        if response.header.id != query.header.id {
            return Err(Error::other(format!(
                "Mismatched DNS transaction ID. Expected {}, got {}",
                query.header.id, response.header.id
            )));
        }
        Ok(response)
    }

    fn target_addr(&self) -> Result<SocketAddr> {
        let ip: IpAddr = self
            .server
            .parse()
            .map_err(|_| Error::other("Server must be an IP address."))?;
        Ok(SocketAddr::new(ip, self.port))
    }
}

impl ToSocketAddrs for &Client {
    type Iter = std::vec::IntoIter<SocketAddr>;
    fn to_socket_addrs(&self) -> std::io::Result<Self::Iter> {
        Ok(vec![SocketAddr::new(
            self.server
                .parse()
                .map_err(|e| std::io::Error::new(std::io::ErrorKind::InvalidInput, e))?,
            self.port,
        )]
        .into_iter())
    }
}
