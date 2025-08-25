# Change Log

## 8.2.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `gif` support to `ImageFormat` enum
* Add `sequence` support to `Document` model

## 8.1.0

* Add `devKeys` support to `Client` service
* Add `upsertDocument` support to `Databases` service

## 8.0.0

* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Update default `quality` for `getFilePreview` from 0 to -1
* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method

## 7.0.1

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests
