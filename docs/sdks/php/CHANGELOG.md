# Change Log

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
