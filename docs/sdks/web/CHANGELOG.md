# Change Log

## 21.5.0

* Add `getScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 21.4.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations

## 21.3.0

* Add new `Realtime` service with methods for subscribing to channels and receiving messages
* Fix `client.setSession` not working when using realtime
* Deprecate `client.subscribe` method in favor of `Realtime` service

> Note: Deprecated methods are still available for backwards compatibility, but might be removed in future versions.

## 21.2.1

* Add transaction support for Databases and TablesDB

## 21.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 18.2.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `gif` support to `ImageFormat` enum
* Fix undefined `fileParam` error in `chunkedUpload` method
* Fix autocompletion not working for `Document` model even when generic is passed

## 18.1.1

* Fix using `devKeys` resulting in an error by conditionally removing credentials

## 18.1.0

* Add `devKeys` support to `Client` service
* Add `upsertDocument` support to `Databases` service

## 18.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Improve CORS error catching in `client.call` method

## 17.0.2

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 17.0.1

* Remove unnecessary titles from method descriptions
* Fix duplicate adding of payload params
* Remove unnecessary awaits and asyncs
* Ensure `AppwriteException` response is always string

## 17.0.0

* Fix pong response & chunked upload
* Add `ping` support to `Realtime` service
