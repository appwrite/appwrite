//! The Storage service (`src/Appwrite/Platform/Modules/Storage`).
//!
//! Every route keeps the PHP contract: path, parameters and validators (in
//! declaration order), scopes, events, audits, abuse limits and the preview
//! cache labels.

mod base;
mod device;
mod filename;
mod folder;
mod http;
mod model;
mod scan;

use appwrite_core::action;
use appwrite_core::platform::{Audit, Labels, Module, Route};
use utopia_http::Method;

/// The Storage module.
pub struct Storage;

const GROUPS: &[&str] = &["api", "storage"];
const BUCKET_READ: &[&str] = &["buckets.read"];
const BUCKET_WRITE: &[&str] = &["buckets.write"];
const FILE_READ: &[&str] = &["files.read"];
const FILE_WRITE: &[&str] = &["files.write"];

/// `APP_LIMIT_WRITE_RATE_DEFAULT` requests per `APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT`.
const FILE_CREATE: Labels = Labels {
    abuse_key: &["ip:{ip},method:{method},url:{url},userId:{userId},chunkId:{chunkId}"],
    abuse_limit: 60,
    abuse_time: 60,
    ..Labels::NONE
};

const FILE_MUTATE: Labels = Labels {
    abuse_key: &["ip:{ip},method:{method},url:{url},userId:{userId}"],
    abuse_limit: 60,
    abuse_time: 60,
    ..Labels::NONE
};

/// Preview responses are cached. The action's `Cache-Control` is 30 days;
/// a hit is served by the context hook with its own max-age.
const PREVIEW: Labels = Labels {
    cache: true,
    cache_resource: "file/{request.fileId}",
    cache_resource_type: "bucket/{request.bucketId}",
    cache_params: Some(&[
        "width",
        "height",
        "gravity",
        "quality",
        "borderWidth",
        "borderColor",
        "borderRadius",
        "opacity",
        "rotation",
        "background",
        "output",
        "project",
    ]),
    ..Labels::NONE
};

const fn audit(event: &'static str, resource: &'static str) -> Option<Audit> {
    Some(Audit { event, resource })
}

macro_rules! route {
    ($method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $labels:expr, $action:path) => {
        Route {
            method: Method::$method,
            path: $path,
            name: $name,
            namespace: "storage",
            sdk_method: $sdk,
            scopes: $scopes,
            event: $event,
            audit: $audit,
            groups: GROUPS,
            labels: $labels,
            action: action!($action),
        }
    };
}

impl Module for Storage {
    fn name(&self) -> &'static str {
        "storage"
    }

    fn routes(&self) -> Vec<Route> {
        use http::buckets::{self, files};
        vec![
            route!(
                POST,
                "/v1/storage/buckets",
                "createBucket",
                "createBucket",
                BUCKET_WRITE,
                Some("buckets.[bucketId].create"),
                audit("bucket.create", "bucket/{response.$id}"),
                Labels::NONE,
                buckets::create::create
            ),
            route!(
                GET,
                "/v1/storage/buckets",
                "listBuckets",
                "listBuckets",
                BUCKET_READ,
                None,
                None,
                Labels::NONE,
                buckets::xlist::xlist
            ),
            route!(
                GET,
                "/v1/storage/buckets/:bucketId",
                "getBucket",
                "getBucket",
                BUCKET_READ,
                None,
                None,
                Labels::NONE,
                buckets::get::get
            ),
            route!(
                PUT,
                "/v1/storage/buckets/:bucketId",
                "updateBucket",
                "updateBucket",
                BUCKET_WRITE,
                Some("buckets.[bucketId].update"),
                audit("bucket.update", "bucket/{response.$id}"),
                Labels::NONE,
                buckets::update::update
            ),
            route!(
                DELETE,
                "/v1/storage/buckets/:bucketId",
                "deleteBucket",
                "deleteBucket",
                BUCKET_WRITE,
                Some("buckets.[bucketId].delete"),
                audit("bucket.delete", "bucket/{request.bucketId}"),
                Labels::NONE,
                buckets::delete::delete
            ),
            route!(
                POST,
                "/v1/storage/buckets/:bucketId/files",
                "createFile",
                "createFile",
                FILE_WRITE,
                Some("buckets.[bucketId].files.[fileId].create"),
                audit("file.create", "file/{response.$id}"),
                FILE_CREATE,
                files::create::create
            ),
            route!(
                GET,
                "/v1/storage/buckets/:bucketId/files",
                "listFiles",
                "listFiles",
                FILE_READ,
                None,
                None,
                Labels::NONE,
                files::xlist::xlist
            ),
            route!(
                GET,
                "/v1/storage/buckets/:bucketId/files/:fileId",
                "getFile",
                "getFile",
                FILE_READ,
                None,
                None,
                Labels::NONE,
                files::get::get
            ),
            route!(
                PUT,
                "/v1/storage/buckets/:bucketId/files/:fileId",
                "updateFile",
                "updateFile",
                FILE_WRITE,
                Some("buckets.[bucketId].files.[fileId].update"),
                audit("file.update", "file/{response.$id}"),
                FILE_MUTATE,
                files::update::update
            ),
            route!(
                DELETE,
                "/v1/storage/buckets/:bucketId/files/:fileId",
                "deleteFile",
                "deleteFile",
                FILE_WRITE,
                Some("buckets.[bucketId].files.[fileId].delete"),
                audit("file.delete", "file/{request.fileId}"),
                FILE_MUTATE,
                files::delete::delete
            ),
            route!(
                GET,
                "/v1/storage/buckets/:bucketId/files/:fileId/preview",
                "getFilePreview",
                "getFilePreview",
                FILE_READ,
                None,
                None,
                PREVIEW,
                files::preview::get::get
            ),
            route!(
                GET,
                "/v1/storage/buckets/:bucketId/files/:fileId/download",
                "getFileDownload",
                "getFileDownload",
                FILE_READ,
                None,
                None,
                Labels::NONE,
                files::download::get::get
            ),
            route!(
                GET,
                "/v1/storage/buckets/:bucketId/files/:fileId/view",
                "getFileView",
                "getFileView",
                FILE_READ,
                None,
                None,
                Labels::NONE,
                files::view::get::get
            ),
            route!(
                GET,
                "/v1/storage/buckets/:bucketId/files/:fileId/push",
                "getFileForPush",
                "getFileForPush",
                &["public"],
                None,
                None,
                Labels::NONE,
                files::push::get::get
            ),
        ]
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn routes_keep_the_php_contract() {
        let routes = Storage.routes();
        assert_eq!(routes.len(), 14);
        let create = routes.iter().find(|r| r.name == "createFile").unwrap();
        assert_eq!(create.labels.abuse_limit, 60);
        assert_eq!(create.labels.abuse_time, 60);
        assert!(create.labels.abuse_key[0].contains("chunkId:{chunkId}"));
        let preview = routes.iter().find(|r| r.name == "getFilePreview").unwrap();
        assert!(preview.labels.cache);
        assert_eq!(preview.labels.cache_resource, "file/{request.fileId}");
        let push = routes.iter().find(|r| r.name == "getFileForPush").unwrap();
        assert_eq!(push.scopes, &["public"]);
    }
}
