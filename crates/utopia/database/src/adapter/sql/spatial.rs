//! Spatial values: WKT, and the engines' binary forms.
//!
//! PHP: `Adapter\SQL\Wkt::encode()`, `SQL::convertArrayToWkt()`,
//! `getSpatialGeometryFromText()` (and SQLite's), `getSpatialAxisOrder()`,
//! and `encode()` / `decode()` with `decodePoint()`, `decodeLinestring()`,
//! `decodePolygon()` of `Postgres` and `MariaDB`.

use php_std::format::str_to_float;
use php_std::pcre;
use php_std::string::{explode, substr, trim};
use utopia_query::schema::ColumnType;

use super::{Engine, Sql};
use crate::adapter::Adapter;
use crate::capability::Capability;
use crate::database;
use crate::error::{Error, Result};
use crate::validator::spatial::Spatial as SpatialValidator;
use crate::value::{Array, ArrayKey, Value};

/// PHP's `trim()` characters.
const WS: &[u8] = b" \n\r\t\x0B\0";

/// `$value[$i]`: an array's element, a string's byte, else `null`.
fn at(value: &Value, i: i64) -> Value {
    match value {
        Value::Array(a) => a.get(&ArrayKey::Int(i)).cloned().unwrap_or(Value::Null),
        Value::Str(s) => {
            let bytes = s.as_bytes();
            usize::try_from(i)
                .ok()
                .and_then(|i| bytes.get(i))
                .map_or(Value::Null, |b| Value::Str(crate::value::Str::from(vec![*b])))
        }
        _ => Value::Null,
    }
}

/// `"{$value}"`.
fn text(value: &Value) -> String {
    match value {
        Value::Null => String::new(),
        other => crate::value::to_text(other),
    }
}

/// `Wkt::points()`: `x y` pairs joined by `, `.
fn points(value: &Value) -> String {
    let Value::Array(points) = value else {
        return String::new();
    };
    points.values().map(|p| format!("{} {}", text(&at(p, 0)), text(&at(p, 1)))).collect::<Vec<_>>().join(", ")
}

/// `is_numeric()`.
fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `Adapter\SQL\Wkt::encode()`: the well-known text of a spatial value of
/// `kind` (a polygon may be given as one ring).
///
/// Fails ([`crate::Kind::Structure`]) for a value that is not a valid
/// geometry of the type, ([`crate::Kind::Database`]) for a type that is not
/// spatial.
pub(crate) fn wkt(value: &Value, kind: ColumnType) -> Result<String> {
    let validator = SpatialValidator::new(kind.as_str());
    if !validator.is_valid(value) {
        return Err(Error::structure(validator.description()));
    }
    match kind {
        ColumnType::Point => Ok(format!("POINT({} {})", text(&at(value, 0)), text(&at(value, 1)))),
        ColumnType::Linestring => Ok(format!("LINESTRING({})", points(value))),
        ColumnType::Polygon => {
            let first = at(value, 0);
            let single_ring = matches!(&first, Value::Array(a) if a.len() == 2)
                && is_numeric(&at(&first, 0))
                && is_numeric(&at(&first, 1));
            let rings: Vec<String> = if single_ring {
                vec![format!("({})", points(value))]
            } else {
                match value {
                    Value::Array(rings) => rings.values().map(|ring| format!("({})", points(ring))).collect(),
                    _ => Vec::new(),
                }
            };
            Ok(format!("POLYGON({})", rings.join(", ")))
        }
        other => Err(Error::database(format!("Unknown spatial type: {}", other.as_str()))),
    }
}

/// Whether a value is a list (`array_is_list()`).
fn is_list(array: &Array) -> bool {
    array.is_list()
}

/// `x y` of a point checked to be two numbers.
fn checked_point(point: &Value, message: &str) -> Result<String> {
    match point {
        Value::Array(p) if p.len() == 2 && is_numeric(&at(point, 0)) && is_numeric(&at(point, 1)) => {
            Ok(format!("{} {}", text(&at(point, 0)), text(&at(point, 1))))
        }
        _ => Err(Error::database(message.to_owned())),
    }
}

impl Sql {
    /// `convertArrayToWkt()`: `POINT(x y)`, `LINESTRING(...)` or
    /// `POLYGON((...), ...)` of a coordinate array.
    pub(crate) fn convert_array_to_wkt(geometry: &Array) -> Result<String> {
        if geometry.is_empty() || !is_list(geometry) {
            return Err(Error::database("Unrecognized geometry array format"));
        }
        let value = Value::Array(geometry.clone());
        let first = at(&value, 0);
        if geometry.len() == 2 && is_numeric(&first) && is_numeric(&at(&value, 1)) {
            return Ok(format!("POINT({} {})", text(&first), text(&at(&value, 1))));
        }
        if let Value::Array(p0) = &first
            && p0.len() == 2
            && is_numeric(&at(&first, 0))
        {
            let points = geometry
                .values()
                .map(|p| checked_point(p, "Invalid point format in geometry array"))
                .collect::<Result<Vec<_>>>()?;
            return Ok(format!("LINESTRING({})", points.join(", ")));
        }
        if matches!(&first, Value::Array(_)) && matches!(at(&first, 0), Value::Array(p00) if p00.len() == 2) {
            let mut rings = Vec::new();
            for ring in geometry.values() {
                let Value::Array(ring) = ring else {
                    return Err(Error::database("Invalid ring format in polygon geometry"));
                };
                let points = ring
                    .values()
                    .map(|p| checked_point(p, "Invalid point format in polygon ring"))
                    .collect::<Result<Vec<_>>>()?;
                rings.push(format!("({})", points.join(", ")));
            }
            return Ok(format!("POLYGON({})", rings.join(", ")));
        }
        Err(Error::database("Unrecognized geometry array format"))
    }

    /// `getSpatialGeometryFromText()`: `ST_GeomFromText(wkt, srid[, axis
    /// order])`; SQLite has no such function and takes the placeholder.
    pub(crate) fn spatial_geometry_from_text(&self, placeholder: &str, srid: Option<i64>) -> String {
        if self.engine == Engine::Sqlite {
            return placeholder.to_owned();
        }
        let srid = srid.unwrap_or(database::DEFAULT_SRID);
        let mut sql = format!("ST_GeomFromText({placeholder}, {srid}");
        if self.supports(Capability::SpatialAxisOrder) {
            sql.push_str(", ");
            sql.push_str(self.spatial_axis_order());
        }
        sql.push(')');
        sql
    }

    /// `getSpatialAxisOrder()`: the data is long-lat.
    pub(crate) fn spatial_axis_order(&self) -> &'static str {
        "'axis-order=long-lat'"
    }

    /// `encode()`: the WKT of a spatial value.
    pub(crate) fn encode(&self, value: &Value, kind: ColumnType) -> Result<String> {
        wkt(value, kind)
    }

    /// `decode()`: the coordinates of a value the engine returned for a
    /// spatial column (Postgres: hex EWKB or WKT; MariaDB and MySQL:
    /// SRID-prefixed WKB or WKT).
    pub(crate) fn decode(&self, value: &[u8], kind: ColumnType) -> Result<Value> {
        let postgres = self.engine == Engine::Postgres;
        match kind {
            ColumnType::Point if postgres => postgres_point(value),
            ColumnType::Point => mariadb_point(value),
            ColumnType::Linestring if postgres => postgres_linestring(value),
            ColumnType::Linestring => mariadb_linestring(value),
            ColumnType::Polygon if postgres => postgres_polygon(value),
            ColumnType::Polygon => mariadb_polygon(value),
            other => Err(Error::database(format!("Unknown spatial type: {}", other.as_str()))),
        }
    }
}

/// `[(float) $x, (float) $y]` of `explode(' ', trim($point))`.
fn pair(point: &[u8]) -> Value {
    let coords = explode(b" ", trim(point, WS), i64::MAX).unwrap_or_default();
    let x = coords.first().map_or(0.0, |c| str_to_float(c));
    let y = coords.get(1).map_or(0.0, |c| str_to_float(c));
    float_pair(x, y)
}

fn float_pair(x: f64, y: f64) -> Value {
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

/// `unpack('V', substr($b, $offset, 4))[1]`.
fn u32_le(b: &[u8], offset: usize) -> Option<u32> {
    b.get(offset..offset + 4).map(|s| u32::from_le_bytes([s[0], s[1], s[2], s[3]]))
}

/// `unpack('d'|'e', substr($b, $offset, 8))[1]`.
fn f64_le(b: &[u8], offset: usize) -> Option<f64> {
    b.get(offset..offset + 8).map(|s| f64::from_le_bytes(s.try_into().unwrap_or([0; 8])))
}

/// The WKT points of a `LINESTRING(...)`.
fn wkt_linestring(wkb: &[u8]) -> Value {
    let start = find(wkb, b"(").map_or(0, |p| p as i64) + 1;
    let body = inside(wkb, start, rfind(wkb, b")"));
    let points = explode(b",", &body, i64::MAX).unwrap_or_default();
    Value::Array(points.into_iter().map(pair).collect())
}

/// The WKT rings of a `POLYGON((...))`, split on `/\)\s*,\s*\(/`.
fn wkt_polygon(wkb: &[u8]) -> Value {
    let start = find(wkb, b"((").map_or(0, |p| p as i64) + 2;
    let body = inside(wkb, start, rfind(wkb, b"))"));
    let rings: Vec<Vec<u8>> = match pcre::preg_split(br"/\)\s*,\s*\(/", &body, -1, 0).value {
        pcre::Value::Array(parts) if !parts.is_empty() => parts
            .entries()
            .iter()
            .map(|(_, v)| match v {
                pcre::Value::Str(s) => s.clone(),
                _ => Vec::new(),
            })
            .collect(),
        _ => vec![body.clone()],
    };
    Value::Array(
        rings
            .into_iter()
            .map(|ring: Vec<u8>| {
                let points = explode(b",", &ring, i64::MAX).unwrap_or_default();
                Value::Array(points.into_iter().map(pair).collect())
            })
            .collect(),
    )
}

/// Postgres `decodePoint()`: `[x, y]` from WKT or hex EWKB.
fn postgres_point(wkb: &[u8]) -> Result<Value> {
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
    Ok(float_pair(read(offset), read(offset + 8)))
}

/// Postgres `decodeLinestring()`: `[[x, y], ...]`.
fn postgres_linestring(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"LINESTRING(") {
        return Ok(wkt_linestring(wkb));
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
        let _ = points.push(float_pair(x, y));
    }
    Ok(Value::Array(points))
}

/// Postgres `decodePolygon()`: `[[[x, y], ...], ...]`.
fn postgres_polygon(wkb: &[u8]) -> Result<Value> {
    if wkb.starts_with(b"POLYGON((") {
        return Ok(wkt_polygon(wkb));
    }
    let mut wkb = wkb.to_vec();
    // preg_match('/^[0-9a-fA-F]+$/'): `$` also matches before a final newline.
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
        let count =
            u32_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack number of points from WKB."))?;
        offset += 4;
        let mut ring = Array::new();
        for _ in 0..count {
            let x = f64_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack X coordinate from WKB."))?;
            let y =
                f64_le(&wkb, offset + 8).ok_or_else(|| Error::database("Failed to unpack Y coordinate from WKB."))?;
            let _ = ring.push(float_pair(x, y));
            offset += 16;
        }
        let _ = rings.push(Value::Array(ring));
    }
    Ok(Value::Array(rings))
}

/// MariaDB `decodePoint()`: `[x, y]` from WKT, or from WKB behind a 4-byte
/// SRID.
fn mariadb_point(wkb: &[u8]) -> Result<Value> {
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
    Ok(float_pair(f64_le(wkb, 9).unwrap_or_default(), f64_le(wkb, 17).unwrap_or_default()))
}

/// MariaDB `decodeLinestring()`: `[[x, y], ...]` from WKT, or from WKB
/// behind a 4-byte SRID.
fn mariadb_linestring(wkb: &[u8]) -> Result<Value> {
    if wkb.to_ascii_uppercase().starts_with(b"LINESTRING(") {
        return Ok(wkt_linestring(wkb));
    }
    let mut offset = 9;
    let n = u32_le(wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of points"))?;
    offset += 4;
    let mut points = Array::new();
    for _ in 0..n {
        let (Some(x), Some(y)) = (f64_le(wkb, offset), f64_le(wkb, offset + 8)) else {
            return Err(Error::database("Invalid WKB: cannot unpack point coordinates"));
        };
        let _ = points.push(float_pair(x, y));
        offset += 16;
    }
    Ok(Value::Array(points))
}

/// MariaDB `decodePolygon()`: `[[[x, y], ...], ...]` from WKT, hex or
/// binary WKB behind a 4-byte SRID.
fn mariadb_polygon(wkb: &[u8]) -> Result<Value> {
    if wkb.starts_with(b"POLYGON((") {
        return Ok(wkt_polygon(wkb));
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
    let count = u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of rings"))?;
    offset += 4;
    let mut rings = Array::new();
    for _ in 0..count {
        let points =
            u32_le(&wkb, offset).ok_or_else(|| Error::database("Invalid WKB: cannot unpack number of points"))?;
        offset += 4;
        let mut ring = Array::new();
        for _ in 0..points {
            let x = f64_le(&wkb, offset).ok_or_else(|| Error::database("Failed to unpack X coordinate from WKB."))?;
            let y =
                f64_le(&wkb, offset + 8).ok_or_else(|| Error::database("Failed to unpack Y coordinate from WKB."))?;
            let _ = ring.push(float_pair(x, y));
            offset += 16;
        }
        let _ = rings.push(Value::Array(ring));
    }
    Ok(Value::Array(rings))
}
