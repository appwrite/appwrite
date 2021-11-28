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
- [Anonymous login](https://appwrite.io/docs/client/account?sdk=flutter#accountCreateAnonymousSession)
- [JWT Support](https://appwrite.io/docs/client/account?sdk=flutter#accountCreateJWT)
- Fallback Cookies for Flutter Web if 3rd party cookies are blocked
- Custom User Agent Support
- [Update membership roles](https://appwrite.io/docs/client/teams?sdk=flutter#teamsUpdateMembershipRoles)
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
- Added per project unique callback for OAuth2 redirects to aviod conflicts between multiple Appwrite projects

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

- Updated deafult params

## 0.0.8

- Fixed compilation error in Client class
- Shorter description for package
