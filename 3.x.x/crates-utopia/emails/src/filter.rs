//! PHP `filter_var($email, FILTER_VALIDATE_EMAIL)` (no `FILTER_FLAG_EMAIL_UNICODE`).
//!
//! Mirrors `php_filter_validate_email` / Michael Rushton regexp1 in php-src
//! `ext/filter/logical_filters.c` (PHP 8.3). Quoted local-parts and IPv6
//! literals are included so behavior matches `filter_var`, not only the
//! unquoted cases in the Utopia PHP unit suite.

/// RFC 2821 maximum length (octets), enforced before the regex in PHP.
const MAX_EMAIL_OCTETS: usize = 320;

/// `FILTER_VALIDATE_EMAIL` without `FILTER_FLAG_EMAIL_UNICODE`.
pub fn filter_validate_email(email: &str) -> bool {
    if email.len() > MAX_EMAIL_OCTETS {
        return false;
    }
    let Some((local, domain)) = split_local_domain(email) else {
        return false;
    };
    if local.is_empty() || domain.is_empty() {
        return false;
    }
    // `(?!(?:...){65,}@)` - 65+ local units before `@` fail. Unquoted, one byte
    // per unit.
    if local.len() >= 65 && !local.starts_with('"') {
        return false;
    }
    is_valid_local(local) && is_valid_domain(domain)
}

fn split_local_domain(email: &str) -> Option<(&str, &str)> {
    if let Some(rest) = email.strip_prefix('"') {
        let mut escaped = false;
        for (i, b) in rest.bytes().enumerate() {
            if escaped {
                escaped = false;
                continue;
            }
            if b == b'\\' {
                escaped = true;
                continue;
            }
            if b == b'"' {
                let after = &rest[i + 1..];
                return after.strip_prefix('@').map(|domain| {
                    let local = &email[..i + 2];
                    (local, domain)
                });
            }
        }
        return None;
    }
    let at = email.find('@')?;
    if email[at + 1..].contains('@') {
        return None;
    }
    Some((&email[..at], &email[at + 1..]))
}

fn is_local_atom_byte(b: u8) -> bool {
    matches!(
        b,
        0x21
            | 0x23..=0x27
            | 0x2A
            | 0x2B
            | 0x2D
            | 0x2F..=0x39
            | 0x3D
            | 0x3F
            | 0x41..=0x5A
            | 0x5E..=0x7E
    )
}

fn is_valid_local(local: &str) -> bool {
    if let Some(inner) = local.strip_prefix('"').and_then(|s| s.strip_suffix('"')) {
        return is_valid_quoted_local(inner);
    }
    let mut atoms = 0usize;
    for atom in local.split('.') {
        if atom.is_empty() || !atom.bytes().all(is_local_atom_byte) {
            return false;
        }
        atoms += 1;
    }
    atoms >= 1
}

fn is_valid_quoted_local(inner: &str) -> bool {
    let mut escaped = false;
    for b in inner.bytes() {
        if escaped {
            if b > 0x7F {
                return false;
            }
            escaped = false;
            continue;
        }
        if b == b'\\' {
            escaped = true;
            continue;
        }
        let ok = matches!(
            b,
            0x01..=0x08 | 0x0B | 0x0C | 0x0E..=0x1F | 0x21 | 0x23..=0x5B | 0x5D..=0x7F
        );
        if !ok {
            return false;
        }
    }
    !escaped
}

fn is_valid_domain(domain: &str) -> bool {
    if let Some(inner) = domain.strip_prefix('[').and_then(|s| s.strip_suffix(']')) {
        return is_valid_domain_literal(inner);
    }
    // `(?!.*[^.]{64,})` - no run of 64+ non-dot octets in the domain.
    if domain.split('.').any(|label| label.len() >= 64) {
        return false;
    }
    let lower = domain.to_ascii_lowercase();
    let mut labels: Vec<&str> = lower.split('.').collect();
    if labels.len() < 2 {
        return false;
    }
    let tld = labels.pop().unwrap_or_default();
    if tld.is_empty() || labels.iter().any(|label| label.is_empty()) {
        return false;
    }
    labels.iter().all(|label| is_valid_domain_label(label)) && is_valid_tld(tld)
}

fn is_valid_domain_label(label: &str) -> bool {
    let rest = label.strip_prefix("xn--").unwrap_or(label);
    alnum_then_hyphen_alnum_groups(rest, false)
}

fn is_valid_tld(tld: &str) -> bool {
    if let Some(rest) = tld.strip_prefix("xn--") {
        return alnum_then_hyphen_alnum_groups(rest, false);
    }
    alnum_then_hyphen_alnum_groups(tld, true)
}

/// `(?:[a-z0-9]+(?:-+[a-z0-9]+)*)` or, when `first_must_be_alpha`, TLD `[a-z][a-z0-9]*(?:-+[a-z0-9]+)*`.
fn alnum_then_hyphen_alnum_groups(s: &str, first_must_be_alpha: bool) -> bool {
    if s.is_empty() {
        return false;
    }
    let bytes = s.as_bytes();
    let first = bytes[0];
    if first_must_be_alpha {
        if !first.is_ascii_alphabetic() {
            return false;
        }
    } else if !first.is_ascii_alphanumeric() {
        return false;
    }
    let mut i = 1;
    while i < bytes.len() && bytes[i].is_ascii_alphanumeric() {
        i += 1;
    }
    while i < bytes.len() {
        if bytes[i] != b'-' {
            return false;
        }
        let start = i;
        while i < bytes.len() && bytes[i] == b'-' {
            i += 1;
        }
        if i == start || i == bytes.len() || !bytes[i].is_ascii_alphanumeric() {
            return false;
        }
        while i < bytes.len() && bytes[i].is_ascii_alphanumeric() {
            i += 1;
        }
    }
    true
}

fn is_valid_domain_literal(inner: &str) -> bool {
    let lower = inner.to_ascii_lowercase();
    if let Some(v6) = lower.strip_prefix("ipv6:") {
        return is_valid_ipv6_literal(v6);
    }
    is_valid_ipv4(inner)
}

fn is_valid_ipv4(s: &str) -> bool {
    let parts: Vec<&str> = s.split('.').collect();
    if parts.len() != 4 {
        return false;
    }
    parts.iter().copied().all(is_ipv4_octet)
}

fn is_ipv4_octet(part: &str) -> bool {
    if part.is_empty() || (part.len() > 1 && part.starts_with('0')) {
        return false;
    }
    part.parse::<u8>().is_ok()
}

fn is_valid_ipv6_literal(s: &str) -> bool {
    if s.split("::").count() > 2 {
        return false;
    }
    if let Some((left, right)) = s.split_once("::") {
        let left_parts = split_hextets(left);
        let right_parts = split_hextets(right);
        return left_parts.len() + right_parts.len() < 8
            && left_parts.iter().chain(&right_parts).all(|p| is_hextet(p));
    }
    let parts = split_hextets(s);
    parts.len() == 8 && parts.iter().all(|p| is_hextet(p))
}

fn split_hextets(s: &str) -> Vec<&str> {
    if s.is_empty() {
        Vec::new()
    } else {
        s.split(':').collect()
    }
}

fn is_hextet(s: &str) -> bool {
    (1..=4).contains(&s.len()) && s.bytes().all(|b| b.is_ascii_hexdigit())
}
