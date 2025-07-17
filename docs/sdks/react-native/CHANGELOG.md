# Change log

## 0.10.1

* Fix URL based methods like `getFileViewURL`, `getFilePreviewURL` etc. by adding the missing `projectId` to searchParams
* Add `gif` to ImageFormat enum

## 0.10.0

* Add generate file URL methods like`getFilePreviewURL`, `getFileViewURL` etc.
* Update (breaking) existing methods like `getFilePreview` to download the image instead of returning URLs

## 0.9.2

* Fix `devKeys` by removing credentials from requests when the key is set

## 0.9.1

* Add `setDevkey` and `upsertDocument` methods

## 0.9.0

* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Update default `quality` for `getFilePreview` from 0 to -1
* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method

## 0.7.4

* Upgrade dependencies to resolve PlatformConstants error with Expo 53
* Update doc examples to use new multi-region endpoint