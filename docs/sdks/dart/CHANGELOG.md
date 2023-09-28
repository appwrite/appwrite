## 10.0.0

* Parameter `url` is now optional in the `createMembership` endpoint
* Parameter `runtime` is now optional in the `update` endpoint of the `Functions` class

## 9.0.1

* Added a new `label` function to the `Role` helper class
* Update internal variable names to prevent name collision
* Fix: content range header inconsistency in chunked uploads [#648](https://github.com/appwrite/sdk-generator/pull/648) 

## 9.0.0

* Support for Appwrite 1.4.0
* New endpoints for fetching user identities
* New endpoints for listing locale codes
* New endpoint for downloading a function deployment
* Updated documentation
* Breaking changes:
  * The `createFunction` method has a new signature.
  * The `createExecution` method has a new signature.
  * The `updateFunction` method has a new signature.
  * The `createDeployment` method no longer requires an entrypoint.
  * The `updateFile` method now includes the ability to update the file name.
  * The `updateMembershipRoles` method has been renamed to `updateMembership`.

## 8.0.1

* Added documentation comments
* Added unit tests
* Upgraded dependencies

## 8.0.0

* Added relationships support
* Added support for new queries: `isNull`, `isNotNull`, `startsWith`, `notStartsWith`, `endsWith`, `between` and `select`.
* Added update attribute support
* Added team prefs support
* Changed function create/update `execute` parameter to optional
* Changed team `update` to `updateName`
* Changed `Account` service to use the `User` model instead of `Account`

## 7.3.0

* Improve helper classes
* Deprecated `InputFile` default constructor and introduced `InputFile.fromPath` and `InputFile.fromBytes` for consistency with other SDKs

## 7.2.0

* Support for GraphQL

## 7.1.0

* Role helper update

## 7.0.0

### NEW
* Support for Appwrite 1.0.0
* More verbose headers have been included in the Clients - `x-sdk-name`, `x-sdk-platform`, `x-sdk-language`, `x-sdk-version`
* Helper classes and methods for Permissions, Roles and IDs
* Helper methods to suport new queries
* All Dates and times are now returned in the ISO 8601 format
* Execution Model now has an additional `stdout` attribute
* Endpoint for creating DateTime attribute
* User imports API with support for multiple hashing algorithms
* CRUD API for functions environment variables
* `createBucket` now supports different compression algorithms

### BREAKING CHANGES

* `databaseId` is no longer part of the `Database` Service constructor. `databaseId` will be part of the respective methods of the database service.
* The `Users.create()` method signature has now been updated to include a `phone` parameter
* `color` attribute is no longer supported in the Avatars Service
* The `number` argument in phone endpoints have been renamed to `phone`
* List endpoints no longer support `limit`, `offset`, `cursor`, `cursorDirection`, `orderAttributes`, `orderTypes` as they have been moved to the `queries` array
* `read` and `write` permission have been deprecated and they are now included in the `permissions` array
* Parameter `permission` for collections and buckets are now renamed to `documentSecurity` & `fileSecurity` respectively
* Renamed methods of the Query helper
    1.  `lesser` renamed to `lessThan`
    2.  `lesserEqual` renamed to `lessThanEqual`
    3.  `greater` renamed to `greaterThan`
    4.  `greaterEqual` renamed to `greaterThanEqual`

**Full Changelog for Appwrite 1.0.0 can be found here**: https://github.com/appwrite/appwrite/blob/master/CHANGES.md

## 6.0.1
* Dependency upgrades
* Doc comments updates
* Cleanup code

## 6.0.0
* Support for Appwrite 0.15
* **BREAKING** `Database` -> `Databases`
* **BREAKING** `account.createSession()` -> `account.createEmailSession()`
* **BREAKING** `dateCreated` attribute removed from `Team`, `Execution`, `File` models
* **BREAKING** `dateCreated` and `dateUpdated` attribute removed from `Func`, `Deployment`, `Bucket` models
* **BREAKING** Realtime channels
    * collections.[COLLECTION_ID] is now databases.[DATABASE_ID].collections.[COLLECTION_ID]
    * collections.[COLLECTION_ID].documents is now databases.[DATABASE_ID].collections.[COLLECTION_ID].documents

**Full Changelog for Appwrite 0.15 can be found here**: https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0150

## 5.0.1
* Code formatting fix

## 5.0.0
* Support for Appwrite 0.14
* **BREAKING** `account.delete()` -> `account.updateStatus()`
* **BREAKING** Execution model `stdout` renamed to `response`
* **BREAKING** Membership model `name` renamed to `userName` and `email` renamed to `userEmail`
* Added `teamName` to Membership model
* New `users.getMemberships` function

## 4.0.2
* Fix null issues with float attributes (https://github.com/appwrite/sdk-for-dart/issues/17 and https://github.com/appwrite/sdk-for-dart/issues/16)

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

## 3.0.2
- String Attribute Type got fixed

## 3.0.1
- Export Query Builder

## 3.0.0
- Support for Appwrite 0.12
- **BREAKING** Updated database service to adapt 0.12 API 
- **BREAKING** Custom ID support while creating resources
- [View all the changes](https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0120)

## 2.0.0
- BREAKING All services and methods now return structured response objects instead of `Response` object

## 1.0.2
- Support for Appwrite 0.11

## 1.0.1
- Export, separate IO and Browser clients for Flutter (Client and Realtime as well) and Dart (Client)

## 1.0.0
- Support for Appwrite 0.10
- Refactored for better cross platform support
- Exception implements `toString()` to get proper error message for unhandled exceptions
- **Breaking** - Signature for `MultipartFile` has changed as we have dropped Dio in favor of [http](https://pub.dev/packages/http) package. [Here is the new signature for MultipartFile](https://pub.dev/documentation/http/latest/http/MultipartFile-class.html)
- **Breaking** - Signature for `Response` has changed, now it only exposes the data.

## 0.7.0
- Support for Appwrite 0.9
- Breaking - removed order type enum, now you should pass string 'ASC' or 'DESC'
- Breaking - changed param name from `env` to `runtime` in the **Functions** API
- Image Crop Gravity support in image preview service
- New endpoint in Account getSession to get session by ID
- New endpoint in the Users API to update user verification status 
- Fix - issues with User-Agent when app name consisted of non-ASCII characters

## 0.6.2

- Removed default values, nothing should change in usage as default values are already allocated in server

## 0.6.1

- Fix for image preview param types

## 0.6.0

- Upgraded to Null-safety, minimum Dart SDK required 2.12.0
- Upgraded all underlying dependencies to null safe version
- BREAKING Renamed users.deleteUser to users.delete
- BREAKING Renamed parameter inviteId to membershipId on teams.updateMembershipStatus, teams.deleteMembership
- JWT Support client.setJWT('JWT_GENERATED_IN_CLIENT')
- [Update membership roles](https://appwrite.io/docs/references/cloud/server-dart/teams?sdk=dart#updateMembershipRoles)
- New awesome image preview features, supports borderRadius, borderColor, borderWidth 

## 0.5.0-dev.1

- Upgraded to Null-safety, minimum Dart SDK required 2.12.0
- Upgraded all underlying dependencies to null safe version

## 0.3.1

- Minor fixes for custom exceptions

## 0.3.0

- Improved code quality
- Added a custom Appwrite exception
- Enabled access to private storage file

## 0.2.0

- Upgraded to work with Appwrite 0.7

## 0.1.0

- First release
