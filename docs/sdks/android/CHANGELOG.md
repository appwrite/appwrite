# Change Log

## 8.0.0

* Added `token` parameter to `getFilePreview` and `getFileView` to support authenticated access using file tokens.
* Changed default `quality` value in `getFilePreview` from `0` to `-1`. Use `-1` for automatic or original quality.
* Removed `Gif` from `ImageFormat` enum. Use supported formats like `PNG` or `WEBP` instead.
* Removed `search` parameter from `listExecutions()` method. This functionality may be replaced by filters in future versions.
