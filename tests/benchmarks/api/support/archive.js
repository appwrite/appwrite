// Code archives for deployments, built in k6 (which has no TextEncoder or zlib):
// a ustar archive in an uncompressed (stored) gzip stream, as the API accepts.
// Shared by functions.js and sites.js; files under support/ are not service scripts.

/** `files` (name → text) as a .tar.gz, in bytes. */
export function archive(files) {
    return gzip(tar(files));
}

/** A ustar archive of `files` (name → text). */
function tar(files) {
    const blocks = [];
    for (const [name, text] of Object.entries(files)) {
        const data = ascii(text);
        const header = new Uint8Array(512);
        const put = (offset, value) => header.set(ascii(value), offset);
        put(0, name);
        put(100, '0000644\0');
        put(108, '0000000\0');
        put(116, '0000000\0');
        put(124, `${data.length.toString(8).padStart(11, '0')}\0`);
        put(136, `${Math.floor(Date.now() / 1000).toString(8).padStart(11, '0')}\0`);
        put(148, '        ');
        put(156, '0');
        put(257, 'ustar\0');
        put(263, '00');
        const sum = header.reduce((a, b) => a + b, 0);
        put(148, `${sum.toString(8).padStart(6, '0')}\0 `);
        blocks.push(header, data, new Uint8Array((512 - (data.length % 512)) % 512));
    }
    blocks.push(new Uint8Array(1024));
    return concat(blocks);
}

/** `bytes` as a gzip stream of stored (uncompressed) deflate blocks. */
function gzip(bytes) {
    const parts = [new Uint8Array([0x1f, 0x8b, 8, 0, 0, 0, 0, 0, 0, 0xff])];
    for (let at = 0; at === 0 || at < bytes.length; at += 65535) {
        const chunk = bytes.subarray(at, at + 65535);
        const last = at + 65535 >= bytes.length ? 1 : 0;
        parts.push(new Uint8Array([last, chunk.length & 0xff, chunk.length >> 8, ~chunk.length & 0xff, (~chunk.length >> 8) & 0xff]), chunk);
    }
    parts.push(le32(crc32(bytes)), le32(bytes.length));
    return concat(parts);
}

/** ASCII text as bytes (k6 has no TextEncoder). */
function ascii(text) {
    return Uint8Array.from(text.split(''), (c) => c.charCodeAt(0));
}

function crc32(bytes) {
    let crc = ~0;
    for (const b of bytes) {
        crc ^= b;
        for (let k = 0; k < 8; k++) {
            crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
        }
    }
    return ~crc >>> 0;
}

function le32(n) {
    return new Uint8Array([n & 0xff, (n >>> 8) & 0xff, (n >>> 16) & 0xff, (n >>> 24) & 0xff]);
}

function concat(parts) {
    const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
    let at = 0;
    for (const p of parts) {
        out.set(p, at);
        at += p.length;
    }
    return out;
}

