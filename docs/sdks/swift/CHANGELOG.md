# Change Log

## 10.2.0

* Update sdk to use swift-native doc comments instead of jsdoc styled comments as per [Swift Documentation Comments](https://github.com/swiftlang/swift/blob/main/docs/DocumentationComments.md)
* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `gif` support to `ImageFormat` enum
* Add `sequence` support to `Document` model
* Add `dart38` and `flutter332` support to runtime models

## 10.1.0

* Adds `upsertDocument` method
* Adds warnings to bulk operation methods
* Adds the new `encrypt` attribute
* Adds runtimes: `flutter332` and `dart38`
* Fix `select` Queries by updating internal attributes like `id`, `createdAt`, `updatedAt` etc. to be optional in `Document` model.
* Fix `listCollection` errors by updating `attributes` typing
* Fix querying datetime values by properly encoding URLs

## 10.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 9.0.0

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 8.0.0

* Remove redundant titles from method descriptions.
* Add `codable` models
* Ensure response attribute in `AppwriteException` is always string

## 7.0.0

* Fix pong response & chunked upload
