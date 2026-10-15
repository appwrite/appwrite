//! Emulated prepares: the placeholders of a statement replaced with quoted
//! literals, as PDO renders a statement for a driver without native
//! placeholders (PostgreSQL and MySQL with `ATTR_EMULATE_PREPARES`).
//!
//! PHP: `pdo_parse_params()` (`ext/pdo/pdo_sql_parser.re`).

use super::{Binding, Param, ParamType, PdoError};
use crate::error::{Error, Result};
use crate::value::Value;

/// What a placeholder found by a driver's scanner is.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum Placeholder {
    /// `:name`.
    Named,
    /// `?`.
    Positional,
    /// `??` (PostgreSQL): an escaped `?`, rendered as `?` when the
    /// statement is rewritten.
    Escaped,
}

/// A placeholder: its byte range in the SQL and its kind.
#[derive(Clone, Copy, Debug)]
pub(crate) struct Found {
    pub(crate) start: usize,
    pub(crate) end: usize,
    pub(crate) kind: Placeholder,
}

/// The statement as PDO sends it: `Ok(None)` when a value cannot be quoted
/// (`execute()` then returns `false`), the HY093 errors of
/// `pdo_parse_params()` otherwise.
///
/// The order of PDO's checks is kept: mixed placeholder kinds fail even
/// without bound values; without bound values the statement goes out as it
/// is; otherwise the number of bound values must match the placeholders
/// (named placeholders may repeat), and each placeholder takes its value
/// (`parameter was not defined` when none is bound to it).
pub(crate) fn render(
    sql: &[u8],
    found: &[Found],
    bindings: &[Binding],
    quote: impl Fn(&Value, ParamType) -> Result<Option<Vec<u8>>>,
) -> Result<Option<Vec<u8>>> {
    let named = found.iter().any(|p| p.kind == Placeholder::Named);
    let positional = found.iter().any(|p| p.kind == Placeholder::Positional);
    if named && positional {
        return Err(PdoError::implementation("HY093", "mixed named and positional parameters").into_error());
    }
    if bindings.is_empty() {
        return Ok(Some(sql.to_vec()));
    }
    let bindno = found.iter().filter(|p| p.kind != Placeholder::Escaped).count();
    if bindno != bindings.len() {
        let ok = !positional
            && bindno > bindings.len()
            && found
                .iter()
                .filter(|p| p.kind == Placeholder::Named)
                .all(|p| named_binding(bindings, &sql[p.start..p.end]).is_some());
        if !ok {
            return Err(PdoError::implementation("HY093", "number of bound variables does not match number of tokens")
                .into_error());
        }
    }
    if found.is_empty() {
        return Ok(Some(sql.to_vec()));
    }
    let mut out = Vec::with_capacity(sql.len() + 32);
    let mut pos = 0;
    let mut bindno = 0;
    for p in found {
        out.extend_from_slice(&sql[pos..p.start]);
        pos = p.end;
        let binding = match p.kind {
            Placeholder::Escaped => {
                out.push(b'?');
                continue;
            }
            Placeholder::Named => named_binding(bindings, &sql[p.start..p.end]),
            Placeholder::Positional => {
                bindno += 1;
                bindings.iter().find(|b| b.param == Param::Position(bindno))
            }
        };
        let Some(binding) = binding else {
            return Err(PdoError::implementation("HY093", "parameter was not defined").into_error());
        };
        match quote_binding(&binding.value, binding.ty, &quote)? {
            Some(literal) => out.extend_from_slice(&literal),
            None => return Ok(None),
        }
    }
    out.extend_from_slice(&sql[pos..]);
    Ok(Some(out))
}

fn named_binding<'a>(bindings: &'a [Binding], name: &[u8]) -> Option<&'a Binding> {
    bindings.iter().find(|b| matches!(&b.param, Param::Name(n) if n.as_bytes() == name))
}

/// The literal a bound value becomes: `NULL` for `null`, then by type.
fn quote_binding(
    value: &Value,
    ty: ParamType,
    quote: &impl Fn(&Value, ParamType) -> Result<Option<Vec<u8>>>,
) -> Result<Option<Vec<u8>>> {
    if matches!(value, Value::Null) {
        return Ok(Some(b"NULL".to_vec()));
    }
    match ty {
        ParamType::Null => Ok(Some(b"NULL".to_vec())),
        ParamType::Bool => Ok(Some(if value.truthy() { b"1".to_vec() } else { b"0".to_vec() })),
        ParamType::Int => Ok(Some(crate::value::to_long(value).to_string().into_bytes())),
        ParamType::Str => quote(value, ty),
    }
}

/// A value's PHP string (`zval_try_get_string`).
pub(crate) fn php_string(value: &Value) -> Result<Vec<u8>> {
    Ok(value.to_php_string().map_err(Error::from)?.as_bytes().to_vec())
}

/// The end (exclusive) of the C comment opening `s`: the first `*/` after
/// the opener.
pub(crate) fn comment_end(s: &[u8]) -> Option<usize> {
    let mut i = 2;
    while i + 1 < s.len() {
        if s[i] == b'*' && s[i + 1] == b'/' {
            return Some(i + 2);
        }
        i += 1;
    }
    None
}
