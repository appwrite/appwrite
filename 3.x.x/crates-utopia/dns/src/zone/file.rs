use crate::error::{Error, Result};
use crate::message::Record;
use crate::zone::Zone;

/// Import/export RFC 1035 master files. PHP `Utopia\DNS\Zone\File`.
#[derive(Debug)]
pub struct File;

struct ParsedRr {
    name: String,
    ttl: u32,
    class: u16,
    type_code: u16,
    rdata: String,
    priority: Option<i64>,
    weight: Option<i64>,
    port: Option<i64>,
}

impl File {
    /// PHP `File::import`.
    pub fn import(content: &str, default_origin: Option<&str>, default_ttl: u32) -> Result<Zone> {
        let lines = preprocess(content);
        let mut records = Vec::new();
        let mut soa = None;
        let mut origin = None;
        let mut zone_name = None;
        let mut zone_name_from_default = false;

        if let Some(default) = default_origin {
            let canon = canonicalize_name(Some(default))
                .ok_or_else(|| Error::import(content, "Default origin must not be empty"))?;
            origin = Some(canon.clone());
            zone_name = Some(canon);
            zone_name_from_default = true;
        }

        let mut last_owner: Option<String> = None;
        let mut last_ttl = default_ttl;
        let mut last_class = Record::CLASS_IN;

        for (line, num) in &lines {
            if line.is_empty() {
                continue;
            }
            match handle_directives(line, &mut origin, &mut last_ttl) {
                Ok(Some(kind)) => {
                    if kind == Directive::Origin {
                        let Some(origin_val) = origin.clone() else {
                            return Err(Error::import(
                                content,
                                "$ORIGIN directive must not be empty",
                            ));
                        };
                        if zone_name.is_none() || zone_name_from_default {
                            zone_name = Some(origin_val);
                            zone_name_from_default = false;
                        }
                    }
                    continue;
                }
                Ok(None) => {}
                Err(e) => return Err(Error::import(content, e.to_string())),
            }
            if line.starts_with('$') {
                continue;
            }
            let owner_omitted = line.starts_with(' ') || line.starts_with('\t');
            let line = line.trim_start();
            let rr = match parse_resource_record(
                line,
                origin.as_deref(),
                last_owner.as_deref(),
                last_ttl,
                last_class,
                owner_omitted,
                *num,
            ) {
                Ok(rr) => rr,
                Err(e) => return Err(Error::import(content, e.to_string())),
            };
            last_owner = Some(rr.name.clone());
            last_ttl = rr.ttl;
            last_class = rr.class;

            let mut record = Record::new(&rr.name, rr.type_code)
                .class(rr.class)
                .ttl(rr.ttl)
                .rdata(rr.rdata);
            if let Some(p) = rr.priority {
                record = record.priority(p);
            }
            if let Some(w) = rr.weight {
                record = record.weight(w);
            }
            if let Some(p) = rr.port {
                record = record.port(p);
            }

            if rr.type_code == Record::TYPE_SOA {
                if soa.is_some() {
                    return Err(Error::import(
                        content,
                        format!("Multiple SOA records found (line {num})."),
                    ));
                }
                soa = Some(record);
                continue;
            }
            records.push(record);
        }

        let soa = soa.ok_or_else(|| Error::import(content, "No SOA record found in zone file"))?;
        let zone_name = zone_name.ok_or_else(|| {
            Error::import(
                content,
                "Unable to determine zone name: provide an $ORIGIN directive or defaultOrigin.",
            )
        })?;
        Zone::new(zone_name, records, soa)
    }

    /// PHP `File::export`.
    #[must_use]
    pub fn export(zone: &Zone, include_comments: bool) -> String {
        let mut out = Vec::new();
        if include_comments {
            out.push(format!("; Zone file for {}", zone.name));
            out.push(format!("; Generated on {}", now_stamp()));
            out.push(String::new());
        }
        out.push(format!("$ORIGIN {}", ensure_trailing_dot(&zone.name)));
        out.push(format!("$TTL {}", zone.soa.ttl));
        out.push(String::new());
        if include_comments {
            out.push("; SOA Record".into());
        }
        out.push(format_resource_record(&zone.soa, &zone.name));
        out.push(String::new());

        let mut by_type = group_records_by_type(&zone.records);
        let preferred = [
            Record::TYPE_NS,
            Record::TYPE_A,
            Record::TYPE_AAAA,
            Record::TYPE_MX,
            Record::TYPE_CNAME,
            Record::TYPE_TXT,
        ];
        for type_code in preferred {
            let Some(list) = by_type.remove(&type_code) else {
                continue;
            };
            if include_comments {
                out.push(format!("; {} Records", type_string(type_code)));
            }
            for r in list {
                out.push(format_resource_record(&r, &zone.name));
            }
            out.push(String::new());
        }
        let mut remaining: Vec<(u16, Vec<Record>)> = by_type.into_iter().collect();
        remaining.sort_by_key(|(t, _)| *t);
        for (type_code, list) in remaining {
            if include_comments {
                out.push(format!("; {} Records", type_string(type_code)));
            }
            for r in list {
                out.push(format_resource_record(&r, &zone.name));
            }
            out.push(String::new());
        }
        out.join("\n")
    }
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Directive {
    Origin,
    Ttl,
}

fn preprocess(content: &str) -> Vec<(String, usize)> {
    let raw_lines = split_lines(content);
    let mut out = Vec::new();
    let mut acc = String::new();
    let mut start_num = 0usize;
    let mut in_paren = false;

    for (i, raw) in raw_lines.iter().enumerate() {
        let line_num = i + 1;
        let original = remove_comment(raw);
        if original.trim().is_empty() {
            if in_paren {
                continue;
            }
            out.push((String::new(), line_num));
            continue;
        }
        let line = original.trim_end().to_string();
        if line.is_empty() {
            if in_paren {
                continue;
            }
            out.push((String::new(), line_num));
            continue;
        }

        let opens = line.matches('(').count();
        let closes = line.matches(')').count();
        if !in_paren && opens > closes {
            in_paren = true;
            acc = line;
            start_num = line_num;
            continue;
        }
        if in_paren {
            acc.push(' ');
            acc.push_str(&line);
            let opens = acc.matches('(').count();
            let closes = acc.matches(')').count();
            if opens <= closes {
                in_paren = false;
                let merged = acc.replace(['(', ')'], "");
                out.push((merged.trim().to_string(), start_num));
                acc.clear();
            }
            continue;
        }
        out.push((line, line_num));
    }
    if in_paren {
        let merged = acc.replace(['(', ')'], "");
        out.push((merged.trim().to_string(), start_num));
    }
    out
}

fn split_lines(content: &str) -> Vec<&str> {
    // PHP preg_split('/\R/') - treat CR LF / CR / LF as line breaks.
    let mut lines = Vec::new();
    let mut rest = content;
    while !rest.is_empty() {
        if let Some(i) = rest.find(['\n', '\r']) {
            lines.push(&rest[..i]);
            if rest.as_bytes().get(i) == Some(&b'\r') && rest.as_bytes().get(i + 1) == Some(&b'\n')
            {
                rest = &rest[i + 2..];
            } else {
                rest = &rest[i + 1..];
            }
        } else {
            lines.push(rest);
            break;
        }
    }
    if content.ends_with('\n') || content.ends_with('\r') {
        // preg_split keeps a trailing empty only when the subject ends with a delimiter
        // and the last split produced content; PHP `: ` preg_split('/\R/', "a\n") => ["a", ""].
        // Our loop already consumed the delimiter without pushing the empty tail when rest
        // becomes empty after a delimiter. Push it to match PHP.
        if content.as_bytes().last().is_some() {
            // If the last consumed delimiter emptied `rest`, the last line was already pushed
            // as the content before it. PHP also yields a trailing empty string.
            // Only add when the original ended with a newline AND we didn't already.
        }
    }
    lines
}

fn handle_directives(
    line: &str,
    origin: &mut Option<String>,
    last_ttl: &mut u32,
) -> Result<Option<Directive>> {
    // PHP: /^\s*\$ORIGIN\s+(\S+)\s*$/i
    if let Some(token) = match_origin_ttl(line, "$ORIGIN") {
        if token.chars().any(char::is_whitespace) || token.is_empty() {
            return Ok(None);
        }
        *origin = canonicalize_name(Some(token));
        return Ok(Some(Directive::Origin));
    }
    // PHP: /^\s*\$TTL\s+(\d+)\s*$/i
    if let Some(token) = match_origin_ttl(line, "$TTL") {
        if token.bytes().all(|b| b.is_ascii_digit()) && !token.is_empty() {
            if let Ok(ttl) = token.parse() {
                *last_ttl = ttl;
                return Ok(Some(Directive::Ttl));
            }
        }
        return Ok(None);
    }
    // PHP: /^\s*\$INCLUDE\b/i
    let trimmed = line.trim_start();
    if trimmed.len() >= 8 && trimmed[..8].eq_ignore_ascii_case("$INCLUDE") {
        let rest = &trimmed[8..];
        if rest.is_empty() || rest.starts_with(|c: char| !c.is_ascii_alphanumeric() && c != '_') {
            return Err(Error::invalid("$INCLUDE directive is not supported"));
        }
    }
    Ok(None)
}

/// Match `$NAME <token>` with optional surrounding whitespace (PHP regex).
fn match_origin_ttl<'a>(line: &'a str, name: &str) -> Option<&'a str> {
    let trimmed = line.trim();
    if trimmed.len() < name.len() || !trimmed[..name.len()].eq_ignore_ascii_case(name) {
        return None;
    }
    let rest = trimmed[name.len()..].trim();
    if rest.is_empty() {
        return None;
    }
    Some(rest)
}

fn parse_resource_record(
    line: &str,
    origin: Option<&str>,
    last_owner: Option<&str>,
    last_ttl: u32,
    last_class: u16,
    owner_omitted: bool,
    line_num: usize,
) -> Result<ParsedRr> {
    let tokens = split_whitespace(line);
    if tokens.is_empty() {
        return Err(Error::invalid(format!(
            "Empty resource record (line {line_num})."
        )));
    }
    let mut i = 0usize;
    let name = if owner_omitted {
        let Some(prev) = last_owner else {
            return Err(Error::invalid(format!(
                "Owner omitted but no previous owner available (line {line_num})."
            )));
        };
        Some(prev.to_string())
    } else if tokens[0] == "@" {
        i += 1;
        origin.map(str::to_string)
    } else {
        let n = absolutize_domain_name(&tokens[i], origin);
        i += 1;
        n
    };
    let Some(name) = name else {
        return Err(Error::invalid(format!(
            "Record is missing an owner name (line {line_num})."
        )));
    };
    let Some(name) = canonicalize_name(Some(&name)) else {
        return Err(Error::invalid(format!(
            "Owner name is invalid (line {line_num})."
        )));
    };

    let mut ttl = last_ttl;
    let mut class = last_class;
    while i + 1 < tokens.len() {
        let t = &tokens[i];
        if t.bytes().all(|b| b.is_ascii_digit()) && !t.is_empty() {
            ttl = t.parse().unwrap_or(ttl);
            i += 1;
            continue;
        }
        let upper = t.to_ascii_uppercase();
        if let Some(c) = class_map(&upper) {
            class = c;
            i += 1;
            continue;
        }
        break;
    }
    if i >= tokens.len() {
        return Err(Error::invalid(format!(
            "Missing record type (line {line_num})."
        )));
    }
    let type_string = tokens[i].to_ascii_uppercase();
    let Some(type_code) = Record::type_name_to_code(&type_string) else {
        return Err(Error::invalid(format!(
            "Invalid record type '{type_string}' (line {line_num})."
        )));
    };
    i += 1;
    let rdata_tokens = &tokens[i..];
    if rdata_tokens.is_empty() {
        return Err(Error::invalid(format!(
            "Record '{type_string}' has no RDATA (line {line_num})."
        )));
    }
    let (rdata, priority, weight, port) = parse_rdata(type_code, rdata_tokens, origin, line_num)?;
    Ok(ParsedRr {
        name,
        ttl,
        class,
        type_code,
        rdata,
        priority,
        weight,
        port,
    })
}

type ParsedRdata = (String, Option<i64>, Option<i64>, Option<i64>);

fn parse_rdata(
    type_code: u16,
    tokens: &[String],
    origin: Option<&str>,
    line_num: usize,
) -> Result<ParsedRdata> {
    match type_code {
        Record::TYPE_A | Record::TYPE_AAAA => Ok((tokens[0].clone(), None, None, None)),
        Record::TYPE_NS | Record::TYPE_CNAME | Record::TYPE_PTR => {
            let name = absolutize_domain_name(&tokens[0], origin).ok_or_else(|| {
                Error::invalid(format!(
                    "Relative domain name requires an origin (line {line_num})."
                ))
            })?;
            Ok((name, None, None, None))
        }
        Record::TYPE_MX => {
            if tokens.len() < 2 || !tokens[0].bytes().all(|b| b.is_ascii_digit()) {
                return Err(Error::invalid(format!(
                    "MX requires numeric priority and exchange (line {line_num})."
                )));
            }
            let priority: i64 = tokens[0].parse().unwrap_or(0);
            let exchange = absolutize_domain_name(&tokens[1], origin).ok_or_else(|| {
                Error::invalid(format!("MX exchange requires an origin (line {line_num})."))
            })?;
            Ok((exchange, Some(priority), None, None))
        }
        Record::TYPE_SRV => {
            if tokens.len() < 4
                || !tokens[0].bytes().all(|b| b.is_ascii_digit())
                || !tokens[1].bytes().all(|b| b.is_ascii_digit())
                || !tokens[2].bytes().all(|b| b.is_ascii_digit())
            {
                return Err(Error::invalid(format!(
                    "SRV requires priority, weight, port, target (line {line_num})."
                )));
            }
            let priority: i64 = tokens[0].parse().unwrap_or(0);
            let weight: i64 = tokens[1].parse().unwrap_or(0);
            let port: i64 = tokens[2].parse().unwrap_or(0);
            let target = absolutize_domain_name(&tokens[3], origin).ok_or_else(|| {
                Error::invalid(format!("SRV target requires an origin (line {line_num})."))
            })?;
            Ok((target, Some(priority), Some(weight), Some(port)))
        }
        Record::TYPE_SOA => {
            if tokens.len() < 7 {
                return Err(Error::invalid(format!(
                    "SOA requires MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE, MINIMUM (line {line_num})."
                )));
            }
            let mname = absolutize_domain_name(&tokens[0], origin);
            let rname = absolutize_domain_name(&tokens[1], origin);
            let (Some(mname), Some(rname)) = (mname, rname) else {
                return Err(Error::invalid(format!(
                    "SOA requires origin for MNAME and RNAME (line {line_num})."
                )));
            };
            let rdata = format!(
                "{mname} {rname} {} {} {} {} {}",
                tokens[2], tokens[3], tokens[4], tokens[5], tokens[6]
            );
            Ok((rdata, None, None, None))
        }
        Record::TYPE_TXT => {
            let mut segments = Vec::new();
            for t in tokens {
                let t = t.trim();
                if t.is_empty() {
                    continue;
                }
                if t.starts_with('"') && t.ends_with('"') && t.len() >= 2 {
                    segments.push(decode_txt_segment(&t[1..t.len() - 1]));
                } else {
                    segments.push(decode_txt_segment(t));
                }
            }
            Ok((segments.concat(), None, None, None))
        }
        Record::TYPE_CAA => {
            if tokens.len() < 3 || !tokens[0].bytes().all(|b| b.is_ascii_digit()) {
                return Err(Error::invalid(format!(
                    "CAA requires flag, tag, and quoted value (line {line_num})."
                )));
            }
            let flag: i64 = tokens[0].parse().unwrap_or(0);
            if !(0..=255).contains(&flag) {
                return Err(Error::invalid(format!(
                    "CAA flag must be between 0 and 255 (line {line_num})."
                )));
            }
            let value_token = &tokens[2];
            if value_token.is_empty()
                || !value_token.starts_with('"')
                || !value_token.ends_with('"')
            {
                return Err(Error::invalid(format!(
                    "CAA value must be quoted (line {line_num})."
                )));
            }
            Ok((tokens.join(" "), None, None, None))
        }
        _ => Ok((tokens.join(" "), None, None, None)),
    }
}

fn format_resource_record(record: &Record, origin: &str) -> String {
    let name = relativize_domain_name(&record.name, origin);
    if record.type_code == Record::TYPE_SOA {
        let parts: Vec<&str> = record.rdata.split(' ').collect();
        if parts.len() >= 7 {
            return format!(
                "{}\t{}\t{}\t{}\t{} {} (\n\t\t\t\t{}\t; serial\n\t\t\t\t{}\t; refresh\n\t\t\t\t{}\t; retry\n\t\t\t\t{}\t; expire\n\t\t\t\t{} )\t; minimum",
                name,
                record.ttl,
                class_string(record.class),
                type_string(record.type_code),
                relativize_domain_name(parts[0], origin),
                relativize_domain_name(parts[1], origin),
                parts[2],
                parts[3],
                parts[4],
                parts[5],
                parts[6],
            );
        }
    }
    let rdata = format_rdata(
        record.type_code,
        &record.rdata,
        origin,
        record.priority,
        record.weight,
        record.port,
    );
    format!(
        "{}\t{}\t{}\t{}\t{}",
        name,
        record.ttl,
        class_string(record.class),
        type_string(record.type_code),
        rdata
    )
}

fn format_rdata(
    type_code: u16,
    rdata: &str,
    origin: &str,
    priority: Option<i64>,
    weight: Option<i64>,
    port: Option<i64>,
) -> String {
    match type_code {
        Record::TYPE_NS | Record::TYPE_CNAME | Record::TYPE_PTR => {
            relativize_domain_name(rdata, origin)
        }
        Record::TYPE_MX => {
            format!(
                "{} {}",
                priority.unwrap_or(0),
                relativize_domain_name(rdata, origin)
            )
        }
        Record::TYPE_SRV => format!(
            "{} {} {} {}",
            priority.unwrap_or(0),
            weight.unwrap_or(0),
            port.unwrap_or(0),
            relativize_domain_name(rdata, origin)
        ),
        Record::TYPE_TXT => {
            let escaped = addcslashes(rdata, "\"\\");
            format!("\"{escaped}\"")
        }
        _ => rdata.to_string(),
    }
}

fn ensure_trailing_dot(name: &str) -> String {
    format!("{}.", name.trim_end_matches('.'))
}

fn absolutize_domain_name(name: &str, origin: Option<&str>) -> Option<String> {
    let name = name.trim();
    if name.is_empty() || name == "@" {
        return origin.and_then(|o| canonicalize_name(Some(o)));
    }
    if name.ends_with('.') {
        return canonicalize_name(Some(name));
    }
    let origin = canonicalize_name(origin);
    match origin.as_deref() {
        None | Some(".") => canonicalize_name(Some(name)),
        Some(origin) => canonicalize_name(Some(&format!("{name}.{origin}"))),
    }
}

fn relativize_domain_name(name: &str, origin: &str) -> String {
    let name = ensure_trailing_dot(name);
    let origin = ensure_trailing_dot(origin);
    if name == origin {
        return "@".into();
    }
    if name.ends_with(&origin) {
        return name[..name.len() - origin.len()]
            .trim_end_matches('.')
            .to_string();
    }
    name
}

fn type_string(type_code: u16) -> String {
    Record::type_code_to_name(type_code).map_or_else(|| format!("TYPE{type_code}"), str::to_string)
}

fn class_string(class: u16) -> String {
    match class {
        Record::CLASS_IN => "IN".into(),
        Record::CLASS_CS => "CS".into(),
        Record::CLASS_CH => "CH".into(),
        Record::CLASS_HS => "HS".into(),
        other => format!("CLASS{other}"),
    }
}

fn class_map(name: &str) -> Option<u16> {
    match name {
        "IN" => Some(Record::CLASS_IN),
        "CS" => Some(Record::CLASS_CS),
        "CH" => Some(Record::CLASS_CH),
        "HS" => Some(Record::CLASS_HS),
        _ => None,
    }
}

fn split_whitespace(s: &str) -> Vec<String> {
    let mut tokens = Vec::new();
    let mut current = String::new();
    let bytes = s.as_bytes();
    let mut in_quotes = false;
    let mut quote = 0u8;
    let mut escaped = false;
    for &ch in bytes {
        if escaped {
            current.push(ch as char);
            escaped = false;
            continue;
        }
        if ch == b'\\' {
            current.push('\\');
            escaped = true;
            continue;
        }
        if in_quotes {
            current.push(ch as char);
            if ch == quote {
                in_quotes = false;
                quote = 0;
            }
            continue;
        }
        if ch == b'"' || ch == b'\'' {
            in_quotes = true;
            quote = ch;
            current.push(ch as char);
            continue;
        }
        if ch == b' ' || ch == b'\t' {
            if !current.is_empty() {
                tokens.push(std::mem::take(&mut current));
            }
            continue;
        }
        current.push(ch as char);
    }
    if !current.is_empty() {
        tokens.push(current);
    }
    tokens
}

fn canonicalize_name(name: Option<&str>) -> Option<String> {
    let name = name?.trim();
    if name.is_empty() {
        return None;
    }
    let trimmed = name.trim_end_matches('.');
    if trimmed.is_empty() {
        return Some(".".into());
    }
    Some(trimmed.to_ascii_lowercase())
}

fn decode_txt_segment(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut decoded = String::new();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] != b'\\' {
            decoded.push(bytes[i] as char);
            i += 1;
            continue;
        }
        i += 1;
        if i >= bytes.len() {
            decoded.push('\\');
            break;
        }
        let next = bytes[i];
        if next.is_ascii_digit() {
            let mut digits = String::new();
            digits.push(next as char);
            let mut count = 1;
            while count < 3 && i + 1 < bytes.len() && bytes[i + 1].is_ascii_digit() {
                i += 1;
                digits.push(bytes[i] as char);
                count += 1;
            }
            let n: u32 = digits.parse().unwrap_or(0);
            decoded.push(char::from_u32(n).unwrap_or('\0'));
            i += 1;
            continue;
        }
        decoded.push(next as char);
        i += 1;
    }
    decoded
}

fn remove_comment(line: &str) -> String {
    let bytes = line.as_bytes();
    let mut result = String::new();
    let mut escaped = false;
    let mut in_quotes = false;
    let mut quote = 0u8;
    for &ch in bytes {
        if escaped {
            result.push(ch as char);
            escaped = false;
            continue;
        }
        if ch == b'\\' {
            result.push('\\');
            escaped = true;
            continue;
        }
        if in_quotes {
            result.push(ch as char);
            if ch == quote {
                in_quotes = false;
            }
            continue;
        }
        if ch == b'"' || ch == b'\'' {
            in_quotes = true;
            quote = ch;
            result.push(ch as char);
            continue;
        }
        if ch == b';' {
            break;
        }
        result.push(ch as char);
    }
    result
}

fn group_records_by_type(records: &[Record]) -> std::collections::BTreeMap<u16, Vec<Record>> {
    let mut by_type = std::collections::BTreeMap::new();
    for r in records {
        by_type
            .entry(r.type_code)
            .or_insert_with(Vec::new)
            .push(r.clone());
    }
    by_type
}

fn addcslashes(s: &str, charset: &str) -> String {
    let mut out = String::new();
    for ch in s.chars() {
        if charset.contains(ch) {
            out.push('\\');
        }
        out.push(ch);
    }
    out
}

fn now_stamp() -> String {
    // PHP `date('Y-m-d H:i:s')`. Tests never assert the stamp value.
    "1970-01-01 00:00:00".into()
}
