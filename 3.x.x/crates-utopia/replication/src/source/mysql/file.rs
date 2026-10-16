use super::{Constants, Transport};
use crate::ReplicationError;

const MAGIC: &[u8] = b"\xfebin";

/// PHP `Utopia\Replication\Source\MySQL\File`.
#[derive(Debug)]
pub struct File {
    chunks: Vec<Vec<u8>>,
    buffer: Vec<u8>,
    chunk_index: usize,
    exhausted: bool,
    checksum: bool,
    pending: Option<Vec<u8>>,
}

impl File {
    /// PHP `__construct(string $source)`.
    #[must_use]
    pub fn new(bytes: impl Into<Vec<u8>>) -> Self {
        Self::from_bytes(bytes)
    }

    /// PHP `__construct` from a complete binlog byte string.
    #[must_use]
    pub fn from_bytes(bytes: impl Into<Vec<u8>>) -> Self {
        Self {
            chunks: vec![bytes.into()],
            buffer: Vec::new(),
            chunk_index: 0,
            exhausted: false,
            checksum: false,
            pending: None,
        }
    }

    /// PHP `__construct` from an iterable of chunks.
    #[must_use]
    pub fn from_chunks(chunks: impl IntoIterator<Item = impl Into<Vec<u8>>>) -> Self {
        Self {
            chunks: chunks.into_iter().map(Into::into).collect(),
            buffer: Vec::new(),
            chunk_index: 0,
            exhausted: false,
            checksum: false,
            pending: None,
        }
    }
}

impl Transport for File {
    fn open(&mut self, _position: Option<&str>) -> Result<(), ReplicationError> {
        self.buffer.clear();
        self.exhausted = false;
        self.pending = None;
        self.checksum = false;
        self.chunk_index = 0;

        let magic = self.take(4);
        if magic != MAGIC {
            return Err(ReplicationError::msg(
                "Not a MySQL binlog file: bad magic header",
            ));
        }

        self.pending = self.read_event()?;
        if let Some(pending) = &self.pending {
            if pending.len() >= Constants::EVENT_HEADER_SIZE + 5
                && pending.get(4) == Some(&Constants::FORMAT_DESCRIPTION_EVENT)
            {
                self.checksum = pending[pending.len() - 5] == 1;
            }
        }
        Ok(())
    }

    fn events(&mut self) -> Result<Vec<Vec<u8>>, ReplicationError> {
        let mut out = Vec::new();
        if let Some(pending) = self.pending.take() {
            out.push(pending);
        }
        while let Some(event) = self.read_event()? {
            out.push(event);
        }
        Ok(out)
    }

    fn checksum(&self) -> bool {
        self.checksum
    }

    fn position(&self) -> String {
        String::new()
    }

    fn close(&mut self) {}
}

impl File {
    fn read_event(&mut self) -> Result<Option<Vec<u8>>, ReplicationError> {
        let header = self.take(Constants::EVENT_HEADER_SIZE);
        if header.is_empty() {
            return Ok(None);
        }
        if header.len() < Constants::EVENT_HEADER_SIZE {
            return Err(ReplicationError::msg(
                "Truncated binlog: incomplete event header",
            ));
        }
        let event_size = u32::from(header[9])
            | (u32::from(header[10]) << 8)
            | (u32::from(header[11]) << 16)
            | (u32::from(header[12]) << 24);
        if (event_size as usize) < Constants::EVENT_HEADER_SIZE {
            return Err(ReplicationError::msg(format!(
                "Corrupt binlog: event_size {event_size} is smaller than the event header"
            )));
        }
        let remaining = event_size as usize - Constants::EVENT_HEADER_SIZE;
        let body = self.take(remaining);
        if body.len() < remaining {
            return Err(ReplicationError::msg(
                "Truncated binlog: incomplete event body",
            ));
        }
        let mut event = header;
        event.extend_from_slice(&body);
        Ok(Some(event))
    }

    fn take(&mut self, bytes: usize) -> Vec<u8> {
        if bytes == 0 {
            return Vec::new();
        }
        while self.buffer.len() < bytes && !self.exhausted {
            if self.chunk_index < self.chunks.len() {
                self.buffer
                    .extend_from_slice(&self.chunks[self.chunk_index]);
                self.chunk_index += 1;
            } else {
                self.exhausted = true;
            }
        }
        let n = bytes.min(self.buffer.len());
        self.buffer.drain(..n).collect()
    }
}
