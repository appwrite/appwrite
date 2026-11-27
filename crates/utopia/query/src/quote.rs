//! Identifier quoting: `` `users`.`id` `` for MySQL, `"users"."id"` for PostgreSQL.

use crate::error::{Error, Result};

fn reject_control(identifier: &str) -> Result<()> {
    if identifier.bytes().any(|b| b < 0x20 || b == 0x7f) {
        return Err(Error::Validation("Identifier contains control character".into()));
    }
    Ok(())
}

fn push_wrapped(out: &mut String, segment: &str, wrap: char) {
    out.push(wrap);
    for c in segment.chars() {
        if c == wrap {
            out.push(wrap);
        }
        out.push(c);
    }
    out.push(wrap);
}

/// Quotes a possibly qualified identifier with `wrap`: each dot-separated
/// segment is wrapped (the wrap character doubled inside), a final `*`
/// stays bare, and `*` alone is returned as is. Control characters are
/// refused.
///
/// PHP: `QuotesIdentifiers::quote()` (with `$wrapChar`).
///
/// ```
/// use utopia_query::quote;
///
/// assert_eq!(quote("schema.users", '`')?, "`schema`.`users`");
/// assert_eq!(quote("users.*", '"')?, "\"users\".*");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\MySQL;
///
/// echo (new MySQL())->from('schema.users')->build()->query, "\n"; // SELECT * FROM `schema`.`users`
/// ```
pub fn quote(identifier: &str, wrap: char) -> Result<String> {
    if identifier == "*" {
        return Ok("*".into());
    }
    reject_control(identifier)?;
    let mut out = String::with_capacity(identifier.len() + 4);
    if !identifier.contains('.') {
        push_wrapped(&mut out, identifier, wrap);
        return Ok(out);
    }
    let count = identifier.split('.').count();
    for (index, segment) in identifier.split('.').enumerate() {
        if index > 0 {
            out.push('.');
        }
        if segment == "*" && index == count - 1 {
            out.push('*');
        } else {
            push_wrapped(&mut out, segment, wrap);
        }
    }
    Ok(out)
}

/// Quotes one identifier without treating dots as qualifiers (a column named
/// `meta.key`, as ClickHouse nested columns are).
///
/// PHP: `QuotesIdentifiers::quoteLiteral()`.
///
/// ```
/// use utopia_query::quote_literal;
///
/// assert_eq!(quote_literal("meta.key", '`')?, "`meta.key`");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Schema\ClickHouse;
///
/// echo (new ClickHouse())->table('t')->string('meta.key')->create()->query, "\n";
/// ```
pub fn quote_literal(identifier: &str, wrap: char) -> Result<String> {
    if identifier == "*" {
        return Ok("*".into());
    }
    reject_control(identifier)?;
    let mut out = String::with_capacity(identifier.len() + 2);
    push_wrapped(&mut out, identifier, wrap);
    Ok(out)
}
