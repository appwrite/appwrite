//! [`Body`]: the bytes a device writes or reads, as a stream.

use std::io;

use async_trait::async_trait;
use bytes::Bytes;
use tokio::io::{AsyncRead, AsyncReadExt, AsyncSeekExt};

/// Where a [`Body`] reads its bytes from. Implement it to stream from a
/// source of your own.
///
/// PHP: `Psr\Http\Message\StreamInterface`, of which a device uses
/// `read()`, `eof()`, `isSeekable()` and `rewind()`.
#[async_trait]
pub trait Source: Send {
    /// Up to `max` more bytes; empty at the end.
    async fn read(&mut self, max: usize) -> io::Result<Bytes>;

    /// Whether [`Source::rewind`] can go back to the start.
    fn is_seekable(&self) -> bool;

    /// Goes back to the start. Sources that are not seekable do nothing.
    async fn rewind(&mut self) -> io::Result<()>;
}

/// In-memory bytes.
struct Memory {
    data: Bytes,
    position: usize,
}

#[async_trait]
impl Source for Memory {
    async fn read(&mut self, max: usize) -> io::Result<Bytes> {
        let end = self.data.len().min(self.position.saturating_add(max));
        let chunk = self.data.slice(self.position..end);
        self.position = end;
        Ok(chunk)
    }

    fn is_seekable(&self) -> bool {
        true
    }

    async fn rewind(&mut self) -> io::Result<()> {
        self.position = 0;
        Ok(())
    }
}

/// An open file, read from where it is positioned. Each read is one trip to
/// the blocking pool, which reads until `max` bytes or the end of the file.
struct File {
    /// `None` only while a read has it on the blocking pool.
    file: Option<std::fs::File>,
    /// A read came back short: the file ended, so later reads return nothing
    /// without touching the disk, until a rewind.
    eof: bool,
}

#[async_trait]
impl Source for File {
    async fn read(&mut self, max: usize) -> io::Result<Bytes> {
        let max = max.min(1 << 20);
        if self.eof || max == 0 {
            return Ok(Bytes::new());
        }
        let mut file = self.file.take().ok_or_else(|| io::Error::other("the file was lost by a failed read"))?;
        let (file, result) = tokio::task::spawn_blocking(move || {
            let mut buffer = Vec::with_capacity(max.min(1 << 16));
            let result = io::Read::read_to_end(&mut io::Read::take(&mut file, max as u64), &mut buffer).map(|_| buffer);
            (file, result)
        })
        .await
        .map_err(io::Error::other)?;
        self.file = Some(file);
        let buffer = result?;
        self.eof = buffer.len() < max;
        Ok(Bytes::from(buffer))
    }

    fn is_seekable(&self) -> bool {
        true
    }

    async fn rewind(&mut self) -> io::Result<()> {
        // A seek only moves the offset: no disk I/O to wait on.
        let file = self.file.as_mut().ok_or_else(|| io::Error::other("the file was lost by a failed read"))?;
        io::Seek::rewind(file)?;
        self.eof = false;
        Ok(())
    }
}

/// An open `tokio::fs::File` with an operation still in flight, which
/// cannot become a [`File`].
struct AsyncFile(tokio::fs::File);

#[async_trait]
impl Source for AsyncFile {
    async fn read(&mut self, max: usize) -> io::Result<Bytes> {
        read_some(&mut self.0, max).await
    }

    fn is_seekable(&self) -> bool {
        true
    }

    async fn rewind(&mut self) -> io::Result<()> {
        self.0.rewind().await.map(|_| ())
    }
}

/// Any reader, read once.
struct Reader<R>(R);

#[async_trait]
impl<R: AsyncRead + Send + Unpin> Source for Reader<R> {
    async fn read(&mut self, max: usize) -> io::Result<Bytes> {
        read_some(&mut self.0, max).await
    }

    fn is_seekable(&self) -> bool {
        false
    }

    async fn rewind(&mut self) -> io::Result<()> {
        Ok(())
    }
}

async fn read_some<R: AsyncRead + Unpin>(reader: &mut R, max: usize) -> io::Result<Bytes> {
    let mut buffer = vec![0; max.min(1 << 20)];
    let mut filled = 0;
    while filled < buffer.len() {
        let n = reader.read(&mut buffer[filled..]).await?;
        if n == 0 {
            break;
        }
        filled += n;
    }
    buffer.truncate(filled);
    Ok(Bytes::from(buffer))
}

/// The contents of a file, as a stream: what [`Device::write`](crate::Device::write)
/// and the other writes take, and what [`Device::read`](crate::Device::read)
/// returns.
///
/// A body is in memory ([`Body::from`] bytes), an open file
/// ([`Body::from_file`]), any reader ([`Body::from_reader`], read once) or a
/// [`Source`] of your own. Writes on a seekable body start from its
/// beginning; S3 needs a seekable one, as it hashes the body before sending
/// it.
///
/// ```
/// use utopia_storage::Body;
///
/// # tokio::runtime::Builder::new_current_thread().build().unwrap().block_on(async {
/// let body = Body::from("Hello World");
/// assert_eq!(&body.bytes().await?[..], b"Hello World");
/// # Ok::<(), std::io::Error>(())
/// # }).unwrap();
/// ```
///
/// ```php
/// use Utopia\Psr7\Stream;
///
/// echo (string) new Stream('Hello World'), "\n"; // Hello World
/// ```
pub struct Body(Box<dyn Source>);

impl Body {
    /// A body over a source of your own.
    pub fn new(source: impl Source + 'static) -> Self {
        Body(Box::new(source))
    }

    /// An empty body.
    pub fn empty() -> Self {
        Body::from(Bytes::new())
    }

    /// A body over an open file, from where it is positioned. Rewinding
    /// goes back to the start of the file.
    ///
    /// PHP: `Stream::fromResource($handle)`.
    pub fn from_file(file: tokio::fs::File) -> Self {
        match file.try_into_std() {
            Ok(file) => Body::from_std_file(file),
            Err(file) => Body(Box::new(AsyncFile(file))),
        }
    }

    /// A body over an open standard library file, from where it is
    /// positioned.
    pub(crate) fn from_std_file(file: std::fs::File) -> Self {
        Body(Box::new(File { file: Some(file), eof: false }))
    }

    /// A body read once from `reader`; it cannot be rewound.
    pub fn from_reader(reader: impl AsyncRead + Send + Unpin + 'static) -> Self {
        Body(Box::new(Reader(reader)))
    }

    /// Up to `max` more bytes; empty at the end.
    pub async fn read(&mut self, max: usize) -> io::Result<Bytes> {
        self.0.read(max).await
    }

    /// Whether the body can be rewound.
    pub fn is_seekable(&self) -> bool {
        self.0.is_seekable()
    }

    /// Goes back to the start of a seekable body.
    pub async fn rewind(&mut self) -> io::Result<()> {
        self.0.rewind().await.map(|_| ())
    }

    /// The rest of the body, read to its end.
    ///
    /// PHP: `(string) $stream` (which reads from the start of a seekable
    /// stream; this reads from where the body is).
    pub async fn bytes(mut self) -> io::Result<Bytes> {
        let mut out = Vec::new();
        loop {
            let chunk = self.0.read(1 << 20).await?;
            if chunk.is_empty() {
                break;
            }
            if out.is_empty() && chunk.len() < (1 << 20) {
                // A body that fits in one read needs no copy.
                let rest = self.0.read(1 << 20).await?;
                if rest.is_empty() {
                    return Ok(chunk);
                }
                out.extend_from_slice(&chunk);
                out.extend_from_slice(&rest);
                continue;
            }
            out.extend_from_slice(&chunk);
        }
        Ok(Bytes::from(out))
    }
}

impl std::fmt::Debug for Body {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Body").field("seekable", &self.is_seekable()).finish()
    }
}

impl From<Bytes> for Body {
    fn from(data: Bytes) -> Self {
        Body(Box::new(Memory { data, position: 0 }))
    }
}

impl From<Vec<u8>> for Body {
    fn from(data: Vec<u8>) -> Self {
        Body::from(Bytes::from(data))
    }
}

impl From<String> for Body {
    fn from(data: String) -> Self {
        Body::from(Bytes::from(data))
    }
}

impl From<&'static str> for Body {
    fn from(data: &'static str) -> Self {
        Body::from(Bytes::from_static(data.as_bytes()))
    }
}

impl From<&'static [u8]> for Body {
    fn from(data: &'static [u8]) -> Self {
        Body::from(Bytes::from_static(data))
    }
}
