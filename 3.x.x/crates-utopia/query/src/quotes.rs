//! PHP `Utopia\Query\QuotesIdentifiers`.

use crate::error::QueryError;

pub fn quote_identifier(wrap_char: char, identifier: &str) -> Result<String, QueryError> {
    if identifier == "*" {
        return Ok("*".to_owned());
    }
    if identifier.chars().any(|c| c.is_control()) {
        return Err(QueryError::validation(
            "Identifier contains control character",
        ));
    }
    if !identifier.contains('.') {
        return Ok(wrap_one(wrap_char, identifier));
    }
    let segments: Vec<&str> = identifier.split('.').collect();
    let last_index = segments.len() - 1;
    let mut wrapped = Vec::with_capacity(segments.len());
    for (index, segment) in segments.iter().enumerate() {
        if *segment == "*" && index == last_index {
            wrapped.push("*".to_owned());
            continue;
        }
        wrapped.push(wrap_one(wrap_char, segment));
    }
    Ok(wrapped.join("."))
}

pub fn quote_literal(wrap_char: char, identifier: &str) -> Result<String, QueryError> {
    if identifier == "*" {
        return Ok("*".to_owned());
    }
    if identifier.chars().any(|c| c.is_control()) {
        return Err(QueryError::validation(
            "Identifier contains control character",
        ));
    }
    Ok(wrap_one(wrap_char, identifier))
}

fn wrap_one(wrap_char: char, identifier: &str) -> String {
    let wrap = wrap_char.to_string();
    format!(
        "{wrap}{}{wrap}",
        identifier.replace(&wrap, &format!("{wrap}{wrap}"))
    )
}
