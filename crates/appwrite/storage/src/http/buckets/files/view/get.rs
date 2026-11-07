//! `GET /v1/storage/buckets/:bucketId/files/:fileId/view`.
//! PHP `Http/Buckets/Files/View/Get.php`.

use utopia_http::Response;

use appwrite_core::Result;
use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;

use crate::base::READ_BUFFER;

use super::super::send::{MIMES, ids, load_file, send};

pub async fn get(ctx: &mut Context) -> Result<Response> {
    let (bucket_id, file_id) = ids(ctx)?;
    let db = ctx.db()?;
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    let file = load_file(ctx, &db, &bucket, &file_id).await?;
    let mime = file.string_or_empty("mimeType");
    let content_type = if MIMES.contains(&mime.as_str()) { mime.clone() } else { "text/plain".to_owned() };
    let disposition = if content_type == "image/svg+xml" { "attachment" } else { "inline" };
    send(ctx, &file, &content_type, disposition, "script-src 'none';", true, READ_BUFFER).await
}
