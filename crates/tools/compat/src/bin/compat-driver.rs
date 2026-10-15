//! Rust side of the compat protocol. Started by `compat` (the runner); see
//! `compat::driver` for the protocol.

use std::alloc::{GlobalAlloc, Layout};

use mimalloc::MiMalloc;

/// mimalloc, the allocator the Rust server runs on (`crates/appwrite/server`),
/// counting allocations and live heap bytes for `$bench`.
struct Counting;

// SAFETY: every call is forwarded unchanged to mimalloc; counting
// only reads the layout and sizes.
unsafe impl GlobalAlloc for Counting {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        compat::bench::Counting::alloc(layout.size());
        // SAFETY: the caller upholds `GlobalAlloc::alloc`'s contract.
        unsafe { MiMalloc.alloc(layout) }
    }

    unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
        compat::bench::Counting::alloc(layout.size());
        // SAFETY: as above.
        unsafe { MiMalloc.alloc_zeroed(layout) }
    }

    unsafe fn realloc(&self, ptr: *mut u8, layout: Layout, new_size: usize) -> *mut u8 {
        compat::bench::Counting::realloc(layout.size(), new_size);
        // SAFETY: as above.
        unsafe { MiMalloc.realloc(ptr, layout, new_size) }
    }

    unsafe fn dealloc(&self, ptr: *mut u8, layout: Layout) {
        compat::bench::Counting::dealloc(layout.size());
        // SAFETY: as above.
        unsafe { MiMalloc.dealloc(ptr, layout) }
    }
}

#[global_allocator]
static ALLOCATOR: Counting = Counting;

fn main() -> std::io::Result<()> {
    // A child the console adapter started to observe stdio and exit().
    compat::libs::console::child();
    compat::driver::php_umask();
    // A 64 MiB stack: unoptimized builds poll the database's document
    // operations (writes through the relationships hook back into the
    // database) many frames deep.
    std::thread::Builder::new()
        .stack_size(64 * 1024 * 1024)
        .spawn(|| tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(compat::driver::serve()))?
        .join()
        .unwrap_or_else(|e| std::panic::resume_unwind(e))
}
