//! What the schema builders accept as names, types and settings, and how
//! they write string literals. Each check is the PHP pattern it ports,
//! scanned natively: PHP's `$` (without `/D`) also matches before a final
//! newline, so a value ending in one `"\n"` passes when the rest does.

/// The value without one final newline, as PCRE's `$` sees its end.
fn body(s: &str) -> &str {
    s.strip_suffix('\n').unwrap_or(s)
}

fn word_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

fn word_start(b: u8) -> bool {
    b.is_ascii_alphabetic() || b == b'_'
}

/// PCRE's `\s` without `/u`: space, `\t`, `\n`, `\v`, `\f`, `\r`.
fn space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r')
}

/// `/^[A-Za-z_][A-Za-z0-9_]*$/`.
pub(crate) fn is_identifier(s: &str) -> bool {
    let b = body(s).as_bytes();
    !b.is_empty() && word_start(b[0]) && b[1..].iter().all(|&c| word_byte(c))
}

/// `/^[A-Za-z0-9_]+$/`.
pub(crate) fn is_word(s: &str) -> bool {
    let b = body(s).as_bytes();
    !b.is_empty() && b.iter().all(|&c| word_byte(c))
}

/// `/^[A-Za-z0-9_.]+$/`.
pub(crate) fn is_dotted_word(s: &str) -> bool {
    let b = body(s).as_bytes();
    !b.is_empty() && b.iter().all(|&c| word_byte(c) || c == b'.')
}

/// `/^[A-Za-z0-9_.\-+\/]+$/`.
pub(crate) fn is_setting_value(s: &str) -> bool {
    let b = body(s).as_bytes();
    !b.is_empty() && b.iter().all(|&c| word_byte(c) || matches!(c, b'.' | b'-' | b'+' | b'/'))
}

/// `/^[A-Za-z_][A-Za-z0-9_]*(\s+[A-Za-z_][A-Za-z0-9_]*)*(\s*\(\s*[A-Za-z0-9_,\s]+\s*\))?$/`:
/// words separated by whitespace, optionally followed by a parenthesised
/// argument list (`DECIMAL(10, 2)`, `DOUBLE PRECISION`).
pub(crate) fn is_type_declaration(s: &str) -> bool {
    fn full(b: &[u8]) -> bool {
        if b.is_empty() || !word_start(b[0]) {
            return false;
        }
        let mut i = 1;
        loop {
            while i < b.len() && word_byte(b[i]) {
                i += 1;
            }
            if i == b.len() {
                return true;
            }
            let spaces = i;
            while i < b.len() && space(b[i]) {
                i += 1;
            }
            if i < b.len() && word_start(b[i]) && i > spaces {
                i += 1;
                continue;
            }
            if i < b.len() && b[i] == b'(' {
                let rest = &b[i + 1..];
                return match rest.split_last() {
                    Some((b')', inner)) => {
                        !inner.is_empty() && inner.iter().all(|&c| word_byte(c) || c == b',' || space(c))
                    }
                    _ => false,
                };
            }
            return false;
        }
    }
    let b = s.as_bytes();
    full(b) || (b.last() == Some(&b'\n') && full(&b[..b.len() - 1]))
}

/// A column name made safe for an index name: every run of characters
/// outside `[A-Za-z0-9_]` becomes one `_`.
pub(crate) fn sanitize(name: &str) -> String {
    let mut out = String::with_capacity(name.len());
    let mut in_run = false;
    for c in name.chars() {
        if c.is_ascii_alphanumeric() || c == '_' {
            out.push(c);
            in_run = false;
        } else if !in_run {
            out.push('_');
            in_run = true;
        }
    }
    out
}

/// The text of a single-quoted SQL literal: `\` doubled, `'` doubled.
pub(crate) fn escape(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    for c in s.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '\'' => out.push_str("''"),
            c => out.push(c),
        }
    }
    out
}

/// `'text'` with [`escape`].
pub(crate) fn literal(s: &str) -> String {
    format!("'{}'", escape(s))
}

/// A float without exponent and trailing zeros:
/// `rtrim(rtrim(sprintf('%F', $value), '0'), '.')`.
pub(crate) fn fixed(value: f64) -> String {
    let printed = php_std::format::sprintf(b"%F", &[php_std::format::Arg::Float(value)]).unwrap_or_default();
    let s = String::from_utf8_lossy(&printed).into_owned();
    s.trim_end_matches('0').trim_end_matches('.').to_owned()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn patterns_allow_a_final_newline() {
        assert!(is_identifier("abc\n"));
        assert!(!is_identifier("abc\n\n"));
        assert!(!is_identifier("1abc"));
        assert!(is_word("utf8mb4_bin"));
        assert!(!is_word(""));
        assert!(!is_word("\n"));
    }

    #[test]
    fn type_declarations() {
        assert!(is_type_declaration("INT"));
        assert!(is_type_declaration("DOUBLE PRECISION"));
        assert!(is_type_declaration("DECIMAL(10, 2)"));
        assert!(is_type_declaration("VARCHAR (255)"));
        assert!(is_type_declaration("INT\n"));
        assert!(!is_type_declaration("INT "));
        assert!(!is_type_declaration("INT()"));
        assert!(!is_type_declaration("INT; DROP"));
        assert!(!is_type_declaration("INT(1))"));
    }

    #[test]
    fn sanitizes_runs() {
        assert_eq!(sanitize("a.b-c"), "a_b_c");
        assert_eq!(sanitize("a..b"), "a_b");
        assert_eq!(sanitize("é"), "_");
    }

    #[test]
    fn fixed_floats() {
        assert_eq!(fixed(0.01), "0.01");
        assert_eq!(fixed(1.0), "1");
        assert_eq!(fixed(0.00001), "0.00001");
    }
}
