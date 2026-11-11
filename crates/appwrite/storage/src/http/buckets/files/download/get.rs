//! `GET /v1/storage/buckets/:bucketId/files/:fileId/download`.
//! PHP `Http/Buckets/Files/Download/Get.php`.

use utopia_http::Response;

use appwrite_core::Result;
use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;

use crate::base::OUTPUT_CHUNK;

use super::super::send::{ids, load_file, send};

pub async fn get(ctx: &mut Context) -> Result<Response> {
    let (bucket_id, file_id) = ids(ctx)?;
    let db = ctx.db()?;
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    let file = load_file(ctx, &db, &bucket, &file_id).await?;
    send(ctx, &file, &file.string_or_empty("mimeType"), "attachment", "sandbox; script-src 'none';", true, OUTPUT_CHUNK)
        .await
}
