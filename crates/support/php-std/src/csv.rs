//! CSV parsing: `str_getcsv()` and `fgetcsv()`.
//!
//! A port of `php_fgetcsv()` (`ext/standard/file.c`, PHP 8.5): fields are
//! split at the delimiter; a field starting (after leading whitespace) with
//! the enclosure runs to the closing enclosure, a doubled enclosure is one
//! enclosure character, an escape character protects the next byte (and is
//! kept), and text after the closing enclosure up to the delimiter is
//! appended raw. Unenclosed fields lose trailing whitespace; the line's
//! trailing `\r\n`, `\n` or `\r` is dropped. An enclosure left open takes the
//! following lines (`fgetcsv()`) or runs to the end of the input
//! (`str_getcsv()`). A blank line is one `null` field.
//!
//! Bytes are characters: the dev image's C library counts one byte per
//! character in the default locale, so no multibyte sequence is special.

use std::io::{self, BufRead};

/// One parsed line: its fields, or `[null]` (`None`) for a blank line.
pub type Fields = Vec<Option<Vec<u8>>>;

fn is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// `php_fgetcsv_lookup_trailing_spaces()`: the end of `buf` without its
/// final line ending.
fn trailing(buf: &[u8], len: usize) -> usize {
    let mut last = [0u8; 2];
    for &b in &buf[..len] {
        last[0] = last[1];
        last[1] = b;
    }
    match last[1] {
        b'\n' if last[0] == b'\r' => len - 2,
        b'\n' | b'\r' => len - 1,
        _ => len,
    }
}

/// Unenclosed fields keep everything up to the line end; `php_fgetcsv`
/// trims trailing spaces only from the end of the field data it copied
/// with `php_fgetcsv_lookup_trailing_spaces`, which strips one line ending.
fn field_end(temp: &[u8]) -> usize {
    trailing(temp, temp.len())
}

struct Parser<'a> {
    delimiter: u8,
    enclosure: u8,
    escape: Option<u8>,
    next_line: Option<&'a mut dyn FnMut() -> io::Result<Option<Vec<u8>>>>,
}

impl Parser<'_> {
    /// `php_fgetcsv()`. `buf` gets a NUL appended, as the C buffer has one:
    /// the parser reads up to one byte past the line's end.
    fn parse(&mut self, line: Vec<u8>) -> io::Result<Option<Fields>> {
        let mut values: Vec<Option<Vec<u8>>> = Vec::new();
        let mut len = line.len();
        let mut buf = line;
        buf.push(0);
        let at = |buf: &Vec<u8>, i: usize| buf.get(i).copied().unwrap_or(0);
        let mut bptr = 0usize;
        let mut limit = trailing(&buf, len);
        let mut line_end = limit;
        let mut line_end_len = len - limit;
        let mut first_field = true;
        loop {
            let mut temp: Vec<u8> = Vec::new();
            let mut inc = usize::from(bptr < limit);
            if inc == 1 {
                let mut t = bptr;
                while at(&buf, t) != self.delimiter && is_space(at(&buf, t)) {
                    t += 1;
                }
                if at(&buf, t) == self.enclosure && t < limit {
                    bptr = t;
                }
            }
            if first_field && bptr == line_end {
                return Ok(None);
            }
            first_field = false;
            let comp_end;
            if inc != 0 && at(&buf, bptr) == self.enclosure {
                let mut state = 0;
                bptr += 1;
                let mut hunk = bptr;
                // 2A. The enclosed part.
                loop {
                    if inc == 0 {
                        if state == 2 {
                            temp.extend_from_slice(&buf[hunk..bptr - 1]);
                            hunk = bptr;
                            break;
                        }
                        if state == 1 {
                            temp.extend_from_slice(&buf[hunk..bptr]);
                            hunk = bptr;
                        }
                        if hunk != line_end {
                            temp.extend_from_slice(&buf[hunk..bptr]);
                            hunk = bptr;
                        }
                        temp.extend_from_slice(&buf[line_end..line_end + line_end_len]);
                        let next = match self.next_line.as_mut() {
                            Some(next) => next()?,
                            None => None,
                        };
                        match next {
                            None => {
                                if bptr > limit {
                                    if hunk == bptr {
                                        hunk -= 1;
                                    }
                                    bptr -= 1;
                                }
                                break;
                            }
                            Some(next) => {
                                len = next.len();
                                buf = next;
                                buf.push(0);
                                bptr = 0;
                                hunk = 0;
                                limit = trailing(&buf, len);
                                line_end = limit;
                                line_end_len = len - limit;
                                state = 0;
                            }
                        }
                    } else {
                        match state {
                            1 => {
                                bptr += 1;
                                state = 0;
                            }
                            2 => {
                                if at(&buf, bptr) != self.enclosure {
                                    temp.extend_from_slice(&buf[hunk..bptr - 1]);
                                    hunk = bptr;
                                    break;
                                }
                                temp.extend_from_slice(&buf[hunk..bptr]);
                                bptr += 1;
                                hunk = bptr;
                                state = 0;
                            }
                            _ => {
                                let b = at(&buf, bptr);
                                if b == self.enclosure {
                                    state = 2;
                                } else if self.escape == Some(b) {
                                    state = 1;
                                }
                                bptr += 1;
                            }
                        }
                    }
                    inc = usize::from(bptr < limit);
                }
                // Up to the delimiter.
                while inc != 0 && at(&buf, bptr) != self.delimiter {
                    bptr += 1;
                    inc = usize::from(bptr < limit);
                }
                temp.extend_from_slice(&buf[hunk..bptr]);
                bptr += inc;
                comp_end = temp.len();
            } else {
                // 2B. An unenclosed field.
                let hunk = bptr;
                while inc != 0 && at(&buf, bptr) != self.delimiter {
                    bptr += 1;
                    inc = usize::from(bptr < limit);
                }
                temp.extend_from_slice(&buf[hunk..bptr]);
                comp_end = field_end(&temp);
                if at(&buf, bptr) == self.delimiter {
                    bptr += 1;
                }
            }
            temp.truncate(comp_end);
            values.push(Some(temp));
            if inc == 0 {
                break;
            }
        }
        Ok(Some(values))
    }
}

/// PHP `str_getcsv($string, $separator, $enclosure, $escape)`: the fields of
/// `input` (an enclosure left open runs to the end); `escape` `None` is
/// `""` (no escape character).
pub fn str_getcsv(input: &[u8], delimiter: u8, enclosure: u8, escape: Option<u8>) -> Fields {
    let mut p = Parser { delimiter, enclosure, escape, next_line: None };
    p.parse(input.to_vec()).ok().flatten().unwrap_or_else(|| vec![None])
}

/// Reads one line, up to and including `\n` (`php_stream_get_line()`).
fn read_line<R: BufRead>(reader: &mut R) -> io::Result<Option<Vec<u8>>> {
    let mut line = Vec::new();
    let n = reader.read_until(b'\n', &mut line)?;
    Ok(if n == 0 { None } else { Some(line) })
}

/// PHP `fgetcsv($stream, 0, $separator, $enclosure, $escape)`: the fields of
/// the next record (an open enclosure takes the following lines), or `None`
/// at the end of the stream (PHP's `false`).
pub fn fgetcsv<R: BufRead>(
    reader: &mut R,
    delimiter: u8,
    enclosure: u8,
    escape: Option<u8>,
) -> io::Result<Option<Fields>> {
    let Some(first) = read_line(reader)? else {
        return Ok(None);
    };
    let mut next = || read_line(reader);
    let mut p = Parser { delimiter, enclosure, escape, next_line: Some(&mut next) };
    Ok(Some(p.parse(first)?.unwrap_or_else(|| vec![None])))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn f(s: &str) -> Vec<Option<String>> {
        str_getcsv(s.as_bytes(), b',', b'"', Some(b'"'))
            .into_iter()
            .map(|v| v.map(|b| String::from_utf8(b).unwrap()))
            .collect()
    }

    #[test]
    fn fields() {
        assert_eq!(f("a,b,c"), [Some("a".into()), Some("b".into()), Some("c".into())]);
        assert_eq!(f(""), [None]);
        assert_eq!(f("\"a,b\",c"), [Some("a,b".into()), Some("c".into())]);
        assert_eq!(f("\"a\"\"b\""), [Some("a\"b".into())]);
        assert_eq!(f("a ,b"), [Some("a ".into()), Some("b".into())]);
        assert_eq!(f("a,"), [Some("a".into()), Some("".into())]);
    }

    #[test]
    fn multi_line() {
        let mut r = io::Cursor::new(b"\"a\nb\",c\nd\n".to_vec());
        let row = fgetcsv(&mut r, b',', b'"', Some(b'"')).unwrap().unwrap();
        assert_eq!(row, vec![Some(b"a\nb".to_vec()), Some(b"c".to_vec())]);
        let row = fgetcsv(&mut r, b',', b'"', Some(b'"')).unwrap().unwrap();
        assert_eq!(row, vec![Some(b"d".to_vec())]);
        assert!(fgetcsv(&mut r, b',', b'"', Some(b'"')).unwrap().is_none());
    }
}
