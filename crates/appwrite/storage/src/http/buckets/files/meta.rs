//! Chunk metadata stored on the file document between requests.

use indexmap::IndexMap;
use serde_json::{Map, Value as Json, json};
use utopia_database::Document;
use utopia_storage::{Metadata, Part};

use appwrite_core::database::documents::Attributes;

const UPLOADER: &str = "uploaderId";

/// What Appwrite keeps in the metadata beside the device's: the file key's
/// envelope (`encryption`) and the MD5 of each chunk's plaintext
/// (`digests`, by chunk number).
#[derive(Debug, Clone, Default)]
pub(super) struct Extra {
    pub(super) encryption: Option<Json>,
    pub(super) digests: std::collections::BTreeMap<i64, String>,
}

/// The [`Extra`] a file document holds.
pub(super) fn extra(document: &Document) -> Extra {
    let Some(json) = document.json("metadata") else {
        return Extra::default();
    };
    let encryption = json.get("encryption").filter(|e| !e.is_null()).cloned();
    let mut digests = std::collections::BTreeMap::new();
    match json.get("digests") {
        Some(Json::Object(map)) => {
            for (key, value) in map {
                if let (Ok(index), Some(digest)) = (key.parse::<i64>(), value.as_str()) {
                    digests.insert(index, digest.to_owned());
                }
            }
        }
        // A list is keyed from 0.
        Some(Json::Array(items)) => {
            for (index, value) in items.iter().enumerate() {
                if let Some(digest) = value.as_str() {
                    digests.insert(index as i64, digest.to_owned());
                }
            }
        }
        _ => {}
    }
    Extra { encryption, digests }
}

/// `array_merge` of the stored and the current extra: the current envelope
/// wins, and the digests are `$current + $stored` sorted by chunk.
pub(super) fn merge_extra(stored: &Extra, current: &Extra) -> Extra {
    let mut digests = stored.digests.clone();
    for (key, value) in &current.digests {
        digests.insert(*key, value.clone());
    }
    Extra { encryption: current.encryption.clone().or_else(|| stored.encryption.clone()), digests }
}

/// Metadata and the user who started the upload.
pub(super) fn load(document: &Document) -> (Metadata, String) {
    let Some(json) = document.json("metadata") else {
        return (Metadata::default(), String::new());
    };
    let uploader = json.get(UPLOADER).and_then(Json::as_str).unwrap_or("").to_owned();
    (from_json(&json), uploader)
}

/// `array_merge` of the stored metadata and the metadata this chunk produced.
/// Parts are unioned and sorted, and `chunks` becomes the number of parts.
pub(super) fn merge(stored: &Metadata, current: &Metadata, uploader: &str) -> (Metadata, String) {
    let mut merged = stored.clone();
    if current.content_type.is_some() {
        merged.content_type.clone_from(&current.content_type);
    }
    if current.upload_id.is_some() {
        merged.upload_id.clone_from(&current.upload_id);
    }
    if current.whole.is_some() {
        merged.whole = current.whole;
    }
    if stored.parts.is_some() || current.parts.is_some() {
        let mut parts = stored.parts.clone().unwrap_or_default();
        if let Some(current_parts) = &current.parts {
            for (key, part) in current_parts {
                parts.insert(*key, part.clone());
            }
        }
        let mut keys: Vec<i64> = parts.keys().copied().collect();
        keys.sort_unstable();
        let mut sorted = IndexMap::new();
        for key in keys {
            if let Some(part) = parts.swap_remove(&key) {
                sorted.insert(key, part);
            }
        }
        merged.chunks = Some(sorted.len() as i64);
        merged.parts = Some(sorted);
    }
    (merged, uploader.to_owned())
}

/// The document value of metadata, including the uploader.
pub(super) fn store(metadata: &Metadata, extra: &Extra, uploader: &str) -> utopia_database::Value {
    let mut map = Map::new();
    if let Some(encryption) = &extra.encryption {
        map.insert("encryption".to_owned(), encryption.clone());
    }
    if !extra.digests.is_empty() {
        let digests: Map<String, Json> = extra.digests.iter().map(|(k, v)| (k.to_string(), json!(v))).collect();
        map.insert("digests".to_owned(), Json::Object(digests));
    }
    if let Some(content_type) = &metadata.content_type {
        map.insert("content_type".to_owned(), json!(content_type));
    }
    if let Some(chunks) = metadata.chunks {
        map.insert("chunks".to_owned(), json!(chunks));
    }
    if let Some(upload_id) = &metadata.upload_id {
        map.insert("uploadId".to_owned(), json!(upload_id));
    }
    if let Some(whole) = metadata.whole {
        map.insert("whole".to_owned(), json!(whole));
    }
    if let Some(parts) = &metadata.parts {
        let mut encoded = Map::new();
        for (key, part) in parts {
            let value = match part {
                Part::Received => json!(1),
                Part::Etag(etag) => json!(etag),
            };
            encoded.insert(key.to_string(), value);
        }
        map.insert("parts".to_owned(), Json::Object(encoded));
    }
    map.insert(UPLOADER.to_owned(), json!(uploader));
    utopia_database::Value::from_json(&Json::Object(map))
}

pub(super) fn upload_id(document: &Document) -> String {
    let json = document.json("metadata");
    json.as_ref().and_then(|json| json.get("uploadId")).and_then(Json::as_str).unwrap_or("").to_owned()
}

fn from_json(json: &Json) -> Metadata {
    let mut metadata = Metadata::default();
    if let Some(content_type) = json.get("content_type").and_then(Json::as_str) {
        metadata.content_type = Some(content_type.to_owned());
    }
    if let Some(chunks) = json.get("chunks").and_then(Json::as_i64) {
        metadata.chunks = Some(chunks);
    }
    if let Some(upload_id) = json.get("uploadId").and_then(Json::as_str) {
        metadata.upload_id = Some(upload_id.to_owned());
    }
    if let Some(whole) = json.get("whole").and_then(Json::as_bool) {
        metadata.whole = Some(whole);
    }
    if let Some(parts) = json.get("parts").and_then(Json::as_object) {
        let mut map = IndexMap::new();
        for (key, value) in parts {
            let Ok(index) = key.parse::<i64>() else { continue };
            let part = match value.as_str() {
                Some(etag) => Part::Etag(etag.to_owned()),
                None => Part::Received,
            };
            map.insert(index, part);
        }
        metadata.parts = Some(map);
    }
    metadata
}
