//! Shared test doubles (PHP `tests/Unit/Support`).

#![allow(dead_code)]

use parking_lot::Mutex;
use std::collections::HashMap;
use std::sync::Arc;
use utopia_nats::connection::{Connection, ConnectionOptions, TransportFactory};
use utopia_nats::error::NatsError;
use utopia_nats::transport::{FakeTransport, Transport};

pub fn connect_fake(
    fake: Arc<FakeTransport>,
    extra: impl FnOnce(&mut ConnectionOptions),
) -> Connection {
    let factory_fake = Arc::clone(&fake);
    let factory: TransportFactory = Arc::new(move |_scheme: &str| -> Arc<dyn Transport> {
        let transport: Arc<FakeTransport> = Arc::clone(&factory_fake);
        transport
    });
    let mut opts = ConnectionOptions {
        servers: vec!["nats://127.0.0.1:4222".into()],
        transport_factory: Some(factory),
        ..ConnectionOptions::default()
    };
    extra(&mut opts);
    Connection::connect(opts).expect("fake connect")
}

/// Byte-buffer transport for protocol parser tests (PHP anonymous Transport).
#[derive(Debug)]
pub struct BufferTransport {
    data: Vec<u8>,
    pos: Mutex<usize>,
}

impl BufferTransport {
    pub fn new(data: impl Into<Vec<u8>>) -> Arc<Self> {
        Arc::new(Self {
            data: data.into(),
            pos: Mutex::new(0),
        })
    }
}

impl Transport for BufferTransport {
    fn connect(&self, _host: &str, _port: u16, _timeout: f64) -> Result<(), NatsError> {
        Ok(())
    }

    fn write(&self, data: &[u8]) -> Result<usize, NatsError> {
        Ok(data.len())
    }

    fn read(&self, max_bytes: usize, _timeout: Option<f64>) -> Result<Vec<u8>, NatsError> {
        let mut pos = self.pos.lock();
        if *pos >= self.data.len() {
            return Ok(Vec::new());
        }
        let n = max_bytes.min(self.data.len() - *pos);
        let chunk = self.data[*pos..*pos + n].to_vec();
        *pos += n;
        Ok(chunk)
    }

    fn read_line(&self, timeout: Option<f64>) -> Result<String, NatsError> {
        Ok(String::from_utf8_lossy(&self.read(65536, timeout)?).into_owned())
    }

    fn upgrade_tls(&self, _options: &HashMap<String, serde_json::Value>) -> Result<(), NatsError> {
        Ok(())
    }

    fn is_connected(&self) -> bool {
        true
    }

    fn close(&self) {}
}
