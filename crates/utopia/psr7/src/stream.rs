//! Message bodies: the [`Stream`] trait, the in-memory or file-backed
//! [`TempStream`], and [`StreamRef`], the shared handle messages carry.
//!
//! Streams are synchronous, like PHP's: an in-memory stream never waits,
//! and a file-backed one reads the file directly. In async code, read a
//! large file-backed body on the blocking pool (`tokio::task::spawn_blocking`).

use std::fs::{File, OpenOptions};
use std::io::{Read, Seek, SeekFrom, Write};
use std::path::Path;
use std::sync::{Arc, Mutex, MutexGuard};

use crate::Error;

/// A readable, writable or seekable sequence of bytes.
///
/// PHP: `Psr\Http\Message\StreamInterface`.
pub trait Stream: std::any::Any + Send {
    /// Up to `length` bytes from the current position; fewer at the end,
    /// none past it (which marks the end as reached, see [`Stream::eof`]).
    ///
    /// Fails with [`Error::Runtime`] when `length` is 0 or the stream is
    /// detached.
    ///
    /// PHP: `read()`.
    fn read(&mut self, length: usize) -> Result<Vec<u8>, Error>;

    /// Writes at the current position, returning how many bytes were written.
    ///
    /// PHP: `write()`.
    fn write(&mut self, data: &[u8]) -> Result<usize, Error>;

    /// Moves the position.
    ///
    /// PHP: `seek()`.
    fn seek(&mut self, position: SeekFrom) -> Result<(), Error>;

    /// Moves the position to the start.
    ///
    /// PHP: `rewind()`.
    fn rewind(&mut self) -> Result<(), Error> {
        self.seek(SeekFrom::Start(0))
    }

    /// The position.
    ///
    /// PHP: `tell()`.
    fn tell(&self) -> Result<u64, Error>;

    /// Whether a read has reached the end (or the stream is detached).
    ///
    /// PHP: `eof()`.
    fn eof(&self) -> bool;

    /// The size in bytes, when known.
    ///
    /// PHP: `getSize()`.
    fn size(&self) -> Result<Option<u64>, Error>;

    /// Everything from the position to the end.
    ///
    /// PHP: `getContents()`.
    fn contents(&mut self) -> Result<Vec<u8>, Error>;

    /// Everything from the start; empty when the stream cannot be read.
    ///
    /// PHP: `__toString()`.
    fn to_bytes(&mut self) -> Vec<u8>;

    /// Closes the stream.
    ///
    /// PHP: `close()`.
    fn close(&mut self);

    /// Takes what the stream reads from, leaving it unusable: `None` for
    /// streams with nothing to hand over.
    ///
    /// PHP: `detach()`.
    fn detach(&mut self) -> Result<Option<Detached>, Error>;

    /// Whether the stream can be read.
    ///
    /// PHP: `isReadable()`.
    fn is_readable(&self) -> bool;

    /// Whether the stream can be written.
    ///
    /// PHP: `isWritable()`.
    fn is_writable(&self) -> bool;

    /// Whether the position can be moved.
    ///
    /// PHP: `isSeekable()`.
    fn is_seekable(&self) -> bool;

    /// What the stream reports about itself, as `stream_get_meta_data()`
    /// lists it.
    ///
    /// PHP: `getMetadata()`.
    fn metadata(&self) -> Result<Vec<(&'static str, Meta)>, Error>;
}

/// A metadata value.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Meta {
    /// A flag.
    Bool(bool),
    /// A count.
    Int(i64),
    /// A name, mode or URI.
    Str(String),
}

/// What a [`TempStream`] reads from, once taken with [`Stream::detach`]:
/// the buffer of an in-memory stream or the open file.
///
/// PHP: the stream resource `detach()` returns.
#[derive(Debug)]
pub enum Detached {
    /// An in-memory stream: its bytes and the position.
    Memory {
        /// The bytes.
        data: Vec<u8>,
        /// The position.
        position: usize,
    },
    /// A file, with the path and `fopen()` mode it was opened with.
    File {
        /// The open file.
        file: File,
        /// Its path.
        uri: String,
        /// Its `fopen()` mode.
        mode: String,
    },
}

/// A stream in memory or over a file.
///
/// [`TempStream::new`] holds its bytes in memory; [`TempStream::open`] and
/// [`TempStream::from_file`] read and write a file without loading it.
///
/// ```
/// use utopia_psr7::stream::{Stream, TempStream};
///
/// let mut stream = TempStream::new(b"hello");
/// assert_eq!(stream.read(3)?, b"hel");
/// assert!(!stream.eof());
/// assert_eq!(stream.contents()?, b"lo");
/// assert!(stream.eof());
/// assert_eq!(stream.to_bytes(), b"hello");
/// # Ok::<(), utopia_psr7::Error>(())
/// ```
///
/// ```php
/// use Utopia\Psr7\Stream;
///
/// $stream = new Stream('hello');
/// echo $stream->read(3), "\n";     // hel
/// var_dump($stream->eof());        // bool(false)
/// echo $stream->getContents(), "\n"; // lo
/// var_dump($stream->eof());        // bool(true)
/// echo $stream, "\n";              // hello
/// ```
///
/// PHP: `Utopia\Psr7\Stream` (a `php://temp` stream or a wrapped resource).
#[derive(Debug)]
pub struct TempStream {
    source: Option<Source>,
    eof: bool,
}

#[derive(Debug)]
enum Source {
    Memory { data: Vec<u8>, position: usize },
    File(Disk),
}

/// A file read through a buffer of [`CHUNK`] bytes, as PHP buffers plain
/// file streams: `position` is where the caller is, the file's own offset
/// is past the buffered bytes.
#[derive(Debug)]
struct Disk {
    file: File,
    uri: String,
    mode: String,
    buffer: Vec<u8>,
    consumed: usize,
    position: u64,
}

/// PHP's stream chunk size: what one buffered read asks the file for.
const CHUNK: usize = 8192;

impl Disk {
    fn new(file: File, uri: String, mode: String) -> Disk {
        let position = (&file).stream_position().unwrap_or(0);
        Disk { file, uri, mode, buffer: Vec::new(), consumed: 0, position }
    }

    fn unread(&self) -> usize {
        self.buffer.len() - self.consumed
    }

    /// Forgets the buffered bytes, moving the file back to `position`.
    fn discard(&mut self) -> std::io::Result<()> {
        if self.buffer.is_empty() {
            return Ok(());
        }
        self.buffer.clear();
        self.consumed = 0;
        self.file.seek(SeekFrom::Start(self.position)).map(|_| ())
    }

    /// Up to `length` bytes; `Ok(None)` when the file cannot be read at all.
    fn read(&mut self, length: usize, eof: &mut bool) -> Option<Vec<u8>> {
        let mut out = Vec::with_capacity(length.min(64 * 1024));
        while out.len() < length {
            if self.unread() == 0 {
                self.buffer.resize(CHUNK, 0);
                self.consumed = 0;
                let n = loop {
                    match self.file.read(&mut self.buffer) {
                        Ok(n) => break Some(n),
                        Err(e) if e.kind() == std::io::ErrorKind::Interrupted => {}
                        Err(_) => break None,
                    }
                };
                match n {
                    Some(0) => {
                        self.buffer.clear();
                        *eof = true;
                        break;
                    }
                    Some(n) => self.buffer.truncate(n),
                    None => {
                        self.buffer.clear();
                        if out.is_empty() {
                            return None;
                        }
                        break;
                    }
                }
            }
            let n = (length - out.len()).min(self.unread());
            out.extend_from_slice(&self.buffer[self.consumed..self.consumed + n]);
            self.consumed += n;
            self.position += n as u64;
        }
        Some(out)
    }
}

impl TempStream {
    /// An in-memory stream holding `content`, positioned at the start.
    ///
    /// PHP: `new Stream($content)`, `Stream\Factory::createStream()`.
    pub fn new(content: &[u8]) -> TempStream {
        TempStream { source: Some(Source::Memory { data: content.to_vec(), position: 0 }), eof: false }
    }

    /// Opens a file with an `fopen()` mode (`r`, `w`, `a`, `x`, `c`, each
    /// optionally with `+`, and `b`/`t`).
    ///
    /// Fails with [`Error::Runtime`] (`Unable to open stream file.`) when the
    /// mode is not valid or the file cannot be opened.
    ///
    /// PHP: `Stream\Factory::createStreamFromFile()`.
    pub fn open(path: impl AsRef<Path>, mode: &str) -> Result<TempStream, Error> {
        let path = path.as_ref();
        let options = open_options(mode).ok_or_else(|| Error::runtime("Unable to open stream file."))?;
        let file = options.open(path).map_err(|_| Error::runtime("Unable to open stream file."))?;
        Ok(TempStream::from_file_at(file, path.to_string_lossy().into_owned(), mode.to_owned()))
    }

    /// Wraps an open file without reading it into memory, so a large payload
    /// stays on disk while it is sent.
    ///
    /// PHP: `Stream::fromResource()`, `Stream\Factory::createStreamFromResource()`.
    pub fn from_file(file: File, path: impl AsRef<Path>) -> TempStream {
        TempStream::from_file_at(file, path.as_ref().to_string_lossy().into_owned(), "r".to_owned())
    }

    /// Wraps what [`Stream::detach`] took from another stream.
    ///
    /// PHP: `Stream::fromResource()` of a detached resource.
    pub fn from_detached(detached: Detached) -> TempStream {
        match detached {
            Detached::Memory { data, position } => {
                TempStream { source: Some(Source::Memory { data, position }), eof: false }
            }
            Detached::File { file, uri, mode } => TempStream::from_file_at(file, uri, mode),
        }
    }

    fn from_file_at(file: File, uri: String, mode: String) -> TempStream {
        TempStream { source: Some(Source::File(Disk::new(file, uri, mode))), eof: false }
    }

    fn source(&mut self) -> Result<&mut Source, Error> {
        self.source.as_mut().ok_or_else(|| Error::runtime("Stream is detached."))
    }

    fn source_ref(&self) -> Result<&Source, Error> {
        self.source.as_ref().ok_or_else(|| Error::runtime("Stream is detached."))
    }

    /// Reads until `length` bytes or the end; a read finding nothing marks
    /// the end. `Ok(None)` when the file cannot be read.
    fn fill(&mut self, length: usize) -> Result<Option<Vec<u8>>, Error> {
        let mut eof = false;
        let out = match self.source.as_mut().ok_or_else(|| Error::runtime("Stream is detached."))? {
            Source::Memory { data, position } => {
                let mut out = Vec::with_capacity(length.min(64 * 1024));
                while out.len() < length {
                    if *position >= data.len() {
                        eof = true;
                        break;
                    }
                    let n = (length - out.len()).min(data.len() - *position);
                    out.extend_from_slice(&data[*position..*position + n]);
                    *position += n;
                }
                Some(out)
            }
            Source::File(disk) => disk.read(length, &mut eof),
        };
        if eof {
            self.eof = true;
        }
        Ok(out)
    }

    fn rest(&mut self) -> Result<Option<Vec<u8>>, Error> {
        let mut out = Vec::new();
        loop {
            match self.fill(CHUNK)? {
                None if out.is_empty() => return Ok(None),
                None => return Ok(Some(out)),
                Some(chunk) if chunk.is_empty() => return Ok(Some(out)),
                Some(chunk) => out.extend_from_slice(&chunk),
            }
        }
    }
}

/// `fopen()`'s mode string as open options; `None` when PHP refuses it.
fn open_options(mode: &str) -> Option<OpenOptions> {
    let first = *mode.as_bytes().first()?;
    let plus = mode.contains('+');
    let mut options = OpenOptions::new();
    match first {
        b'r' => {
            options.read(true).write(plus);
        }
        b'w' => {
            options.write(true).read(plus).create(true).truncate(true);
        }
        b'a' => {
            options.append(true).read(plus).create(true);
        }
        b'x' => {
            options.write(true).read(plus).create_new(true);
        }
        b'c' => {
            options.write(true).read(plus).create(true);
        }
        _ => return None,
    }
    Some(options)
}

impl Stream for TempStream {
    fn read(&mut self, length: usize) -> Result<Vec<u8>, Error> {
        if length < 1 {
            return Err(Error::runtime("Read length must be greater than zero."));
        }
        self.fill(length)?.ok_or_else(|| Error::runtime("Unable to read stream."))
    }

    fn write(&mut self, data: &[u8]) -> Result<usize, Error> {
        match self.source()? {
            Source::Memory { data: buffer, position } => {
                if *position > buffer.len() {
                    buffer.resize(*position, 0);
                }
                let end = *position + data.len();
                let overlap = end.min(buffer.len());
                buffer[*position..overlap].copy_from_slice(&data[..overlap - *position]);
                buffer.extend_from_slice(&data[overlap - *position..]);
                *position = end;
                Ok(data.len())
            }
            Source::File(disk) => {
                if data.is_empty() {
                    return Ok(0);
                }
                let failed = || Error::runtime("Unable to write to stream.");
                disk.discard().map_err(|_| failed())?;
                disk.file.write_all(data).map_err(|_| failed())?;
                disk.position += data.len() as u64;
                Ok(data.len())
            }
        }
    }

    fn seek(&mut self, position: SeekFrom) -> Result<(), Error> {
        let failed = || Error::runtime("Unable to seek stream.");
        match self.source()? {
            Source::Memory { data, position: at } => {
                let target = match position {
                    SeekFrom::Start(n) => i128::from(n),
                    SeekFrom::Current(n) => *at as i128 + i128::from(n),
                    SeekFrom::End(n) => data.len() as i128 + i128::from(n),
                };
                if target < 0 || target > i128::from(i64::MAX) {
                    return Err(failed());
                }
                *at = usize::try_from(target).map_err(|_| failed())?;
            }
            Source::File(disk) => {
                // A forward move within the buffered bytes keeps them.
                let ahead = match position {
                    SeekFrom::Current(n) if n > 0 => u64::try_from(n).ok(),
                    SeekFrom::Start(n) if n > disk.position => Some(n - disk.position),
                    _ => None,
                };
                match ahead {
                    Some(n) if n <= disk.unread() as u64 => {
                        disk.consumed += n as usize;
                        disk.position += n;
                    }
                    _ => {
                        let target = match position {
                            SeekFrom::Current(n) => {
                                let target = i128::from(disk.position) + i128::from(n);
                                SeekFrom::Start(u64::try_from(target).map_err(|_| failed())?)
                            }
                            other => other,
                        };
                        disk.buffer.clear();
                        disk.consumed = 0;
                        disk.position = disk.file.seek(target).map_err(|_| failed())?;
                    }
                }
            }
        }
        self.eof = false;
        Ok(())
    }

    fn tell(&self) -> Result<u64, Error> {
        match self.source_ref()? {
            Source::Memory { position, .. } => Ok(*position as u64),
            Source::File(disk) => Ok(disk.position),
        }
    }

    fn eof(&self) -> bool {
        self.source.is_none() || self.eof
    }

    fn size(&self) -> Result<Option<u64>, Error> {
        match self.source_ref()? {
            Source::Memory { data, .. } => Ok(Some(data.len() as u64)),
            Source::File(disk) => Ok(disk.file.metadata().ok().map(|m| m.len())),
        }
    }

    fn contents(&mut self) -> Result<Vec<u8>, Error> {
        self.rest()?.ok_or_else(|| Error::runtime("Unable to read stream contents."))
    }

    fn to_bytes(&mut self) -> Vec<u8> {
        if self.seek(SeekFrom::Start(0)).is_err() {
            return Vec::new();
        }
        self.rest().ok().flatten().unwrap_or_default()
    }

    fn close(&mut self) {
        self.source = None;
    }

    fn detach(&mut self) -> Result<Option<Detached>, Error> {
        let source = self.source.take().ok_or_else(|| Error::runtime("Stream is detached."))?;
        Ok(Some(match source {
            Source::Memory { data, position } => Detached::Memory { data, position },
            Source::File(mut disk) => {
                let _ = disk.discard();
                Detached::File { file: disk.file, uri: disk.uri, mode: disk.mode }
            }
        }))
    }

    fn is_readable(&self) -> bool {
        self.source.is_some()
    }

    fn is_writable(&self) -> bool {
        self.source.is_some()
    }

    fn is_seekable(&self) -> bool {
        self.source.is_some()
    }

    fn metadata(&self) -> Result<Vec<(&'static str, Meta)>, Error> {
        Ok(match self.source_ref()? {
            Source::Memory { .. } => vec![
                ("wrapper_type", Meta::Str("PHP".into())),
                ("stream_type", Meta::Str("TEMP".into())),
                ("mode", Meta::Str("w+b".into())),
                ("unread_bytes", Meta::Int(0)),
                ("seekable", Meta::Bool(true)),
                ("uri", Meta::Str("php://temp".into())),
            ],
            Source::File(disk) => vec![
                ("timed_out", Meta::Bool(false)),
                ("blocked", Meta::Bool(true)),
                ("eof", Meta::Bool(self.eof)),
                ("wrapper_type", Meta::Str("plainfile".into())),
                ("stream_type", Meta::Str("STDIO".into())),
                ("mode", Meta::Str(disk.mode.clone())),
                ("unread_bytes", Meta::Int(disk.unread() as i64)),
                ("seekable", Meta::Bool(true)),
                ("uri", Meta::Str(disk.uri.clone())),
            ],
        })
    }
}

/// A shared handle to a stream: what messages carry as their body.
///
/// Clones share the stream (and its position), as every copy of a PHP
/// message holds the same stream object.
///
/// PHP: the `StreamInterface` object `getBody()` returns.
#[derive(Clone)]
pub struct StreamRef(Arc<Mutex<Box<dyn Stream>>>);

impl std::fmt::Debug for StreamRef {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("StreamRef").finish_non_exhaustive()
    }
}

impl Default for StreamRef {
    /// An empty in-memory stream.
    fn default() -> Self {
        StreamRef::new(TempStream::new(b""))
    }
}

impl StreamRef {
    /// Shares `stream`.
    pub fn new(stream: impl Stream + 'static) -> StreamRef {
        StreamRef(Arc::new(Mutex::new(Box::new(stream))))
    }

    /// An in-memory stream holding `content`.
    pub fn from_bytes(content: &[u8]) -> StreamRef {
        StreamRef::new(TempStream::new(content))
    }

    /// The stream, locked for one or more calls.
    pub fn lock(&self) -> MutexGuard<'_, Box<dyn Stream>> {
        self.0.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Everything from the start ([`Stream::to_bytes`]).
    pub fn to_bytes(&self) -> Vec<u8> {
        self.lock().to_bytes()
    }

    /// Whether two handles share one stream.
    pub fn ptr_eq(&self, other: &StreamRef) -> bool {
        Arc::ptr_eq(&self.0, &other.0)
    }

    /// Calls `f` with the stream when it is a `T`, such as a
    /// [`crate::multipart::Body`] a transport sends part by part.
    ///
    /// PHP: `$body instanceof Body`.
    pub fn downcast<T: Stream, R>(&self, f: impl FnOnce(&T) -> R) -> Option<R> {
        let guard = self.lock();
        let any: &dyn std::any::Any = &**guard;
        any.downcast_ref::<T>().map(f)
    }
}

/// Creates streams.
///
/// PHP: `Psr\Http\Message\StreamFactoryInterface`.
pub trait StreamFactory {
    /// An in-memory stream holding `content`.
    ///
    /// PHP: `createStream()`.
    fn create_stream(&self, content: &[u8]) -> StreamRef;

    /// A stream over a file, opened with an `fopen()` mode.
    ///
    /// PHP: `createStreamFromFile()`.
    fn create_stream_from_file(&self, path: &Path, mode: &str) -> Result<StreamRef, Error>;
}

/// The default [`StreamFactory`]: [`TempStream`]s.
///
/// PHP: `Utopia\Psr7\Stream\Factory`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Factory;

impl StreamFactory for Factory {
    fn create_stream(&self, content: &[u8]) -> StreamRef {
        StreamRef::from_bytes(content)
    }

    fn create_stream_from_file(&self, path: &Path, mode: &str) -> Result<StreamRef, Error> {
        TempStream::open(path, mode).map(StreamRef::new)
    }
}

impl Factory {
    /// Wraps what another stream's [`Stream::detach`] handed over.
    ///
    /// PHP: `createStreamFromResource()`.
    pub fn create_stream_from_resource(&self, resource: Detached) -> StreamRef {
        StreamRef::new(TempStream::from_detached(resource))
    }
}
