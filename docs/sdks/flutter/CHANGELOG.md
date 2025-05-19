# Change Log

## 16.0.0

* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Update default `quality` for `getFilePreview` from 0 to -1

## 15.0.2

* Avoid setting empty `User-Agent` header and only encode it when present.
* Update doc examples to use new multi-region endpoint: `https://<REGION>.cloud.appwrite.io/v1`.

## 15.0.1

* Removed `Content-Type` header from GET and HEAD requests.
* Add validation for setting endpoint in `setEndpoint` and `setEndPointRealtime` methods.
* Include Figma in list of available OAuth providers.

## 15.0.0

* Encode `User-Agent` header to fix invalid HTTP header field value error.
* Breaking changes:
  * Changed the typing of `AppwriteException`'s response parameter from a `dynamic` object to an optional string (`?String`).

## 14.0.0

* Fixed realtime pong response.
* Fixed issues with `chunkedUpload` method.

## 13.0.0

* Fixed realtime reconnection issues
* Support for Appwrite 1.6
* Update dependencies
* Added `scheduledAt` attribute to `Execution` response model
* Added `scheduledAt` parameter to `createExecution()`: Enables creating a delayed execution
* Breaking changes:
  * Removed `otp` parameter from `deleteMFAAuthenticator`.

You can find the new syntax for breaking changes in the [Appwrite API references](https://appwrite.io/docs/references). Select version `1.6.x`.

**Please note: This version is compatible with Appwrite 1.6 and later only. If you do not update your Appwrite SDK, old SDKs will not break your app. Appwrite APIs are backwards compatible.**

## 12.0.4

* Fixed concurrent modification error when closing realtime socket

## 12.0.3

* Upgrade dependencies

## 12.0.2

* Fixed realtime multiple subscription issues

## 12.0.1

* Fixed parameters using enum types

## 12.0.0

* Added enum support
* Added SSR support
* Added messaging service support
* Added contains query support
* Added or query support

## 11.0.1

* Fix between queries

## 11.0.0

* Parameter `url` is now optional in the `createMembership` endpoint

## 10.0.1

* Added a new `label` function to the `Role` helper class
* Update internal variable names to prevent name collision
* Fix: content range header inconsistency in chunked uploads [#648](https://github.com/appwrite/sdk-generator/pull/648)

## 10.0.0

* Support for Appwrite 1.4.0
* New endpoints for fetching user identities
* New endpoints for listing locale codes
* Updated documentation
* Breaking changes:
  * The `createFunction` method has a new signature.
  * The `createExecution` method has a new signature.
  * The `updateFunction` method has a new signature.
  * The `createDeployment` method no longer requires an entrypoint.
  * The `updateFile` method now includes the ability to update the file name.
  * The `updateMembershipRoles` method has been renamed to `updateMembership`.

## 9.0.1

* Added documentation comments
* Added unit tests
* Upgraded dependencies

## 9.0.0

* Added relationships support
* Added support for new queries: `isNull`, `isNotNull`, `startsWith`, `notStartsWith`, `endsWith`, `between` and `select`.
* Added update attribute support
* Added team prefs support
* Changed function create/update `execute` parameter to optional
* Changed team `update` to `updateName`
* Changed `Account` service to use the `User` model instead of `Account`

## 8.3.0

* Fix: back navigation bringing back web browser after OAuth session creation
* Update: Deprecated `InputFile` default constructor and introduced `InputFile.fromPath` and `InputFile.fromBytes` for consistency with other SDKs

## 8.2.2

* Fix: notify callback when websocket closes [#604](https://github.com/appwrite/sdk-generator/pull/604)

## 8.2.1

* Fix OAuth on web
* Improve helper classes

## 8.2.0

* Support for GraphQL

## 8.1.0

* Role helper update

## 8.0.0

### NEW
* Support for Appwrite 1.0.0
* More verbose headers have been included in the Clients - `x-sdk-name`, `x-sdk-platform`, `x-sdk-language`, `x-sdk-version`
* Helper classes and methods for Permissions, Roles and IDs
* Helper methods to suport new queries
* All Dates and times are now returned in the ISO 8601 format

### BREAKING CHANGES

* `databaseId` is no longer part of the `Database` Service constructor. `databaseId` will be part of the respective methods of the database service.
* `color` attribute is no longer supported in the Avatars Service
* The `number` argument in phone endpoints have been renamed to `phone`
* List endpoints no longer support `limit`, `offset`, `cursor`, `cursorDirection`, `orderAttributes`, `orderTypes` as they have been moved to the `queries` array
* `read` and `write` permission have been deprecated and they are now included in the `permissions` array
* Renamed methods of the Query helper
    1.  `lesser` renamed to `lessThan`
    2.  `lesserEqual` renamed to `lessThanEqual`
    3.  `greater` renamed to `greaterThan`
    4.  `greaterEqual` renamed to `greaterThanEqual`
* `User` response model is now renamed to `Account`

**Full Changelog for Appwrite 1.0.0 can be found here**:
https://github.com/appwrite/appwrite/blob/master/CHANGES.md

## 7.0.0
* **BREAKING** Switched to using [flutter_web_auth_2](https://pub.dev/packages/flutter_web_auth_2), check Getting Started section in Readme for changes (Android and Web will require adjustments for OAuth to work properly)
* Fixes Concurrent modification issue
* Upgrade dependencies
* **Windows** support for OAuth sessions

## 6.0.0
* Support for Appwrite 0.15
* **NEW** Phone authentication `account.createPhoneSession()`
* **BREAKING** `Database` -> `Databases`
* **BREAKING** `account.createSession()` -> `account.createEmailSession()`
* **BREAKING** `dateCreated` attribute removed from `Team`, `Execution`, `File` models
* **BREAKING** `dateCreated` and `dateUpdated` attribute removed from `Func`, `Deployment`, `Bucket` models
* **BREAKING** Realtime channels
    * collections.[COLLECTION_ID] is now databases.[DATABASE_ID].collections.[COLLECTION_ID]
    * collections.[COLLECTION_ID].documents is now databases.[DATABASE_ID].collections.[COLLECTION_ID].documents

**Full Changelog for Appwrite 0.15 can be found here**: https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0150

## 5.0.0
* Support for Appwrite 0.14
* **BREAKING** `account.delete()` -> `account.updateStatus()`
* **BREAKING** Execution model `stdout` renamed to `response`
* **BREAKING** Membership model `name` renamed to `userName` and `email` renamed to `userEmail`
* Added `teamName` to Membership model

## 4.0.2
* Upgrade dependencies

## 4.0.1
* Fix InputFile filename param
* Fix examples

## 4.0.0
* Support for Appwrite 0.13
* **BREAKING** **Tags** have been renamed to **Deployments**
* **BREAKING** `createFile` function expects Bucket ID as the first parameter
* **BREAKING** `createDeployment` and `createFile` functions expect an instance **InputFile** rather than the instance of **MultipartFile**
* **BREAKING** `list<Entity>` endpoints now contain a `total` attribute instead of `sum`
* `onProgress()` callback function for endpoints supporting file uploads
* Support for synchronous function executions
* Bug fixes and Improvements

**Full Changelog for Appwrite 0.13 can be found here**: https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0130

## 3.0.1
- Export Query Builder

## 3.0.0
- Support for Appwrite 0.12
- **BREAKING** Updated database service to adapt 0.12 API
- **BREAKING** Custom ID support while creating resources
- [View all the changes](https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0120)

## 2.1.0
- Updated `flutter_we_auth` plugin now supports Flutter web for OAuth2 sessions [read more](https://github.com/appwrite/sdk-for-flutter/blob/master/README.md#web)
- Added linters and updated codebase to match the rules

## 2.0.3
- Support for Appwrite 0.11
- Fix comments on `sum` attributes

## 2.0.2
- Fix realtime not restarting when there was only one subscription and that was closed and reopened

## 2.0.1
- Fix realtime close and reconnect working only 1 out of two times due to future returning too early
- Add dart doc comments to newly added response models

## 2.0.0
- BREAKING All services and methods now return proper response objects instead of `Response` object

## 1.0.4
- Fix user agent by using `packageName` instead of `appName`

## 1.0.3
- Upgrade `flutter_web_auth` to `0.3.1`

## 1.0.2
- Fix timestamp in Realtime Response to Integer

## 1.0.1
- Fix null pointer exception while creating OAuth2 session
- Export RealtimeMessage
- Export, separate IO and Browser clients for Flutter (Client and Realtime as well) and Dart (Client)

## 1.0.0
- Support for Appwrite 0.10
- Refactored for better cross platform support
- Exception implements `toString()` to get proper error message for unhandled exceptions
- Introduces new Realtime service, [more on official docs](link-to-realtime-docs)
- Breaking Signature for `MultipartFile` has changed as now we are using `http` package. [Here is the new signature for MultipartFile](https://pub.dev/documentation/http/latest/http/MultipartFile-class.html)
- Breaking Signature for `Response` has changed, now it only exposes the `data`.

## 0.7.1
- Fix - createOAuth2Session completing too early

## 0.7.0
- Support for Appwrite 0.9
- Breaking - removed order type enum, now you should pass string 'ASC' or 'DESC'
- Image Crop Gravity support in image preview service
- New endpoint in Account getSession to get session by ID
- Fix - issues with User-Agent when app name consisted of non-ASCII characters
- Fix - issue with null Success and Failure URL in createOAuth2Session
- Updated underlying dependencies

## 0.6.3

- Removed default values, nothing should change in usage as default values are already allocated in server

## 0.6.2

- Fixed deployment bug

## 0.6.1

- Fix for image preview param types

## 0.6.0

- Upgraded to Null-safety, minimum Dart SDK required 2.12.0
- Upgraded all underlying dependencies to null safe version
- BREAKING Renamed parameter inviteId to membershipId on teams.updateMembershipStatus, teams.deleteMembership
- [Anonymous login](https://appwrite.io/docs/references/cloud/client-flutter/account?sdk=flutter#createAnonymousSession)
- [JWT Support](https://appwrite.io/docs/references/cloud/client-flutter/account?sdk=flutter#createJWT)
- Fallback Cookies for Flutter Web if 3rd party cookies are blocked
- Custom User Agent Support
- [Update membership roles](https://appwrite.io/docs/references/cloud/client-flutter/teams?sdk=flutter#updateMembershipRoles)
- New awesome image preview features, supports borderRadius, borderColor, borderWidth

## 0.5.0-dev.1

- Upgraded to Null-safety, minimum Dart SDK required 2.12.0 and minimum Flutter SDK version required 2.0.0
- Upgraded all underlying dependencies to null safe version
- All of Avatars service now return Future<Response></Response> instead of String like the Storage getFilePreview, getFileView and getFileDownload
- Upgraded to Null-safety, minimum Dart SDK required 2.12.0
- Upgraded all underlying dependencies to null safe version

## 0.4.0

- Improved code quality
- Enabled access to private storage files
- Easier integration for preview images with the image widget
- Added custom Appwrite exceptions
- Breaking: getFilePreview, getFileDownload and getFileView now return Future instead of String

## 0.4.0-dev.3

- Added code formatting as part of the CI
- Added custom Appwrite exceptions

## 0.4.0-dev.2

- Minor fixes for custom exceptions

## 0.4.0-dev.1

- Improved code quality
- Enabled access to private storage file
- Added easier integration for preview images and the Image widget

## 0.3.0

- Upgraded to work with Appwrite 0.7

## 0.3.0-dev.2

- Fix for an error when using a self-signed certificate for Web

## 0.3.0-dev.1

- Updated package dependencies (@lohanidamodar)
- Added Flutter for Web compatibility (@lohanidamodar)

## 0.2.3

- Fixed OAuth2 cookie bug, where a new session cookie couldn't overwrite an old cookie

## 0.2.2

- Fixed an error that happened when the OAuth session creation request was sent before any other API call
- Fixed a bug in the Avatars service where location URL generation had syntax error

## 0.2.1

- Fixed callback scheme

## 0.2.0

- Updated flutter_web_auth plugin to version 0.2.4
- Added per project unique callback for OAuth2 redirects to avoid conflicts between multiple Appwrite projects

## 0.1.1

- Updated flutter_web_auth version

## 0.1.0

- Added examples file
- Some minor style fixes

## 0.0.14

- Using MultipartFile for file uploads

## 0.0.13

- Fix for file upload method

## 0.0.12

- Added file upload support for storage service

## 0.0.11

- Added integration with web auth plugin to support Appwrite OAuth API

## 0.0.9

- Updated default params

## 0.0.8

- Fixed compilation error in Client class
- Shorter description for package
