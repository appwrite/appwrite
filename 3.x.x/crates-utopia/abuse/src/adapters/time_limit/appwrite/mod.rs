//! Appwrite `TablesDB` time-limit adapter (`TimeLimit\Appwrite\TablesDB`).

mod client;
mod tables_db;

pub use client::{unique_id, Client, Query};
pub use tables_db::{TablesDB, DATABASE_NAME, TABLE_ID, TABLE_LOCK, TABLE_NAME};
