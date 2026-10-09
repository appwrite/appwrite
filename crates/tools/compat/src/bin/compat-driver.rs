//! Rust side of the compat protocol. Started by `compat` (the runner); see
//! `compat::driver` for the protocol.

fn main() -> std::io::Result<()> {
    tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(compat::driver::serve())
}
