## Storage Cache Header Configuration

Appwrite Storage now supports advanced cache header configuration for files and buckets. You can control HTTP cache headers globally (per bucket), per file, or even per download request.

### Supported Cache Headers
- **Cache-Control**: Set via `cacheControl` or `maxAge` (in seconds)
- **ETag**: Configurable as static, hash, or date
- **Expires**: Set via `expires` (RFC 1123 date string)
- **Last-Modified**: Mode can be `static`, `date`, or `file` (file modification time)
- **Vary**: Set via `vary`
- **Pragma**: Set via `pragma`

### Configuration Levels
1. **Bucket Defaults**: Set global cache headers for all files in a bucket
2. **File Overrides**: Set cache headers for individual files (overrides bucket)
3. **Download-Time Overrides**: Set cache headers per download request (overrides file and bucket)

### API Parameters
- `maxAge` (integer, 0–31536000): Max-Age value for Cache-Control
- `expires` (string, RFC 1123): Expires header value
- `lastModifiedMode` (string): One of `static`, `date`, `file`
- `lastModifiedStatic` (string, RFC 1123): Used if mode is `static`
- `vary` (string): Vary header value
- `pragma` (string): Pragma header value

### Validation
- `maxAge`: Must be a non-negative integer, up to 1 year (31536000 seconds)
- `expires` and `lastModifiedStatic`: Must be valid RFC 1123 date strings (e.g., `Sun, 06 Nov 1994 08:49:37 GMT`)
- `lastModifiedMode`: Must be one of `static`, `date`, `file`
- `vary` and `pragma`: Strings, up to 128 characters

### Example Usage
#### Set Bucket Defaults
```json
{
  "maxAge": 86400,
  "expires": "Sun, 06 Nov 2025 08:49:37 GMT",
  "lastModifiedMode": "date",
  "vary": "Accept-Encoding",
  "pragma": "public"
}
```

#### Set File-Specific Cache Headers
```json
{
  "maxAge": 3600,
  "expires": "Mon, 07 Nov 2025 08:49:37 GMT",
  "lastModifiedMode": "static",
  "lastModifiedStatic": "Mon, 07 Nov 2025 08:00:00 GMT"
}
```

#### Override on Download
Add query parameters to the download endpoint:
```
GET /v1/storage/buckets/{bucketId}/files/{fileId}/download?maxAge=600&expires=Tue,%2008%20Nov%202025%2008:49:37%20GMT
```

### Notes
- Download-time overrides have the highest priority, followed by file-specific, then bucket defaults.
- All values are validated for type and format.
- Invalid values will result in a 400 error with a descriptive message.

---

For more details, see the [API Reference](https://appwrite.io/docs/references/cloud/client-web/storage).
