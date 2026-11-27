//! The C that PHP's own `crypt()`, `password_verify()` and `md5()` run, exported by
//! ext/standard for other extensions. php-std ports them to Rust, which gives the same
//! bytes but is slower: its blowfish takes 18% longer than PHP's, so a Bcrypt or PHPass
//! hash or verify did too. Argon2 stays in Rust, which is faster than PHP's.

use std::ffi::{c_char, c_int};
use std::sync::OnceLock;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::ffi::{HashTable, zend_string};
use ext_php_rs::types::ZendStr;

/// `PHP_MAX_SALT_LEN` (ext/standard/php_crypt.h).
const MAX_SALT_LEN: usize = 123;

/// `PHP_MD5_CTX` (ext/standard/md5.h).
#[repr(C)]
struct Md5Context {
    lo: u32,
    hi: u32,
    a: u32,
    b: u32,
    c: u32,
    d: u32,
    buffer: [u8; 64],
    block: [u32; 16],
}

unsafe extern "C" {
    fn php_crypt(
        password: *const c_char,
        pass_len: c_int,
        salt: *const c_char,
        salt_len: c_int,
        quiet: bool,
    ) -> *mut zend_string;
    #[link_name = "PHP_MD5InitArgs"]
    fn md5_init(context: *mut Md5Context, args: *mut HashTable);
    #[link_name = "PHP_MD5Update"]
    fn md5_update(context: *mut Md5Context, data: *const u8, size: usize);
    #[link_name = "PHP_MD5Final"]
    fn md5_final(result: *mut u8, context: *mut Md5Context);
}

/// `php_crypt($password, $salt)`, as PHP passes them: NUL-terminated (the blowfish key and
/// the salt are read as C strings), with their lengths. `None` when it fails.
fn crypt_raw(password: &[u8], salt: &[u8]) -> Option<Vec<u8>> {
    let (Ok(pass_len), Ok(salt_len)) = (c_int::try_from(password.len()), c_int::try_from(salt.len())) else {
        return None;
    };
    let password = [password, b"\0"].concat();
    let salt = [salt, b"\0"].concat();
    // SAFETY: both buffers end in a NUL past the lengths given, as a `zend_string` does; the
    // result is a new string this owns (the first and only reference), released by `ZBox`.
    unsafe {
        // `quiet` is unused by PHP 8.5's php_crypt().
        let result = php_crypt(password.as_ptr().cast(), pass_len, salt.as_ptr().cast(), salt_len, false);
        if result.is_null() {
            return None;
        }
        Some(ZBox::from_raw(result.cast::<ZendStr>()).as_bytes().to_vec())
    }
}

/// `crypt($string, $salt)` (ext/standard/crypt.c): the salt cut to `PHP_MAX_SALT_LEN`, and
/// `*0` (or `*1` for a salt starting `*0`) when it fails.
pub fn crypt(string: &[u8], salt: &[u8]) -> Vec<u8> {
    let salt = &salt[..salt.len().min(MAX_SALT_LEN)];
    crypt_raw(string, salt).unwrap_or_else(|| if salt.starts_with(b"*0") { b"*1".to_vec() } else { b"*0".to_vec() })
}

/// `password_verify($password, $hash)` (ext/standard/password.c): Argon2 hashes in Rust,
/// every other (bcrypt, the default algorithm) as `php_password_bcrypt_verify()` does.
pub fn password_verify(password: &[u8], hash: &[u8]) -> bool {
    if matches!(ident(hash), Some(b"argon2i" | b"argon2id")) {
        return php_std::string::password_verify(password, hash);
    }
    let Some(computed) = crypt_raw(password, hash) else { return false };
    if hash.len() < 13 {
        return false;
    }
    // `php_safe_bcmp()`: lengths first, then every byte.
    computed.len() == hash.len() && computed.iter().zip(hash).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
}

/// `php_password_algo_extract_ident()`: between the first byte and the next `$`.
fn ident(hash: &[u8]) -> Option<&[u8]> {
    if hash.len() < 3 {
        return None;
    }
    let rest = &hash[1..];
    rest.iter().position(|&b| b == b'$').map(|end| &rest[..end])
}

/// `md5($data)`: the lowercase hex digest, through PHP's MD5 once the first call has
/// checked the context layout against php-std's; otherwise php-std's.
pub fn md5_hex(data: &[u8]) -> [u8; 32] {
    static NATIVE: OnceLock<bool> = OnceLock::new();
    let native = *NATIVE.get_or_init(|| {
        [&b""[..], b"abc", &[0x5a; 200]].iter().all(|input| md5_native(input) == php_std::string::md5_hex(input))
    });
    if native { md5_native(data) } else { php_std::string::md5_hex(data) }
}

fn md5_native(data: &[u8]) -> [u8; 32] {
    let mut context = std::mem::MaybeUninit::<Md5Context>::zeroed();
    let mut digest = [0u8; 16];
    // SAFETY: a context of the header's layout, initialised before use, and a 16-byte digest.
    unsafe {
        md5_init(context.as_mut_ptr(), std::ptr::null_mut());
        md5_update(context.as_mut_ptr(), data.as_ptr(), data.len());
        md5_final(digest.as_mut_ptr(), context.as_mut_ptr());
    }
    let mut hex = [0u8; 32];
    for (i, byte) in digest.iter().enumerate() {
        hex[2 * i] = b"0123456789abcdef"[usize::from(byte >> 4)];
        hex[2 * i + 1] = b"0123456789abcdef"[usize::from(byte & 15)];
    }
    hex
}
