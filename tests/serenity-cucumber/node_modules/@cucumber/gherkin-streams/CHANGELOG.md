# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## [5.0.1] - 2022-03-31

### Changed

- Peer dependencies are now more permissive and simply request any version greater than:
  - @cucumber/gherkin: >=22.0.0
  - @cucumber/messages: >=17.1.1
  - @cucumber/message-streams: >=4.0.0

## [5.0.0] - 2022-03-07

### Changed

- `@cucumber/gherkin`, `@cucumber/messages` and `@cucumber/message-streams` are now
  peer dependencies. You now have to add `@cucumber/gherkin` in your dependencies:
  ```diff
  {
    "dependencies": {
  +   "@cucumber/gherkin": "22.0.0",
      "@cucumber/gherkin-streams": "5.0.0",
    }
  }
  ```
  ([PR#5](https://github.com/cucumber/gherkin-streams/pull/5))

## [4.0.0] - 2021-09-01
### Changed
- Upgrade to `@cucumber/messages` `17.1.0`
- Upgrade to `@cucumber/gherkin` `21.0.0`

## [3.0.0] - 2021-07-08
### Changed
- Upgrade to `@cucumber/messages` `17.0.0`
- Upgrade to `@cucumber/gherkin` `20.0.0`

## [2.0.2] - 2021-05-17
### Changed
- Upgrade to `@cucumber/message-streams` `2.0.0`

## [2.0.1] - 2021-05-17
### Fixed
- Use `^x.y.z` version for `@cucumber/*` dependencies, allowing minor and patch releases to be picked up.

## [2.0.0] - 2021-05-15
### Added
- Add ability to specify a `relativeTo` path for cleaner emitted `uri`s [#1510](https://github.com/cucumber/cucumber/pull/1510)

### Changed
- Upgrade to gherkin 19.0.0

## [1.0.0] - 2021-03-24

[Unreleased]: https://github.com/cucumber/gherkin-streams/compare/v5.0.1...main
[5.0.1]: https://github.com/cucumber/gherkin-streams/compare/v5.0.0...v5.0.1
[5.0.0]: https://github.com/cucumber/gherkin-streams/compare/v4.0.0...v5.0.0
[4.0.0]: https://github.com/cucumber/gherkin-streams/releases/tag/v3.0.0
[3.0.0]: https://github.com/cucumber/gherkin-streams/releases/tag/v2.0.2
[2.0.2]: https://github.com/cucumber/gherkin-streams/releases/tag/v2.0.1
[2.0.1]: https://github.com/cucumber/gherkin-streams/releases/tag/v2.0.0
[2.0.0]: https://github.com/cucumber/gherkin-streams/releases/tag/v1.0.0
[1.0.0]: https://github.com/cucumber/gherkin-streams/releases/tag/v1.0.0

<!-- Contributors in alphabetical order -->
