//! Geometries: arrays to WKT for PostGIS, and the hex EWKB PostGIS returns
//! (or WKT) back to coordinate arrays.
//!
//! PHP: `SQL::convertArrayToWKT`, `SQL::getSpatialTypeFromWKT`,
//! `Postgres::decodePoint`, `Postgres::decodeLinestring`,
//! `Postgres::decodePolygon`.

use php_std::format::str_to_float;
use php_std::string::{explode, substr, trim};

use crate::error::{Error, Result};
use crate::value::{Array, ArrayKey, Value};

const WS: &[u8] = b" \n\r\t\x0B\0";

fn is_numeric(v: Option<&Value>) -> bool {
    match v {
        Some(Value::Int(_) | Value::Float(_)) => true,
        Some(Value::Str(s)) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

fn count(v: Option<&Value>) -> Option<usize> {
    match v {
        Some(Value::Array(a)) => Some(a.len()),
        _ => None,
    }
}

fn at(a: &Array, i: i64) -> Option<&Value> {
    a.get(&ArrayKey::Int(i))
}

fn text(v: Option<&Value>) -> String {
    v.map(crate::value::to_text).unwrap_or_default()
}

/// `convertArrayToWKT($geometry)`: `POINT(x y)`, `LINESTRING(...)` or
/// `POLYGON((...), ...)`.
pub fn array_to_wkt(geometry: &Array) -> Result<String> {
    if geometry.len() == 2 && is_numeric(at(geometry, 0)) && is_numeric(at(geometry, 1)) {
        return Ok(format!("POINT({} {})", text(at(geometry, 0)), text(at(geometry, 1))));
    }
    let first = at(geometry, 0);
    if let Some(Value::Array(p0)) = first
        && p0.len() == 2
        && is_numeric(at(p0, 0))
    {
        let mut points = Vec::new();
        for point in geometry.values() {
            match point {
                Value::Array(p) if p.len() == 2 && is_numeric(at(p, 0)) && is_numeric(at(p, 1)) => {
                    points.push(format!("{} {}", text(at(p, 0)), text(at(p, 1))));
                }
                _ => return Err(Error::database("Invalid point format in geometry array")),
            }
        }
        return Ok(format!("LINESTRING({})", points.join(", ")));
    }
    if let Some(Value::Array(p0)) = first
        && count(at(p0, 0)) == Some(2)
    {
        let mut rings = Vec::new();
        for ring in geometry.values() {
            let Value::Array(ring) = ring else {
                return Err(Error::database("Invalid ring format in polygon geometry"));
            };
            let mut points = Vec::new();
            for point in ring.values() {
                match point {
                    Value::Array(p) if p.len() == 2 && is_numeric(at(p, 0)) && is_numeric(at(p, 1)) => {
                        points.push(format!("{} {}", text(at(p, 0)), text(at(p, 1))));
                    }
                    _ => return Err(Error::database("Invalid point format in polygon ring")),
                }
            }
            rings.push(format!("({})", points.join(", ")));
        }
        return Ok(format!("POLYGON({})", rings.join(", ")));
    }
    Err(Error::database("Unrecognized geometry array format"))
}

/// `getSpatialTypeFromWKT($wkt)`: the lowercase type before `(`.
pub fn type_from_wkt(wkt: &[u8]) -> Result<Vec<u8>> {
    let wkt = trim(wkt, WS);
    let Some(pos) = wkt.iter().position(|c| *c == b'(') else {
        return Err(Error::database("Invalid spatial type"));
    };
    Ok(trim(&wkt[..pos], WS).to_ascii_lowercase())
}

fn float(v: f64) -> Value {
    Value::Float(v)
}

fn pair(point: &[u8]) -> Value {
    let coords = explode(b" ", trim(point, WS), i64::MAX).unwrap_or_default();
    let x = coords.first().map_or(0.0, |c| str_to_float(c));
    let y = coords.get(1).map_or(0.0, |c| str_to_float(c));
    Value::Array([float(x), float(y)].into_iter().collect())
}

/// `substr($s, $start, $end - $start)` with PHP's `strpos`/`strrpos`
/// results (`false` counts as 0).
fn inside(s: &[u8], start: i64, end: Option<usize>) -> Vec<u8> {
    let end = end.map_or(0, |e| e as i64);
    substr(s, start, Some(end - start)).to_vec()
}

fn find(s: &[u8], needle: &[u8]) -> Option<usize> {
    s.windows(needle.len()).position(|w| w == needle)
}

fn rfind(s: &[u8], needle: &[u8]) -> Option<usize> {
    s.windows(needle.len()).rposition(|w| w == needle)
}

fn u32_le(b: &[u8], offset: usize) -> Option<u32> {
    b.get(offset..offset + 4).map(|s| u32::from_le_bytes([s[0], s[1], s[2], s[3]]))
}

fn f64_le(b: &[u8], offset: usize) -> Option<f64> {
    b.get(offset..offset + 8).map(|s| f64::from_le_bytes(s.try_into().unwrap_or([0; 8])))
}

/// `decodePoint($wkb)` (Postgres): `[x, y]` from WKT or hex EWKB.
pub fn decode_point(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"POINT(") {
        let start = find(wkb, b"(").map_or(0, |p| p as i64) + 1;
        return Ok(pair(&inside(wkb, start, rfind(wkb, b")"))));
    }
    let Some(bin) = php_std::encoding::hex2bin(wkb) else {
        return Err(Error::database("Invalid hex WKB string"));
    };
    if bin.len() < 13 {
        return Err(Error::database("WKB too short"));
    }
    let le = bin[0] == 1;
    let tb = [bin[1], bin[2], bin[3], bin[4]];
    let kind = if le { u32::from_le_bytes(tb) } else { u32::from_be_bytes(tb) };
    let offset = 5 + if kind & 0x2000_0000 != 0 { 4 } else { 0 };
    if bin.len() < offset + 16 {
        return Err(Error::database("WKB too short for coordinates"));
    }
    let read = |o: usize| {
        let b: [u8; 8] = bin[o..o + 8].try_into().unwrap_or([0; 8]);
        if le { f64::from_le_bytes(b) } else { f64::from_be_bytes(b) }
    };
    Ok(Value::Array([float(read(offset)), float(read(offset + 8))].into_iter().collect()))
}

/// `decodeLinestring($wkb)` (Postgres): `[[x, y], ...]`.
pub fn decode_linestring(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"LINESTRING(") {
        let start = find(wkb, b"(").map_or(0, |p| p as i64) + 1;
        let body = inside(wkb, start, rfind(wkb, b")"));
        let points = explode(b",", &body, i64::MAX).unwrap_or_default();
        return Ok(Value::Array(points.into_iter().map(pair).collect()));
    }
    let mut wkb = wkb.to_vec();
    if php_std::string::ctype_xdigit(&wkb) {
        wkb =
            php_std::encoding::hex2bin(&wkb).ok_or_else(|| Error::database("Failed to convert hex WKB to binary."))?;
    }
    if wkb.len() < 9 {
        return Err(Error::database("WKB too short to be a valid geometry"));
    }
    match wkb[0] {
        0 => return Err(Error::database("Big-endian WKB not supported")),
        1 => {}
        _ => return Err(Error::database("Invalid byte order in WKB")),
    }
    let kind = u32_le(&wkb, 1).ok_or_else(|| Error::database("Failed to unpack the type field from WKB."))?;
    let geom = kind & 0xFF;
    if geom != 2 {
        return Err(Error::database(format!("Not a LINESTRING geometry type, got {geom}")));
    }
    let mut offset = 5 + if kind & 0x2000_0000 != 0 { 4 } else { 0 };
    let n = u32_le(&wkb, offset)
        .ok_or_else(|| Error::database(format!("Failed to unpack number of points at offset {offset}.")))?;
    offset += 4;
    let mut points = Array::new();
    for _ in 0..n {
        let x = f64_le(&wkb, offset)
            .ok_or_else(|| Error::database(format!("Failed to unpack X coordinate at offset {offset}.")))?;
        offset += 8;
        let y = f64_le(&wkb, offset)
            .ok_or_else(|| Error::database(format!("Failed to unpack Y coordinate at offset {offset}.")))?;
        offset += 8;
        let _ = points.push(Value::Array([float(x), float(y)].into_iter().collect()));
    }
    Ok(Value::Array(points))
}

/// `decodePolygon($wkb)` (Postgres): `[[[x, y], ...], ...]`.
pub fn decode_polygon(wkb: &[u8]) -> Result<Value> {
    if wkb.starts_with(b"POLYGON((") {
        let start = find(wkb, b"((").map_or(0, |p| p as i64) + 2;
        let body = inside(wkb, start, rfind(wkb, b"))"));
        let rings = explode(b"),(", &body, i64::MAX).unwrap_or_default();
        return Ok(Value::Array(
            rings
                .into_iter()
                .map(|ring| {
                    let points = explode(b",", ring, i64::MAX).unwrap_or_default();
                    Value::Array(points.into_iter().map(pair).collect())
                })
                .collect(),
        ));
    }
    let mut wkb = wkb.to_vec();
    let body = wkb.strip_suffix(b"\n").unwrap_or(&wkb);
    if !body.is_empty() && body.iter().all(u8::is_ascii_hexdigit) {
        wkb = php_std::encoding::hex2bin(&wkb).ok_or_else(|| Error::database("Invalid hex WKB"))?;
    }
    if wkb.len() < 9 {
        return Err(Error::database("WKB too short"));
    }
    let kind = u32_le(&wkb, 1).ok_or_else(|| Error::database("Failed to unpack type field from WKB."))?;
    let geom = kind & 0xFF;
    if geom != 3 {
        return Err(Error::database(format!("Not a POLYGON geometry type, got {geom}")));
    }
    let mut offset = 5 + if kind & 0x2000_0000 != 0 { 4 } else { 0 };
    let n = u32_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack number of rings from WKB."))?;
    offset += 4;
    let mut rings = Array::new();
    for _ in 0..n {
        let points =
            u32_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack number of points from WKB."))?;
        offset += 4;
        let mut ring = Array::new();
        for _ in 0..points {
            let x = f64_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack X coordinate from WKB."))?;
            let y =
                f64_le(&wkb, offset + 8).ok_or_else(|| Error::database("Failed to unpack Y coordinate from WKB."))?;
            let _ = ring.push(Value::Array([float(x), float(y)].into_iter().collect()));
            offset += 16;
        }
        let _ = rings.push(Value::Array(ring));
    }
    Ok(Value::Array(rings))
}
