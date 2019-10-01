# Version 0.2.0 (PRE-RELEASE)

## Features

* Added option to limit access to the Appwrite console
* Added option to disable abuse check and rate limits
* Added input field with the server API endpoint for easy access
* Added new OAuth providers for Google and Gitlab
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