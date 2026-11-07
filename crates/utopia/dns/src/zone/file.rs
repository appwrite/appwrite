//! Zone files (RFC 1035 §5, the master file format).

use super::Zone;
use crate::Error;
use crate::message::Record;

/// The classes zone files name.
const CLASSES: [(&str, u16); 4] =
    [("IN", Record::CLASS_IN), ("CS", Record::CLASS_CS), ("CH", Record::CLASS_CH), ("HS", Record::CLASS_HS)];

/// Imports a zone from a zone file.
///
/// Supports `$ORIGIN` and `$TTL` (other `$` directives are skipped,
/// `$INCLUDE` is refused), `@`, relative names, omitted owners (the
/// previous one), omitted TTLs and classes (the previous ones, in any
/// order), `;` comments outside quotes, and records spanning lines in
/// parentheses. TXT strings are joined and `\DDD` escapes decoded. The zone
/// is named by the first `$ORIGIN`, else `default_origin`; it needs exactly
/// one `SOA` record.
///
/// Fails with [`Error::Import`], carrying the file.
///
/// ```
/// use utopia_dns::zone;
///
/// let zone = zone::import(b"$ORIGIN example.com.
/// $TTL 1800
/// @ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
/// www IN A 192.168.1.10
/// mail 300 IN MX 10 mail", None, 3600)?;
///
/// assert_eq!(zone.name(), b"example.com");
/// assert_eq!(zone.records()[1].rdata(), b"mail.example.com");
/// assert_eq!(zone.records()[1].priority(), Some(10));
/// # Ok::<(), utopia_dns::Error>(())
/// ```
///
/// ```php
/// use Utopia\DNS\Zone\File;
///
/// $zone = File::import(<<<'ZONE'
/// $ORIGIN example.com.
/// $TTL 1800
/// @ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
/// www IN A 192.168.1.10
/// mail 300 IN MX 10 mail
/// ZONE);
///
/// echo $zone->name, "\n";                 // example.com
/// echo $zone->records[1]->rdata, "\n";    // mail.example.com
/// echo $zone->records[1]->priority, "\n"; // 10
/// ```
///
/// PHP: `Zone\File::import()`.
pub fn import(content: &[u8], default_origin: Option<&[u8]>, default_ttl: i64) -> Result<Zone, Error> {
    let fail = |message: Vec<u8>, previous: Option<Error>| Error::Import {
        content: content.to_vec(),
        message,
        previous: previous.map(Box::new),
    };
    let lines = preprocess(content);
    let mut records = Vec::new();
    let mut soa: Option<Record> = None;
    let mut origin: Option<Vec<u8>> = None;
    let mut zone_name: Option<Vec<u8>> = None;
    let mut zone_name_from_default = false;
    if let Some(default) = default_origin {
        let Some(canonical) = canonicalize(default) else {
            return Err(fail(b"Default origin must not be empty".to_vec(), None));
        };
        origin = Some(canonical.clone());
        zone_name = Some(canonical);
        zone_name_from_default = true;
    }
    let mut last_owner: Option<Vec<u8>> = None;
    let mut last_ttl = default_ttl;
    let mut last_class = Record::CLASS_IN;

    for (line, num) in lines {
        if line.is_empty() {
            continue;
        }
        match directive(&line, &mut origin, &mut last_ttl) {
            Err(e) => {
                let message = e.message().to_vec();
                return Err(fail(message, Some(e)));
            }
            Ok(Some(Directive::Origin)) => {
                if origin.is_none() {
                    return Err(fail(b"$ORIGIN directive must not be empty".to_vec(), None));
                }
                if zone_name.is_none() || zone_name_from_default {
                    zone_name = origin.clone();
                    zone_name_from_default = false;
                }
                continue;
            }
            Ok(Some(Directive::Ttl)) => continue,
            Ok(None) => {}
        }
        if line[0] == b'$' {
            continue;
        }
        let owner_omitted = line[0] == b' ' || line[0] == b'\t';
        let start = line.iter().position(|b| !b" \t\n\r\0\x0B".contains(b)).unwrap_or(line.len());
        let line = &line[start..];
        let rr = match parse_record(
            line,
            origin.as_deref(),
            last_owner.as_deref(),
            last_ttl,
            last_class,
            owner_omitted,
            num,
        ) {
            Ok(rr) => rr,
            Err(e) => {
                let message = e.message().to_vec();
                return Err(fail(message, Some(e)));
            }
        };
        last_owner = Some(rr.name.clone());
        last_ttl = rr.ttl;
        last_class = rr.class;
        let mut record = Record::new(&rr.name, rr.ty).with_class(rr.class).with_ttl(rr.ttl).with_rdata(&rr.rdata);
        if let Some(p) = rr.priority {
            record = record.with_priority(p);
        }
        if let Some(w) = rr.weight {
            record = record.with_weight(w);
        }
        if let Some(p) = rr.port {
            record = record.with_port(p);
        }
        if rr.ty == Record::TYPE_SOA {
            if soa.is_some() {
                return Err(fail(format!("Multiple SOA records found (line {num}).").into_bytes(), None));
            }
            soa = Some(record);
            continue;
        }
        records.push(record);
    }
    let Some(soa) = soa else {
        return Err(fail(b"No SOA record found in zone file".to_vec(), None));
    };
    let Some(zone_name) = zone_name else {
        return Err(fail(
            b"Unable to determine zone name: provide an $ORIGIN directive or defaultOrigin.".to_vec(),
            None,
        ));
    };
    Zone::new(zone_name, records, soa)
}

/// Exports a zone as a zone file: `$ORIGIN`, `$TTL` (the `SOA`'s), the
/// `SOA` over several lines, then the records grouped by type (`NS`, `A`,
/// `AAAA`, `MX`, `CNAME`, `TXT`, then the others in order of appearance),
/// with names relative to the zone. Comments, when included, head the file
/// (with the time, from [`std::time::SystemTime`], in UTC) and each group.
///
/// PHP: `Zone\File::export()` (PHP writes the time in its default time
/// zone).
pub fn export(zone: &Zone, include_comments: bool) -> Vec<u8> {
    export_at(zone, include_comments, &now())
}

/// [`export`] with the time written in the header comment (`Y-m-d H:i:s`).
pub fn export_at(zone: &Zone, include_comments: bool, generated: &str) -> Vec<u8> {
    let name = zone.name();
    let mut out: Vec<Vec<u8>> = Vec::new();
    if include_comments {
        out.push([b"; Zone file for ", name].concat());
        out.push(format!("; Generated on {generated}").into_bytes());
        out.push(Vec::new());
    }
    out.push([&b"$ORIGIN "[..], &trailing_dot(name)].concat());
    out.push(format!("$TTL {}", zone.soa().ttl()).into_bytes());
    out.push(Vec::new());
    if include_comments {
        out.push(b"; SOA Record".to_vec());
    }
    out.push(format_record(zone.soa(), name));
    out.push(Vec::new());

    let mut groups: Vec<(u16, Vec<&Record>)> = Vec::new();
    for record in zone.records() {
        match groups.iter_mut().find(|(t, _)| *t == record.ty()) {
            Some((_, list)) => list.push(record),
            None => groups.push((record.ty(), vec![record])),
        }
    }
    let preferred =
        [Record::TYPE_NS, Record::TYPE_A, Record::TYPE_AAAA, Record::TYPE_MX, Record::TYPE_CNAME, Record::TYPE_TXT];
    let mut order: Vec<usize> = preferred.iter().filter_map(|t| groups.iter().position(|(g, _)| g == t)).collect();
    order.extend((0..groups.len()).filter(|i| !preferred.contains(&groups[*i].0)));
    for i in order {
        let (ty, list) = &groups[i];
        if include_comments {
            out.push(format!("; {} Records", type_string(*ty)).into_bytes());
        }
        for record in list {
            out.push(format_record(record, name));
        }
        out.push(Vec::new());
    }
    out.join(&b'\n')
}

/// The current UTC time as `Y-m-d H:i:s`.
fn now() -> String {
    let secs = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_secs()) as i64;
    let (days, rem) = (secs.div_euclid(86_400), secs.rem_euclid(86_400));
    // Civil date from days since the epoch (Howard Hinnant's algorithm).
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = doy - (153 * mp + 2) / 5 + 1;
    let month = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = yoe + era * 400 + i64::from(month <= 2);
    format!("{year:04}-{month:02}-{day:02} {:02}:{:02}:{:02}", rem / 3600, rem % 3600 / 60, rem % 60)
}

enum Directive {
    Origin,
    Ttl,
}

/// PCRE `\s` without `/u`.
fn is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// `/^\s*\$ORIGIN\s+(\S+)\s*$/i`, `/^\s*\$TTL\s+(\d+)\s*$/i` and
/// `/^\s*\$INCLUDE\b/i`.
fn directive(line: &[u8], origin: &mut Option<Vec<u8>>, last_ttl: &mut i64) -> Result<Option<Directive>, Error> {
    let start = line.iter().position(|&b| !is_space(b)).unwrap_or(line.len());
    let rest = &line[start..];
    // `$` matches at the end or before a final newline.
    let body = rest.strip_suffix(b"\n").unwrap_or(rest);
    if let Some(argument) = keyword(body, b"$ORIGIN")
        && let Some(value) = single_token(argument, |b| !is_space(b))
    {
        *origin = canonicalize(value);
        return Ok(Some(Directive::Origin));
    }
    if let Some(argument) = keyword(body, b"$TTL")
        && let Some(value) = single_token(argument, |b| b.is_ascii_digit())
    {
        *last_ttl = php_std::format::str_to_int(value);
        return Ok(Some(Directive::Ttl));
    }
    if rest.len() >= 8
        && rest[..8].eq_ignore_ascii_case(b"$INCLUDE")
        && !rest.get(8).is_some_and(|b| b.is_ascii_alphanumeric() || *b == b'_')
    {
        return Err(Error::invalid("$INCLUDE directive is not supported"));
    }
    Ok(None)
}

/// The text after `name` and at least one space (case-insensitive name).
fn keyword<'a>(line: &'a [u8], name: &[u8]) -> Option<&'a [u8]> {
    if line.len() <= name.len() || !line[..name.len()].eq_ignore_ascii_case(name) {
        return None;
    }
    let rest = &line[name.len()..];
    let spaces = rest.iter().take_while(|&&b| is_space(b)).count();
    if spaces == 0 {
        return None;
    }
    Some(&rest[spaces..])
}

/// `(X+)\s*$`: one non-empty run of `class` bytes and only space after it.
fn single_token(text: &[u8], class: impl Fn(u8) -> bool) -> Option<&[u8]> {
    let len = text.iter().take_while(|&&b| class(b)).count();
    if len == 0 || !text[len..].iter().all(|&b| is_space(b)) {
        return None;
    }
    Some(&text[..len])
}

/// Lines without comments, parenthesised groups joined, each with its
/// first line's number; blank lines kept as empty.
fn preprocess(content: &[u8]) -> Vec<(Vec<u8>, usize)> {
    let mut out = Vec::new();
    let mut acc: Vec<u8> = Vec::new();
    let mut start_num = 0;
    let mut in_paren = false;
    for (i, raw) in split_lines(content).into_iter().enumerate() {
        let num = i + 1;
        let original = remove_comment(raw);
        let line = php_std::string::rtrim(&original, php_std::string::TRIM_CHARACTERS);
        if php_std::string::trim(&original, php_std::string::TRIM_CHARACTERS).is_empty() || line.is_empty() {
            if !in_paren {
                out.push((Vec::new(), num));
            }
            continue;
        }
        let opens = count(line, b'(');
        let closes = count(line, b')');
        if !in_paren && opens > closes {
            in_paren = true;
            acc = line.to_vec();
            start_num = num;
            continue;
        }
        if in_paren {
            acc.push(b' ');
            acc.extend_from_slice(line);
            let opens = opens + count(&acc, b'(');
            let closes = closes + count(&acc, b')');
            if opens <= closes {
                in_paren = false;
                out.push((merge(&acc), start_num));
                acc.clear();
            }
            continue;
        }
        out.push((line.to_vec(), num));
    }
    if in_paren {
        out.push((merge(&acc), start_num));
    }
    out
}

fn count(s: &[u8], b: u8) -> usize {
    s.iter().filter(|&&c| c == b).count()
}

/// `trim(str_replace(['(', ')'], '', $acc))`.
fn merge(acc: &[u8]) -> Vec<u8> {
    let stripped: Vec<u8> = acc.iter().copied().filter(|&b| b != b'(' && b != b')').collect();
    php_std::string::trim(&stripped, php_std::string::TRIM_CHARACTERS).to_vec()
}

/// `preg_split('/\R/')`: `\r\n`, `\n`, `\r`, `\v`, `\f` and the byte 0x85.
fn split_lines(content: &[u8]) -> Vec<&[u8]> {
    let mut lines = Vec::new();
    let mut start = 0;
    let mut i = 0;
    while i < content.len() {
        match content[i] {
            b'\r' if content.get(i + 1) == Some(&b'\n') => {
                lines.push(&content[start..i]);
                i += 2;
                start = i;
            }
            b'\n' | b'\r' | 0x0B | 0x0C | 0x85 => {
                lines.push(&content[start..i]);
                i += 1;
                start = i;
            }
            _ => i += 1,
        }
    }
    lines.push(&content[start..]);
    lines
}

/// The line up to a `;` outside quotes that is not escaped.
fn remove_comment(line: &[u8]) -> Vec<u8> {
    let mut result = Vec::with_capacity(line.len());
    let mut escaped = false;
    let mut quote: Option<u8> = None;
    for &ch in line {
        if escaped {
            result.push(ch);
            escaped = false;
            continue;
        }
        if ch == b'\\' {
            result.push(ch);
            escaped = true;
            continue;
        }
        if let Some(q) = quote {
            result.push(ch);
            if ch == q {
                quote = None;
            }
            continue;
        }
        if ch == b'"' || ch == b'\'' {
            quote = Some(ch);
            result.push(ch);
            continue;
        }
        if ch == b';' {
            break;
        }
        result.push(ch);
    }
    result
}

/// Tokens split on spaces and tabs, keeping quoted strings and escapes whole.
fn split_whitespace(s: &[u8]) -> Vec<Vec<u8>> {
    let mut tokens = Vec::new();
    let mut current = Vec::new();
    let mut escaped = false;
    let mut quote: Option<u8> = None;
    for &ch in s {
        if escaped {
            current.push(ch);
            escaped = false;
            continue;
        }
        if ch == b'\\' {
            current.push(ch);
            escaped = true;
            continue;
        }
        if let Some(q) = quote {
            current.push(ch);
            if ch == q {
                quote = None;
            }
            continue;
        }
        if ch == b'"' || ch == b'\'' {
            quote = Some(ch);
            current.push(ch);
            continue;
        }
        if ch == b' ' || ch == b'\t' {
            if !current.is_empty() {
                tokens.push(std::mem::take(&mut current));
            }
            continue;
        }
        current.push(ch);
    }
    if !current.is_empty() {
        tokens.push(current);
    }
    tokens
}

/// Trimmed, without trailing dots, lower case; `.` for the root, `None` when empty.
fn canonicalize(name: &[u8]) -> Option<Vec<u8>> {
    let name = php_std::string::trim(name, php_std::string::TRIM_CHARACTERS);
    if name.is_empty() {
        return None;
    }
    let end = name.iter().rposition(|&b| b != b'.').map_or(0, |i| i + 1);
    if end == 0 {
        return Some(b".".to_vec());
    }
    Some(name[..end].to_ascii_lowercase())
}

/// A name made absolute against the origin.
fn absolutize(name: &[u8], origin: Option<&[u8]>) -> Option<Vec<u8>> {
    let name = php_std::string::trim(name, php_std::string::TRIM_CHARACTERS);
    if name.is_empty() || name == b"@" {
        return origin.map(<[u8]>::to_vec);
    }
    if name.ends_with(b".") {
        return canonicalize(name);
    }
    match origin.and_then(canonicalize) {
        None => canonicalize(name),
        Some(origin) if origin == b"." => canonicalize(name),
        Some(origin) => canonicalize(&[name, b".", &origin].concat()),
    }
}

/// A name relative to the origin: `@` for the origin itself.
fn relativize(name: &[u8], origin: &[u8]) -> Vec<u8> {
    let name = trailing_dot(name);
    let origin = trailing_dot(origin);
    if name == origin {
        return b"@".to_vec();
    }
    if name.ends_with(&origin) {
        let head = &name[..name.len() - origin.len()];
        let end = head.iter().rposition(|&b| b != b'.').map_or(0, |i| i + 1);
        return head[..end].to_vec();
    }
    name
}

fn trailing_dot(name: &[u8]) -> Vec<u8> {
    let end = name.iter().rposition(|&b| b != b'.').map_or(0, |i| i + 1);
    [&name[..end], b"."].concat()
}

fn type_string(ty: u16) -> String {
    Record::type_code_to_name(ty).map_or_else(|| format!("TYPE{ty}"), str::to_owned)
}

fn class_string(class: u16) -> String {
    CLASSES.iter().find(|(_, c)| *c == class).map_or_else(|| format!("CLASS{class}"), |(n, _)| (*n).to_owned())
}

struct ParsedRecord {
    name: Vec<u8>,
    ttl: i64,
    class: u16,
    ty: u16,
    rdata: Vec<u8>,
    priority: Option<i64>,
    weight: Option<i64>,
    port: Option<i64>,
}

fn is_digits(t: &[u8]) -> bool {
    !t.is_empty() && t.iter().all(u8::is_ascii_digit)
}

fn parse_record(
    line: &[u8],
    origin: Option<&[u8]>,
    last_owner: Option<&[u8]>,
    last_ttl: i64,
    last_class: u16,
    owner_omitted: bool,
    num: usize,
) -> Result<ParsedRecord, Error> {
    let tokens = split_whitespace(line);
    if tokens.is_empty() {
        return Err(Error::invalid(format!("Empty resource record (line {num}).")));
    }
    let mut i = 0;
    let name = if owner_omitted {
        match last_owner {
            Some(owner) => Some(owner.to_vec()),
            None => {
                return Err(Error::invalid(format!("Owner omitted but no previous owner available (line {num}).")));
            }
        }
    } else if tokens[0] == b"@" {
        i += 1;
        origin.map(<[u8]>::to_vec)
    } else {
        i += 1;
        absolutize(&tokens[0], origin)
    };
    let Some(name) = name else {
        return Err(Error::invalid(format!("Record is missing an owner name (line {num}).")));
    };
    let Some(name) = canonicalize(&name) else {
        return Err(Error::invalid(format!("Owner name is invalid (line {num}).")));
    };
    let (mut ttl, mut class) = (last_ttl, last_class);
    while i + 1 < tokens.len() {
        let t = &tokens[i];
        if is_digits(t) {
            ttl = php_std::format::str_to_int(t);
            i += 1;
            continue;
        }
        if let Some((_, c)) = CLASSES.iter().find(|(n, _)| n.as_bytes().eq_ignore_ascii_case(t)) {
            class = *c;
            i += 1;
            continue;
        }
        break;
    }
    if i >= tokens.len() {
        return Err(Error::invalid(format!("Missing record type (line {num}).")));
    }
    let type_string = tokens[i].to_ascii_uppercase();
    let ty = std::str::from_utf8(&type_string).ok().and_then(Record::type_name_to_code);
    let Some(ty) = ty else {
        let mut message = b"Invalid record type '".to_vec();
        message.extend_from_slice(&type_string);
        message.extend_from_slice(format!("' (line {num}).").as_bytes());
        return Err(Error::InvalidArgument(message));
    };
    i += 1;
    let rdata = &tokens[i..];
    if rdata.is_empty() {
        let mut message = b"Record '".to_vec();
        message.extend_from_slice(&type_string);
        message.extend_from_slice(format!("' has no RDATA (line {num}).").as_bytes());
        return Err(Error::InvalidArgument(message));
    }
    let (rdata, priority, weight, port) = parse_rdata(ty, rdata, origin, num)?;
    Ok(ParsedRecord { name, ttl, class, ty, rdata, priority, weight, port })
}

type Rdata = (Vec<u8>, Option<i64>, Option<i64>, Option<i64>);

fn parse_rdata(ty: u16, tokens: &[Vec<u8>], origin: Option<&[u8]>, num: usize) -> Result<Rdata, Error> {
    let joined = || tokens.join(&b' ');
    Ok(match ty {
        Record::TYPE_A | Record::TYPE_AAAA => (tokens[0].clone(), None, None, None),
        Record::TYPE_NS | Record::TYPE_CNAME | Record::TYPE_PTR => match absolutize(&tokens[0], origin) {
            Some(name) => (name, None, None, None),
            None => return Err(Error::invalid(format!("Relative domain name requires an origin (line {num})."))),
        },
        Record::TYPE_MX => {
            if tokens.len() < 2 || !is_digits(&tokens[0]) {
                return Err(Error::invalid(format!("MX requires numeric priority and exchange (line {num}).")));
            }
            let priority = php_std::format::str_to_int(&tokens[0]);
            match absolutize(&tokens[1], origin) {
                Some(exchange) => (exchange, Some(priority), None, None),
                None => return Err(Error::invalid(format!("MX exchange requires an origin (line {num})."))),
            }
        }
        Record::TYPE_SRV => {
            if tokens.len() < 4 || !is_digits(&tokens[0]) || !is_digits(&tokens[1]) || !is_digits(&tokens[2]) {
                return Err(Error::invalid(format!("SRV requires priority, weight, port, target (line {num}).")));
            }
            let numbers: Vec<i64> = tokens[..3].iter().map(|t| php_std::format::str_to_int(t)).collect();
            match absolutize(&tokens[3], origin) {
                Some(target) => (target, Some(numbers[0]), Some(numbers[1]), Some(numbers[2])),
                None => return Err(Error::invalid(format!("SRV target requires an origin (line {num})."))),
            }
        }
        Record::TYPE_SOA => {
            if tokens.len() < 7 {
                return Err(Error::invalid(format!(
                    "SOA requires MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE, MINIMUM (line {num})."
                )));
            }
            let (Some(mname), Some(rname)) = (absolutize(&tokens[0], origin), absolutize(&tokens[1], origin)) else {
                return Err(Error::invalid(format!("SOA requires origin for MNAME and RNAME (line {num}).")));
            };
            let parts: Vec<&[u8]> =
                [&mname[..], &rname[..]].into_iter().chain(tokens[2..7].iter().map(Vec::as_slice)).collect();
            (parts.join(&b' '), None, None, None)
        }
        Record::TYPE_TXT => {
            let mut text = Vec::new();
            for token in tokens {
                let t = php_std::string::trim(token, php_std::string::TRIM_CHARACTERS);
                if t.is_empty() {
                    continue;
                }
                if t[0] == b'"' && t.ends_with(b"\"") {
                    text.extend_from_slice(&decode_txt(t.get(1..t.len() - 1).unwrap_or_default()));
                } else {
                    text.extend_from_slice(&decode_txt(t));
                }
            }
            (text, None, None, None)
        }
        Record::TYPE_CAA => {
            if tokens.len() < 3 || !is_digits(&tokens[0]) {
                return Err(Error::invalid(format!("CAA requires flag, tag, and quoted value (line {num}).")));
            }
            let flag = php_std::format::str_to_int(&tokens[0]);
            if !(0..=255).contains(&flag) {
                return Err(Error::invalid(format!("CAA flag must be between 0 and 255 (line {num}).")));
            }
            let value = &tokens[2];
            if value.is_empty() || value[0] != b'"' || !value.ends_with(b"\"") {
                return Err(Error::invalid(format!("CAA value must be quoted (line {num}).")));
            }
            (joined(), None, None, None)
        }
        _ => (joined(), None, None, None),
    })
}

/// A TXT string with `\DDD` (up to three digits) and `\X` escapes decoded.
fn decode_txt(value: &[u8]) -> Vec<u8> {
    let mut decoded = Vec::with_capacity(value.len());
    let mut i = 0;
    while i < value.len() {
        let ch = value[i];
        if ch != b'\\' {
            decoded.push(ch);
            i += 1;
            continue;
        }
        i += 1;
        let Some(&next) = value.get(i) else {
            decoded.push(b'\\');
            break;
        };
        if next.is_ascii_digit() {
            let mut code = u32::from(next - b'0');
            let mut digits = 1;
            while digits < 3 && value.get(i + 1).is_some_and(u8::is_ascii_digit) {
                i += 1;
                code = code * 10 + u32::from(value[i] - b'0');
                digits += 1;
            }
            decoded.push((code % 256) as u8);
            i += 1;
            continue;
        }
        decoded.push(next);
        i += 1;
    }
    decoded
}

fn format_record(record: &Record, origin: &[u8]) -> Vec<u8> {
    let name = relativize(record.name(), origin);
    let mut out = name;
    if record.ty() == Record::TYPE_SOA {
        let parts: Vec<&[u8]> = record.rdata().split(|&b| b == b' ').collect();
        if parts.len() >= 7 {
            out.extend_from_slice(
                format!("\t{}\t{}\t{}\t", record.ttl(), class_string(record.class()), type_string(record.ty()))
                    .as_bytes(),
            );
            out.extend_from_slice(&relativize(parts[0], origin));
            out.push(b' ');
            out.extend_from_slice(&relativize(parts[1], origin));
            out.extend_from_slice(b" (\n\t\t\t\t");
            out.extend_from_slice(parts[2]);
            out.extend_from_slice(b"\t; serial\n\t\t\t\t");
            out.extend_from_slice(parts[3]);
            out.extend_from_slice(b"\t; refresh\n\t\t\t\t");
            out.extend_from_slice(parts[4]);
            out.extend_from_slice(b"\t; retry\n\t\t\t\t");
            out.extend_from_slice(parts[5]);
            out.extend_from_slice(b"\t; expire\n\t\t\t\t");
            out.extend_from_slice(parts[6]);
            out.extend_from_slice(b" )\t; minimum");
            return out;
        }
    }
    out.extend_from_slice(
        format!("\t{}\t{}\t{}\t", record.ttl(), class_string(record.class()), type_string(record.ty())).as_bytes(),
    );
    let rdata = record.rdata();
    match record.ty() {
        Record::TYPE_NS | Record::TYPE_CNAME | Record::TYPE_PTR => out.extend_from_slice(&relativize(rdata, origin)),
        Record::TYPE_MX => {
            out.extend_from_slice(record.priority().unwrap_or(0).to_string().as_bytes());
            out.push(b' ');
            out.extend_from_slice(&relativize(rdata, origin));
        }
        Record::TYPE_SRV => {
            let numbers = [record.priority(), record.weight(), record.port()].map(|n| n.unwrap_or(0).to_string());
            out.extend_from_slice(format!("{} {} {} ", numbers[0], numbers[1], numbers[2]).as_bytes());
            out.extend_from_slice(&relativize(rdata, origin));
        }
        Record::TYPE_TXT => {
            out.push(b'"');
            out.extend_from_slice(&php_std::string::addcslashes(rdata, b"\"\\"));
            out.push(b'"');
        }
        _ => out.extend_from_slice(rdata),
    }
    out
}
