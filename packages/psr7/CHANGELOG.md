# Changelog

All notable changes to this project will be documented in this file.

This project follows semantic versioning.

## [Unreleased]

### Fixed

- `Request\Factory::multipart()` always sets `Content-Type` to `multipart/form-data` with the generated body's `boundary`, even when the caller already supplied `Content-Type`.

### Added

- Initial PSR-7 message implementations and PSR-17 factories extracted from `utopia-php/client`.
