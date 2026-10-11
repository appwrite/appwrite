//! Rust side of the compat protocol. Started by `compat` (the runner); see
//! `compat::driver` for the protocol.

use std::alloc::{GlobalAlloc, Layout, System};

/// The system allocator, counting allocations for `$bench`.
struct Counting;

// SAFETY: every call is forwarded unchanged to the system allocator; counting
// only reads the layout.
unsafe impl GlobalAlloc for Counting {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        compat::bench::Counting::record(layout.size());
        // SAFETY: the caller upholds `GlobalAlloc::alloc`'s contract.
        unsafe { System.alloc(layout) }
    }

    unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
        compat::bench::Counting::record(layout.size());
        // SAFETY: as above.
        unsafe { System.alloc_zeroed(layout) }
    }

    unsafe fn realloc(&self, ptr: *mut u8, layout: Layout, new_size: usize) -> *mut u8 {
        compat::bench::Counting::record(new_size);
        // SAFETY: as above.
        unsafe { System.realloc(ptr, layout, new_size) }
    }

    unsafe fn dealloc(&self, ptr: *mut u8, layout: Layout) {
        // SAFETY: as above.
        unsafe { System.dealloc(ptr, layout) }
    }
}

#[global_allocator]
static ALLOCATOR: Counting = Counting;

fn main() -> std::io::Result<()> {
    tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(compat::driver::serve())
}
