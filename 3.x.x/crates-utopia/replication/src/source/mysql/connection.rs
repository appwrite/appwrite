use super::{Client, Constants, GtidSet, Transport};
use crate::ReplicationError;

/// PHP `Utopia\Replication\Source\MySQL\Connection`.
pub struct Connection {
    host: String,
    port: u16,
    username: String,
    password: String,
    server_id: u32,
    ssl: bool,
    ssl_verify: bool,
    ssl_ca: String,
    heartbeat: f64,
    client: Option<Client>,
    checksum: bool,
    position: String,
}

impl std::fmt::Debug for Connection {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Connection")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("server_id", &self.server_id)
            .finish_non_exhaustive()
    }
}

impl Connection {
    /// PHP `__construct`.
    #[must_use]
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        host: impl Into<String>,
        port: u16,
        username: impl Into<String>,
        password: impl Into<String>,
        server_id: u32,
        ssl: bool,
        ssl_verify: bool,
        ssl_ca: impl Into<String>,
        heartbeat: f64,
    ) -> Self {
        Self {
            host: host.into(),
            port,
            username: username.into(),
            password: password.into(),
            server_id,
            ssl,
            ssl_verify,
            ssl_ca: ssl_ca.into(),
            heartbeat,
            client: None,
            checksum: false,
            position: String::new(),
        }
    }
}

impl Transport for Connection {
    fn open(&mut self, position: Option<&str>) -> Result<(), ReplicationError> {
        let mut client = Client::new(
            self.host.clone(),
            self.port,
            self.username.clone(),
            self.password.clone(),
            self.ssl,
            self.ssl_verify,
            self.ssl_ca.clone(),
            30.0,
        );
        client.connect()?;
        client.execute("SET @master_binlog_checksum = @@global.binlog_checksum")?;
        let checksum = client
            .query_scalar("SELECT @@global.binlog_checksum")?
            .unwrap_or_else(|| "NONE".into());
        self.checksum = !checksum.trim().eq_ignore_ascii_case("NONE");
        if self.heartbeat > 0.0 {
            let period = (self.heartbeat * 1_000_000_000.0) as i64;
            client.execute(&format!("SET @master_heartbeat_period = {period}"))?;
        }
        register_slave(&mut client, self.server_id, self.port)?;
        self.position = if let Some(p) = position {
            if p.is_empty() {
                client
                    .query_scalar("SELECT @@global.gtid_executed")?
                    .unwrap_or_default()
            } else {
                p.to_owned()
            }
        } else {
            client
                .query_scalar("SELECT @@global.gtid_executed")?
                .unwrap_or_default()
        };
        send_dump_command(&mut client, self.server_id, &GtidSet::new(&self.position))?;
        self.client = Some(client);
        Ok(())
    }

    fn events(&mut self) -> Result<Vec<Vec<u8>>, ReplicationError> {
        let Some(client) = self.client.as_mut() else {
            return Ok(Vec::new());
        };
        let packet = client.read_packet()?;
        let marker = packet.first().copied().unwrap_or(0);
        if marker == Constants::PACKET_EOF && packet.len() < 9 {
            return Ok(Vec::new());
        }
        client.throw_if_error(&packet)?;
        // Live dumps are unbounded; return one event per call so callers can
        // stream. PHP yields forever; we expose `next_event` for that.
        Ok(vec![packet.get(1..).unwrap_or(&[]).to_vec()])
    }

    fn checksum(&self) -> bool {
        self.checksum
    }

    fn position(&self) -> String {
        self.position.clone()
    }

    fn close(&mut self) {
        if let Some(client) = self.client.as_mut() {
            client.close();
        }
        self.client = None;
    }
}

impl Connection {
    /// Read the next event from a live dump (PHP generator iteration).
    pub fn next_event(&mut self) -> Result<Option<Vec<u8>>, ReplicationError> {
        let Some(client) = self.client.as_mut() else {
            return Ok(None);
        };
        let packet = client.read_packet()?;
        let marker = packet.first().copied().unwrap_or(0);
        if marker == Constants::PACKET_EOF && packet.len() < 9 {
            return Ok(None);
        }
        client.throw_if_error(&packet)?;
        Ok(Some(packet.get(1..).unwrap_or(&[]).to_vec()))
    }
}

fn register_slave(client: &mut Client, server_id: u32, port: u16) -> Result<(), ReplicationError> {
    let mut payload = vec![Constants::COM_REGISTER_SLAVE];
    payload.extend_from_slice(&server_id.to_le_bytes());
    payload.push(0);
    payload.push(0);
    payload.push(0);
    payload.extend_from_slice(&port.to_le_bytes());
    payload.extend_from_slice(&0u32.to_le_bytes());
    payload.extend_from_slice(&0u32.to_le_bytes());
    client.write_command(&payload)?;
    client.read_ok()
}

fn send_dump_command(
    client: &mut Client,
    server_id: u32,
    executed: &GtidSet,
) -> Result<(), ReplicationError> {
    let encoded = executed.encode();
    let mut payload = vec![Constants::COM_BINLOG_DUMP_GTID];
    payload.extend_from_slice(&0u16.to_le_bytes());
    payload.extend_from_slice(&server_id.to_le_bytes());
    payload.extend_from_slice(&0u32.to_le_bytes());
    payload.extend_from_slice(&4u64.to_le_bytes());
    payload.extend_from_slice(&(encoded.len() as u32).to_le_bytes());
    payload.extend_from_slice(&encoded);
    client.write_command(&payload)
}
