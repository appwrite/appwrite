# Change Log

## 13.2.1

* Add transaction support for Databases and TablesDB

## 13.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 10.2.0

* Update sdk to use swift-native doc comments instead of jsdoc styled comments as per [Swift Documentation Comments](https://github.com/swiftlang/swift/blob/main/docs/DocumentationComments.md)
* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `gif` support to `ImageFormat` enum
* Remove `Content-Type`, `Content-Length` headers and body from websocket requests

## 10.1.1

* Adds warnings to bulk operation methods
* Fix select Queries by updating internal attributes like id, createdAt, updatedAt etc. to be optional in Document model.
* Fix querying datetime values by properly encoding URLs

## 10.1.0

* Add `devKeys` support to `Client` service
* Add `upsertDocument` support to `Databases` service

## 10.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Remove `search` param from `listExecutions` method
* Remove `Gif` from ImageFormat enum

## 9.0.1

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 9.0.0

* Remove redundant titles from method descriptions.
* Add `codable` models
* Ensure response attribute in `AppwriteException` is always string
