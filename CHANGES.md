# Version 0.8.0 (Not Released Yet)

## Features

- Added Anonymous Login ([RFC-010](https://github.com/appwrite/rfc/blob/main/010-anonymous-login.md), #914)
- Added new Environment Variable to enable or disable Anonymous Login 
- Added events for functions and executions (#971)
- Splited token & session models to become 2 different internal entities (#922)
- Added Dart 2.12 as a new Cloud Functions runtime (#989)
- Updated all the console bottom control to be consistent. Dropped the `+` icon
## Bugs

- Fixed default value for HTTPS force option

## Breaking Changes

- Only logged in users can execute functions (for guests, use anonymous login)
- Only the user who has triggered the execution get access to the relevant execution logs
- Function execution env `APPWRITE_FUNCTION_EVENT_PAYLOAD` renamed to `APPWRITE_FUNCTION_EVENT_DATA`

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
