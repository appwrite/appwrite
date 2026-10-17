//! [`Page`]: the page of each parent's related documents a relationship
//! query asks for.
//!
//! PHP: `Utopia\Database\Relationship\Page`.

use std::ops::Range;

use utopia_query::{Method, Value as QValue};

use crate::document::Document;
use crate::query::Query;

/// The page of each parent's related documents that a relationship
/// query's limit, offset and cursor ask for.
///
/// Related documents are read for every parent at once, so the page is cut
/// from each parent's documents after they are grouped, not by the read. A
/// cursor is a related document's id, and a parent that does not hold it
/// has an empty page.
///
/// ```
/// use utopia_database::{Document, Query};
/// use utopia_database::relationship::page::Page;
///
/// let (page, rest) = Page::from(vec![Query::limit(2), Query::equal("approved", [true]), Query::offset(1)]);
/// let page = page.expect("a page");
/// assert_eq!((page.limit, page.offset), (Some(2), 1));
/// assert_eq!(rest.len(), 1);
///
/// let documents: Vec<Document> = ["a", "b", "c", "d"]
///     .iter()
///     .map(|id| Document::from_pairs([("$id", utopia_database::Value::from(*id))]).unwrap())
///     .collect();
/// let ids: Vec<String> = page.slice(&documents).iter().map(|d| d.id().to_owned()).collect();
/// assert_eq!(ids, ["b", "c"]);
/// ```
///
/// PHP: `Utopia\Database\Relationship\Page`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Page {
    /// The most documents of each parent, or every one.
    pub limit: Option<i64>,
    /// The documents of each parent skipped first (after the cursor).
    pub offset: i64,
    /// The id of the related document the page starts after (or ends
    /// before).
    pub cursor: Option<String>,
    /// Whether the page ends before the cursor rather than starting after
    /// it.
    pub before: bool,
}

impl Page {
    /// The pagination queries split from the rest, as the page they ask
    /// for, or `None` when they ask for none (`from()`). The first of each
    /// pagination query counts.
    pub fn from(queries: Vec<Query>) -> (Option<Page>, Vec<Query>) {
        let mut limit = None;
        let mut offset = None;
        let mut cursor = None;
        let mut before = false;
        let mut paginated = false;
        let mut rest = Vec::with_capacity(queries.len());

        for query in queries {
            let method = query.method();
            match method {
                Method::Limit => {
                    if limit.is_none() {
                        limit = integer(query.value());
                    }
                }
                Method::Offset => {
                    if offset.is_none() {
                        offset = integer(query.value());
                    }
                }
                Method::CursorAfter | Method::CursorBefore => {
                    if cursor.is_none() {
                        cursor = match (query.document_value(), query.value()) {
                            (Some(document), _) => Some(document.id().to_owned()),
                            (None, Some(QValue::Str(id))) => Some(String::from_utf8_lossy(id.as_bytes()).into_owned()),
                            _ => None,
                        };
                        before = method == Method::CursorBefore;
                    }
                }
                _ => {
                    rest.push(query);
                    continue;
                }
            }
            paginated = true;
        }

        let page = paginated.then(|| Page { limit, offset: offset.unwrap_or(0), cursor, before });
        (page, rest)
    }

    /// One parent's related documents, in their order, cut to the page
    /// (`slice()`).
    pub fn slice(&self, documents: &[Document]) -> Vec<Document> {
        let ids: Vec<&str> = documents.iter().map(Document::id).collect();
        documents[self.window(&ids)].to_vec()
    }

    /// The positions [`Page::slice`] keeps of documents with these ids.
    pub(crate) fn window(&self, ids: &[&str]) -> Range<usize> {
        let Some(cursor) = &self.cursor else {
            return array_slice(ids.len(), self.offset, self.limit);
        };
        let Some(position) = ids.iter().position(|id| id == cursor) else {
            return 0..0;
        };
        if self.before {
            // array_reverse(array_slice(array_reverse(preceding), offset, limit)).
            let kept = array_slice(position, self.offset, self.limit);
            return position - kept.end..position - kept.start;
        }
        let start = (position as i64).saturating_add(1).saturating_add(self.offset);
        array_slice(ids.len(), start, self.limit)
    }
}

/// `is_numeric($value) ? (int) $value : null`.
fn integer(value: Option<&QValue>) -> Option<i64> {
    let value = value?;
    match value {
        QValue::Int(i) => Some(*i),
        QValue::Float(f) => Some(php_std::format::dval_to_lval(*f)),
        QValue::Str(s) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
            Some(php_std::format::Arg::Str(s.as_bytes()).to_int())
        }
        _ => None,
    }
}

/// The positions `array_slice()` keeps of a list of `len` items.
fn array_slice(len: usize, offset: i64, length: Option<i64>) -> Range<usize> {
    let len = len as i64;
    if offset > len {
        return 0..0;
    }
    let start = if offset < 0 { (len + offset).max(0) } else { offset };
    let end = match length {
        None => len,
        Some(length) if length < 0 => (len + length).max(start),
        Some(length) => start.saturating_add(length).min(len),
    };
    start as usize..end.max(start) as usize
}

#[cfg(test)]
mod tests {
    use super::array_slice;

    // PHP: array_slice([0, 1, 2, 3], ...).
    #[test]
    fn slices_as_php_does() {
        assert_eq!(array_slice(4, 1, Some(2)), 1..3);
        assert_eq!(array_slice(4, 5, Some(2)), 0..0);
        assert_eq!(array_slice(4, 4, None), 4..4);
        assert_eq!(array_slice(4, -1, None), 3..4);
        assert_eq!(array_slice(4, 1, Some(-1)), 1..3);
        assert_eq!(array_slice(4, 0, Some(0)), 0..0);
    }
}
