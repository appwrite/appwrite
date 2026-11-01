//! Parsers: from raw contents to a key/value map.

use php_std::json::{self, Flags};
use php_std::{ArrayKey, Str};

use crate::schema::{Field, Kind, Schema};
use crate::{Array, Error, Value, php, widen};

/// Turns a source's contents into a map of keys to values.
///
/// The schema lets loosely typed formats convert values to the type of the
/// property that reads them ([`Dotenv`] reads booleans this way).
///
/// PHP: `Utopia\Config\Parser` (`parse($contents, $reflection)`).
pub trait Parser {
    /// The map in `contents`.
    ///
    /// PHP: `parse($contents, $reflection)`.
    fn parse(&self, contents: &Value, schema: Option<&Schema>) -> Result<Array, Error>;
}

fn parse_error(message: &str) -> Error {
    Error::Parse(message.to_owned())
}

fn string(contents: &Value) -> Result<&[u8], Error> {
    match contents {
        Value::Str(s) => Ok(s.as_bytes()),
        _ => Err(parse_error("Contents must be a string.")),
    }
}

/// A config must be a map; an empty array is one. A non-empty list is not
/// (nor is a map whose keys are `0, 1, 2...`, which decodes the same).
fn require_map(config: Value, message: &str) -> Result<Array, Error> {
    match config {
        Value::Array(a) if a.is_empty() || !a.is_list() => Ok(a),
        _ => Err(parse_error(message)),
    }
}

/// Contents that are already an array, used as they are.
///
/// PHP: `Utopia\Config\Parser\None`.
///
/// ```
/// use utopia_config::{Array, NoParser, Parser, Value};
///
/// assert!(NoParser.parse(&Value::Array(Array::default()), None)?.is_empty());
/// assert_eq!(NoParser.parse(&Value::from("x"), None).unwrap_err().to_string(), "Contents must be an array");
/// # Ok::<(), utopia_config::Error>(())
/// ```
///
/// ```php
/// use Utopia\Config\Parser\None;
///
/// var_dump((new None())->parse([])); // array(0) {}
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct NoParser;

impl Parser for NoParser {
    fn parse(&self, contents: &Value, _schema: Option<&Schema>) -> Result<Array, Error> {
        match contents {
            Value::Array(a) => Ok(a.clone()),
            _ => Err(parse_error("Contents must be an array")),
        }
    }
}

/// A JSON object.
///
/// PHP: `Utopia\Config\Parser\JSON` (`json_decode($contents, true)`).
///
/// ```
/// use utopia_config::{Json, Parser, Value};
///
/// let config = Json.parse(&Value::from(r#"{"port": 8080, "tls": true}"#), None)?;
/// assert_eq!(config.get_str("port"), Some(&Value::Int(8080)));
/// # Ok::<(), utopia_config::Error>(())
/// ```
///
/// ```php
/// use Utopia\Config\Parser\JSON;
///
/// $config = (new JSON())->parse('{"port": 8080, "tls": true}');
/// var_dump($config['port']); // int(8080)
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct Json;

impl Parser for Json {
    fn parse(&self, contents: &Value, _schema: Option<&Schema>) -> Result<Array, Error> {
        let text = string(contents)?;
        if text.is_empty() || text == b"0" {
            return Ok(Array::default());
        }
        let decoded = json::decode(text, Some(true), json::DEFAULT_DEPTH, Flags::NONE)
            .map_err(|_| parse_error("Config file is not a valid JSON file."))?;
        require_map(widen(decoded), "Config file must decode to a JSON object.")
    }
}

/// A YAML mapping (the first document), read as PHP's ext-yaml reads it:
/// YAML 1.1 booleans (`yes`, `off`), octal and sexagesimal integers, merge
/// keys.
///
/// PHP: `Utopia\Config\Parser\YAML` (`yaml_parse($contents)`).
///
/// ```
/// use utopia_config::{Parser, Value, Yaml};
///
/// let config = Yaml.parse(&Value::from("debug: yes\nworkers: 4\n"), None)?;
/// assert_eq!(config.get_str("debug"), Some(&Value::Bool(true)));
/// assert_eq!(config.get_str("workers"), Some(&Value::Int(4)));
/// # Ok::<(), utopia_config::Error>(())
/// ```
///
/// ```php
/// use Utopia\Config\Parser\YAML;
///
/// $config = (new YAML())->parse("debug: yes\nworkers: 4\n");
/// var_dump($config['debug'], $config['workers']); // bool(true) int(4)
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct Yaml;

impl Parser for Yaml {
    fn parse(&self, contents: &Value, _schema: Option<&Schema>) -> Result<Array, Error> {
        let text = string(contents)?;
        if text.is_empty() || text == b"0" {
            return Ok(Array::default());
        }
        let invalid = || parse_error("Config file is not a valid YAML file.");
        let config = widen(php_std::yaml::parse(text).ok_or_else(invalid)?);
        match &config {
            Value::Null | Value::Bool(false) => return Err(invalid()),
            Value::Str(s) if s.as_bytes() == text => return Err(invalid()),
            _ => {}
        }
        require_map(config, "Config file must decode to a YAML mapping.")
    }
}

/// A PHP file that returns an array.
///
/// PHP includes the file. Rust cannot run PHP, so it evaluates the constant
/// expression the file returns: literals, arrays, engine constants such as
/// `PHP_EOL` and arithmetic (see the crate's PHP notes). Files that compute
/// their config with PHP code (variables, function calls, interpolation) are
/// refused with [`Error::Parse`].
///
/// PHP: `Utopia\Config\Parser\PHP`.
///
/// ```
/// use utopia_config::{Parser, Php, Value};
///
/// let config = Php.parse(&Value::from("<?php return ['workers' => 2 * 4, 'name' => 'api'];"), None)?;
/// assert_eq!(config.get_str("workers"), Some(&Value::Int(8)));
/// # Ok::<(), utopia_config::Error>(())
/// ```
///
/// ```php
/// use Utopia\Config\Parser\PHP;
///
/// $config = (new PHP())->parse("<?php return ['workers' => 2 * 4, 'name' => 'api'];");
/// var_dump($config['workers']); // int(8)
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct Php;

impl Parser for Php {
    fn parse(&self, contents: &Value, _schema: Option<&Schema>) -> Result<Array, Error> {
        let text = string(contents)?;
        if !text.starts_with(b"<?php") || (text.len() > 5 && !is_ctype_space(text[5])) {
            return Err(parse_error("PHP config must start with an opening <?php tag."));
        }
        match php::evaluate(text) {
            Ok(php_std::Value::Array(a)) => Ok(widen_php(a)),
            Ok(_) => Err(parse_error("PHP config file must return an array.")),
            Err(php::Failure::Thrown(message)) => {
                Err(Error::Parse(format!("Failed to parse PHP config file: {message}")))
            }
            Err(php::Failure::Unsupported(what)) => {
                Err(Error::Parse(format!("Failed to parse PHP config file: unsupported PHP: {what}")))
            }
        }
    }
}

fn widen_php(array: php_std::Array) -> Array {
    match widen(php_std::Value::Array(array)) {
        Value::Array(a) => a,
        _ => Array::default(),
    }
}

/// `ctype_space()`: space, `\t`, `\n`, `\v`, `\f`, `\r`.
fn is_ctype_space(c: u8) -> bool {
    matches!(c, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// PHP's `trim()` characters: space, `\t`, `\n`, `\r`, `\0`, `\v`.
fn is_trim(c: u8) -> bool {
    matches!(c, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0B)
}

fn trim(s: &[u8]) -> &[u8] {
    let start = s.iter().position(|&c| !is_trim(c)).unwrap_or(s.len());
    let end = s.iter().rposition(|&c| !is_trim(c)).map_or(start, |e| e + 1);
    &s[start..end.max(start)]
}

fn ltrim(s: &[u8]) -> &[u8] {
    let start = s.iter().position(|&c| !is_trim(c)).unwrap_or(s.len());
    &s[start..]
}

/// `KEY=value` lines (`.env` files).
///
/// Blank lines and lines starting with `#` are skipped; `export KEY=value`
/// is `KEY=value`. A value may be single-quoted (literal) or double-quoted
/// (`\"` and `\\` are escapes); unquoted, a `#` after whitespace starts a
/// comment. `null` (any case) is null. Values of properties typed `bool` in
/// the schema become booleans: `1`, `true`, `yes`, `on`, `enabled` and `0`,
/// `false`, `no`, `off`, `disabled` (any case, compared as PHP's `==`).
///
/// PHP: `Utopia\Config\Parser\Dotenv`.
///
/// ```
/// use utopia_config::{Dotenv, Parser, Value};
///
/// let config = Dotenv.parse(&Value::from("HOST=127.0.0.1\nPASSWORD=\"abc#123\" # quoted\nEMPTY=null"), None)?;
/// assert_eq!(config.get_str("HOST"), Some(&Value::from("127.0.0.1")));
/// assert_eq!(config.get_str("PASSWORD"), Some(&Value::from("abc#123")));
/// assert_eq!(config.get_str("EMPTY"), Some(&Value::Null));
/// # Ok::<(), utopia_config::Error>(())
/// ```
///
/// ```php
/// use Utopia\Config\Parser\Dotenv;
///
/// $config = (new Dotenv())->parse("HOST=127.0.0.1\nPASSWORD=\"abc#123\" # quoted\nEMPTY=null");
/// echo $config['HOST'], "\n";      // 127.0.0.1
/// echo $config['PASSWORD'], "\n";  // abc#123
/// var_dump($config['EMPTY']);      // NULL
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct Dotenv;

const TRUTHY: [&[u8]; 5] = [b"1", b"true", b"yes", b"on", b"enabled"];
const FALSY: [&[u8]; 5] = [b"0", b"false", b"no", b"off", b"disabled"];

fn invalid_dotenv() -> Error {
    parse_error("Config file is not a valid dotenv file.")
}

impl Dotenv {
    fn value(raw: &[u8]) -> Result<Vec<u8>, Error> {
        let raw = trim(raw);
        if let Some(&quote @ (b'"' | b'\'')) = raw.first() {
            return Self::quoted(raw, quote);
        }
        let mut from = 0;
        while let Some(at) = raw[from..].iter().position(|&c| c == b'#').map(|p| p + from) {
            if at == 0 {
                return Ok(Vec::new());
            }
            if is_ctype_space(raw[at - 1]) {
                return Ok(trim(&raw[..at - 1]).to_vec());
            }
            from = at + 1;
        }
        Ok(raw.to_vec())
    }

    fn quoted(raw: &[u8], quote: u8) -> Result<Vec<u8>, Error> {
        let mut value = Vec::new();
        let mut i = 1;
        while i < raw.len() {
            let c = raw[i];
            if c == b'\\' && quote == b'"' && i + 1 < raw.len() && matches!(raw[i + 1], b'"' | b'\\') {
                value.push(raw[i + 1]);
                i += 2;
                continue;
            }
            if c == quote {
                let rest = trim(&raw[i + 1..]);
                if !rest.is_empty() && rest[0] != b'#' {
                    return Err(invalid_dotenv());
                }
                return Ok(value);
            }
            value.push(c);
            i += 1;
        }
        Err(invalid_dotenv())
    }

    /// Whether the schema has a `bool` property read by the key `name`.
    fn is_bool(schema: Option<&Schema>, name: &[u8]) -> bool {
        let Some(schema) = schema else { return false };
        schema
            .properties()
            .iter()
            .find(|p| matches!(&p.field, Field::Key { name: key, .. } if key.as_bytes() == name))
            .is_some_and(|p| matches!(p.kind.kind, Kind::Bool))
    }
}

impl Parser for Dotenv {
    fn parse(&self, contents: &Value, schema: Option<&Schema>) -> Result<Array, Error> {
        let text = string(contents)?;
        let mut config = Array::default();
        if text.is_empty() || text == b"0" {
            return Ok(config);
        }
        for line in text.split(|&c| c == b'\n') {
            let line = trim(line);
            if line.is_empty() || line[0] == b'#' {
                continue;
            }
            let Some(eq) = line.iter().position(|&c| c == b'=') else {
                return Err(invalid_dotenv());
            };
            let mut name = trim(&line[..eq]);
            if name.starts_with(b"export") && name.len() > 6 && is_ctype_space(name[6]) {
                name = ltrim(&name[6..]);
            }
            let raw = Self::value(&line[eq + 1..])?;
            if name.is_empty() || name == b"0" {
                return Err(invalid_dotenv());
            }
            let mut value = Value::Str(Str::from(raw.clone()));
            if Self::is_bool(schema, name) {
                let lower = raw.to_ascii_lowercase();
                if TRUTHY.iter().any(|t| php_std::value::loose_str_eq(&lower, t)) {
                    value = Value::Bool(true);
                } else if FALSY.iter().any(|f| php_std::value::loose_str_eq(&lower, f)) {
                    value = Value::Bool(false);
                }
            }
            if matches!(&value, Value::Str(s) if s.as_bytes().eq_ignore_ascii_case(b"null")) {
                value = Value::Null;
            }
            config.set(ArrayKey::normalize(Str::copy_from(name)), value);
        }
        Ok(config)
    }
}
