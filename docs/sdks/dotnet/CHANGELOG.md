# Change Log

## 0.14.0

* Refactor from Newtonsoft.Json to System.Text.Json for serialization/deserialization
* Update package dependencies in `Package.csproj.twig`
* Migrate all serialization/deserialization logic in `Client.cs.twig`, `Query.cs.twig`, and `Extensions.cs.twig`
* Update model attributes from `[JsonProperty]` to `[JsonPropertyName]` in `Model.cs.twig`
* Create new `ObjectToInferredTypesConverter.cs.twig` for proper object type handling
* Replace `JsonConverter` with `JsonConverter<object>` in `ValueClassConverter.cs.twig`
* Update error handling to use `JsonDocument` instead of `JObject`

## 0.13.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 0.12.0

* fix: remove content-type from GET requests by @loks0n in https://github.com/appwrite/sdk-for-dotnet/pull/59
* update: min and max are not optional in methods like `UpdateIntegerAttribute` etc.
* chore: regenerate sdk by @ChiragAgg5k in https://github.com/appwrite/sdk-for-dotnet/pull/60
* chore: fix build error by @ChiragAgg5k in https://github.com/appwrite/sdk-for-dotnet/pull/61

## 0.11.0

* Add new push message parameters by @abnegate in https://github.com/appwrite/sdk-for-dotnet/pull/56

## 0.10.0

* fix: chunk upload by @byawitz in https://github.com/appwrite/sdk-for-dotnet/pull/52

## 0.9.0

* Support for Appwrite 1.6
* Added `key` attribute to `Runtime` response model.
* Added `buildSize` attribute to `Deployments` response model.
* Added `scheduledAt` attribute to `Executions` response model.
* Added `scopes` attribute to `Functions` response model.
* Added `specifications` attribute to `Functions` response model.
* Added new response model for `Specifications`.
* Added new response model for `Builds`.
* Added `createJWT()` : Enables creating a JWT using the `userId`.
* Added `listSpecifications()`: Enables listing available runtime specifications.
* Added `deleteExecution()` : Enables deleting executions.
* Added `updateDeploymentBuild()`: Enables cancelling a deployment.
* Added `scheduledAt` parameter to `createExecution()`: Enables creating a delayed execution

#### Breaking changes
You can find the new syntax for breaking changes in the [Appwrite API references](https://appwrite.io/docs/references). Select version `1.6.x`.
* Removed `otp` parameter from `deleteMFAAuthenticator`.
* Added `scopes` parameter for create/update function.
* Renamed `templateBranch` to `templateVersion`  in `createFunction()`.
* Renamed `downloadDeployment()` to `getDeploymentDownload()`

> **Please note: This version is compatible with Appwrite 1.6 and later only. If you do not update your Appwrite SDK, old SDKs will not break your app. Appwrite APIs are backwards compatible.**
