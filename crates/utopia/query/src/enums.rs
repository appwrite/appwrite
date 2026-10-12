//! Small enumerations shared across the library.

use crate::method::backed_enum;

backed_enum! {
    /// What kind of statement a classifier found.
    ///
    /// PHP: `Utopia\Query\Type`.
    pub enum Type: "Utopia\\Query\\Type" {
        /// A statement that only reads.
        Read = "read",
        /// A statement that writes.
        Write = "write",
        /// `BEGIN` / `START TRANSACTION`.
        TransactionBegin = "transaction_begin",
        /// `COMMIT` / `ROLLBACK`.
        TransactionEnd = "transaction_end",
        /// Another transaction statement (savepoints).
        Transaction = "transaction",
        /// Not recognised.
        Unknown = "unknown",
    }
}

backed_enum! {
    /// Which side of a cursor row a page is on.
    ///
    /// PHP: `Utopia\Query\CursorDirection`.
    pub enum CursorDirection: "Utopia\\Query\\CursorDirection" {
        /// Rows after the cursor.
        After = "after",
        /// Rows before the cursor.
        Before = "before",
    }
}

backed_enum! {
    /// A sort direction.
    ///
    /// PHP: `Utopia\Query\OrderDirection`.
    pub enum OrderDirection: "Utopia\\Query\\OrderDirection" {
        /// `ASC`.
        Asc = "ASC",
        /// `DESC`.
        Desc = "DESC",
        /// Random order.
        Random = "RANDOM",
    }
}

backed_enum! {
    /// Where `NULL`s sort.
    ///
    /// PHP: `Utopia\Query\NullsPosition`.
    pub enum NullsPosition: "Utopia\\Query\\NullsPosition" {
        /// `NULLS FIRST`.
        First = "FIRST",
        /// `NULLS LAST`.
        Last = "LAST",
    }
}
