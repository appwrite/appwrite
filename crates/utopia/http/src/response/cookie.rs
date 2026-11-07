use std::fmt::Write;

/// A cookie added with [`Response::add_cookie`](crate::Response::add_cookie). `None` means not given.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Cookie {
    /// The cookie name.
    pub name: String,
    /// The value; empty or `None` deletes the cookie.
    pub value: Option<String>,
    /// The expiry, in Unix seconds.
    pub expire: Option<i64>,
    /// The `path` attribute.
    pub path: Option<String>,
    /// The `domain` attribute.
    pub domain: Option<String>,
    /// Whether the cookie is sent over HTTPS only.
    pub secure: Option<bool>,
    /// Whether scripts are denied access to the cookie.
    pub http_only: Option<bool>,
    /// The `SameSite` attribute (see [`same_site`](crate::same_site)).
    pub same_site: Option<String>,
}

impl Cookie {
    /// The `Set-Cookie` header value for this cookie at `now` (Unix
    /// seconds), or `None` when the cookie is refused: an empty name, a name
    /// with `=`, a name, path or domain with separators or whitespace, or an
    /// expiry past the year 9999.
    ///
    /// The value is URL-encoded; an empty value deletes the cookie.
    ///
    /// PHP: the header Swoole sends for the cookie.
    pub fn header(&self, now: i64) -> Option<String> {
        let name = &self.name;
        let forbidden =
            |s: &str| s.bytes().any(|b| matches!(b, b',' | b';' | b' ' | b'\t' | b'\r' | b'\n' | 0x0B | 0x0C));
        if name.is_empty() || name.contains('=') || forbidden(name) {
            return None;
        }
        if self.path.as_deref().is_some_and(forbidden) || self.domain.as_deref().is_some_and(forbidden) {
            return None;
        }
        // Expiry years past 9999 are refused.
        if self.expire.is_some_and(|e| e >= 253_402_300_800) {
            return None;
        }
        let value = self.value.as_deref().unwrap_or("");
        let mut out = String::with_capacity(name.len() + value.len() * 3 + 128);
        out.push_str(name);
        out.push('=');
        if value.is_empty() {
            out.push_str("deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0");
        } else {
            urlencode_into(&mut out, value.as_bytes());
            let expires = self.expire.unwrap_or(0);
            if expires > 0 {
                out.push_str("; expires=");
                cookie_date(&mut out, expires);
                let _ = write!(out, "; Max-Age={}", (expires - now).max(0));
            }
        }
        if let Some(path) = self.path.as_deref().filter(|p| !p.is_empty()) {
            out.push_str("; path=");
            out.push_str(path);
        }
        if let Some(domain) = self.domain.as_deref().filter(|d| !d.is_empty()) {
            out.push_str("; domain=");
            out.push_str(domain);
        }
        if self.secure == Some(true) {
            out.push_str("; secure");
        }
        if self.http_only == Some(true) {
            out.push_str("; HttpOnly");
        }
        if let Some(same_site) = self.same_site.as_deref().filter(|s| !s.is_empty()) {
            out.push_str("; SameSite=");
            out.push_str(same_site);
        }
        Some(out)
    }
}

/// PHP `urlencode()`, appended: alphanumerics and `-_.` are kept, a space
/// is `+`, every other byte is `%XX`.
fn urlencode_into(out: &mut String, s: &[u8]) {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    for &c in s {
        if c == b' ' {
            out.push('+');
        } else if c.is_ascii_alphanumeric() || matches!(c, b'-' | b'.' | b'_') {
            out.push(char::from(c));
        } else {
            out.push('%');
            out.push(char::from(HEX[usize::from(c >> 4)]));
            out.push(char::from(HEX[usize::from(c & 15)]));
        }
    }
}

/// `D, d-M-Y H:i:s GMT` in UTC, appended.
fn cookie_date(out: &mut String, timestamp: i64) {
    const DAYS: [&str; 7] = ["Thu", "Fri", "Sat", "Sun", "Mon", "Tue", "Wed"];
    const MONTHS: [&str; 12] = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    let days = timestamp.div_euclid(86_400);
    let secs = timestamp.rem_euclid(86_400);
    let (y, m, d) = civil(days);
    let _ = write!(
        out,
        "{}, {:02}-{}-{:04} {:02}:{:02}:{:02} GMT",
        DAYS[days.rem_euclid(7) as usize],
        d,
        MONTHS[(m - 1) as usize],
        y,
        secs / 3600,
        secs % 3600 / 60,
        secs % 60
    );
}

/// Days since the Unix epoch to (year, month, day).
fn civil(days: i64) -> (i64, i64, i64) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { y + 1 } else { y }, m, d)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cookie_lines_like_swoole() {
        let c = Cookie {
            name: "n".into(),
            value: Some("v a;l=ü".into()),
            expire: Some(1000),
            path: Some("/p".into()),
            domain: Some("d.com".into()),
            secure: Some(true),
            http_only: Some(true),
            same_site: Some("Strict".into()),
        };
        assert_eq!(
            c.header(2000).unwrap(),
            "n=v+a%3Bl%3D%C3%BC; expires=Thu, 01-Jan-1970 00:16:40 GMT; Max-Age=0; path=/p; domain=d.com; secure; HttpOnly; SameSite=Strict"
        );
        let empty = Cookie { name: "n".into(), ..Default::default() };
        assert_eq!(empty.header(0).unwrap(), "n=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0");
    }
}
