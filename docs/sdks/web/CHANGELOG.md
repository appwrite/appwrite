# Change Log

## 19.0.0

* **NEW**: Introduced `Tables` API support with improved terminology
  * Added new Table-based methods:
    * `createTable` (replaces `createCollection`)
    * `createRow` (replaces `createDocument`)
    * `updateRow` (replaces `updateDocument`)
    * `deleteRow` (replaces `deleteDocument`)
    * `getRow` (replaces `getDocument`)
    * `listRows` (replaces `listDocuments`) and much more...
  * **DEPRECATED**: Old Document-based methods are now deprecated and will not receive further updates
  * Existing applications continue to work with deprecated methods
  * For documentation on new terminology, see: https://appwrite.io/docs/products/databases/tables 
* Add `gif` support to `ImageFormat` enum
* Fix `Document` autocompletion not working even when a generic type is provided
* Fix undefined `filePath` param in `chunkedUpload` method

## 18.1.1

* Fix `devKeys` bug by removing credentials when the key is set

## 18.1.0

* Add `devKeys` and `upsertDocument` support

## 18.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Improve CORS error catching in `client.call` method

## 17.0.2

* Fix requests failing by removing `content-type` header from `GET` and `HEAD` requests

## 17.0.1

* Remove unnecessary titles from method descriptions
* Fix duplicate adding of payload params
* Remove unnecessary awaits and asyncs
* Ensure `AppwriteException` response is always string

## 17.0.0

* Add `createHeartbeat` and `ping` functionality to realtime
* Add `avif` support to `ImageFormat` enum
* Fix: Pong response & chunked upload
* Improve some doc comments
