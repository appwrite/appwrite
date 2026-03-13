# Change Log

## 20.0.1

* Fix doc examples with proper formatting

## 20.0.0

* Add array-based enum parameters (e.g., `permissions: array<BrowserPermission>`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 19.1.0

* Added ability to create columns and indexes synchronously while creating a table

## 19.0.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `createTemplateDeployment` method signature: replace `version` parameter with `type` (TemplateReferenceType) and `reference` parameters
* Add `Theme`, `Timezone` and `Output` enums

## 18.0.1

* Fix `TablesDB` service to use correct file name

## 18.0.0

* Fix duplicate methods issue (e.g., `updateMFA` and `updateMfa`) causing build and runtime errors
* Add support for `getScreenshot` method to `Avatars` service
* Add `Output`, `Theme` and `Timezone` enums

## 17.5.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `createResendProvider` and `updateResendProvider` methods to `Messaging` service

## 17.4.1

* Add transaction support for Databases and TablesDB

## 17.3.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 15.1.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `dart38` and `flutter332` support to runtime models
* Add `gif` support to `ImageFormat` enum
* Add `upsertDocument` support to `Databases` service
* Add `sequence` support to `Document` model