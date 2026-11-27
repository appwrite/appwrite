//! Runs work on a larger stack, as `stacker::grow` does, but keeps the stack for the thread's
//! next call. `stacker::grow` maps a new stack for each call and unmaps it after, so every page
//! the work touches is a fresh, zero-filled page fault: walking a tree 300 levels deep spent
//! more on those than on the work. A kept stack's pages are already there.
//!
//! One stack is kept per thread, up to [`KEPT`] bytes; a deeper call maps its own, as before.
//! A call made while the kept stack is in use (work that calls back into PHP, which calls in
//! again) maps its own too. Its low page is a guard page, as `stacker`'s is.

use std::cell::Cell;
use std::panic::{AssertUnwindSafe, catch_unwind, resume_unwind};
use std::ptr::NonNull;

/// The largest stack kept for a thread, and the size each kept stack is mapped at. The pages
/// only become memory once used, so a thread that never goes deep keeps none of it resident.
const KEPT: usize = 4 << 20;

thread_local! {
    static SPARE: Cell<Option<Stack>> = const { Cell::new(None) };
}

struct Stack {
    /// The mapping, guard page first.
    map: NonNull<u8>,
    len: usize,
    page: usize,
}

impl Stack {
    fn new(size: usize) -> Option<Self> {
        // SAFETY: plain libc calls; the mapping is checked before use and owned by `Self`.
        unsafe {
            let page = usize::try_from(libc::sysconf(libc::_SC_PAGESIZE)).ok().filter(|&page| page > 0)?;
            let len = size.div_ceil(page).checked_add(1)?.checked_mul(page)?;
            let map = libc::mmap(
                std::ptr::null_mut(),
                len,
                libc::PROT_READ | libc::PROT_WRITE,
                libc::MAP_PRIVATE | libc::MAP_ANONYMOUS | libc::MAP_NORESERVE,
                -1,
                0,
            );
            if map == libc::MAP_FAILED {
                return None;
            }
            let stack = Self { map: NonNull::new(map.cast())?, len, page };
            if libc::mprotect(map, page, libc::PROT_NONE) != 0 {
                return None;
            }
            Some(stack)
        }
    }

    /// The usable part, above the guard page.
    fn area(&self) -> (*mut u8, usize) {
        // SAFETY: `page` is inside the mapping.
        (unsafe { self.map.as_ptr().add(self.page) }, self.len - self.page)
    }
}

impl Drop for Stack {
    fn drop(&mut self) {
        // SAFETY: the mapping is this value's, and no stack frame is on it any more.
        unsafe {
            libc::munmap(self.map.as_ptr().cast(), self.len);
        }
    }
}

/// Runs `work` on a stack of at least `size` bytes.
pub fn grow<T>(size: usize, work: impl FnOnce() -> T) -> T {
    if size > KEPT {
        return stacker::grow(size, work);
    }
    let Some(stack) = SPARE.take().or_else(|| Stack::new(KEPT)) else {
        return stacker::grow(size, work);
    };
    let (base, len) = stack.area();
    let mut work = Some(work);
    // SAFETY: the area is mapped, writable and unused, and `psm::on_stack` does not let the
    // closure unwind across it: a panic is caught there and resumed here, once off it.
    let done =
        unsafe { psm::on_stack(base, len, || catch_unwind(AssertUnwindSafe(|| work.take().map(|work| work())))) };
    // A nested call may have kept its stack meanwhile; either one does.
    SPARE.set(Some(stack));
    match done {
        Ok(Some(value)) => value,
        Ok(None) => unreachable!("the work runs once"),
        Err(panic) => resume_unwind(panic),
    }
}
