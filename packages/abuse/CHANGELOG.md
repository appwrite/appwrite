# Changelog

## 4.0.0 (unreleased)

### Breaking changes

- Requires utopia-php/database `^8.0` instead of `^7.0.0`. Pass a database 8 `Utopia\Database\Database` to `TimeLimit\Database`; `getLogs()` returns database 8 `Document`s. PHP `>=8.5` is unchanged from 3.1.0.
- `TimeLimit\Database::ATTRIBUTES` and `TimeLimit\Database::INDEXES` are removed. `TimeLimit\Database::attributes()` and `TimeLimit\Database::indexes()` return the same schema as `Utopia\Database\Attribute` and `Utopia\Database\Index` objects, and `setup()` creates the collection from them. The schema is unchanged, so an existing `abuse` collection needs no migration.

### Changed

- abuse does not require utopia-php/query directly; the query library arrives through utopia-php/database 8.
- `Adapter::check()` and `Adapter::peek()` are declared `@phpstan-impure`, so static analysis no longer assumes repeated calls return the same `Result`.
- `minimum-stability` is `dev` with `prefer-stable: true` until utopia-php/database 8.0.0 is tagged; it returns to `stable` with the tag.

### Tests

- `DatabaseSchemaTest` covers `TimeLimit\Database` against database 8: hits counted per key and window, one row per key and window, `cleanup()` removing only earlier windows, and `setup()` running again without losing counts.
- The Appwrite `TablesDB` end-to-end fixture deletes only the database it created, cleans up after a failed setup, keeps ownership for a retry when cleanup fails, and reports both failures when setup and cleanup fail together (`TablesDBFixtureTest`).
- `DiscoveryTest` checks that the configured end-to-end discovery includes the backend and lifecycle cases.
