//! `/v1/storage/buckets/:bucketId/files`. One file per PHP action.

pub mod create;
pub mod delete;
pub mod download;
pub mod get;
pub mod preview;
pub mod push;
pub mod update;
pub mod view;
pub mod xlist;

mod meta;
mod send;
