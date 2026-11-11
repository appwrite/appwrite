//! Bucket and file response models (`Response::MODEL_BUCKET`, `MODEL_FILE`).

use serde::Serialize;
use serde::ser::{SerializeMap, Serializer};
use utopia_database::Document;

use appwrite_core::database::documents::Attributes;
use appwrite_core::response::dt;

/// `bucket`, with `totalSize` left at 0 the way the PHP get and list actions do.
pub struct BucketModel<'a> {
    pub document: &'a Document,
}

impl Serialize for BucketModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let doc = self.document;
        let mut map = serializer.serialize_map(Some(16))?;
        map.serialize_entry("$id", doc.id())?;
        map.serialize_entry("$createdAt", &dt(&doc.datetime("$createdAt")))?;
        map.serialize_entry("$updatedAt", &dt(&doc.datetime("$updatedAt")))?;
        map.serialize_entry("$permissions", &permissions(doc))?;
        map.serialize_entry("fileSecurity", &doc.boolean("fileSecurity").unwrap_or(false))?;
        map.serialize_entry("name", &doc.string_or_empty("name"))?;
        map.serialize_entry("description", &doc.string_or_empty("description"))?;
        map.serialize_entry("enabled", &doc.boolean("enabled").unwrap_or(true))?;
        map.serialize_entry("maximumFileSize", &long(doc, "maximumFileSize"))?;
        map.serialize_entry("allowedFileExtensions", &doc.strings("allowedFileExtensions"))?;
        map.serialize_entry("compression", &doc.string_or_empty("compression"))?;
        map.serialize_entry("encryption", &doc.boolean("encryption").unwrap_or(true))?;
        map.serialize_entry("antivirus", &doc.boolean("antivirus").unwrap_or(true))?;
        map.serialize_entry("transformations", &doc.boolean("transformations").unwrap_or(true))?;
        map.serialize_entry("stripMetadata", &doc.boolean("stripMetadata").unwrap_or(false))?;
        map.serialize_entry("totalSize", &0_i64)?;
        map.end()
    }
}

/// `{total, buckets}`.
pub struct BucketList<'a> {
    pub total: i64,
    pub buckets: &'a [Document],
}

impl Serialize for BucketList<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut map = serializer.serialize_map(Some(2))?;
        map.serialize_entry("total", &self.total)?;
        let items: Vec<BucketModel<'_>> = self.buckets.iter().map(|document| BucketModel { document }).collect();
        map.serialize_entry("buckets", &items)?;
        map.end()
    }
}

/// `file`. `compression`, `encryption` and `key` are the PHP model's filter.
pub struct FileModel<'a> {
    pub document: &'a Document,
}

impl Serialize for FileModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let doc = self.document;
        let algorithm = doc.string_or_empty("algorithm");
        let compression = if algorithm.is_empty() { "none".to_owned() } else { algorithm };
        let folder = doc.string_or_empty("folder");
        let name = doc.string_or_empty("name");
        let mut map = serializer.serialize_map(Some(16))?;
        map.serialize_entry("$id", doc.id())?;
        map.serialize_entry("bucketId", &doc.string_or_empty("bucketId"))?;
        map.serialize_entry("$createdAt", &dt(&doc.datetime("$createdAt")))?;
        map.serialize_entry("$updatedAt", &dt(&doc.datetime("$updatedAt")))?;
        map.serialize_entry("$permissions", &permissions(doc))?;
        map.serialize_entry("name", &name)?;
        map.serialize_entry("folder", &folder)?;
        map.serialize_entry("key", &format!("{folder}{name}"))?;
        map.serialize_entry("signature", &doc.string_or_empty("signature"))?;
        map.serialize_entry("mimeType", &doc.string_or_empty("mimeType"))?;
        map.serialize_entry("sizeOriginal", &long(doc, "sizeOriginal"))?;
        map.serialize_entry("sizeActual", &long(doc, "sizeActual"))?;
        map.serialize_entry("chunksTotal", &long(doc, "chunksTotal"))?;
        map.serialize_entry("chunksUploaded", &long(doc, "chunksUploaded"))?;
        map.serialize_entry("encryption", &!doc.string_or_empty("openSSLCipher").is_empty())?;
        map.serialize_entry("compression", &compression)?;
        map.end()
    }
}

/// `{total, files}`.
pub struct FileList<'a> {
    pub total: i64,
    pub files: &'a [Document],
}

impl Serialize for FileList<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut map = serializer.serialize_map(Some(2))?;
        map.serialize_entry("total", &self.total)?;
        let items: Vec<FileModel<'_>> = self.files.iter().map(|document| FileModel { document }).collect();
        map.serialize_entry("files", &items)?;
        map.end()
    }
}

fn permissions(document: &Document) -> Vec<String> {
    document.permissions().unwrap_or_else(|_| document.strings("$permissions"))
}

fn long(document: &Document, key: &str) -> i64 {
    document.get(key).map(utopia_database::value::to_long).unwrap_or(0)
}
