# Changelog

## Unreleased

### Added

- Mobile app detection: `Utopia\Detector\Detector\Mobile` with the `Flutter`,
  `ReactNative`, `Android` and `Ios` options under `Utopia\Detector\Detection\Mobile`.
  Inputs are file names (`Mobile::INPUT_FILE`), `package.json` or `pubspec.yaml`
  content (`Mobile::INPUT_PACKAGES`) and Gradle script or version catalog content
  (`Mobile::INPUT_GRADLE`). Each detection reports the platforms it builds for
  through `getPlatforms()`. Like every `Utopia\Detector` class, they are registered by the
  `utopia` extension, over `crates/utopia/detector`'s `mobile` module.
