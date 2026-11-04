# Storage file types: extension vs MIME type

## Problem

A bucket restricts uploads with `allowedFileExtensions`. The check in
`Storage/Http/Buckets/Files/Create.php` looks only at the name the client sends:

```php
$fileExt = new FileExt($allowedFileExtensions);
if (!empty($allowedFileExtensions) && !$fileExt->isValid($fileName)) {
    throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'File extension not allowed');
}
```

The stored `mimeType` comes from content sniffing later, at the last chunk. The two
are never compared. A bucket limited to `jpg` and `png` accepts `invoice.png` holding
a PDF, a zip or an HTML page. Developers set the allowlist to mean "only images",
and it does not.

Serving limits the damage: `View/Get.php` sends the stored type only when it is in
`storage-mimes`, and adds `X-Content-Type-Options: nosniff`. So this is a contract
gap, not a direct XSS. Users still get files of a type the bucket owner refused.

## Decision

Keep `allowedFileExtensions` as the one bucket setting. After the last chunk is
assembled, check the sniffed MIME type against the types those extensions map to.
Reject on mismatch with `STORAGE_FILE_TYPE_UNSUPPORTED` and delete the assembled file.

- **One setting, not two.** No `allowedMimeTypes` attribute. Developers think in
  extensions; a second list they must keep in sync is worse than today.
- **Map extensions to types in config.** Extend `app/config/storage/formats.php` with
  an `extension → [mime types]` map (`jpg → [image/jpeg]`, `svg → [image/svg+xml,
  text/xml]`, `docx → [application/vnd.openxmlformats-…, application/zip]`).
- **Ambiguous sniffs pass.** When sniffing returns a type in `formats['ambiguous']`
  (`application/octet-stream`, `application/zip`, …), accept it if the extension maps
  to a type in the same container family. Sniffing cannot tell an APK from a zip, and
  rejecting real files is worse than the gap.
- **Unmapped extensions keep today's behaviour.** An allowlisted extension with no
  entry in the map is checked by name only. Coverage grows by adding map entries,
  never by breaking uploads.
- **Empty allowlist is unchanged.** No restriction, no check.
- **Check at finalize, not per chunk.** The type is only known once the whole file
  exists; the first chunk alone is not enough for zip-based formats.

`Files/Update.php` renames files without touching content. It keeps the name check
only, so a rename cannot move a file to a disallowed extension; the content was
already checked on upload.

The S3-compatible upload path (`S3/Http/S3/Base.php`) gets the same check at its
finalize step.

## Compatibility

This rejects uploads that succeed today, so it ships as a behaviour change in the
release notes. Existing stored files are not rescanned. The error code stays
`storage_file_type_unsupported`; the message changes to
`File content does not match its extension`, so SDKs need no update.

## Tests

- E2E in `tests/e2e/Services/Storage`: a PNG-only bucket rejects a PDF named `.png`
  (single request and chunked), accepts a real PNG, accepts a `.apk` that sniffs as zip,
  and accepts any content for an extension missing from the map.
- Unit test for the extension → MIME match against the config map.
