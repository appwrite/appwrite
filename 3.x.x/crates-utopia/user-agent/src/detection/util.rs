/// Normalize a version string: underscores to dots, trim trailing `.` and `-`.
pub fn version(version: &str) -> String {
    trim_version(version.replace('_', "."))
}

/// First two numeric segments of a version (major.minor).
pub fn display_version(version_str: &str) -> String {
    let normalized = version(version_str);
    let parts: Vec<&str> = normalized.split('.').collect();
    match parts.len() {
        0 => String::new(),
        1 => parts[0].to_string(),
        _ => format!("{}.{}", parts[0], parts[1]),
    }
}

fn trim_version(version: String) -> String {
    version.trim_matches(&['.', '-'][..]).to_string()
}

/// Read a numeric version immediately following a named token (space or slash).
///
/// Avoids compiling a fresh `Regex` on each call (hot path for OS/client detection).
pub fn token_version(user_agent: &str, token: &str) -> Option<String> {
    if token.is_empty() {
        return None;
    }

    let ua_bytes = user_agent.as_bytes();
    let token_bytes = token.as_bytes();
    let token_len = token_bytes.len();
    if token_len > ua_bytes.len() {
        return None;
    }

    let mut i = 0;
    while i + token_len <= ua_bytes.len() {
        if eq_ignore_ascii_case(&ua_bytes[i..i + token_len], token_bytes) {
            let after = i + token_len;
            if after < ua_bytes.len() && (ua_bytes[after] == b' ' || ua_bytes[after] == b'/') {
                let ver_start = after + 1;
                if ver_start < ua_bytes.len() && ua_bytes[ver_start].is_ascii_digit() {
                    let mut ver_end = ver_start + 1;
                    while ver_end < ua_bytes.len() {
                        let c = ua_bytes[ver_end];
                        if c.is_ascii_digit() || c == b'.' || c == b'_' {
                            ver_end += 1;
                        } else {
                            break;
                        }
                    }
                    return Some(version(&user_agent[ver_start..ver_end]));
                }
            }
        }
        i += 1;
    }
    None
}

fn eq_ignore_ascii_case(a: &[u8], b: &[u8]) -> bool {
    a.len() == b.len()
        && a.iter()
            .zip(b.iter())
            .all(|(x, y)| x.eq_ignore_ascii_case(y))
}

/// Case-insensitive substring search without allocating a lowered copy of `haystack`.
pub fn contains_ignore_case(haystack: &str, needle: &str) -> bool {
    if needle.is_empty() {
        return true;
    }
    let h = haystack.as_bytes();
    let n = needle.as_bytes();
    if n.len() > h.len() {
        return false;
    }
    for i in 0..=(h.len() - n.len()) {
        if eq_ignore_ascii_case(&h[i..i + n.len()], n) {
            return true;
        }
    }
    false
}
