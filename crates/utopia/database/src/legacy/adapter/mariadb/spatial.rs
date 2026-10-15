//! Geometries: arrays to WKT, and the SRID-prefixed WKB MariaDB and MySQL
//! return for spatial columns (or WKT) back to coordinate arrays.
//!
//! PHP: `SQL::convertArrayToWKT`, `SQL::getSpatialTypeFromWKT`,
//! `SQL::decodePoint`, `SQL::decodeLinestring`, `SQL::decodePolygon`.

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

fn pair(point: &[u8]) -> Value {
    let coords = explode(b" ", trim(point, WS), i64::MAX).unwrap_or_default();
    let x = coords.first().map_or(0.0, |c| str_to_float(c));
    let y = coords.get(1).map_or(0.0, |c| str_to_float(c));
    Value::Array([Value::Float(x), Value::Float(y)].into_iter().collect())
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

/// `unpack('V', substr($wkb, $offset, 4))[1]`: `None` when fewer than 4
/// bytes remain.
fn u32_le(b: &[u8], offset: usize) -> Option<u32> {
    b.get(offset..offset + 4).map(|s| u32::from_le_bytes([s[0], s[1], s[2], s[3]]))
}

/// `unpack('d', substr($wkb, $offset, 8))[1]`.
fn f64_le(b: &[u8], offset: usize) -> Option<f64> {
    b.get(offset..offset + 8).map(|s| f64::from_le_bytes(s.try_into().unwrap_or([0; 8])))
}

fn point(x: f64, y: f64) -> Value {
    Value::Array([Value::Float(x), Value::Float(y)].into_iter().collect())
}

/// `decodePoint($wkb)` (SQL): `[x, y]` from WKT, or from WKB behind a
/// 4-byte SRID.
pub fn decode_point(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"POINT(") {
        let start = find(wkb, b"(").map_or(0, |p| p as i64) + 1;
        return Ok(pair(&inside(wkb, start, rfind(wkb, b")"))));
    }
    if wkb.len() < 25 {
        return Err(Error::database("Invalid WKB: too short for POINT"));
    }
    if wkb[4] != 1 {
        return Err(Error::database("Only little-endian WKB supported"));
    }
    let x = f64_le(wkb, 9).unwrap_or_default();
    let y = f64_le(wkb, 17).unwrap_or_default();
    Ok(point(x, y))
}

/// `decodeLinestring($wkb)` (SQL): `[[x, y], ...]` from WKT, or from WKB
/// behind a 4-byte SRID.
pub fn decode_linestring(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"LINESTRING(") {
        let start = find(wkb, b"(").map_or(0, |p| p as i64) + 1;
        let body = inside(wkb, start, rfind(wkb, b")"));
        let points = explode(b",", &body, i64::MAX).unwrap_or_default();
        return Ok(Value::Array(points.into_iter().map(pair).collect()));
    }
    let mut offset = 9;
    let n = u32_le(wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of points"))?;
    offset += 4;
    let mut points = Array::new();
    for _ in 0..n {
        let (Some(x), Some(y)) = (f64_le(wkb, offset), f64_le(wkb, offset + 8)) else {
            return Err(Error::database("Invalid WKB: cannot unpack point coordinates"));
        };
        let _ = points.push(point(x, y));
        offset += 16;
    }
    Ok(Value::Array(points))
}

/// `decodePolygon($wkb)` (SQL): `[[[x, y], ...], ...]` from WKT, hex or
/// binary WKB behind a 4-byte SRID.
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
    if wkb.starts_with(b"0x") || php_std::string::ctype_xdigit(&wkb) {
        let hex = if wkb.starts_with(b"0x") { wkb[2..].to_vec() } else { wkb.clone() };
        wkb = php_std::encoding::hex2bin(&hex).ok_or_else(|| Error::database("Invalid hex WKB"))?;
    }
    if wkb.len() < 21 {
        return Err(Error::database("WKB too short to be a POLYGON"));
    }
    let mut offset = 4;
    if wkb[offset] != 1 {
        return Err(Error::database("Only little-endian WKB supported"));
    }
    offset += 1;
    let kind = u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack geometry type"))?;
    let has_srid = kind & 0x2000_0000 == 0x2000_0000;
    let geom = kind & 0xFF;
    offset += 4;
    if geom != 3 {
        return Err(Error::database(format!("Not a POLYGON geometry type, got {geom}")));
    }
    if has_srid {
        offset += 4;
    }
    let rings_count =
        u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of rings"))?;
    offset += 4;
    let mut rings = Array::new();
    for _ in 0..rings_count {
        let points =
            u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of points"))?;
        offset += 4;
        let mut ring = Array::new();
        for _ in 0..points {
            let x = f64_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack X coordinate from WKB."))?;
            let y =
                f64_le(&wkb, offset + 8).ok_or_else(|| Error::database("Failed to unpack Y coordinate from WKB."))?;
            let _ = ring.push(point(x, y));
            offset += 16;
        }
        let _ = rings.push(Value::Array(ring));
    }
    Ok(Value::Array(rings))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn wkb_point(x: f64, y: f64) -> Vec<u8> {
        let mut b = 4326u32.to_le_bytes().to_vec();
        b.push(1);
        b.extend_from_slice(&1u32.to_le_bytes());
        b.extend_from_slice(&x.to_le_bytes());
        b.extend_from_slice(&y.to_le_bytes());
        b
    }

    #[test]
    fn decodes_srid_prefixed_points() {
        let v = decode_point(&wkb_point(1.5, -2.0)).unwrap();
        assert_eq!(v, point(1.5, -2.0));
        assert!(decode_point(b"short").is_err());
        assert_eq!(decode_point(b"POINT(3 4)").unwrap(), point(3.0, 4.0));
    }
}
