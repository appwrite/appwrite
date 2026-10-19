//! File actions, including the chunked upload.

use serde_json::{Value as Json, json};
use utopia_compression::{Algorithm, Gzip, Zstd};
use utopia_database::validator::permissions::Permissions;
use utopia_database::validator::query::cursor::Cursor as CursorValidator;
use utopia_database::{Database, Document, Kind, PermissionType, Query, Value};
use utopia_http::{Response, StatusCode};
use utopia_lock::{Distributed, Lock, Wait};
use utopia_query::Method;
use utopia_storage::Metadata;
use utopia_storage::validator::{FileExt, FileSize, Upload};
use utopia_validators::{Text, Validator};

use appwrite_core::database::LIMIT_COUNT;
use appwrite_core::database::collections;
use appwrite_core::database::documents::{self, Attributes};
use appwrite_core::platform::Context;
use appwrite_core::validators::{CustomId, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{
    self, READ_BUFFER, aggregate, allows, assert_assignable, bucket_collection, default_file_permissions,
    file_permissions, id_or_unique, list_value, long, permission_list, reset_events, unauthorized, user_id,
};
use crate::device::{self, Stored};
use crate::model::{FileList, FileModel};

use super::meta;

/// `POST /v1/storage/buckets/:bucketId/files`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let mut file_id = id_or_unique(ctx.required_str("fileId", &CustomId)?);
    let permissions = file_permissions_for_create(ctx)?;
    let folder =
        crate::folder::Folder::normalize(&ctx.optional_str("folder", "", &crate::folder::Folder)?.unwrap_or_default());
    let db = ctx.db()?;
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    if !allows(ctx, &bucket, PermissionType::Create)? {
        return Err(unauthorized(ctx));
    }

    let upload = incoming(ctx)?;
    let header = ctx.request.header_line("content-range").unwrap_or_default();
    let ranged = !header.is_empty() && header != "0";
    let (chunk, mut chunks, file_size) = if ranged {
        let Some(range) = ctx.request.content_range() else {
            return Err(Error::new(ErrorType::StorageInvalidContentRange));
        };
        let end = range.end.unwrap_or(0);
        let total = range.size.unwrap_or(0);
        if end > total {
            return Err(Error::new(ErrorType::StorageInvalidContentRange));
        }
        let header_id = ctx.request.header_line("x-appwrite-id").unwrap_or_default();
        if !header_id.is_empty() {
            file_id = header_id;
        }
        if !uid().is_valid(&Value::from(file_id.as_str())) {
            return Err(Error::new(ErrorType::StorageInvalidAppwriteId));
        }
        let (chunk, chunks) = device::chunk_index(range.start, total);
        (chunk, chunks, total)
    } else {
        (1, 1, upload.size)
    };

    let allowed = bucket.strings("allowedFileExtensions");
    if !allowed.is_empty() && !FileExt::new(allowed).is_valid(&Json::String(upload.name.clone())) {
        return Err(Error::with_message(ErrorType::StorageFileTypeUnsupported, "File extension not allowed"));
    }
    let maximum = long(&bucket, "maximumFileSize");
    if maximum > appwrite_core::config::env_int("_APP_STORAGE_LIMIT", 0) {
        return Err(Error::with_message(
            ErrorType::GeneralServerError,
            "Maximum bucket file size is larger than _APP_STORAGE_LIMIT",
        ));
    }
    if !FileSize::new(maximum).is_valid(&json!(file_size)) {
        return Err(Error::with_message(ErrorType::StorageInvalidFileSize, "File size not allowed"));
    }
    if !Upload::new([upload.tmp.clone()]).is_valid(&Json::String(upload.tmp.clone())) {
        return Err(Error::new(ErrorType::StorageInvalidFile));
    }

    let local = Stored::local_root();
    let mime = local.file_mime_type(&upload.tmp).await?;
    let bytes = std::fs::read(&upload.tmp)
        .map_err(|err| Error::with_message(ErrorType::StorageInvalidFile, err.to_string()))?;
    let device = Stored::for_project(&ctx.project.id)?;
    let path = device.file_path(bucket.id(), &file_id, &upload.name);
    let lock_key = format!("storage:file:{}:{}:{file_id}", ctx.project.id, bucket.id());
    let lock = Distributed::new(ctx.state.abuse.clone(), lock_key, 600);
    let acquired = lock
        .acquire(Wait::from_secs_f64(120.0))
        .await
        .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))?;
    if !acquired {
        return Err(busy(ctx));
    }
    let result = transfer(
        ctx,
        &db,
        &device,
        &bucket,
        &file_id,
        &upload.name,
        &folder,
        &permissions,
        &path,
        chunk,
        &mut chunks,
        file_size,
        ranged,
        bytes,
        mime,
        &lock,
    )
    .await;
    let _ = lock.release().await;
    result
}

/// `GET /v1/storage/buckets/:bucketId/files`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let mut queries = ctx.queries(&collections::FILES)?;
    let search = ctx.optional_str("search", "", &Text::with_min(256, 0))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;
    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    let db = ctx.db()?;
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let readable = allows(ctx, &bucket, PermissionType::Read)?;
    if !secured && !readable {
        return Err(unauthorized(ctx));
    }
    let collection = bucket_collection(&bucket);
    resolve_file_cursor(&db, &collection, &mut queries, secured && !readable).await?;
    let filters = Query::group_by_type(&queries)?.filters;
    let (files, total) = if secured && !readable {
        let files = db.find(&collection, queries, PermissionType::Read).await.map_err(base::map_db)?;
        let total = if include_total {
            db.count(&collection, filters, Some(LIMIT_COUNT)).await.map_err(base::map_db)?
        } else {
            0
        };
        (files, total)
    } else {
        let auth = db.authorization();
        let files = auth.skip(db.find(&collection, queries, PermissionType::Read)).await.map_err(base::map_db)?;
        let total = if include_total {
            auth.skip(db.count(&collection, filters, Some(LIMIT_COUNT))).await.map_err(base::map_db)?
        } else {
            0
        };
        (files, total)
    };
    Ok(ctx.json(StatusCode::OK, &FileList { total, files: &files }))
}

/// `GET /v1/storage/buckets/:bucketId/files/:fileId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let db = ctx.db()?;
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    let file = readable_file(ctx, &db, &bucket, &file_id, PermissionType::Read).await?;
    Ok(ctx.json(StatusCode::OK, &FileModel { document: &file }))
}

/// `PUT /v1/storage/buckets/:bucketId/files/:fileId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let name = match ctx.param("name") {
        None => None,
        Some(value) if value.is_null() => None,
        Some(_) => Some(ctx.required_str("name", &Text::new(128))?),
    };
    let raw = ctx.param("permissions").cloned().unwrap_or(Json::Null);
    let db = ctx.db()?;
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let permitted = allows(ctx, &bucket, PermissionType::Update)?;
    if !secured && !permitted {
        return Err(unauthorized(ctx));
    }
    let collection = bucket_collection(&bucket);
    let file = db
        .authorization()
        .skip(db.get_document(&collection, &file_id, Vec::new(), false))
        .await
        .map_err(base::map_db)?;
    if file.is_empty() {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    let permissions = match permission_list(&raw)? {
        None => file.permissions().map_err(Error::from)?,
        Some(items) => {
            let permissions = aggregate(&items, file_permissions())?;
            assert_assignable(ctx, &permissions)?;
            permissions
        }
    };
    let mut updates = Document::default();
    updates.put("$permissions", list_value(&permissions));
    if let Some(name) = &name {
        updates.put("name", Value::from(name.as_str()));
    }
    let file = if secured && !permitted {
        db.update_document(&collection, &file_id, updates).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.update_document(&collection, &file_id, updates)).await.map_err(base::map_db)?
    };
    ctx.event_param("bucketId", bucket.id().to_owned());
    ctx.event_param("fileId", file.id().to_owned());
    Ok(ctx.json(StatusCode::OK, &FileModel { document: &file }))
}

/// `DELETE /v1/storage/buckets/:bucketId/files/:fileId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let db = ctx.db()?;
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let permitted = allows(ctx, &bucket, PermissionType::Delete)?;
    if !secured && !permitted {
        return Err(unauthorized(ctx));
    }
    let collection = bucket_collection(&bucket);
    let file = db
        .authorization()
        .skip(db.get_document(&collection, &file_id, Vec::new(), false))
        .await
        .map_err(base::map_db)?;
    if file.is_empty() {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    if secured && !permitted && !allows(ctx, &file, PermissionType::Delete)? {
        return Err(unauthorized(ctx));
    }
    let device = Stored::for_project(&ctx.project.id)?;
    let path = file.string_or_empty("path");
    let device_deleted = if !chunks_settled(&file) {
        device.abort(&path, &meta::upload_id(&file)).await.unwrap_or(true)
    } else {
        device.delete(&path).await?
    };
    if !device_deleted {
        return Err(Error::with_message(ErrorType::GeneralServerError, "Failed to delete file from device"));
    }
    let project = ctx.project.message_payload();
    ctx.events.deletes.push(base::cache_delete(&project, &file_id, bucket.id()));
    let deleted = if secured && !permitted {
        db.delete_document(&collection, &file_id).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.delete_document(&collection, &file_id)).await.map_err(base::map_db)?
    };
    if !deleted {
        let exists = db
            .authorization()
            .skip(db.get_document(&collection, &file_id, Vec::new(), false))
            .await
            .map_err(base::map_db)?;
        if !exists.is_empty() {
            return Err(Error::with_message(ErrorType::GeneralServerError, "Failed to remove file from DB"));
        }
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    ctx.event_param("bucketId", bucket.id().to_owned());
    ctx.event_param("fileId", file.id().to_owned());
    ctx.event_payload(&FileModel { document: &file });
    Ok(ctx.no_content())
}

struct Incoming {
    name: String,
    tmp: String,
    size: i64,
}

fn incoming(ctx: &Context) -> Result<Incoming> {
    let value = ctx.request.files("file").or_else(|| ctx.request.files("0"));
    let Some(value) = value.filter(|value| !value.is_null() && !value.as_array().is_some_and(Vec::is_empty)) else {
        return Err(Error::new(ErrorType::StorageFileEmpty));
    };
    let field = |key: &str| -> Option<&Json> {
        let field = value.get(key)?;
        field.as_array().and_then(|items| items.first()).or(Some(field))
    };
    let Some(name) = field("name").and_then(Json::as_str).filter(|name| !name.is_empty()) else {
        return Err(Error::new(ErrorType::StorageFileEmpty));
    };
    let Some(tmp) = field("tmp_name").and_then(Json::as_str).filter(|tmp| !tmp.is_empty()) else {
        return Err(Error::new(ErrorType::StorageFileEmpty));
    };
    let size = field("size")
        .and_then(|value| {
            value
                .as_i64()
                .or_else(|| value.as_u64().map(|n| n as i64))
                .or_else(|| value.as_str().and_then(|text| text.parse().ok()))
        })
        .unwrap_or(0);
    Ok(Incoming { name: name.to_owned(), tmp: tmp.to_owned(), size })
}

fn file_permissions_for_create(ctx: &Context) -> Result<Vec<String>> {
    let raw = ctx.param("permissions").cloned().unwrap_or(Json::Null);
    match permission_list(&raw)? {
        None => Ok(default_file_permissions(ctx)),
        Some(items) => {
            // Create accepts read, update, delete and write. `create` is rejected
            // here, before authorization, the way the PHP param validator does.
            let allowed = [PermissionType::Read, PermissionType::Update, PermissionType::Delete, PermissionType::Write];
            let validator = Permissions::new(appwrite_core::validators::LIMIT_ARRAY_PARAMS_SIZE as i64, &allowed);
            if !validator.is_valid(&list_value(&items)) {
                return Err(Error::invalid_param("permissions", &validator.description()));
            }
            let permissions = aggregate(&items, file_permissions())?;
            assert_assignable(ctx, &permissions)?;
            Ok(permissions)
        }
    }
}

#[allow(clippy::too_many_arguments)]
async fn transfer(
    ctx: &mut Context,
    db: &Database,
    device: &Stored,
    bucket: &Document,
    file_id: &str,
    file_name: &str,
    folder: &str,
    permissions: &[String],
    path: &str,
    chunk: i64,
    chunks: &mut i64,
    file_size: i64,
    ranged: bool,
    bytes: Vec<u8>,
    mime: String,
    lock: &Distributed,
) -> Result<Response> {
    let collection = bucket_collection(bucket);
    let auth = db.authorization();
    let existing = auth.skip(db.get_document(&collection, file_id, Vec::new(), false)).await.map_err(base::map_db)?;
    let mut metadata = Metadata { content_type: Some(mime.clone()), ..Metadata::default() };
    let mut created_in_request = false;
    if !existing.is_empty() {
        *chunks = match existing.get("chunksTotal") {
            None | Some(Value::Null) => 1,
            Some(value) => utopia_database::value::to_long(value),
        };
        let uploaded = long(&existing, "chunksUploaded");
        if uploaded == *chunks && !ranged {
            return Err(Error::new(ErrorType::StorageFileAlreadyExists));
        }
        assert_resume(ctx, bucket, &existing)?;
        if long(&existing, "sizeOriginal") != file_size {
            return Err(Error::new(ErrorType::StorageInvalidContentRange));
        }
        if uploaded == *chunks {
            reset_events(ctx);
            return Ok(ctx.json(StatusCode::OK, &FileModel { document: &existing }));
        }
        let (stored, _) = meta::load(&existing);
        metadata = stored;
        if metadata.content_type.is_none() {
            metadata.content_type = Some(mime);
        }
    } else {
        let content_type = metadata.content_type.clone().unwrap_or_default();
        device.prepare(path, &content_type, *chunks, &mut metadata).await?;
        if ranged {
            let document = pending(
                file_id,
                bucket,
                file_name,
                folder,
                path,
                permissions,
                file_size,
                *chunks,
                &metadata,
                &user_id(ctx),
            );
            match db.create_document(&collection, document).await {
                Ok(_) => created_in_request = true,
                Err(err) if err.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::StorageFileAlreadyExists)),
                Err(err) => return Err(base::map_db(err)),
            }
        }
    }
    if !lock.refresh().await.map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))? {
        return Err(busy(ctx));
    }
    let content_type = metadata.content_type.clone().unwrap_or_default();
    let received = device.upload(bytes, path, &content_type, chunk, *chunks, &mut metadata).await?;
    if !lock.is_held().await.map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))? {
        return Err(busy(ctx));
    }
    finish(
        ctx,
        db,
        device,
        bucket,
        file_id,
        file_name,
        folder,
        permissions,
        path,
        *chunks,
        file_size,
        ranged,
        metadata,
        created_in_request,
        received,
    )
    .await
}

#[allow(clippy::too_many_arguments)]
async fn finish(
    ctx: &mut Context,
    db: &Database,
    device: &Stored,
    bucket: &Document,
    file_id: &str,
    file_name: &str,
    folder: &str,
    permissions: &[String],
    path: &str,
    mut chunks: i64,
    file_size: i64,
    ranged: bool,
    mut metadata: Metadata,
    created_in_request: bool,
    received: i64,
) -> Result<Response> {
    let collection = bucket_collection(bucket);
    let auth = db.authorization();
    let file = auth.skip(db.get_document(&collection, file_id, Vec::new(), false)).await.map_err(base::map_db)?;
    let mut uploaded = 0;
    let mut uploader = user_id(ctx);
    if !file.is_empty() {
        chunks = match file.get("chunksTotal") {
            None | Some(Value::Null) => 1,
            Some(value) => utopia_database::value::to_long(value),
        };
        uploaded = long(&file, "chunksUploaded");
        let (stored, stored_uploader) = meta::load(&file);
        if !stored_uploader.is_empty() {
            uploader = stored_uploader.clone();
        }
        let (merged, merged_uploader) = meta::merge(&stored, &metadata, &stored_uploader);
        metadata = merged;
        uploader = if merged_uploader.is_empty() { uploader } else { merged_uploader };
        if uploaded == chunks && !ranged {
            return Err(Error::new(ErrorType::StorageFileAlreadyExists));
        }
        if !created_in_request {
            assert_resume(ctx, bucket, &file)?;
        }
        if uploaded == chunks {
            reset_events(ctx);
            return Ok(ctx.json(StatusCode::OK, &FileModel { document: &file }));
        }
    }
    if received == 0 {
        return Err(Error::with_message(ErrorType::GeneralServerError, "Failed uploading file"));
    }
    let counted = metadata.parts.as_ref().map(|parts| parts.len() as i64).unwrap_or(received);
    let chunks_uploaded = uploaded.max(counted);
    let file = if chunks_uploaded == chunks && uploaded < chunks {
        device.finalize(path, chunks, &mut metadata).await?;
        if ranged && device.file_size(path).await? as i64 != file_size {
            discard(db, device, &collection, file_id, path, file.is_empty()).await?;
            return Err(Error::new(ErrorType::StorageInvalidContentRange));
        }
        if device.is_local()
            && let Err(err) = crate::scan::scan(path, bucket.boolean("antivirus").unwrap_or(true), file_size).await
        {
            discard(db, device, &collection, file_id, path, file.is_empty()).await?;
            return Err(err);
        }
        let mut mime = device.file_mime_type(path).await?;
        mime = rewrite_mime(&mime, file_name);
        let hash = device.file_hash(path).await?;
        let (algorithm, sealed) = persist(ctx, device, bucket, path, file_size, &mime).await?;
        let size_actual = device.file_size(path).await? as i64;
        if file.is_empty() {
            let mut document = Document::default();
            document.put("$id", Value::from(file_id));
            document.put("$permissions", list_value(permissions));
            document.put("bucketId", Value::from(bucket.id()));
            document.put("bucketInternalId", Value::from(documents::sequence_text(bucket).as_str()));
            document.put("name", Value::from(file_name));
            document.put("folder", Value::from(folder));
            document.put("path", Value::from(path));
            document.put("signature", Value::from(hash.as_str()));
            document.put("mimeType", Value::from(mime.as_str()));
            document.put("sizeOriginal", Value::Int(file_size));
            document.put("sizeActual", Value::Int(size_actual));
            document.put("algorithm", Value::from(algorithm.as_str()));
            document.put("comment", Value::from(""));
            document.put("chunksTotal", Value::Int(chunks));
            document.put("chunksUploaded", Value::Int(chunks_uploaded));
            put_cipher(&mut document, sealed.as_ref());
            document.put("search", Value::from(format!("{file_id} {file_name}").as_str()));
            document.put("metadata", meta::store(&metadata, &uploader));
            match db.create_document(&collection, document).await {
                Ok(created) => created,
                Err(err) if err.is_a(Kind::Duplicate) => return Err(Error::new(ErrorType::StorageFileAlreadyExists)),
                Err(err) => return Err(base::map_db(err)),
            }
        } else {
            let mut updates = Document::default();
            updates.put("signature", Value::from(hash.as_str()));
            updates.put("mimeType", Value::from(mime.as_str()));
            updates.put("sizeActual", Value::Int(size_actual));
            updates.put("algorithm", Value::from(algorithm.as_str()));
            put_cipher(&mut updates, sealed.as_ref());
            updates.put("metadata", meta::store(&metadata, &uploader));
            updates.put("chunksUploaded", Value::Int(chunks_uploaded));
            auth.skip(db.update_document(&collection, file_id, updates)).await.map_err(base::map_db)?
        }
    } else {
        let mut updates = Document::default();
        updates.put("chunksUploaded", Value::Int(chunks_uploaded));
        updates.put("metadata", meta::store(&metadata, &uploader));
        auth.skip(db.update_document(&collection, file_id, updates)).await.map_err(base::map_db)?
    };
    if chunks_uploaded == chunks {
        ctx.event_param("bucketId", bucket.id().to_owned());
        ctx.event_param("fileId", file.id().to_owned());
    } else {
        reset_events(ctx);
    }
    Ok(ctx.created(&FileModel { document: &file }))
}

struct Sealed {
    iv: String,
    tag: String,
}

async fn persist(
    ctx: &Context,
    device: &Stored,
    bucket: &Document,
    path: &str,
    file_size: i64,
    mime: &str,
) -> Result<(String, Option<Sealed>)> {
    let requested = bucket.string_or_empty("compression");
    let compress = file_size <= READ_BUFFER && !requested.is_empty() && requested != "none";
    let encrypt = bucket.boolean("encryption").unwrap_or(true) && file_size <= READ_BUFFER;
    let algorithm = if compress { requested } else { "none".to_owned() };
    if !compress && !encrypt {
        return Ok((algorithm, None));
    }
    let mut data = base::read_all(device, path).await?;
    if compress {
        let algorithm = algorithm.clone();
        data = utopia_async::spawn_blocking(move || match algorithm.as_str() {
            "zstd" => Zstd::new().compress(&data),
            _ => Gzip::new().compress(&data),
        })
        .await
        .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))?
        .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))?;
    }
    let sealed = if encrypt {
        let (encrypted, iv, tag) = appwrite_core::crypto::seal(&ctx.state.config.openssl_key_v1, &data);
        data = encrypted;
        Some(Sealed { iv: hex::encode(iv), tag: hex::encode(tag) })
    } else {
        None
    };
    device.write(path, data, mime).await?;
    Ok((algorithm, sealed))
}

fn put_cipher(document: &mut Document, sealed: Option<&Sealed>) {
    match sealed {
        Some(sealed) => {
            document.put("openSSLVersion", Value::from("1"));
            document.put("openSSLCipher", Value::from("aes-128-gcm"));
            document.put("openSSLTag", Value::from(sealed.tag.as_str()));
            document.put("openSSLIV", Value::from(sealed.iv.as_str()));
        }
        None => {
            document.put("openSSLVersion", Value::Null);
            document.put("openSSLCipher", Value::Null);
            document.put("openSSLTag", Value::Null);
            document.put("openSSLIV", Value::Null);
        }
    }
}

#[allow(clippy::too_many_arguments)]
fn pending(
    file_id: &str,
    bucket: &Document,
    file_name: &str,
    folder: &str,
    path: &str,
    permissions: &[String],
    file_size: i64,
    chunks: i64,
    metadata: &Metadata,
    uploader: &str,
) -> Document {
    let mut document = Document::default();
    document.put("$id", Value::from(file_id));
    document.put("$permissions", list_value(permissions));
    document.put("bucketId", Value::from(bucket.id()));
    document.put("bucketInternalId", Value::from(documents::sequence_text(bucket).as_str()));
    document.put("name", Value::from(file_name));
    document.put("folder", Value::from(folder));
    document.put("path", Value::from(path));
    document.put("signature", Value::from(""));
    document.put("mimeType", Value::from(""));
    document.put("sizeOriginal", Value::Int(file_size));
    document.put("sizeActual", Value::Int(0));
    document.put("algorithm", Value::from("none"));
    document.put("comment", Value::from(""));
    document.put("chunksTotal", Value::Int(chunks));
    document.put("chunksUploaded", Value::Int(0));
    document.put("search", Value::from(format!("{file_id} {file_name}").as_str()));
    document.put("metadata", meta::store(metadata, uploader));
    document
}

fn assert_resume(ctx: &Context, bucket: &Document, file: &Document) -> Result<()> {
    if allows(ctx, bucket, PermissionType::Update)? {
        return Ok(());
    }
    if bucket.boolean("fileSecurity").unwrap_or(false) && allows(ctx, file, PermissionType::Update)? {
        return Ok(());
    }
    let (_, uploader) = meta::load(file);
    let id = user_id(ctx);
    if !uploader.is_empty() && uploader == id {
        return Ok(());
    }
    Err(unauthorized(ctx))
}

async fn discard(
    db: &Database,
    device: &Stored,
    collection: &str,
    file_id: &str,
    path: &str,
    empty: bool,
) -> Result<()> {
    let removed = device.delete(path).await?;
    if removed && !empty {
        let deleted = db.authorization().skip(db.delete_document(collection, file_id)).await.map_err(base::map_db)?;
        if !deleted {
            return Err(Error::with_message(ErrorType::GeneralServerError, "Failed to remove file from DB"));
        }
    }
    Ok(())
}

fn rewrite_mime(mime: &str, name: &str) -> String {
    const AMBIGUOUS: &[&str] = &[
        "application/octet-stream",
        "application/vnd.android.package-archive",
        "application/vnd.ms-opentype",
        "application/zip",
        "font/sfnt",
    ];
    if !AMBIGUOUS.contains(&mime) {
        return mime.to_owned();
    }
    match base::extension(name).to_ascii_lowercase().as_str() {
        "3mf" => "model/3mf".to_owned(),
        "aar" | "jar" | "war" => "application/java-archive".to_owned(),
        "apk" => "application/vnd.android.package-archive".to_owned(),
        "kmz" => "application/vnd.google-earth.kmz".to_owned(),
        "msix" => "application/msix".to_owned(),
        "otf" => "font/otf".to_owned(),
        "ttf" => "font/ttf".to_owned(),
        "usdz" => "model/vnd.usdz+zip".to_owned(),
        "xpi" => "application/x-xpinstall".to_owned(),
        _ => mime.to_owned(),
    }
}

fn busy(ctx: &mut Context) -> Error {
    ctx.response_headers.push(("retry-after", "5".to_owned()));
    Error::with_message(ErrorType::GeneralRateLimitExceeded, "File upload is busy. Try again.")
}

fn chunks_settled(file: &Document) -> bool {
    match (file.get("chunksTotal"), file.get("chunksUploaded")) {
        (None, None) => true,
        (Some(total), Some(uploaded)) => {
            utopia_database::value::to_long(total) == utopia_database::value::to_long(uploaded)
        }
        _ => false,
    }
}

async fn readable_file(
    ctx: &Context,
    db: &Database,
    bucket: &Document,
    file_id: &str,
    kind: PermissionType,
) -> Result<Document> {
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let permitted = allows(ctx, bucket, kind)?;
    if !secured && !permitted {
        return Err(unauthorized(ctx));
    }
    let collection = bucket_collection(bucket);
    let file = if secured && !permitted {
        db.get_document(&collection, file_id, Vec::new(), false).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.get_document(&collection, file_id, Vec::new(), false)).await.map_err(base::map_db)?
    };
    if file.is_empty() {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    Ok(file)
}

async fn resolve_file_cursor(db: &Database, collection: &str, queries: &mut [Query], enforce: bool) -> Result<()> {
    let Some(cursor) =
        queries.iter().position(|query| matches!(query.method(), Method::CursorAfter | Method::CursorBefore))
    else {
        return Ok(());
    };
    let validator = CursorValidator::default();
    if !validator.check(&queries[cursor]) {
        return Err(Error::with_message(ErrorType::GeneralQueryInvalid, validator.description()));
    }
    let id = queries[cursor]
        .value()
        .map(|value| utopia_database::value::to_text(&utopia_database::value::from_query(value)))
        .unwrap_or_default();
    let document = if enforce {
        db.get_document(collection, &id, Vec::new(), false).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.get_document(collection, &id, Vec::new(), false)).await.map_err(base::map_db)?
    };
    if document.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralCursorNotFound,
            format!("File '{id}' for the 'cursor' value not found."),
        ));
    }
    queries[cursor].set_value(utopia_database::value::to_query(&Value::from(document)));
    Ok(())
}
