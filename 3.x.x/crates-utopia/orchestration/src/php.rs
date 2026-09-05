use std::collections::HashMap;
use std::fmt::Write;

use crate::error::OrchestrationError;

/// PHP `empty()` for floats (`0.0` is empty).
#[must_use]
pub fn php_empty_f64(value: f64) -> bool {
    value == 0.0
}

/// PHP `empty()` for ints.
#[must_use]
pub fn php_empty_i64(value: i64) -> bool {
    value == 0
}

/// PHP `empty()` for strings (`""` and `"0"`).
#[must_use]
pub fn php_empty_str(value: &str) -> bool {
    value.is_empty() || value == "0"
}

/// PHP `substr($s, $start, $length)` on bytes.
#[must_use]
pub fn php_substr(s: &str, start: usize, length: usize) -> String {
    let bytes = s.as_bytes();
    if start >= bytes.len() {
        return String::new();
    }
    let end = (start + length).min(bytes.len());
    String::from_utf8_lossy(&bytes[start..end]).into_owned()
}

/// PHP `strpos($haystack, $needle, $offset)`.
#[must_use]
pub fn php_strpos(haystack: &str, needle: &str, offset: usize) -> Option<usize> {
    let bytes = haystack.as_bytes();
    if offset > bytes.len() {
        return None;
    }
    haystack[offset..].find(needle).map(|index| index + offset)
}

/// PHP `parse_str` for `key=value&...` lines.
#[must_use]
pub fn php_parse_str(input: &str) -> HashMap<String, String> {
    let mut map = HashMap::new();
    for pair in input.split('&') {
        if pair.is_empty() {
            continue;
        }
        let (key, value) = pair.split_once('=').unwrap_or((pair, ""));
        map.insert(php_urldecode(key), php_urldecode(value));
    }
    map
}

/// PHP `urldecode` (`+` → space).
#[must_use]
pub fn php_urldecode(input: &str) -> String {
    let mut out = Vec::new();
    let bytes = input.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'+' => {
                out.push(b' ');
                i += 1;
            }
            b'%' if i + 2 < bytes.len() => {
                let hex = &input[i + 1..i + 3];
                if let Ok(value) = u8::from_str_radix(hex, 16) {
                    out.push(value);
                    i += 3;
                } else {
                    out.push(bytes[i]);
                    i += 1;
                }
            }
            b => {
                out.push(b);
                i += 1;
            }
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// PHP `urlencode` (space as `+`, uppercase hex).
#[must_use]
pub fn php_urlencode(input: &str) -> String {
    let mut out = String::new();
    for b in input.bytes() {
        if b == b' ' {
            out.push('+');
        } else if b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.') {
            out.push(char::from(b));
        } else {
            let _ = write!(out, "%{b:02X}");
        }
    }
    out
}

/// PHP `http_build_query` for string pairs (`true` already converted to `"1"`).
#[must_use]
pub fn php_http_build_query(pairs: &[(&str, &str)]) -> String {
    pairs
        .iter()
        .map(|(k, v)| format!("{}={}", php_urlencode(k), php_urlencode(v)))
        .collect::<Vec<_>>()
        .join("&")
}

/// PHP `Adapter::filterEnvKey`.
#[must_use]
pub fn filter_env_key(input: &str) -> String {
    input
        .chars()
        .filter(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '.' | '-'))
        .collect()
}

/// PHP `DockerCLI::parseIOStats`.
#[must_use]
pub fn parse_io_stats(stats: &str) -> HashMap<String, f64> {
    let stats = stats.to_lowercase();
    let units: [(&str, f64); 9] = [
        ("tib", 1_099_511_627_776.0),
        ("gib", 1_073_741_824.0),
        ("mib", 1_048_576.0),
        ("kib", 1024.0),
        ("tb", 1_000_000_000_000.0),
        ("gb", 1_000_000_000.0),
        ("mb", 1_000_000.0),
        ("kb", 1000.0),
        ("b", 1.0),
    ];
    let (in_str, out_str) = stats.split_once(" / ").unwrap_or((stats.as_str(), ""));
    let in_unit = units.iter().find(|(u, _)| in_str.ends_with(u)).copied();
    let out_unit = units.iter().find(|(u, _)| out_str.ends_with(u)).copied();
    let in_multiply = in_unit.map_or(1.0, |(_, v)| v);
    let out_multiply = out_unit.map_or(1.0, |(_, v)| v);
    let in_value: f64 = in_str
        .trim_end_matches(in_unit.map_or("", |(u, _)| u))
        .parse()
        .unwrap_or(0.0);
    let out_value: f64 = out_str
        .trim_end_matches(out_unit.map_or("", |(u, _)| u))
        .parse()
        .unwrap_or(0.0);
    let mut response = HashMap::new();
    response.insert("in".to_string(), in_value * in_multiply);
    response.insert("out".to_string(), out_value * out_multiply);
    response
}

/// PHP `Orchestration::parseCommandString`.
pub fn parse_command_string(command: &str) -> Result<Vec<String>, OrchestrationError> {
    let mut current_pos = 0usize;
    let mut command_processed = Vec::new();

    if php_strpos(command, " ", current_pos).is_none() {
        return Ok(vec![command.to_string()]);
    }

    while let Some(mut place) = php_strpos(command, " ", current_pos) {
        let next = place + 1;
        if command.as_bytes().get(next) == Some(&b'\'') {
            command_processed.push(php_substr(command, current_pos, place - current_pos));
            let closing = php_strpos(command, "'", place + 2);
            let Some(closing_string) = closing else {
                return Err(OrchestrationError::Orchestration(
                    "Invalid Command given, are you missing an `'` at the end?".to_string(),
                ));
            };
            command_processed.push(php_substr(command, place + 1, closing_string));
            place = closing_string + 1;
        } else {
            command_processed.push(php_substr(command, current_pos, place - current_pos));
            place += 1;
        }

        if php_strpos(command, " ", place).is_none() {
            let rest = php_substr(command, place, command.len().saturating_sub(current_pos));
            if !php_empty_str(&rest) {
                command_processed.push(rest);
            }
        }

        current_pos = place;
    }

    Ok(command_processed)
}
