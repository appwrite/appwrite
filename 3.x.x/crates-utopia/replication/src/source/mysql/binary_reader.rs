/// PHP `Utopia\Replication\Source\MySQL\BinaryReader`.
#[derive(Debug)]
pub struct BinaryReader {
    buffer: Vec<u8>,
    position: usize,
}

impl BinaryReader {
    /// PHP `__construct(string $buffer)`.
    #[must_use]
    pub fn new(buffer: impl Into<Vec<u8>>) -> Self {
        Self {
            buffer: buffer.into(),
            position: 0,
        }
    }

    /// PHP `eof()`.
    #[must_use]
    pub fn eof(&self) -> bool {
        self.position >= self.buffer.len()
    }

    /// PHP `remaining()`.
    #[must_use]
    pub fn remaining(&self) -> isize {
        self.buffer.len() as isize - self.position as isize
    }

    /// PHP `position()`.
    #[must_use]
    pub fn position(&self) -> usize {
        self.position
    }

    /// PHP `skip(int $bytes)`.
    pub fn skip(&mut self, bytes: usize) {
        self.position = self.position.saturating_add(bytes);
    }

    /// PHP `read(int $bytes)`.
    pub fn read(&mut self, bytes: usize) -> Vec<u8> {
        if bytes == 0 {
            return Vec::new();
        }
        let start = self.position.min(self.buffer.len());
        let end = start.saturating_add(bytes).min(self.buffer.len());
        let value = self.buffer[start..end].to_vec();
        self.position = self.position.saturating_add(bytes);
        value
    }

    /// PHP `readUInt(int $bytes)`.
    #[must_use]
    pub fn read_uint(&mut self, bytes: usize) -> i64 {
        let chunk = self.read(bytes);
        let mut value: u64 = 0;
        for (i, b) in chunk.iter().enumerate() {
            value |= u64::from(*b) << (i * 8);
        }
        value as i64
    }

    /// PHP `readUInt8()`.
    #[must_use]
    pub fn read_uint8(&mut self) -> i64 {
        let chunk = self.read(1);
        i64::from(*chunk.first().unwrap_or(&0))
    }

    /// PHP `readUInt16()`.
    #[must_use]
    pub fn read_uint16(&mut self) -> i64 {
        self.read_uint(2)
    }

    /// PHP `readUInt32()`.
    #[must_use]
    pub fn read_uint32(&mut self) -> i64 {
        self.read_uint(4)
    }

    /// PHP `readUInt64()`.
    #[must_use]
    pub fn read_uint64(&mut self) -> i64 {
        self.read_uint(8)
    }

    /// PHP `readLengthEncodedInt()`.
    #[must_use]
    pub fn read_length_encoded_int(&mut self) -> Option<i64> {
        let first = self.read_uint8() as u8;
        match first {
            0xFB => None,
            n if n < 0xFB => Some(i64::from(n)),
            0xFC => Some(self.read_uint(2)),
            0xFD => Some(self.read_uint(3)),
            _ => Some(self.read_uint(8)),
        }
    }

    /// PHP `readLengthEncodedString()`.
    #[must_use]
    pub fn read_length_encoded_string(&mut self) -> Option<Vec<u8>> {
        let length = self.read_length_encoded_int()?;
        Some(self.read(length.max(0) as usize))
    }

    /// PHP `readNullTerminatedString()`.
    #[must_use]
    pub fn read_null_terminated_string(&mut self) -> Vec<u8> {
        let end = self.buffer[self.position.min(self.buffer.len())..]
            .iter()
            .position(|&b| b == 0)
            .map_or(self.buffer.len(), |i| self.position + i);
        let value = self.buffer[self.position.min(self.buffer.len())..end].to_vec();
        self.position = end.saturating_add(1);
        value
    }
}
