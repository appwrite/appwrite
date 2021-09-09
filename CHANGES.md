# Version 0.10.3
- Fixed memory leak in realtime service (#1606)
- Fixed function execution output now being UTF-8 encoded before saved (#1607)

# Version 0.10.2

## Bugs
- Fixed SSL certificates status not being updated (#1592)
- Fixed failing team invites on console (#1580)

# Version 0.10.1

## Bugs
- Improved error messages on Migration regarding invalid document structures (#1576)
- Fixed Console SDK endpoint to work with multiple proxies (#1575)
- Fixed last function environments variables being corrupt (#1577)
- Fixed `_APP_FUNCTIONS_CPUS` variable for cloud functions (#1568)

# Version 0.10.0

## Features
- Added Realtime (#948)
- Added Realtime statistics to the console (#948)
- Added Magic URL login (#1552)
- Refactored E-Mail template (#1422)
- Improved locale management (#1440)
- Added `$permissions` to execution response (#948)
- Switch from using Docker CLI to Docker API by intergrating [utopia-php/orchestration](https://github.com/utopia-php/orchestration) (#1420)
- Added DOCKERHUB_PULL_USERNAME, DOCKERHUB_PULL_PASSWORD and DOCKERHUB_PULL_EMAIL env variables for pulling from private DockerHub repos (#1420)
- Added `updateName`, `updateEmail` and `updatePassword` to Users service and console (#1547)

## Bugs
- Fixed MariaDB timeout after 24 hours (#1510)
- Fixed upgrading installation with customized `docker-compose.yml` file (#1513)
- Fixed usage stats on the dashboard displaying invalid total users count (#1514)

# Version 0.9.4

## Security

- Fixed security vulnerability that exposes project ID's from other admin users (#1453)


# Version 0.9.3

## Bugs

- Fixed Abuse Limit keys for JWT and E-Mail confirmation (#1434)

# Version 0.9.2

## Bugs

- Fixed JWT session validation (#1408)
- Fixed passing valid JWT session to Cloud Functions (#1421)
- Fixed race condition when uploading and extracting bigger Cloud Functions (#1419)

# Version 0.9.1

## Bugs

- Fixed PDO Connection timeout (#1385)
- Removed unnecessary `app` resource and replace with `utopia` (#1384)
- Fixed missing quote in Functions Worker logs (#1375)

# Version 0.9.0

## Features

- Added support for Android
- Added a new Cloud Functions runtime for
  - Java 16.0
  - Java 11.0
  - Node 16.0
  - Dart 2.13
- Added a new gravity option when croping storage images using the file preview endpoint (#1260)
- Upgraded GEOIP DB file to Jun 2021 release (#1256)
- Added file created date to file info on the console (#1183)
- Added file size to file info on the console (#1183)
- Added internal support for connection pools for improved performance (#1278)
- Added new abstraction for workers executable files (#1276)
- Added a new API in the Users API to allow you to force update your user verification status (#1223)
- Using a fixed commit to avoid breaking changes for imagemagick extenstion (#1274)
- Updated the design of all the email templates (#1225)
- Refactored Devices page in Console: (#1167)
  - Renamed *Devices* to *Sessions*
  - Add Provider Icon to each Session
  - Add Anonymous Account Placeholder
- Upgraded phpmailer version to 6.5.0 (#1317)
- Upgraded telegraf docker image version to v1.2.0
- Added new environment variables to the `telegraf` service: (#1202)
  - _APP_INFLUXDB_HOST
  - _APP_INFLUXDB_PORT
- Added `expires` parameter to Account Recovery and Email Validation URL's
- Added new endpoint to get a session based on it's ID (#1294)
- Added added a new version param to the migration script (#1342)
- Improved Queue Interval for all workers from 5 seconds to 1 (#1308 Thanks to @Meldiron)

## Breaking Changes (Read before upgrading!)
- Renamed `env` param on `/v1/functions` to `runtime` (#1314)
- Renamed `deleteUser` method in all SDKs to `delete` (#1216)

## Bugs

- Fixed bug causing runtimes conflict and hanging executions when max Functions containers limit passed (#1288)
- Fixed 404 error when removing a project member on the Appwrite console (#1214)
- Fixed Swoole buffer output size to allow downloading files bigger than allowed size (#1189)
- Fixed ClamAV status when anti virus is not running (#1188)
- Fixed deleteSession which was removing cookieFallback from the localstorage on any logout instead of current session (#1206)
- Fixed Nepal flag (#1173)
- Fixed a bug in the Twitch OAuth adapter (#1209)
- Fixed missing session object when OAuth session creation event is triggered (#1208)
- Fixed bug where we didn't ignore the email case, converted all emails to lowercase internally (#1243)
- Fixed a console bug where you can't click a user with no name, added a placehoder for anonyomous users (#1220)
- Fixed unique keys not being updated when changing a user's email address (#1301)
- Fixed a bug where decimal integers where wrongly used with database filters (#1349)

## Security

- Fixed potential XSS injection on the console

# Version 0.8.0

## Features
- Refactoring SSL generation to work on every request so no domain environment variable is required for SSL generation (#1133)
- Added Anonymous Login ([RFC-010](https://github.com/appwrite/rfc/blob/main/010-anonymous-login.md), #914)
- Added events for functions and executions (#971)
- Added JWT support (#784)
- Added ARM support (#726)
- New awesome image preview features, supports borderRadius, borderColor, borderWidth 
- Split token & session models to become 2 different internal entities (#922)
- Added Dart 2.12 as a new Cloud Functions runtime (#989)
- Added option to disable email/password (#947)
- Added option to disable anonymous login (need to merge and apply changed) (#947)
- Added option to disable JWT auth (#947)
- Added option to disable team invites (#947)
- Option to limit number of users (good for app launches + root account PR) (#947)
- Added 2 new endpoints to the projects API to allow new settings 
- Enabled 501 errors (Not Implemented) from the error handler
- Added Python 3.9 as a new Cloud Functions runtime (#1044)
- Added Deno 1.8 as a new Cloud Functions runtime (#989)
- Upgraded to PHP 8.0 (#713)
- ClamAV is now disabled by default to allow lower min requirements for Appwrite (#1064)
- Added a new env var named `_APP_LOCALE` that allow to change the default `en` locale value (#1056)
- Updated all the console bottom control to be consistent. Dropped the `+` icon (#1062)
- Added Response Models for Documents and Preferences (#1075, #1102)
- Added new endpoint to update team membership roles (#1142)
- Removed DB connection from webhooks worker for improved performance (#1150)

## Bugs

- Fixed default value for HTTPS force option
- Fixed form array casting in dashboard (#1070)
- Fixed collection document rule form in dashboard (#1069)
- Bugs in the Teams API:
  - Fixed incorrect audit worker event names (#1143)
  - Increased limit of memberships fetched in `createTeamMembership` to 2000 (#1143)
  - Fixed exception thrown when SSL certificate is already stored in the database (#1151)
- Fixed user delete button in the Appwrite console (#1216)
- Fixed missing placeholder for user name when empty (#1220)

## Breaking Changes (Read before upgrading!)

- Rename `deleteuser` to `delete` on Users Api (#1089)
- Environment variable `_APP_FUNCTIONS_ENVS` renamed to `_APP_FUNCTIONS_RUNTIMES` (#1101)
- Only logged in users can execute functions (for guests, use anonymous login) (#976)
- Only the user who has triggered the execution get access to the relevant execution logs (#1045)
- Function execution environment variable `APPWRITE_FUNCTION_EVENT_PAYLOAD` renamed to `APPWRITE_FUNCTION_EVENT_DATA`  (#1045)
- Function execution environment variable `APPWRITE_FUNCTION_ENV_NAME` renamed to `APPWRITE_FUNCTION_RUNTIME_NAME` (#1101)
- Function execution environment variable `APPWRITE_FUNCTION_ENV_VERSION` renamed to `APPWRITE_FUNCTION_RUNTIME_VERSION` (#1101)
- Introduces rate limits for:
  - Team invite (10 requests in every 60 minutes per IP address) (#1088)
- Rename param `inviteId` to the more accurate `membershipId` in the Teams API (#1129)

# Version 0.7.2

## Features

- When creating new resources from the client API, the current user gets both read & write permissions by default. (#1007)
- Added timestamp to errors logs on the HTTP API container (#1002)
- Added verbose tests output on the terminal and CI (#1006)

## Upgrades

- Upgraded utopia-php/abuse to version 0.4.0
- Upgraded utopia-php/analytics to version 0.2.0

## Bugs

- Fixed certificates worker error on successful operations (#1010)
- Fixed head requests not responding (#998)
- Fixed bug when using auth credential for the Redis container (#993)
- Fixed server warning logs on 3** redirect endpoints (#1013)

# Version 0.7.1

## Features

- Better error logs on appwrite certificates worker
- Added option for Redis authentication
- Force adding a security email on setup
- SMTP is now disabled by default, no dummy SMTP is included in setup
- Added a new endpoint that returns the server and SDKs latest versions numbers #941
- Custom data strings, userId, and JWT available for cloud functions #967

## Upgrades

- Upgraded redis extenstion lib to version 5.3.3
- Upgraded maxmind extenstion lib to version 1.10.0
- Upgraded utopia-php/cli lib to version 0.10.0
- Upgraded matomo/device-detector lib to version 4.1.0
- Upgraded dragonmantank/cron-expression lib to version 3.1.0
- Upgraded influxdb/influxdb-php lib to version 1.15.2
- Upgraded phpmailer/phpmailer lib to version 6.3.0
- Upgraded adhocore/jwt lib to version 1.1.2
- Upgraded domnikl/statsd to slickdeals/statsd version 3.0
 
## Bug Fixes

- Updated missing storage env vars
- Fixed a bug, that added a wrong timzone offset to user log timestamps
- Fixed a bug, that Response format header was not added in the access-control-allow-header list.
- Fixed a bug where countryName is unknown on sessions (#933)
- Added missing event users.update.prefs (#952)
- Fixed bug not allowing to reset document permissions (#977)

## Security

- Fixed an XSS vulnerability in the Appwrite console

# Version 0.7.0

## Features

- Improved Webhooks and added new system events - [Learn more]()
- Added response to /locale/languages API with a list of languages (@TorstenDittmann ,[#351](https://github.com/appwrite/appwrite/issues/351))
- Added a new route in the Avatars API to get user initials avatar ([#386](https://github.com/appwrite/appwrite/issues/386))
- Added API response payload structure info and examples to the docs site ([#381](https://github.com/appwrite/appwrite/issues/381))
- Added support for Brotli compression (@PedroCisnerosSantana, @Rohitub222, [#310](https://github.com/appwrite/appwrite/issues/310))
- New deletion worker ([#521](https://github.com/appwrite/appwrite/issues/521))
- New maintenance worker - cleaning up system logs and other optimizations ([#766](https://github.com/appwrite/appwrite/pull/766))
- New email worker - all emails are now sent asynchronously for improved performance (@TorstenDittmann ,[#402](https://github.com/appwrite/appwrite/pull/402))
- Moved all Appwrite container logs to STDOUT & STDERR ([#389](https://github.com/appwrite/appwrite/issues/389))
- New Doctor CLI to debug the Appwrite server ([#415](https://github.com/appwrite/appwrite/issues/415))
- Added container names to docker-compose.yml (@drandell)
- Optimised function execution by using fully-qualified function calls
- Added support for boolean 'true' and 'false' in query strings alongside 1 and 0
- Updated storage calculation to match IEC standards
- Now using Alpine as base Docker image
- Switch standard dev ports to 95xx prefix ([#780](https://github.com/appwrite/appwrite/pull/780))
- User & Team name max length is now 128 chars and not 100 for better API consistency
- Collection name max length is now 128 chars and not 256 for better API consistency
- Project name max length is now 128 chars and not 100 for better API consistency
- Webhook name max length is now 128 chars and not 256 for better API consistency
- API Key name max length is now 128 chars and not 256 for better API consistency
- Task name max length is now 128 chars and not 256 for better API consistency
- Platform name max length is now 128 chars and not 256 for better API consistency
- Webhooks payloads are now exactly the same as any of the API response objects, documentation added
- Added new locale: Marathi -mr (@spielers)
- New and consistent response format for all API object + new response examples in the docs
  - Removed user roles attribute from user object (can be fetched from /v1/teams/memberships) **
  - Removed type attribute from session object response (used only internally)
  - ** - might be changed before merging to master
  - Added fallback option to 0.6 format for backward compatibility with any changes (@christyjacob4 [#772](https://github.com/appwrite/appwrite/pull/772))
- Added option to disable mail sending by setting an empty SMTP host value ([#730](https://github.com/appwrite/appwrite/issues/730))
- Upgraded installation script ([#490](https://github.com/appwrite/appwrite/issues/490))
- Added new environment variables for ClamAV hostname and port ([#780](https://github.com/appwrite/appwrite/pull/780))
- New OAuth adapter for Box.com (@armino-dev - [#420](https://github.com/appwrite/appwrite/issues/410))
- New OAuth adapter for PayPal sandbox  (@armino-dev - [#420](https://github.com/appwrite/appwrite/issues/410))
- New OAuth adapter for Tradeshift  (@armino-dev - [#855](https://github.com/appwrite/appwrite/pull/855))
- New OAuth adapter for Tradeshift sandbox  (@armino-dev - [#855](https://github.com/appwrite/appwrite/pull/855))
- Introducing new permssion types: role:guest & role:member
- Disabled rate-limits on server side integrations
- Refactored migration script 

### User Interface

- Updated grid for OAuth2 providers list in the console ([#413](https://github.com/appwrite/appwrite/issues/413))
- Added Google Fonts to Appwrite for offline availability 
- Added option to delete user from the console (@PineappleIOnic - [#538](https://github.com/appwrite/appwrite/issues/538))
- Added option to delete team from the console ([#380](https://github.com/appwrite/appwrite/issues/380))
- Added option to view team members from the console ([#378](https://github.com/appwrite/appwrite/issues/378))
- Add option to assign new team members to a team from the console and the API ([#379](https://github.com/appwrite/appwrite/issues/379))
- Added Select All Checkbox for on Console API key Scopes Screen ([#477](https://github.com/appwrite/appwrite/issues/477))
- Added pagination and search for team memberships route ([#387](https://github.com/appwrite/appwrite/issues/387))
- Added pagination for projects list on the console home page.
- UI performance & accessibility improvements ([#406](https://github.com/appwrite/appwrite/pull/406))
- New UI micro-interactions and CSS fixes (@AnatoleLucet)
- Added toggle to hide/show secret keys and passwords inside the dashboard (@kodumbeats, [#535](https://github.com/appwrite/appwrite/issues/535))

### Upgrades

- Upgraded QR codes generator library (@PedroCisnerosSantana - [#475](https://github.com/appwrite/appwrite/issues/475))
- Upgraded Traefik image to version 2.3
- Upgraded MariaDB to version 10.5.5
- Upgraded Redis Docker image to version 6.0 (alpine)
- Upgraded Influxdb Docker image to version 1.8 (alpine)
- Upgraded Redis Resque queue library to version 1.3.6 ([#319](https://github.com/appwrite/appwrite/issues/319))
- Upgraded ClamAV container image to version 1.0.11 ([#412](https://github.com/appwrite/appwrite/issues/412))
- Upgraded device detctor to version 3.12.6
- Upgraded GEOIP DB file to Feb 2021 release

## Breaking Changes (Read before upgrading!)

- **Deprecated** `first` and `last` query params for documents list route in the database API
- **Deprecated** Deprectaed Pubjabi Translations ('pn')
- **Deprecated** `PATCH /account/prefs` is now updating the prefs payload and not just merging it
- **Deprecated** `PATCH /users/:userId/prefs` is now updating the prefs payload and not just merging it
- Switched order of limit and offset params in all the SDKs `listDocuments` method for better consistency
- Default `limit` param value in all the SDKs `listDocuments` method is now 25 for better consistency

## Bug Fixes

- Fixed a bug that caused blocked users to be able to create sessions ([#777](https://github.com/appwrite/appwrite/pull/781))
- Fixed an issue where Special characters in _APP_OPENSSL_KEY_V1_ env caused an error ([#732](https://github.com/appwrite/appwrite/issues/732))
- Fixed an issue where Account webhook doesn't trigger through the console ([#493](https://github.com/appwrite/appwrite/issues/493))
- Fixed case sensitive country flag code ([#526](https://github.com/appwrite/appwrite/issues/526))
- Fixed redirect to Appwrite login page when deep link is provided ([#427](https://github.com/appwrite/appwrite/issues/427))
- Fixed an issue where Creating documents fails for parent documents would result in an error ([#514](https://github.com/appwrite/appwrite/issues/514))
- Fixed an issue with Email Sending Problem using external smtp ([#707](https://github.com/appwrite/appwrite/issues/707))
- Fixed an issue where you could not remove a key from User Prefs ([#316](https://github.com/appwrite/appwrite/issues/316))
- Fixed an issue where events are not fully visible in the console ([#492](https://github.com/appwrite/appwrite/issues/492))
- Fixed an issue where UI would wrongly validate integers ([#394](https://github.com/appwrite/appwrite/issues/394))
- Fixed an issue where graphs were cut in mobile view ([#376](https://github.com/appwrite/appwrite/issues/376))
- Fixed URL issue where console/ would not display list of projects ([#372](https://github.com/appwrite/appwrite/issues/372))
- Fixed output of /v1/health/queue/certificates returning wrong data
- Fixed bug where team members count was wrong in some cases
- Fixed network calculation for uploaded files
- Fixed a UI bug preventing float values in numeric fields
- Fixed scroll positioning when moving rules order up & down
- Fixed missing validation for database documents key length (32 chars)
- Grammar fix for pt-br email templates (@rubensdemelo)
- Fixed update form labels and tooltips for Flutter Android apps
- Fixed missing custom scopes param for OAuth2 session create API route
- Fixed wrong JSON validation when creating and updating database documents
- Fixed bug where max file size was limited to a max of 10MB
- Fixed bug preventing the deletion of the project logo
- Fixed Bug when trying to overwrite OAuth cookie in the Flutter SDK
- Fixed OAuth redirect when using the self-hosted instance default success URL ([#454](https://github.com/appwrite/appwrite/issues/454))
- Fixed bug denying authentication with Github OAuth provider
- Fixed a bug making read permission overwrite write permission in some cases
- Fixed consistent property names in databases by enforcing camel case

## Security

- Access to Health API now requires authentication with an API Key with access to `health.read` scope allowed
- Added option to force HTTPS connection to the Appwrite server (_APP_OPTIONS_FORCE_HTTPS)
- Now using your `_APP_SYSTEM_EMAIL_ADDRESS` as the email address for issuing and renewing SSL certificates
- Block iframe access to Appwrite console using the `X-Frame-Options` header.
- Fixed `roles` param input validator
- API Keys are now stored encrypted 
- Disabled domains whitlist ACL for the Appwrite console

# Version 0.6.2 (PRE-RELEASE)

## Features

- New OAuth adapter for sign-in with Apple

## Bug Fixes

- Fixed custom domain not setting correct domain
- Fixed wrong SDK method type in avatars browser route 
- Fixed bug denied public documents (*) to be accessed by guest users
- Fixed cache-control issue not allowing collection UI to update properly
- Fixed a bug where single permission tag in the console was not being saved
- Added missing webhooks events in the console
- Added missing option to delete project
- Fixed a bug where the session was not set properly when the API used an IP with a non-standard port as hostname
- Fixed bug where requests number on the dashboard was hidden when the number got too long
- Permission fields are not required for file creation or update

## Security

- [low severity] Patch for email library (https://github.com/advisories/GHSA-f7hx-fqxw-rvvj)

# Version 0.6.1 (PRE-RELEASE)

## Bug Fixes

- Fix for Google OAuth provider not working properly
- Fix for login error when using a remote host with non-default ports
- Removed empty activity tab on the document editor
- Changed upgrade script name to ‘migrate’ to better reflect what it actually does
- Fixed bug where after clicking the cancel option in the confirmation dialog the button got disabled
- Fixed a small grammar error in the documents list screen

# Version 0.6.0 (PRE-RELEASE)

## Features

- New collections UI with ability to create and update a collection
- New documents UI with ability to create and update a document
- Added support for Flutter iOS & Android apps
- Added support for default DB document values
- Exposed health API to all the server SDKs
- New locale for Khmer
- Added TypeScript type hinting to the JS SDK (@zevektor)
- Added LTR/RTL support for markdown editor
- Added cachebuster to version number on footer
- New OAuth logos
- Minor fixes to the dark mode theme
- Added JSON view for a project user
- Removed setKey and setMode methods from all client SDKs

## Breaking Changes

- Updated all the REST API query params to be in camelCase
- Normalized locale phone codes response body

## Bug Fixes

- Fixed project users logout button
- Fixed wrong target in database back link

# Version 0.5.3 (PRE-RELEASE)

## Bug Fixes

- Fixed bug where multiple unique attribute were allowed
- Blocked forms from being submitted unlimited times
  
# Version 0.5.2 (PRE-RELEASE)

## Bug Fixes

- Fixed missing attributes in user account

# Version 0.5.1 (PRE-RELEASE)

## Bug Fixes

- Delayed SSL init when server startup for traefik to be ready for HTTP challenge
- Enabled easy access to the upgrade tool from the terminal

# Version 0.5.0 (PRE-RELEASE)

## Features

- Upgraded core API PHP version to 7.4
- New database rule validation options
- Allow non-web platform to skip origin header
- Limited console dashboard to show max 5 alerts at the same time
- Added more webhooks events
- Normalized all webhooks event names
- Added support for SameSite cookie option with fallback cookie for old clients
- Added a new Discord OAuth adapter
- Added a new Twitch OAuth adapter
- Added a new Spotify OAuth adapter
- Added a new Yahoo OAuth adapter
- Added a new Salesforce OAuth adapter
- Added a new Yandex OAuth adapter
- Added a new Paypal OAuth adapter
- Added a new Bitly OAuth adapter
- Upgraded MariaDB image to version 1.0.2
- Upgraded SMTP image to version 1.0.1
- File upload route (POST /v1/storage/files) now accept a single file per request
- Added ENV vars to change system email sender name and address 
- Usage for requests made by project admin in the console are not counted as API usage
- Added ENV var to change default file upload size limit. New default value is 100MB
- Added option to delete file directly from the dashboard
- Added option to view file preview from the dashboard
- Added option to add custom domains with auto SSL certificate generator

## Bug Fixes

- Fixed bug where user status was saved as a string instead of an integer
- Fixed gravatar icons not showing up correctly on the console
- Fixed code location of project not found error
- Fixed bug where tags element would ignore tab key for parsing new tags
- Fixed OAuth login error saying project UID is missing when its not
- Fixed wrong input validation for user preferences

## Breaking Changes

- Merged Auth and Account service route to make the API REST compatible

# Version 0.4.0 (PRE-RELEASE)

## Features

- Added 5 new locales for locale service and email templates (is, ml, th, fo, ph, pn)
- 2 stage Docker build
- Limit HTTP origin check only to browser integrations
- Updated new Brexit date to 31-01-2020
- Added a version number to sign in and signup pages for easier debugging of issues
- Preparation for adding SameSite cookie option support
- Using native Docker volumes for setup for better cross-platform support and easier management of read/write permissions
- Added support for custom SSL certificates without needing to set a proxy
- Added project UID validation check when making an API call. This should help developers to understand our authentication errors better.
- Updated ClamAV docker image to version 1.0.7
- Updated MariaDB docker image to version 1.0.1
- Core Docker image size reduced to 127MB

## Security

- [PHP-FPM security patch fix](https://bugs.php.net/patch-display.php?bug_id=78599&patch=0001-Fix-bug-78599-env_path_info-underflow-can-lead-to-RC.patch&revision=latest) - Upgraded PHP version to 7.3.12 [Major]
- Remove executable permission from avatars files [Minor]
- Updated SDK Generator Twig dependency with security issue: https://www.exploit-db.com/exploits/44102 [Minor]

## Bug Fixes

- New loading message when creating a new project
- Fixed broken redirect URL when creating a new project
- Fixed broken modal when a user password is too short
- Fixed issue denying the creation of session cookies on localhosts with port other than 80 or 443
- Fixed bug that prevented actual file size calculation
- Fixed MariaDB SQL abuse table time field-type
- Fixed error message not showing up in console failed signup
- Fixed cookie session not being appropriately set when accessing the console from IP hostname

## Breaking Changes

- OAuth path is now /auth/login/oauth instead of /auth/oauth and /auth/oauth/callback is now /auth/login/oauth/callback, this is for better consistency with new login methods we will introduce in the future
- Changed file attribute sizeCompressed to sizeActual to better reflect server logic

# Version 0.3.0 (PRE-RELEASE)

## Features

- Added 19 new locales for locale service and email templates (af, ar, bn, cz, hu, hy, jv, ko, lt, ml, no, ru, si, sq, sv, ta, vi, zh-cn, zh-tw)
- New users service routes to allow updates pref and name update
- New OAuth adapters (Amazon, Dropbox, Microsoft, Slack, VK)
- Added support for ES6 require statements in JS SDK
- New Locale API route for fetching a list of continents

## Bug Fixes
- Fix for typos in PT-BR translations
- Fix for UI crash when project user was missing a name
- Fix for it locale including the en templates by mistake
- Fix for UI not showing user's prefs properly
- Fixed 401 unexpected error when no permission passed in creation of a new resource

## Breaking Changes

- users/deleteUsersSession method name changed to users/deleteUserSession in all SDKs for better consistency

# Version 0.2.0 (PRE-RELEASE)

## Features

- Added option to limit access to the Appwrite console
- Added option to disable abuse check and rate limits
- Added input field with the server API endpoint for easy access
- Added new OAuth providers for Google, Bitbucket, and GitLab
- Added 15 new locales for locale service and email templates (cat, de, es, fi, fr, gr, hi, id, it, nl, pt-br, pt-pt, ro, tr, ua)
- Improved test coverage for the project and synced DEV & CI environments settings

## Bug Fixes

- Fixed bug not allowing to update OAuth providers settings
- Fixed some broken API examples in docs
- Fixed bug that caused the Appwrite container to change DB directory file permissions.

## Breaking Changes

- Changed auth service 'redirect' param to 'confirm' for better clarity
- Updated all SDKs to sync with API changes
