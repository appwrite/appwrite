//! TCP socket tuning applied to listeners and accepted streams.
//!
//! Linux-only knobs that required `unsafe` `setsockopt` in the PHP rust port
//! (TCP_FASTOPEN, TCP_DEFER_ACCEPT, TCP_USER_TIMEOUT, TCP_QUICKACK,
//! TCP_NOTSENT_LOWAT) are skipped. Portable knobs use `socket2`.

use std::io;

use socket2::SockRef;
use tokio::net::{TcpListener, TcpStream};

use super::config::TcpConfig;

/// Apply listener-side options before `accept()` starts.
pub fn apply_listener(listener: &TcpListener, config: &TcpConfig) -> io::Result<()> {
    let socket = SockRef::from(listener);

    #[cfg(unix)]
    if config.enable_reuse_port {
        let _ = socket.set_reuse_port(true);
    }

    let _ = config;
    Ok(())
}

/// Apply stream-side options on an accepted client connection.
pub fn apply_stream(stream: &TcpStream, config: &TcpConfig) -> io::Result<()> {
    stream.set_nodelay(true)?;

    let socket = SockRef::from(stream);

    if config.socket_buffer_size > 0 {
        let rcv = config.socket_buffer_size as usize;
        let _ = socket.set_recv_buffer_size(rcv);
    }
    if config.buffer_output_size > 0 {
        let snd = config.buffer_output_size as usize;
        let _ = socket.set_send_buffer_size(snd);
    }

    let keepalive = socket2::TcpKeepalive::new()
        .with_time(std::time::Duration::from_secs(u64::from(
            config.tcp_keepidle,
        )))
        .with_interval(std::time::Duration::from_secs(u64::from(
            config.tcp_keepinterval,
        )))
        .with_retries(config.tcp_keepcount);
    let _ = socket.set_tcp_keepalive(&keepalive);

    Ok(())
}
