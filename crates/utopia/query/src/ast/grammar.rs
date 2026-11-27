//! The lexical rules for names the serializer writes verbatim.

/// Whitespace a cast type may hold between its words: `" \t\n\v\f\r"`.
const WHITESPACE: &[u8] = b" \t\n\x0B\x0C\r";

/// The lexical rules for function names and cast types, which the
/// [`Serializer`](super::Serializer) writes verbatim, so a quoted identifier
/// cannot carry SQL into them.
///
/// PHP: `Utopia\Query\AST\Grammar`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Grammar;

impl Grammar {
    /// Whether `value` is a bare identifier: ASCII letters, digits and `_`,
    /// not starting with a digit.
    ///
    /// PHP: `Grammar::isIdentifier()`.
    ///
    /// ```
    /// use utopia_query::ast::Grammar;
    ///
    /// assert!(Grammar::is_identifier("LOWER"));
    /// assert!(!Grammar::is_identifier("NOW() OR 1=1"));
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\AST\Grammar;
    ///
    /// var_dump(Grammar::isIdentifier('LOWER'));        // bool(true)
    /// var_dump(Grammar::isIdentifier('NOW() OR 1=1')); // bool(false)
    /// ```
    pub fn is_identifier(value: &str) -> bool {
        let bytes = value.as_bytes();
        !bytes.is_empty() && !bytes[0].is_ascii_digit() && bytes.iter().all(|&b| is_identifier_byte(b))
    }

    /// Whether `value` is a type name: whitespace-separated identifiers,
    /// optionally followed by parenthesised arguments of identifiers, commas
    /// and whitespace (`DECIMAL(10, 2)`, `TIMESTAMP WITH TIME ZONE`).
    ///
    /// PHP: `Grammar::isType()`.
    ///
    /// ```
    /// use utopia_query::ast::Grammar;
    ///
    /// assert!(Grammar::is_type("DECIMAL(10, 2)"));
    /// assert!(!Grammar::is_type("TEXT) OR (1=1"));
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\AST\Grammar;
    ///
    /// var_dump(Grammar::isType('DECIMAL(10, 2)')); // bool(true)
    /// var_dump(Grammar::isType('TEXT) OR (1=1'));  // bool(false)
    /// ```
    pub fn is_type(value: &str) -> bool {
        let bytes = value.as_bytes();
        let Some(open) = bytes.iter().position(|&b| b == b'(') else {
            return is_words(bytes);
        };
        if bytes.last() != Some(&b')') {
            return false;
        }
        let arguments = &bytes[open + 1..bytes.len() - 1];
        let mut head = &bytes[..open];
        while let Some((last, rest)) = head.split_last() {
            if !WHITESPACE.contains(last) {
                break;
            }
            head = rest;
        }
        !arguments.is_empty()
            && arguments.iter().all(|&b| is_identifier_byte(b) || b == b',' || WHITESPACE.contains(&b))
            && is_words(head)
    }
}

fn is_identifier_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

/// PHP's `Grammar::isWords()`: identifiers separated by whitespace, with none
/// leading or trailing.
fn is_words(value: &[u8]) -> bool {
    let mut words = value.split(|b| WHITESPACE.contains(b));
    let first = words.next().unwrap_or_default();
    if first.is_empty() || value.last().is_none_or(|b| WHITESPACE.contains(b)) {
        return false;
    }
    std::iter::once(first)
        .chain(words)
        .all(|word| word.is_empty() || (!word[0].is_ascii_digit() && word.iter().all(|&b| is_identifier_byte(b))))
}
