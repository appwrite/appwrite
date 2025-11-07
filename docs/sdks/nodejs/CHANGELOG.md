# Change Log

## 20.3.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `createResendProvider` and `updateResendProvider` methods to `Messaging` service

## 20.2.1

* Add transaction support for Databases and TablesDB

## 20.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 17.2.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Fix autocompletion not working for `Document` model even when generic is passed

## 17.1.0

* Add `upsertDocument` method
* Add `dart-3.8` and `flutter-3.32` runtimes
* Add `gif` image format
* Update bulk operation methods to reflect warning message
* Fix file parameter handling in chunked upload method

## 17.0.0

* Add `REGION` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime`, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Updates enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Removes `search` param from `listExecutions` method

## 16.0.0

* Fix: remove content-type from GET requests
* Update (breaking): min and max params are now optional in `updateFloatAttribute` and `updateIntegerAttribute` methods (changes their positioning in method definition)

## 15.0.1

* Remove titles from all function descriptions
* Fix typing for collection "attribute" key
* Remove unnecessary awaits and asyncs
* Ensure `AppwriteException` response is always string

## 15.0.0

* Fix: pong response & chunked upload

## 14.2.0

* Add new push message parameters

## 14.1.0

* Support updating attribute name and size

## 14.0.0

* Support for Appwrite 1.6
* Add `key` attribute to `Runtime` response model.
* Add `buildSize` attribute to `Deployments` response model
* Add `scheduledAt` attribute to `Executions` response model
* Add `scopes` attribute to `Functions` response model
* Add `specifications` attribute to `Functions` response model
* Add new response model for `Specifications`
* Add new response model for `Builds`
* Add `createJWT()` : Enables creating a JWT using the `userId`
* Add `listSpecifications()`: Enables listing available runtime specifications
* Add `deleteExecution()` : Enables deleting executions
* Add `updateDeploymentBuild()`: Enables cancelling a deployment
* Add `scheduledAt` parameter to `createExecution()`: Enables creating a delayed execution
* Breaking changes
    * Remove `otp` parameter from `deleteMFAAuthenticator`.
    * Add `scopes` parameter for create/update function.
    * Rename `templateBranch` to `templateVersion`  in `createFunction()`.
    * Rename `downloadDeployment()` to `getDeploymentDownload()`

> You can find the new syntax for breaking changes in the [Appwrite API references](https://appwrite.io/docs/references). Select version `1.6.x`.