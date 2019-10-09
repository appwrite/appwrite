# Version 0.3.0 (PRE-RELEASE) - PLANNED

## Features

* Added 7 new locales for locale service and email templates (af, ar, cz, hu, lt, no, ru, si, sv, ta, vi, zh-cn, zh-tw)
* New users service routes to allow updates pref and name update
* 2 stage Docker build
* New database rule validation options
* Update docs example with auth info
* Limit HTTP origin check only to browser integrations
* Allow electron apps to not pass origin header
* New OAuth adapters (Amazon, Dropbox, Microsoft, Slack, VK)
* Added support for ES6 require statements in JS SDK

## Breaking Changs

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

# Version 0.1.15 (NOT-RELEASED)
