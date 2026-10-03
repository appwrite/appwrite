//! Port of `tests/Unit/Transport/TcpTransportWriteTest.php`.

use std::io::{self, Write};
use utopia_nats::transport::write_fully;

struct PartialWrite {
    buffer: Vec<u8>,
    chunk: usize,
    write_calls: usize,
}

impl Write for PartialWrite {
    fn write(&mut self, buf: &[u8]) -> io::Result<usize> {
        self.write_calls += 1;
        let n = buf.len().min(self.chunk);
        self.buffer.extend_from_slice(&buf[..n]);
        Ok(n)
    }

    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}

#[test]
fn test_write_loops_until_all_bytes_written() {
    let mut sink = PartialWrite {
        buffer: Vec::new(),
        chunk: 3,
        write_calls: 0,
    };
    let data = b"HELLO NATS WORLD";
    let written = write_fully(&mut sink, data).unwrap();
    assert_eq!(written, data.len());
    assert_eq!(sink.buffer, data, "all bytes reached the sink");
    assert_eq!(sink.write_calls, 6);
}
