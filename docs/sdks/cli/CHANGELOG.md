# Change Log

## 8.1.1

* Type generation fixes:
  * Add ability to generate types directly to a specific file by passing a file path to `appwrite types output_path`, instead of just a directory
  * Fix non-required attributes to not be null if default value is provided
  * Fix `Models` import error
  * Improve formatting and add auto-generated comments

## 8.1.0

* Add multi-region support to `init` command
* Update `init` command to clear previous configuration in `appwrite.json`
* Update localConfig to store multi-region endpoint
* Fix throw error when creating unknown attribute instead of timing out
* Fix equal comparison of large numbers and BigNumber instances using proper equality checks
* Fix duplication of reasons when comparing localConfig with remoteConfig
* Fix `firstOrNull()` to `firstOrNull` in types generation for dart
* Refactor to use `isCloud()` method consistently

## 8.0.2

* Add Type generation fixes:
  * Properly handle enum attributes in dart, java and kotlin
  * Fix initialisation of null attributes in dart's fromMap method
  * Fix relationships and enums in swift

## 8.0.1

* Add `resourceId` and `resourceType` attributes to `createRedirectRule`
* Add `providerReference` to vcs command for getting repository contents
* Add warning comment to `bulk updateDocuments` method
* Fix type generation for enums in Typescript and PHP language

## 8.0.0

* Add `types` command to generate language specific typings for collections. Currently supports - `php`, `swift`, `dart`, `js`, `ts`, `kotlin` and `java`
* Update bulk operation docs to include experiment feature warnings
* Remove assistant service and commands

## 7.0.0

* Add `sites` command
* Add `tokens` command
* Add `devKeys` support to `projects` command
* Add `init site`, `pull site` and `push site` commands
* Add bulk operation methods like `createDocuments`, `deleteDocuments` etc.
* Add new upsert methods: `upsertDocument` and `upsertDocuments`
* Update GET requests to not include content-type header

## 6.2.3

* Fix hot swapping error in `python-ml` function

## 6.2.2

* Fix GitHub builds by adding `qemu-system` package
* Fix attribute creation timed out

## 6.2.1

* Add `listOrganizations` method to `organizations` service and fix init project command

## 6.2.0

* Add specifications support to CLI
* Update package version
* Fix: Missed specifications param when updating a function