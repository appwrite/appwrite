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
- [Update membership roles](https://appwrite.io/docs/client/teams?sdk=dart#teamsUpdateMembershipRoles)
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