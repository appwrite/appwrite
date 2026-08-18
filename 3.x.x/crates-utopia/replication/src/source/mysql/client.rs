use std::io::{Read, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::time::Duration;

use native_tls::{TlsConnector, TlsStream};
use rand::rngs::OsRng;
use rsa::pkcs1::DecodeRsaPublicKey;
use rsa::pkcs8::DecodePublicKey;
use rsa::Oaep;
use sha1::Sha1;
use sha2::{Digest, Sha256};

use super::{BinaryReader, Constants};
use crate::ReplicationError;

const MAX_PACKET_SIZE: u32 = 0x4000_0000;
const CHARSET_UTF8MB4: u8 = 45;

enum Stream {
    Plain(TcpStream),
    Tls(TlsStream<TcpStream>),
}

impl Stream {
    fn read_exact(&mut self, buf: &mut [u8]) -> std::io::Result<()> {
        match self {
            Self::Plain(s) => s.read_exact(buf),
            Self::Tls(s) => s.read_exact(buf),
        }
    }

    fn write_all(&mut self, buf: &[u8]) -> std::io::Result<()> {
        match self {
            Self::Plain(s) => s.write_all(buf),
            Self::Tls(s) => s.write_all(buf),
        }
    }
}

/// PHP `Utopia\Replication\Source\MySQL\Client`.
pub struct Client {
    host: String,
    port: u16,
    username: String,
    password: String,
    ssl: bool,
    ssl_verify: bool,
    ssl_ca: String,
    timeout: Duration,
    stream: Option<Stream>,
    sequence: i32,
}

impl std::fmt::Debug for Client {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Client")
            .field("host", &self.host)
            .field("port", &self.port)
            .finish_non_exhaustive()
    }
}

impl Client {
    /// PHP `__construct`.
    #[must_use]
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        host: impl Into<String>,
        port: u16,
        username: impl Into<String>,
        password: impl Into<String>,
        ssl: bool,
        ssl_verify: bool,
        ssl_ca: impl Into<String>,
        timeout_secs: f64,
    ) -> Self {
        Self {
            host: host.into(),
            port,
            username: username.into(),
            password: password.into(),
            ssl,
            ssl_verify,
            ssl_ca: ssl_ca.into(),
            timeout: Duration::from_secs_f64(timeout_secs.max(0.0)),
            stream: None,
            sequence: 0,
        }
    }

    /// PHP `connect()`.
    pub fn connect(&mut self) -> Result<(), ReplicationError> {
        let addr = format!("{}:{}", self.host, self.port);
        let mut addrs = addr
            .to_socket_addrs()
            .map_err(|e| ReplicationError::msg(format!("Failed to connect to {addr}: {e}")))?;
        let sock_addr = addrs.next().ok_or_else(|| {
            ReplicationError::msg(format!("Failed to connect to {addr}: no addresses"))
        })?;
        let stream = TcpStream::connect_timeout(&sock_addr, self.timeout).map_err(|e| {
            ReplicationError::msg(format!(
                "Failed to connect to {}:{}: {e}",
                self.host, self.port
            ))
        })?;
        stream
            .set_read_timeout(Some(self.timeout))
            .map_err(|e| ReplicationError::msg(e.to_string()))?;
        stream
            .set_write_timeout(Some(self.timeout))
            .map_err(|e| ReplicationError::msg(e.to_string()))?;
        self.stream = Some(Stream::Plain(stream));
        self.authenticate()
    }

    /// PHP `close()`.
    pub fn close(&mut self) {
        self.stream = None;
    }

    fn stream(&mut self) -> Result<&mut Stream, ReplicationError> {
        self.stream
            .as_mut()
            .ok_or_else(|| ReplicationError::msg("Not connected"))
    }

    /// PHP `readPacket()`.
    pub fn read_packet(&mut self) -> Result<Vec<u8>, ReplicationError> {
        let mut payload = Vec::new();
        loop {
            let mut header = [0u8; 4];
            self.stream()?.read_exact(&mut header).map_err(|e| {
                ReplicationError::msg(format!(
                    "Connection closed while reading packet header: {e}"
                ))
            })?;
            let length =
                u32::from(header[0]) | (u32::from(header[1]) << 8) | (u32::from(header[2]) << 16);
            self.sequence = i32::from(header[3]);
            if length > 0 {
                let mut chunk = vec![0u8; length as usize];
                self.stream()?.read_exact(&mut chunk).map_err(|e| {
                    ReplicationError::msg(format!(
                        "Connection closed while reading packet body: {e}"
                    ))
                })?;
                payload.extend_from_slice(&chunk);
            }
            if length != 0x00FF_FFFF {
                break;
            }
        }
        Ok(payload)
    }

    /// PHP `writePacket(string $payload)`.
    pub fn write_packet(&mut self, payload: &[u8]) -> Result<(), ReplicationError> {
        self.sequence += 1;
        self.send_frames(payload)
    }

    /// PHP `writeCommand(string $payload)`.
    pub fn write_command(&mut self, payload: &[u8]) -> Result<(), ReplicationError> {
        self.sequence = -1;
        self.write_packet(payload)
    }

    fn send_frames(&mut self, payload: &[u8]) -> Result<(), ReplicationError> {
        let length = payload.len();
        let mut offset = 0usize;
        loop {
            let size = (length - offset).min(0x00FF_FFFF);
            let header = [
                (size & 0xFF) as u8,
                ((size >> 8) & 0xFF) as u8,
                ((size >> 16) & 0xFF) as u8,
                self.sequence as u8,
            ];
            let mut frame = header.to_vec();
            frame.extend_from_slice(&payload[offset..offset + size]);
            self.stream()?
                .write_all(&frame)
                .map_err(|e| ReplicationError::msg(format!("Failed to write packet: {e}")))?;
            offset += size;
            if size == 0x00FF_FFFF {
                self.sequence += 1;
            } else {
                break;
            }
        }
        Ok(())
    }

    /// PHP `execute(string $sql)`.
    pub fn execute(&mut self, sql: &str) -> Result<(), ReplicationError> {
        let mut payload = vec![Constants::COM_QUERY];
        payload.extend_from_slice(sql.as_bytes());
        self.write_command(&payload)?;
        let packet = self.read_packet()?;
        self.assert_ok(&packet)
    }

    /// PHP `queryScalar(string $sql)`.
    pub fn query_scalar(&mut self, sql: &str) -> Result<Option<String>, ReplicationError> {
        let mut payload = vec![Constants::COM_QUERY];
        payload.extend_from_slice(sql.as_bytes());
        self.write_command(&payload)?;
        let first = self.read_packet()?;
        if first.first() == Some(&Constants::PACKET_ERR) {
            return Err(self.throw_error(&first));
        }
        let mut reader = BinaryReader::new(first);
        let columns = reader.read_length_encoded_int().unwrap_or(0).max(0) as usize;
        for _ in 0..columns {
            let _ = self.read_packet()?;
        }
        let _ = self.read_packet()?;
        let mut value = None;
        loop {
            let packet = self.read_packet()?;
            let type_ = packet.first().copied().unwrap_or(0);
            if type_ == Constants::PACKET_EOF && packet.len() < 9 {
                break;
            }
            if type_ == Constants::PACKET_ERR {
                return Err(self.throw_error(&packet));
            }
            if value.is_none() {
                value = BinaryReader::new(packet)
                    .read_length_encoded_string()
                    .map(|b| String::from_utf8_lossy(&b).into_owned());
            }
        }
        Ok(value)
    }

    /// PHP `readOk()`.
    pub fn read_ok(&mut self) -> Result<(), ReplicationError> {
        let packet = self.read_packet()?;
        self.assert_ok(&packet)
    }

    /// PHP `throwIfError(string $packet)`.
    pub fn throw_if_error(&self, packet: &[u8]) -> Result<(), ReplicationError> {
        if packet.first() == Some(&Constants::PACKET_ERR) {
            return Err(self.throw_error(packet));
        }
        Ok(())
    }

    fn authenticate(&mut self) -> Result<(), ReplicationError> {
        let handshake = self.read_packet()?;
        let mut reader = BinaryReader::new(handshake);
        reader.skip(1);
        let _ = reader.read_null_terminated_string();
        reader.skip(4);
        let mut auth_data = reader.read(8);
        reader.skip(1);
        let mut capabilities = reader.read_uint16() as u32;
        reader.skip(1);
        reader.skip(2);
        capabilities |= (reader.read_uint16() as u32) << 16;
        let auth_data_len = reader.read_uint8() as usize;
        reader.skip(10);
        if capabilities & Constants::CLIENT_SECURE_CONNECTION != 0 {
            auth_data.extend_from_slice(&reader.read(13.max(auth_data_len.saturating_sub(8))));
        }
        let plugin = if capabilities & Constants::CLIENT_PLUGIN_AUTH != 0 {
            String::from_utf8_lossy(&reader.read_null_terminated_string()).into_owned()
        } else {
            "mysql_native_password".into()
        };
        let nonce: Vec<u8> = auth_data.into_iter().take(20).collect();
        if self.ssl {
            self.upgrade_to_tls(capabilities)?;
        }
        self.send_handshake_response(&nonce, &plugin)?;
        self.finish_auth(&nonce, &plugin)
    }

    fn upgrade_to_tls(&mut self, server_capabilities: u32) -> Result<(), ReplicationError> {
        if server_capabilities & Constants::CLIENT_SSL == 0 {
            return Err(ReplicationError::msg(
                "TLS requested but the server does not support it",
            ));
        }
        let mut payload = Vec::new();
        payload
            .extend_from_slice(&(self.client_capabilities() | Constants::CLIENT_SSL).to_le_bytes());
        payload.extend_from_slice(&MAX_PACKET_SIZE.to_le_bytes());
        payload.push(CHARSET_UTF8MB4);
        payload.extend_from_slice(&[0u8; 23]);
        self.write_packet(&payload)?;

        let Stream::Plain(tcp) = self
            .stream
            .take()
            .ok_or_else(|| ReplicationError::msg("Not connected"))?
        else {
            return Err(ReplicationError::msg("Already using TLS"));
        };
        let mut builder = TlsConnector::builder();
        builder.danger_accept_invalid_certs(!self.ssl_verify);
        builder.danger_accept_invalid_hostnames(!self.ssl_verify);
        if !self.ssl_ca.is_empty() {
            let pem = std::fs::read(&self.ssl_ca)
                .map_err(|e| ReplicationError::msg(format!("TLS handshake failed: {e}")))?;
            if let Ok(cert) = native_tls::Certificate::from_pem(&pem) {
                builder.add_root_certificate(cert);
            }
        }
        let connector = builder
            .build()
            .map_err(|e| ReplicationError::msg(format!("TLS handshake failed: {e}")))?;
        let tls = connector
            .connect(&self.host, tcp)
            .map_err(|e| ReplicationError::msg(format!("TLS handshake failed: {e}")))?;
        self.stream = Some(Stream::Tls(tls));
        Ok(())
    }

    fn client_capabilities(&self) -> u32 {
        Constants::CLIENT_LONG_PASSWORD
            | Constants::CLIENT_LONG_FLAG
            | Constants::CLIENT_PROTOCOL_41
            | Constants::CLIENT_SECURE_CONNECTION
            | Constants::CLIENT_PLUGIN_AUTH
            | Constants::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA
    }

    fn send_handshake_response(
        &mut self,
        nonce: &[u8],
        plugin: &str,
    ) -> Result<(), ReplicationError> {
        let mut capabilities = self.client_capabilities();
        if self.ssl {
            capabilities |= Constants::CLIENT_SSL;
        }
        let auth_response = self.scramble(plugin, nonce);
        let mut payload = Vec::new();
        payload.extend_from_slice(&capabilities.to_le_bytes());
        payload.extend_from_slice(&MAX_PACKET_SIZE.to_le_bytes());
        payload.push(CHARSET_UTF8MB4);
        payload.extend_from_slice(&[0u8; 23]);
        payload.extend_from_slice(self.username.as_bytes());
        payload.push(0);
        payload.extend_from_slice(&length_encoded_int(auth_response.len() as i64)?);
        payload.extend_from_slice(&auth_response);
        payload.extend_from_slice(plugin.as_bytes());
        payload.push(0);
        self.write_packet(&payload)
    }

    #[allow(unused_assignments)]
    fn finish_auth(&mut self, nonce: &[u8], _plugin: &str) -> Result<(), ReplicationError> {
        let mut nonce = nonce.to_vec();
        let mut plugin = String::new();
        loop {
            let packet = self.read_packet()?;
            let marker = packet.first().copied().unwrap_or(0);
            match marker {
                Constants::PACKET_OK => return Ok(()),
                Constants::PACKET_ERR => return Err(self.throw_error(&packet)),
                Constants::PACKET_EOF => {
                    let mut reader = BinaryReader::new(packet[1..].to_vec());
                    plugin =
                        String::from_utf8_lossy(&reader.read_null_terminated_string()).into_owned();
                    let remaining = reader.remaining().max(0) as usize;
                    nonce = reader.read(remaining).into_iter().take(20).collect();
                    self.write_packet(&self.scramble(&plugin, &nonce))?;
                }
                Constants::PACKET_AUTH_MORE_DATA => {
                    let status = packet.get(1).copied().unwrap_or(0);
                    if status == Constants::AUTH_FAST_SUCCESS {
                        continue;
                    }
                    if status == Constants::AUTH_FULL_REQUIRED {
                        self.full_auth(&nonce)?;
                        continue;
                    }
                    return Err(ReplicationError::msg(format!(
                        "Unexpected auth status: {status}"
                    )));
                }
                other => {
                    return Err(ReplicationError::msg(format!(
                        "Unexpected auth packet marker: {other}"
                    )));
                }
            }
        }
    }

    fn full_auth(&mut self, nonce: &[u8]) -> Result<(), ReplicationError> {
        self.write_packet(&[Constants::AUTH_REQUEST_PUBLIC_KEY])?;
        let packet = self.read_packet()?;
        if packet.first() != Some(&Constants::PACKET_AUTH_MORE_DATA) {
            return Err(ReplicationError::msg(
                "Expected public key in auth exchange",
            ));
        }
        let public_key = &packet[1..];
        let pem = String::from_utf8_lossy(public_key);
        let key = rsa::RsaPublicKey::from_public_key_pem(&pem)
            .or_else(|_| rsa::RsaPublicKey::from_pkcs1_pem(&pem))
            .map_err(|e| {
                ReplicationError::msg(format!("Failed to RSA-encrypt credentials: {e}"))
            })?;
        let mut plain = self.password.as_bytes().to_vec();
        plain.push(0);
        let mut masked = Vec::with_capacity(plain.len());
        let nonce_len = nonce.len().max(1);
        for (i, b) in plain.iter().enumerate() {
            masked.push(b ^ nonce[i % nonce_len]);
        }
        let padding = Oaep::new::<Sha1>();
        let encrypted = key.encrypt(&mut OsRng, padding, &masked).map_err(|e| {
            ReplicationError::msg(format!("Failed to RSA-encrypt credentials: {e}"))
        })?;
        self.write_packet(&encrypted)
    }

    fn scramble(&self, plugin: &str, nonce: &[u8]) -> Vec<u8> {
        if self.password.is_empty() {
            return Vec::new();
        }
        if plugin == "mysql_native_password" {
            self.scramble_native(nonce)
        } else {
            self.scramble_caching_sha2(nonce)
        }
    }

    fn scramble_caching_sha2(&self, nonce: &[u8]) -> Vec<u8> {
        let m1 = Sha256::digest(self.password.as_bytes());
        let m2 = Sha256::digest(m1);
        let mut m2n = Vec::with_capacity(32 + nonce.len());
        m2n.extend_from_slice(&m2);
        m2n.extend_from_slice(nonce);
        let m3 = Sha256::digest(&m2n);
        m1.iter().zip(m3.iter()).map(|(a, b)| a ^ b).collect()
    }

    fn scramble_native(&self, nonce: &[u8]) -> Vec<u8> {
        let stage1 = Sha1::digest(self.password.as_bytes());
        let stage2 = Sha1::digest(stage1);
        let mut concat = Vec::with_capacity(nonce.len() + 20);
        concat.extend_from_slice(nonce);
        concat.extend_from_slice(&stage2);
        let token = Sha1::digest(&concat);
        stage1
            .iter()
            .zip(token.iter())
            .map(|(a, b)| a ^ b)
            .collect()
    }

    fn assert_ok(&self, packet: &[u8]) -> Result<(), ReplicationError> {
        if packet.first() == Some(&Constants::PACKET_ERR) {
            return Err(self.throw_error(packet));
        }
        Ok(())
    }

    fn throw_error(&self, packet: &[u8]) -> ReplicationError {
        let mut reader = BinaryReader::new(packet.get(1..).unwrap_or(&[]).to_vec());
        let code = reader.read_uint16();
        let remaining = reader.remaining().max(0) as usize;
        let mut message = String::from_utf8_lossy(&reader.read(remaining)).into_owned();
        if message.starts_with('#') && message.len() >= 6 {
            message = message[6..].to_owned();
        }
        ReplicationError::msg(format!("MySQL error {code}: {message}"))
    }
}

fn length_encoded_int(value: i64) -> Result<Vec<u8>, ReplicationError> {
    if value < 0 {
        return Err(ReplicationError::msg(format!(
            "Cannot length-encode a negative value: {value}"
        )));
    }
    let v = value as u64;
    Ok(match v {
        n if n < 0xFB => vec![n as u8],
        n if n < 0x1_0000 => {
            let mut out = vec![0xFC];
            out.extend_from_slice(&(n as u16).to_le_bytes());
            out
        }
        n if n < 0x100_0000 => {
            let bytes = (n as u32).to_le_bytes();
            vec![0xFD, bytes[0], bytes[1], bytes[2]]
        }
        n => {
            let mut out = vec![0xFE];
            out.extend_from_slice(&n.to_le_bytes());
            out
        }
    })
}
