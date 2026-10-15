//! Documents: the document cache, reads, finds and writes.
//!
//! PHP: `Trait\Documents` and `Trait\Cursors`.
//!
//! | File | PHP |
//! |---|---|
//! | `cache.rs` | the document cache: keys' epochs, purges, `withCache()`, the cached reads' helpers |
//! | `read.rs` | `getDocument()`, `readDocument()`, `refetchDocuments()`, `applySelectFiltersToDocuments()`, the query validators |
//! | `find.rs` | `find()`, `findOne()`, `count()`, `sum()`, `aggregate()`, `cursor()`, joins |
//! | `cursors.rs` | `resolveCursor()` (`Trait\Cursors`) |
//! | `create.rs` | `createDocument()`, `createDocuments()`, `prepareCreate()`, `createPrepared()`, `authorize*()` |
//! | `update.rs` | `updateDocument()`, `updateDocuments()` |
//! | `upsert.rs` | `upsertDocument()`, `upsertDocuments()` |
//! | `numeric.rs` | `increaseDocumentAttribute()`, `decreaseDocumentAttribute()` |
//! | `delete.rs` | `deleteDocument()`, `deleteDocuments()` |

mod cache;
mod create;
mod cursors;
mod delete;
mod find;
mod numeric;
mod read;
mod update;
mod upsert;

#[allow(unused_imports)]
pub(crate) use cache::{CachedDefinition, Validity};
