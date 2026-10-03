//! 4-tuple key packing for the BPF sockmap. MUST match PHP `Loader::tupleKey()`
//! bit-for-bit so the BPF program finds the same socket entries.
//!
//! Key layout (from `relay.bpf.c::tuple_key`):
//!   bits 48..63: local_port (host byte order, low 16 bits)
//!   bits 32..47: remote_port as raw network-order bytes read as a little-endian u16
//!   bits 0..31 : remote_ip4 as raw network-order bytes read as a little-endian u32
//!
//! The PHP implementation reads the remote port with `unpack('v', ...)` (little-endian
//! read) and the remote IP with `unpack('V', ...)` (little-endian read) - but the
//! source bytes are already in network order. We reproduce that by taking the
//! raw big-endian bytes from `sockaddr_in` and interpreting them as little-endian.
//!
//! Inputs:
//! - `local_port`: host byte order (already correct)
//! - `remote_port_be_bytes`: the 2 bytes of `sockaddr_in.sin_port` (network order)
//! - `remote_ip_be_bytes`: the 4 bytes of `sockaddr_in.sin_addr.s_addr` (network order)

/// Pack the tuple key from raw network-order bytes.
pub fn tuple_key(
    local_port: u16,
    remote_port_be_bytes: [u8; 2],
    remote_ip_be_bytes: [u8; 4],
) -> u64 {
    // Read the raw bytes as little-endian (mirrors PHP `unpack('v')` and `unpack('V')`).
    let rport_le = u16::from_le_bytes(remote_port_be_bytes);
    let rip_le = u32::from_le_bytes(remote_ip_be_bytes);

    let mut k: u64 = u64::from(local_port) & 0xffff;
    k <<= 16;
    k |= u64::from(rport_le) & 0xffff;
    k <<= 32;
    k |= u64::from(rip_le);
    k
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Fixture: local_port 50000, remote 127.0.0.1:8080.
    /// sockaddr_in.sin_port for 8080 network order = 0x1F 0x90.
    /// sockaddr_in.sin_addr.s_addr for 127.0.0.1 network order = 0x7F 0x00 0x00 0x01.
    /// PHP `unpack('v', "\x1F\x90")` = 0x901F (little-endian u16 read of BE bytes).
    /// PHP `unpack('V', "\x7F\x00\x00\x01")` = 0x0100007F.
    /// Expected: bits 48..63 = 50000 (0xC350), bits 32..47 = 0x901F,
    /// bits 0..31 = 0x0100007F.
    #[test]
    fn tuple_key_matches_php_pack() {
        let local_port: u16 = 50000;
        let remote_port_be = [0x1F, 0x90]; // 8080 in network order
        let remote_ip_be = [0x7F, 0x00, 0x00, 0x01]; // 127.0.0.1 in network order

        let k = tuple_key(local_port, remote_port_be, remote_ip_be);

        // Manual reconstruction:
        //   ((50000 & 0xffff) << 48)      = 0xC350_0000_0000_0000
        //   | ((0x901F & 0xffff) << 32)   = 0x0000_901F_0000_0000
        //   | 0x0100007F                  = 0x0000_0000_0100_007F
        let expected: u64 = (0xC350u64 << 48) | (0x901Fu64 << 32) | 0x0100_007Fu64;
        assert_eq!(k, expected);
    }

    #[test]
    fn tuple_key_different_inputs_differ() {
        let a = tuple_key(5432, [0x1F, 0x90], [0x08, 0x08, 0x08, 0x08]);
        let b = tuple_key(5433, [0x1F, 0x90], [0x08, 0x08, 0x08, 0x08]);
        assert_ne!(a, b);
    }

    #[test]
    fn tuple_key_zero_port_still_packs() {
        let k = tuple_key(1, [0, 0], [0, 0, 0, 0]);
        assert_eq!(k, 1u64 << 48);
    }
}
