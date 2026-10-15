//! `resolveCursor()`.
//!
//! PHP: `Trait\Cursors`.

use utopia_query::CursorDirection;

use super::super::Database;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;

impl Database {
    /// The cursor document a page `direction` of the document `id` reads
    /// from, with the joined values `queries`' orders need
    /// (`resolveCursor()`).
    pub async fn resolve_cursor(
        &self,
        collection: &str,
        id: &str,
        direction: CursorDirection,
        queries: Vec<Query>,
    ) -> Result<Document> {
        let _ = (collection, id, direction, queries);
        todo!("reads area: resolveCursor")
    }
}
