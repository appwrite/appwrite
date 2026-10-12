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

/// What a [`Spool`] keeps in memory before it moves to a temporary file:
/// `php://temp`'s default.
#[cfg(feature = "s3")]
const SPOOL_MEMORY: u64 = 2 << 20;

/// A body read once, kept as it is read so it can be rewound: in memory up
/// to [`SPOOL_MEMORY`], then in an anonymous temporary file. Bytes are
/// handed over as they arrive; a rewind replays what was kept, then goes on
/// reading the source.
///
/// PHP: the `php://temp` stream `S3::read()` copies a response into.
#[cfg(feature = "s3")]
struct Spool {
    /// `None` once it ended.
    source: Option<Body>,
    kept: Kept,
    /// How many bytes were kept.
    length: u64,
    position: u64,
}

#[cfg(feature = "s3")]
enum Kept {
    Memory(Vec<Bytes>),
    File {
        file: tokio::fs::File,
        /// Where the file is positioned.
        cursor: u64,
    },
}

#[cfg(feature = "s3")]
impl Spool {
    async fn keep(&mut self, chunk: &Bytes) -> io::Result<()> {
        use tokio::io::AsyncWriteExt;

        if let Kept::Memory(chunks) = &mut self.kept {
            if self.length + chunk.len() as u64 <= SPOOL_MEMORY {
                chunks.push(chunk.clone());
                return Ok(());
            }
            let mut file = spool_file().await?;
            for kept in chunks.iter() {
                file.write_all(kept).await?;
            }
            self.kept = Kept::File { file, cursor: self.length };
        }
        let Kept::File { file, cursor } = &mut self.kept else { unreachable!("moved to a file above") };
        if *cursor != self.length {
            *cursor = file.seek(io::SeekFrom::Start(self.length)).await?;
        }
        file.write_all(chunk).await?;
        file.flush().await?;
        *cursor += chunk.len() as u64;
        Ok(())
    }

    /// Up to `max` kept bytes from the position.
    async fn replay(&mut self, max: usize) -> io::Result<Bytes> {
        let max = (self.length - self.position).min(max.min(1 << 20) as u64) as usize;
        match &mut self.kept {
            Kept::Memory(chunks) => {
                let mut start = self.position as usize;
                for chunk in chunks.iter() {
                    if start < chunk.len() {
                        let part = chunk.slice(start..chunk.len().min(start + max));
                        self.position += part.len() as u64;
                        return Ok(part);
                    }
                    start -= chunk.len();
                }
                Ok(Bytes::new())
            }
            Kept::File { file, cursor } => {
                if *cursor != self.position {
                    *cursor = file.seek(io::SeekFrom::Start(self.position)).await?;
                }
                let mut buffer = vec![0; max];
                file.read_exact(&mut buffer).await?;
                *cursor += max as u64;
                self.position += max as u64;
                Ok(Bytes::from(buffer))
            }
        }
    }
}

#[cfg(feature = "s3")]
#[async_trait]
impl Source for Spool {
    async fn read(&mut self, max: usize) -> io::Result<Bytes> {
        if max == 0 {
            return Ok(Bytes::new());
        }
        if self.position < self.length {
            return self.replay(max).await;
        }
        let Some(source) = &mut self.source else { return Ok(Bytes::new()) };
        let chunk = source.read(max).await?;
        if chunk.is_empty() {
            self.source = None;
            return Ok(chunk);
        }
        self.keep(&chunk).await?;
        self.length += chunk.len() as u64;
        self.position = self.length;
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

/// A new temporary file, removed from its directory as soon as it is open,
/// so it goes away with its handle.
#[cfg(feature = "s3")]
async fn spool_file() -> io::Result<tokio::fs::File> {
    use std::sync::atomic::{AtomicU64, Ordering};
    static COUNTER: AtomicU64 = AtomicU64::new(0);

    let dir = std::env::temp_dir();
    loop {
        let nanos = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap_or_default().as_nanos();
        let name =
            format!("utopia-storage-spool-{}-{}-{nanos}", std::process::id(), COUNTER.fetch_add(1, Ordering::Relaxed));
        let path = dir.join(name);
        match tokio::fs::OpenOptions::new().read(true).write(true).create_new(true).open(&path).await {
            Ok(file) => {
                tokio::fs::remove_file(&path).await?;
                return Ok(file);
            }
            Err(e) if e.kind() == io::ErrorKind::AlreadyExists => continue,
            Err(e) => return Err(e),
        }
    }
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

    /// The body kept as it is read, so it can be rewound however it was
    /// made: in memory up to 2 MiB, then in a temporary file.
    #[cfg(feature = "s3")]
    pub(crate) fn spooled(self) -> Self {
        Body(Box::new(Spool { source: Some(self), kept: Kept::Memory(Vec::new()), length: 0, position: 0 }))
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
