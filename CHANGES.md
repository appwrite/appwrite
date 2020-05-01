# Version 0.6.0 (PRE-RELEASE)

## Features

* Added support for Flutter iOS & Android apps
* New locale for Khmer
* Added TypeScript type hinting to the JS SDK (@zevektor)

## Breaking Changes

* Updated all the REST API query params to be in camelCase
* Normalized locale phone codes response body

## Bug Fixes

* Fixed project users logout button

# Version 0.5.3 (PRE-RELEASE)

## Bug Fixes

* Fixed bug where multiple unique attribute were allowed
* Blocked forms from being submitted unlimited times
  
# Version 0.5.2 (PRE-RELEASE)

## Bug Fixes

* Fixed missing attributes in user account

# Version 0.5.1 (PRE-RELEASE)

## Bug Fixes

* Delayed SSL init when server startup for traefik to be ready for HTTP challenge
* Enabled easy access to the upgrade tool from the terminal

# Version 0.5.0 (PRE-RELEASE)

## Features

* Upgraded core API PHP version to 7.4
* New database rule validation options
* Allow non-web platform to skip origin header
* Limited console dashboard to show max 5 alerts at the same time
* Added more webhooks events
* Normailized all webhooks event names
* Added support for SameSite cookie option with fallback cookie for old clients
* Added a new Discord OAuth adapter
* Added a new Twitch OAuth adapter
* Added a new Spotify OAuth adapter
* Added a new Yahoo OAuth adapter
* Added a new Salesforce OAuth adapter
* Added a new Yandex OAuth adapter
* Added a new Paypal OAuth adapter
* Added a new Bitly OAuth adapter
* Upgraded MariaDB image to version 1.0.2
* Upgraded SMTP image to version 1.0.1
* File upload route (POST /v1/storage/files) now accept a single file per request
* Added ENV vars to change system email sender name and address 
* Usage for requests made by project admin in the console are not counted as API usage
* Added ENV var to change default file upload size limit. New default value is 100MB
* Added option to delete file directly from the dashboard
* Added option to view file preview from the dashboard
* Added option to add custom domains with auto SSL certificate generator

## Bug Fixes

* Fixed bug where user status was saved as a string instead of an integer
* Fixed gravatar icons not showing up correctly on the console
* Fixed code location of project not found error
* Fixed bug where tags element would ignore tab key for parsing new tags
* Fixed OAuth login error saying project UID is missing when its not
* Fixed wrong input validation for user preferences

## Breaking Changes

* Merged Auth and Account service route to make the API REST compatible

# Version 0.4.0 (PRE-RELEASE)

## Features

* Added 5 new locales for locale service and email templates (is, ml, th, fo, ph, pn)
* 2 stage Docker build
* Limit HTTP origin check only to browser integrations
* Updated new Brexit date to 31-01-2020
* Added a version number to sign in and signup pages for easier debugging of issues
* Preparation for adding SameSite cookie option support
* Using native Docker volumes for setup for better cross-platform support and easier management of read/write permissions
* Added support for custom SSL certificates without needing to set a proxy
* Added project UID validation check when making an API call. This should help developers to understand our authentication errors better.
* Updated ClamAV docker image to version 1.0.7
* Updated MariaDB docker image to version 1.0.1
* Core Docker image size reduced to 127MB

## Security

* [PHP-FPM security patch fix](https://bugs.php.net/patch-display.php?bug_id=78599&patch=0001-Fix-bug-78599-env_path_info-underflow-can-lead-to-RC.patch&revision=latest) - Upgraded PHP version to 7.3.12 [Major]
* Remove executable permission from avatars files [Minor]
* Updated SDK Generator Twig dependency with security issue: https://www.exploit-db.com/exploits/44102 [Minor]

## Bug Fixes

* New loading message when creating a new project
* Fixed broken redirect URL when creating a new project
* Fixed broken modal when a user password is too short
* Fixed issue denying the creation of session cookies on localhosts with port other than 80 or 443
* Fixed bug that prevented actual file size calculation
* Fixed MariaDB SQL abuse table time field-type
* Fixed error message not showing up in console failed signup
* Fixed cookie session not being appropriately set when accessing the console from IP hostname

## Breaking Changes

* OAuth path is now /auth/login/oauth instead of /auth/oauth and /auth/oauth/callback is now /auth/login/oauth/callback, this is for better consistency with new login methods we will introduce in the future
* Changed file attribute sizeCompressed to sizeActual to better reflect server logic

# Version 0.3.0 (PRE-RELEASE)

## Features

* Added 19 new locales for locale service and email templates (af, ar, bn, cz, hu, hy, jv, ko, lt, ml, no, ru, si, sq, sv, ta, vi, zh-cn, zh-tw)
* New users service routes to allow updates pref and name update
* New OAuth adapters (Amazon, Dropbox, Microsoft, Slack, VK)
* Added support for ES6 require statements in JS SDK
* New Locale API route for fetching a list of continents

## Bug Fixes
* Fix for typos in PT-BR translations
* Fix for UI crash when project user was missing a name
* Fix for it locale including the en templates by mistake
* Fix for UI not showing user's prefs properly
* Fixed 401 unexpected error when no permission passed in creation of a new resource

## Breaking Changes

* users/deleteUsersSession method name changed to users/deleteUserSession in all SDKs for better consistency

# Version 0.2.0 (PRE-RELEASE)

## Features

* Added option to limit access to the Appwrite console
* Added option to disable abuse check and rate limits
* Added input field with the server API endpoint for easy access
* Added new OAuth providers for Google, Bitbucket, and GitLab
* Added 15 new locales for locale service and email templates (cat, de, es, fi, fr, gr, hi, id, it, nl, pt-br, pt-pt, ro, tr, ua)
* Improved test coverage for the project and synced DEV & CI environments settings

## Bug Fixes

* Fixed bug not allowing to update OAuth providers settings
* Fixed some broken API examples in docs
* Fixed bug that caused the Appwrite container to change DB directory file permissions.

## Breaking Changes

* Changed auth service 'redirect' param to 'confirm' for better clarity
* Updated all SDKs to sync with API changes
