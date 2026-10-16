//! BPF sockmap loader. Mirrors `src/Sockmap/Loader.php`.
//!
//! Workspace `unsafe_code` is forbidden, so the Linux BPF attach path is not
//! compiled in. `load()` always reports unavailable; tuple packing stays for tests.

pub mod tuple;

use std::os::fd::RawFd;
use std::path::{Path, PathBuf};

use parking_lot::Mutex;

/// BPF sockmap zero-copy relay loader (inert without unsafe BPF syscalls).
#[derive(Debug)]
pub struct Sockmap {
    bpf_object_path: PathBuf,
    state: Mutex<State>,
}

#[derive(Debug)]
struct State {
    available: bool,
    last_error: String,
}

impl Sockmap {
    pub fn new(bpf_object_path: impl AsRef<Path>) -> Self {
        Self {
            bpf_object_path: bpf_object_path.as_ref().to_path_buf(),
            state: Mutex::new(State {
                available: false,
                last_error: String::new(),
            }),
        }
    }

    pub fn bpf_object_path(&self) -> &Path {
        &self.bpf_object_path
    }

    /// Attempt to load the BPF object. Always fails in this port (no unsafe).
    pub fn load(&self) -> bool {
        let mut state = self.state.lock();
        state.available = false;
        state.last_error =
            "sockmap BPF loader requires unsafe (disabled in utopia-proxy)".to_string();
        false
    }

    pub fn is_available(&self) -> bool {
        self.state.lock().available
    }

    pub fn last_error(&self) -> String {
        self.state.lock().last_error.clone()
    }

    pub fn insert_pair(&self, accept_fd: RawFd, backend_fd: RawFd) -> bool {
        let _ = (accept_fd, backend_fd);
        false
    }

    pub fn remove_pair(&self, accept_fd: RawFd, backend_fd: RawFd) {
        let _ = (accept_fd, backend_fd);
    }

    pub fn close(&self) {
        let mut state = self.state.lock();
        state.available = false;
    }
}

impl Drop for Sockmap {
    fn drop(&mut self) {
        self.close();
    }
}
