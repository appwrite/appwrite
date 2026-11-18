# Changelog

## 4.0.0 (unreleased)

### Breaking changes

- Requires utopia-php/database `^10.2` instead of `^7.0.0`, which brings utopia-php/cache `^8.0`. Pass a database 10 `Utopia\Database\Database` to `TimeLimit\Database`; `getLogs()` returns database 10 `Document`s. PHP `>=8.5` is unchanged from 3.1.0.
- Requires appwrite/appwrite `^32.2.0` instead of `^27.1`, the SDK generation appwrite/appwrite itself runs this package against. `TimeLimit\Appwrite\TablesDB` takes an `Appwrite\Client`, so a caller still on a 27.x client upgrades with it. The adapter's own calls are unchanged: the `TablesDB` service, `Query`, `ID`, `TablesDBIndexType` and the `Row` model it reads all keep their signatures, and the SDK's tightened requirements (PHP `>=8.5`, `psr/http-client` `^1.0`, `utopia-php/client` `^0.6`) are ones abuse already carries.
- `TimeLimit` declares `abstract protected function unhit(string $key, int $window): void`, so a custom `TimeLimit` subclass must implement it. Every bundled adapter does.
- `TimeLimit\Database::ATTRIBUTES` and `TimeLimit\Database::INDEXES` are removed. `TimeLimit\Database::attributes()` and `TimeLimit\Database::indexes()` return the same schema as `Utopia\Database\Attribute` and `Utopia\Database\Index` objects, and `setup()` creates the collection from them. The schema is unchanged, so an existing `abuse` collection needs no migration.

### Added

- `TimeLimit::release()` takes back one hit from the current window, never below zero, for a request that reserved a hit with `check()` and turned out not to count.

### Changed

- `TimeLimit\Database` and `TimeLimit\Appwrite\TablesDB` cap the counter at the limit atomically (`increaseDocumentAttribute` / `incrementRowColumn` with a max), so concurrent `check()` calls no longer read under the limit and all pass. `TablesDB` needs an Appwrite server that accepts the `max` and `min` arguments of the row increment and decrement endpoints.
- The SDK client no longer follows redirects, so an `APPWRITE_ENDPOINT` that redirects (an `http://` endpoint on a host that forces `https://`, say) now fails the call instead of being followed with the API key attached. Point `Client::setEndpoint()` at the final URL.
- abuse does not require utopia-php/query directly; the query library arrives through utopia-php/database.
- `Adapter::check()` and `Adapter::peek()` are declared `@phpstan-impure`, so static analysis no longer assumes repeated calls return the same `Result`.
- `minimum-stability` is `stable` again: every requirement resolves to a stable release.

### Tests

- `DatabaseSchemaTest` covers `TimeLimit\Database` against database 10: hits counted per key and window, one row per key and window, `cleanup()` removing only earlier windows, and `setup()` running again without losing counts.
- `TablesDBTest` covers `TimeLimit\Appwrite\TablesDB::setup()` against a recording client: the table is created with its columns and indexes inline and nothing is created one by one or polled, and a table left behind by an earlier run is repaired through the per-type column endpoints with the same schema, its index types round tripping through the SDK's `TablesDBIndexType`. The same requests go out on appwrite/appwrite 27.1 and 32.2.
- The Appwrite `TablesDB` end-to-end fixture deletes only the database it created, cleans up after a failed setup, keeps ownership for a retry when cleanup fails, and reports both failures when setup and cleanup fail together (`TablesDBFixtureTest`).
- `DiscoveryTest` checks that the configured end-to-end discovery includes the backend and lifecycle cases.
