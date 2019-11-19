# Version 0.4.0 (PRE-RELEASE) - PLANNED

## Features

* Added 5 new locales for locale service and email templates (is, ml, th, fo, ph, pn)
* 2 stage Docker build
* New database rule validation options
* Update docs example with auth info
* Limit HTTP origin check only to browser integrations
* Allow electron apps to not pass origin header
* Updated new brexit date to 31-01-2020
* Added a version number to signin and signup pages for easier debugging of issues
* Perperation for adding SameSite cookie option support
* Using native Docker volumes for setup for better cross-platform support and easier management of read/write permissions
* Added support for custom SSL certificates without needing to set a proxy

## Security

* Remove executable permission from avatars files [Minor]
* Updated SDK Generator Twig dependency with security issue: https://www.exploit-db.com/exploits/44102 [Minor]

## Bug Fixes

* New loading message when creating a new project
* Fixed broken redirect URL when creating a new project
* Fixed broken modal when a user password is too short
* Fixed issue denying the creation of seesion cookies on localhosts with port other than 80 or 443
* Fixed bug that prevented actual file size calculation

## Breaking Changes

* OAuth path is now /auth/login/oauth instead of /auth/oauth and /auth/oauth/callback is now /auth/login/oauth/callback, this is for better consistency with new login methods we will introduce in the future
* Changed file attribute sizeCompressed to sizeActual to better reflect server logic

# Version 0.3.0 (PRE-RELEASE)

## Features

* Added 19 new locales for locale service and email templates (af, ar, bn, cz, hu, hy, jv, ko, lt, ml, no, ru, si, sq, sv, ta, vi, zh-cn, zh-tw)
* New users service routes to allow updates pref and name update
* New OAuth adapters (Amazon, Dropbox, Microsoft, Slack, VK)
* Added support for ES6 require statements in JS SDK
* New Locale API route for fetching list of continents

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
* Added new OAuth providers for Google, Bitbucket and GitLab
* Added 15 new locales for locale service and email templates (cat, de, es, fi, fr, gr, hi, id, it, nl, pt-br, pt-pt, ro, tr, ua)
* Improved test coverage for the project and synced DEV & CI environments settings

## Bug Fixes

* Fixed bug not allowing to update OAuth providers settings
* Fixed some broken API examples in docs
* Fixed bug that caused the Appwrite container to change DB directory file permissions.

## Breaking Changes

* Changed auth service 'redirect' param to 'confirm' for better clarity
* Updated all SDKs to sync with API changes
