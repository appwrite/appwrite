# Change Log

## 12.0.1

Fix type generation for `point`, `lineString` and `polygon` columns

## 12.0.0

* Change `create-deployment-template`'s `version` parameter to `type` and `reference`. eg. usage - `create-deployment-template --type tag --reference 1.0.0`
* Remove `bucket-id` parameter from `create-csv-export` command
* Allow enabling or disabling of image `transformations` in a bucket
* Fix type generation for `point`, `lineString` and `polygon` columns

## 11.1.1

* Fix duplicate `enums` during type generation by prefixing them with table name. For example, `enum MyEnum` will now be generated as `enum MyTableMyEnum` to avoid conflicts.

## 11.1.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance

## 11.0.0

* Rename `create-csv-migration` to `create-csv-import` command to create a CSV import of a collection/table
* Add `create-csv-export` command to create a CSV export of a collection/table
* Add `create-resend-provider` and `update-resend-provider` commands to create and update a Resend Email provider
* Fix syncing of tables deleted locally during `push tables` command
* Fix added push command support for cli spatial types
* Fix attribute changing during push
* Replace pkg with @yao-pkg/pkg in dependencies

## 10.2.3

* Fix `init tables` command not working
* Improve tablesDB resource syncing during `push tables` command

## 10.2.2

* Fix `logout` command showing duplicate sessions
* Fix `logout` command showing a blank email even when logged out
* Add syncing of `tablesDB` resource during `push tables` command

## 10.2.1

* Add transaction support for Databases and TablesDB

## 10.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 10.0.1

* Fix CLI Dart model generation issues
* Fix row permissions and security sync
* Fix error when pushing columns with relationships
* Fix resource name from attributes to columns for TablesDB indexes

## 10.0.0

* **Breaking:** Removed Avatars CLI command and all related subcommands; corresponding examples deleted
* **Feat:** Geo defaults now accept coordinate arrays for Databases and Tables DB, with automatic normalization
* **Feat:** Pull command skips deprecated resources by default and shows clearer totals/messages
* **Feat:** Updated CLI descriptions: Databases marked legacy; added tables-db, projects, and project
* Fix TypeScript type generation now quotes invalid property names to produce valid typings
* Update documentation: Removed Avatars CLI examples and updated help text to reflect new geo defaults and terminology

## 8.3.0

* **Feat:** Add support for `appwrite.config.json` file
  * All new projects will be initialized with this configuration file
  * Resolves bundler conflicts (e.g., Vite) that incorrectly interpret `.json` files as library imports
* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Type generation fixes:
  * Fix relationships using the relatedCollection's id instead of name
  * Update auto generated comment to show relative path instead of absolute path

> **Note:** The existing `appwrite.json` file remains fully supported for backward compatibility

## 8.2.2

* Fix object comparison logic when pushing settings
* Type generation fixes:
   * Dart: Fixed import casing to snake_case, removed `extends Document` and hardcoded attributes, removed unnecessary imports
   * Java: Fixed indentation to 4 spaces, updated imports to `java.util.Objects`, fixed enum casing in strict mode as per [Oracle official docs](https://docs.oracle.com/javase/tutorial/java/javaOO/enum.html)
   * Javascript: Updated optional values formatting from `|null` to `| null`
   * Kotlin: Fixed indentation to 4 spaces per [Kotlinlang official docs](https://kotlinlang.org/docs/coding-conventions.html#indentation)
   * PHP: Fixed indentation to 4 spaces per [PHP Fig official docs](https://www.php-fig.org/psr/psr-2/)
   * Swift: Fixed indentation to 4 spaces, improved `decodeIfPresent` usage for optionals, added missing `public` to `init` method
   * Typescript: Fixed indentation to 4 spaces per [Typescript coding guidelines](https://github.com/microsoft/TypeScript/wiki/Coding-guidelines)

## 8.2.1

* Added `--with-variables` option to the Sites command for adding/updating environment variables  
* Fixed Functions environment variables not being pushed with `--with-variables`  
* Removed `awaitPools` when wiping old variables  

> **Note:** Storing environment variables in the `vars` attribute of `appwrite.json` is now deprecated due to security risks. Variables are now synced directly from the `.env` file in the root directory of the function’s or site’s folder.

## 8.2.0

* Add `encrypt` attribute support
* Add improved warnings on attribute recreation and deletion
* Fix `null` parsing error when using create attribute command
* Type generation fixes and improvements:
  * Add `--strict` / `-s` flag to `appwrite types` command to generate types in strict mode. This automatically converts the casing of attributes to match the language's naming conventions
  * Add automatic package import to `dart` language which uses package detection to import the correct package
  * Add `Document` class extension to generated types in `dart` and `js` language to support internal attributes like `$id` and `$collectionId` etc.
  * Add proper enum support to `js` language
  * Fix indentation in `java`, `kotlin` and `swift` to use 2 spaces instead of 4 for consistency across all languages
  * Fix doc comments to use correct syntax in various languages (for eg. `///` instead of `/*`)
  * Update enums in `dart` to use lowerCamelCase in `strict` mode as per [constant_identifier_names](https://dart.dev/tools/diagnostics/constant_identifier_names?utm_source=dartdev&utm_medium=redir&utm_id=diagcode&utm_content=constant_identifier_names)

## 8.1.1

* Fix circular dependency issue due to usage of `success` method in `utils.js` file from `parser.js` file
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