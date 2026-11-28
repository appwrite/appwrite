//! Where an algorithm writes its output, and where its codec's working memory comes from.

use std::ffi::c_void;

/// The allocator a codec's working state (zstd's contexts, brotli's encoder and decoder) comes
/// from during one call, in the C libraries' own shape: `alloc(opaque, size)` and
/// `free(opaque, address)`. A block must be aligned as `malloc()` aligns one.
///
/// The state is created and freed within the call, as PHP's extensions create and free
/// theirs, so no state outlives it: a codec behaves as on its first call, even on corrupt
/// input.
#[derive(Debug, Clone, Copy)]
pub struct Allocator {
    /// Allocates `size` bytes, or returns null.
    pub alloc: extern "C" fn(opaque: *mut c_void, size: usize) -> *mut c_void,
    /// Frees a block `alloc` returned.
    pub free: extern "C" fn(opaque: *mut c_void, address: *mut c_void),
    /// Passed to both.
    pub opaque: *mut c_void,
}

/// A growable byte buffer an algorithm writes its output into, in place.
///
/// `Vec<u8>` is one. A caller with its own string type implements it so the output is written
/// once, where it ends up: the PHP extension writes straight into a PHP string, which a `Vec`
/// would cost a second copy of (and twice the memory, for a moment).
///
/// The `*_into` methods of each algorithm ([`Gzip::compress_into`](crate::Gzip::compress_into),
/// ...) append to the sink; [`Algorithm::compress`](crate::Algorithm::compress) is
/// `compress_into` a new `Vec`. On an error, what the sink holds is unspecified.
///
/// # Safety
///
/// [`Sink::as_mut_ptr`] points to [`Sink::capacity`] writable bytes, the first [`Sink::len`] of
/// them the output so far; [`Sink::grow`] and [`Sink::reserve`] keep those bytes (the pointer
/// may change).
pub unsafe trait Sink {
    /// How many bytes were written.
    fn len(&self) -> usize;

    /// Whether nothing was written.
    fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// How many bytes fit without growing.
    fn capacity(&self) -> usize;

    /// Makes room for `capacity` bytes in all (nothing to do when there is). `false` when the
    /// memory is not there, which the algorithm reports as its codec would. The algorithms ask
    /// for what PHP's function would have at that point, so a sink that grows to exactly that
    /// (reallocating in place, as PHP's allocator does for a large block) holds no more.
    fn grow(&mut self, capacity: usize) -> bool;

    /// [`Sink::grow`] to `capacity` when that is cheap to do: a guess at the final size (a gzip
    /// trailer's length, a bound) that saves growing step by step. Declining (`false`) is
    /// never an error; the algorithm then grows as it needs to.
    fn reserve(&mut self, capacity: usize) -> bool {
        self.grow(capacity)
    }

    /// The start of the buffer.
    fn as_mut_ptr(&mut self) -> *mut u8;

    /// Records that the first `len` bytes are written.
    ///
    /// # Safety
    ///
    /// `len <= capacity()`, and the first `len` bytes were written.
    unsafe fn set_len(&mut self, len: usize);

    /// The allocator for the codec's working state during the call; `None` (the default) for
    /// the C library's own, `malloc()`.
    fn allocator(&self) -> Option<Allocator> {
        None
    }
}

// SAFETY: a `Vec`'s pointer covers its capacity, and growing keeps its contents.
unsafe impl Sink for Vec<u8> {
    fn len(&self) -> usize {
        Vec::len(self)
    }

    fn capacity(&self) -> usize {
        Vec::capacity(self)
    }

    /// Amortized, as a `Vec` grows: the algorithms grow step by step, as PHP's buffers do.
    fn grow(&mut self, capacity: usize) -> bool {
        capacity <= Vec::capacity(self) || self.try_reserve(capacity - Vec::len(self)).is_ok()
    }

    fn as_mut_ptr(&mut self) -> *mut u8 {
        Vec::as_mut_ptr(self)
    }

    unsafe fn set_len(&mut self, len: usize) {
        // SAFETY: the caller's contract.
        unsafe { Vec::set_len(self, len) }
    }
}

/// The free room of `sink` after what it holds: its address and size.
pub(crate) fn spare<S: Sink + ?Sized>(sink: &mut S) -> (*mut u8, usize) {
    let (len, capacity) = (sink.len(), sink.capacity());
    // SAFETY: `len <= capacity`, so the offset stays within the buffer.
    (unsafe { sink.as_mut_ptr().add(len) }, capacity - len)
}

/// Appends `bytes` to `sink`; `false` when it cannot grow.
pub(crate) fn append<S: Sink + ?Sized>(sink: &mut S, bytes: &[u8]) -> bool {
    let len = sink.len();
    if !sink.grow(len + bytes.len()) {
        return false;
    }
    let (at, _) = spare(sink);
    // SAFETY: room for `bytes` was made after the `len` bytes written.
    unsafe {
        std::ptr::copy_nonoverlapping(bytes.as_ptr(), at, bytes.len());
        sink.set_len(len + bytes.len());
    }
    true
}
