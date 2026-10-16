use crate::source::mysql::{Client, Connection, Decoder, EventParser, GtidSet, Transport};
use crate::{Change, ReplicationError, Source};

/// PHP `Utopia\Replication\Source\MySQL`.
pub struct MySQL {
    host: String,
    port: u16,
    username: String,
    password: String,
    server_id: u32,
    schema: Option<String>,
    ssl: bool,
    ssl_verify: bool,
    ssl_ca: String,
    heartbeat: f64,
    transport: Option<Connection>,
    decoder: Option<Decoder>,
    schema_client: Option<Client>,
}

impl std::fmt::Debug for MySQL {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MySQL")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("schema", &self.schema)
            .finish_non_exhaustive()
    }
}

impl MySQL {
    /// PHP `__construct`.
    #[must_use]
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        host: impl Into<String>,
        port: u16,
        username: impl Into<String>,
        password: impl Into<String>,
        server_id: u32,
        schema: Option<String>,
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
            schema,
            ssl,
            ssl_verify,
            ssl_ca: ssl_ca.into(),
            heartbeat,
            transport: None,
            decoder: None,
            schema_client: None,
        }
    }

    fn resolve_columns(&mut self, schema: &str, table: &str) -> Vec<String> {
        let result = (|| -> Result<Vec<String>, ReplicationError> {
            if self.schema_client.is_none() {
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
                client.execute("SET SESSION group_concat_max_len = 1048576")?;
                self.schema_client = Some(client);
            }
            let client = self.schema_client.as_mut().unwrap();
            let schema_hex = format!("0x{}", hex::encode(schema.as_bytes()));
            let table_hex = format!("0x{}", hex::encode(table.as_bytes()));
            let names = client.query_scalar(&format!(
                "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR 0x00) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = {schema_hex} AND TABLE_NAME = {table_hex}"
            ))?;
            Ok(match names {
                None => Vec::new(),
                Some(s) if s.is_empty() => Vec::new(),
                Some(s) => s.split('\0').map(str::to_owned).collect(),
            })
        })();
        if let Ok(names) = result {
            names
        } else {
            self.schema_client = None;
            Vec::new()
        }
    }

    /// Blocking next change from the live dump.
    pub fn next_change(&mut self) -> Result<Option<Change>, ReplicationError> {
        loop {
            let event = {
                let transport = self
                    .transport
                    .as_mut()
                    .ok_or_else(|| ReplicationError::msg("Not started"))?;
                match transport.next_event()? {
                    Some(event) => event,
                    None => return Ok(None),
                }
            };
            let decoder = self
                .decoder
                .as_mut()
                .ok_or_else(|| ReplicationError::msg("Not started"))?;
            if let Some(change) = decoder.decode(&event)? {
                return Ok(Some(change));
            }
        }
    }
}

impl Source for MySQL {
    fn start(&mut self, position: Option<&str>) -> Result<(), ReplicationError> {
        let mut transport = Connection::new(
            self.host.clone(),
            self.port,
            self.username.clone(),
            self.password.clone(),
            self.server_id,
            self.ssl,
            self.ssl_verify,
            self.ssl_ca.clone(),
            self.heartbeat,
        );
        transport.open(position)?;
        let executed = GtidSet::new(&transport.position());
        let checksum = transport.checksum();
        let schema = self.schema.clone();
        let host = self.host.clone();
        let port = self.port;
        let username = self.username.clone();
        let password = self.password.clone();
        let ssl = self.ssl;
        let ssl_verify = self.ssl_verify;
        let ssl_ca = self.ssl_ca.clone();
        // Resolver captures nothing from self after start; columns resolved via schema client on MySQL.
        let parser = EventParser::with_resolver(move |s, t| {
            let mut tmp = MySQL::new(
                host.clone(),
                port,
                username.clone(),
                password.clone(),
                0,
                None,
                ssl,
                ssl_verify,
                ssl_ca.clone(),
                15.0,
            );
            tmp.resolve_columns(s, t)
        });
        self.decoder = Some(Decoder::new(parser, executed, schema, checksum));
        self.transport = Some(transport);
        Ok(())
    }

    fn get_changes(&mut self) -> Result<Vec<Change>, ReplicationError> {
        let mut changes = Vec::new();
        if let Some(change) = self.next_change()? {
            changes.push(change);
        }
        Ok(changes)
    }

    fn stop(&mut self) {
        if let Some(transport) = self.transport.as_mut() {
            transport.close();
        }
        if let Some(client) = self.schema_client.as_mut() {
            client.close();
        }
        self.schema_client = None;
        self.transport = None;
        self.decoder = None;
    }
}
