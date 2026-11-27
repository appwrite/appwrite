//! Process and host functions.

use std::time::{SystemTime, UNIX_EPOCH};

/// PHP `gethostname()`: the kernel's node name.
pub fn gethostname() -> String {
    rustix::system::uname().nodename().to_string_lossy().into_owned()
}

/// PHP `getmypid()`.
pub fn getmypid() -> u32 {
    std::process::id()
}

/// PHP `uniqid($prefix, $more_entropy)`: seconds and microseconds in hex
/// (`%08x%05x`), plus `%.8F` of a random value in `[0, 10)` with more entropy.
///
/// Without more entropy, like PHP, two calls never return the same value in
/// one process: a call in the same microsecond as the previous one waits for
/// the clock to move on.
pub fn uniqid(prefix: &str, more_entropy: bool) -> String {
    static LAST: std::sync::Mutex<(u64, u32)> = std::sync::Mutex::new((0, 0));
    let mut now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    if !more_entropy {
        let mut last = LAST.lock().unwrap_or_else(|e| e.into_inner());
        while (now.as_secs(), now.subsec_micros()) == *last {
            std::hint::spin_loop();
            now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
        }
        *last = (now.as_secs(), now.subsec_micros());
    }
    let mut out = format!("{prefix}{:08x}{:05x}", now.as_secs(), now.subsec_micros());
    if more_entropy {
        let mut bytes = [0u8; 8];
        let _ = getrandom::fill(&mut bytes);
        let lcg = (u64::from_le_bytes(bytes) >> 11) as f64 / (1u64 << 53) as f64;
        out.push_str(&format!("{:.8}", lcg * 10.0));
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn uniqid_shape() {
        let id = uniqid("", true);
        assert_eq!(id.len(), 23, "{id}");
        assert_eq!(&id[14..15], ".");
        assert_eq!(uniqid("p", false).len(), 14);
    }
}
