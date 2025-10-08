# Change Log

## v0.12.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service
* Add `orderRandom` query support

## 0.9.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `upsertDocument` support to `Databases` service
* Update doc examples to use correct syntax

## 0.8.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 0.7.0

* Version skipped

## 0.6.0

* Add bulk API methods: `createDocuments`, `deleteDocuments` etc.

## 0.5.0

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 0.4.0

* Fix pong response and chunked upload

## 0.3.0

* Add new push message parameters
