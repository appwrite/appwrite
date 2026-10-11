//! RESP2, the Redis wire protocol: commands out ([`encode`]), replies in
//! ([`parse`]), and server errors raised ([`unwrap`]).

use php_std::format::str_to_int;

use crate::{Error, Result};

/// A reply frame.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Reply {
    /// A null bulk string or null array (`$-1`, `*-1`).
    Nil,
    /// An integer (`:`).
    Int(i64),
    /// A simple (`+`) or bulk (`$`) string.
    Str(Vec<u8>),
    /// An array (`*`).
    Array(Vec<Reply>),
    /// An error reply (`-`), its text without the dash.
    Error(Vec<u8>),
}

impl Reply {
    /// The string, for simple and bulk strings.
    pub fn as_bytes(&self) -> Option<&[u8]> {
        match self {
            Reply::Str(s) => Some(s),
            _ => None,
        }
    }

    /// Whether the reply counts as true: not nil, not an error, not `0`, not
    /// an empty string or `"0"`, not an empty array.
    ///
    /// PHP: the `(bool)` of the decoded reply.
    pub fn truthy(&self) -> bool {
        match self {
            Reply::Nil | Reply::Error(_) => false,
            Reply::Int(i) => *i != 0,
            Reply::Str(s) => !(s.is_empty() || s.as_slice() == b"0"),
            Reply::Array(items) => !items.is_empty(),
        }
    }
}

/// A command as a RESP array of bulk strings.
///
/// ```
/// assert_eq!(utopia_cache::resp::encode(&[b"SET", b"foo", b"bar"]), b"*3\r\n$3\r\nSET\r\n$3\r\nfoo\r\n$3\r\nbar\r\n");
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Redis\Client;
///
/// echo json_encode(Client::encode(['SET', 'foo', 'bar'])), "\n"; // "*3\r\n$3\r\nSET\r\n$3\r\nfoo\r\n$3\r\nbar\r\n"
/// ```
pub fn encode<A: AsRef<[u8]>>(args: &[A]) -> Vec<u8> {
    let mut out = Vec::with_capacity(16 + args.iter().map(|a| a.as_ref().len() + 16).sum::<usize>());
    encode_into(&mut out, args);
    out
}

/// [`encode`] appended to `out`.
pub fn encode_into<A: AsRef<[u8]>>(out: &mut Vec<u8>, args: &[A]) {
    out.push(b'*');
    out.extend_from_slice(args.len().to_string().as_bytes());
    out.extend_from_slice(b"\r\n");
    for arg in args {
        let arg = arg.as_ref();
        out.push(b'$');
        out.extend_from_slice(arg.len().to_string().as_bytes());
        out.extend_from_slice(b"\r\n");
        out.extend_from_slice(arg);
        out.extend_from_slice(b"\r\n");
    }
}

/// One reply from `buffer` at `offset`, advancing `offset` past what it
/// read. `Ok(None)` when the buffer does not hold a whole frame yet (the
/// offset may have moved past a header); an error for an unknown frame type.
///
/// ```
/// use utopia_cache::resp::{parse, Reply};
///
/// let mut offset = 0;
/// assert_eq!(parse(b"*2\r\n:1\r\n$1\r\nx\r\n", &mut offset)?, Some(Reply::Array(vec![Reply::Int(1), Reply::Str(b"x".to_vec())])));
/// assert_eq!(offset, 15);
/// assert_eq!(parse(b"$5\r\nhel", &mut 0)?, None);
/// # Ok::<(), utopia_cache::Error>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Redis\Client;
///
/// $offset = 0;
/// var_dump(Client::parse("*2\r\n:1\r\n\$1\r\nx\r\n", $offset)); // [1, "x"]
/// var_dump($offset); // int(15)
/// $offset = 0;
/// var_dump(Client::parse("\$5\r\nhel", $offset) === Client::INCOMPLETE); // bool(true)
/// ```
pub fn parse(buffer: &[u8], offset: &mut usize) -> Result<Option<Reply>> {
    let Some(&kind) = buffer.get(*offset) else { return Ok(None) };
    let Some(end) = find_crlf(buffer, *offset + 1) else { return Ok(None) };
    let line = &buffer[*offset + 1..end];
    *offset = end + 2;
    Ok(Some(match kind {
        b'+' => Reply::Str(line.to_vec()),
        b'-' => Reply::Error(line.to_vec()),
        b':' => Reply::Int(str_to_int(line)),
        b'$' => {
            let len = str_to_int(line);
            if len == -1 {
                return Ok(Some(Reply::Nil));
            }
            let start = *offset as i64;
            if (buffer.len() as i64) < start.saturating_add(len).saturating_add(2) {
                return Ok(None);
            }
            let value = substr(buffer, *offset, len);
            *offset = (start + len + 2).max(0) as usize;
            Reply::Str(value.to_vec())
        }
        b'*' => {
            let count = str_to_int(line);
            if count == -1 {
                return Ok(Some(Reply::Nil));
            }
            let mut items = Vec::new();
            for _ in 0..count.max(0) {
                match parse(buffer, offset)? {
                    Some(item) => items.push(item),
                    None => return Ok(None),
                }
            }
            Reply::Array(items)
        }
        other => {
            let mut message = b"Unknown RESP type: ".to_vec();
            message.push(other);
            return Err(Error::Redis(String::from_utf8_lossy(&message).into_owned()));
        }
    }))
}

fn find_crlf(buffer: &[u8], from: usize) -> Option<usize> {
    buffer.get(from..)?.windows(2).position(|w| w == b"\r\n").map(|i| from + i)
}

/// `substr($buffer, $offset, $len)` for `$len >= -strlen`.
fn substr(buffer: &[u8], offset: usize, len: i64) -> &[u8] {
    let rest = &buffer[offset.min(buffer.len())..];
    if len >= 0 {
        &rest[..(len as usize).min(rest.len())]
    } else {
        let keep = rest.len() as i64 + len;
        if keep <= 0 { &[] } else { &rest[..keep as usize] }
    }
}

/// The reply with every error reply in it raised: the first one found,
/// depth first, becomes [`Error::Redis`].
///
/// ```
/// use utopia_cache::resp::{unwrap, Reply};
///
/// assert!(unwrap(Reply::Array(vec![Reply::Int(1), Reply::Error(b"NOAUTH".to_vec())])).is_err());
/// assert_eq!(unwrap(Reply::Int(42))?, Reply::Int(42));
/// # Ok::<(), utopia_cache::Error>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Redis\Client;
/// use Utopia\Cache\Adapter\Redis\RedisError;
///
/// try {
///     Client::unwrap([1, new RedisError(new \RedisException('NOAUTH'))]);
/// } catch (\RedisException $e) {
///     echo $e->getMessage(), "\n"; // NOAUTH
/// }
/// echo Client::unwrap(42), "\n"; // 42
/// ```
pub fn unwrap(reply: Reply) -> Result<Reply> {
    match reply {
        Reply::Error(message) => Err(Error::Redis(String::from_utf8_lossy(&message).into_owned())),
        Reply::Array(items) => Ok(Reply::Array(items.into_iter().map(unwrap).collect::<Result<_>>()?)),
        other => Ok(other),
    }
}

/// Whether a Redis error's code (its first word) is `NOSCRIPT`.
///
/// PHP: `NoScript::matches`.
///
/// ```
/// assert!(utopia_cache::resp::is_noscript("NOSCRIPT No matching script. Please use EVAL."));
/// assert!(!utopia_cache::resp::is_noscript("ERR user set key to \"NOSCRIPT\""));
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Redis\NoScript;
///
/// var_dump(NoScript::matches('NOSCRIPT No matching script. Please use EVAL.')); // bool(true)
/// var_dump(NoScript::matches('ERR user set key to "NOSCRIPT"')); // bool(false)
/// ```
pub fn is_noscript(error: &str) -> bool {
    error.split(' ').next() == Some("NOSCRIPT")
}
