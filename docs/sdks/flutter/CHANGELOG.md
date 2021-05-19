## 0.6.0

- Upgraded to Null-safety, minimum Dart SDK required 2.12.0
- Upgraded all underlying dependencies to null safe version
- [Anonymous login](https://appwrite.io/docs/client/account?sdk=flutter#accountCreateAnonymousSession)
- [JWT Support](https://appwrite.io/docs/client/account?sdk=flutter#accountCreateJWT)
- Fallback Cookies for Flutter Web if 3rd party cookies are blocked
- Custom User Agent Support
- [Update membership roles](https://appwrite.io/docs/client/teams?sdk=flutter#teamsUpdateMembershipRoles)
- Renamed parameter inviteId to membershipId on teams.updateMembershipStatus, teams.deleteMembership (Breaking Change)

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

- Fixed an error that happend when the OAuth session creation request was sent before any other API call
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