# Changelog

## 3.0.0 (unreleased)

### Breaking changes

- Requires utopia-php/database `^8.0`. Pass a database 8 `Utopia\Database\Database` to `TimeLimit\Database`; `getLogs()` returns database 8 `Document`s.
- Adds a direct requirement on utopia-php/query `^0.6`, the query library database 8 builds on.
- Requires PHP 8.5 or later, the floor utopia-php/database 8 sets. abuse 2.x declared PHP 8.4.1 but could not be installed on PHP 8.4 either, because every utopia-php/database 7 release requires PHP 8.5.
- `TimeLimit\Database::ATTRIBUTES` and `TimeLimit\Database::INDEXES` are removed. `TimeLimit\Database::attributes()` and `TimeLimit\Database::indexes()` return the same schema as `Utopia\Database\Attribute` and `Utopia\Database\Index` objects, and `setup()` creates the collection from them. The schema is unchanged, so an existing `abuse` collection needs no migration.

### Dependencies

- `utopia-php/database` is required as `^8.0` instead of a development branch pinned through an inline alias, and the `repositories` block is gone. Before 8.0.0 is tagged the constraint is served by the database branch's own `8.0.x-dev` alias. A `repositories` entry is honoured only in the root package, so consumers could not resolve the previous requirement without replicating it themselves.
- `minimum-stability` is `dev` with `prefer-stable: true` until utopia-php/database 8.0.0 is tagged; it returns to `stable` with the tag.
- Adds `extra.branch-alias` mapping `dev-feat-query-lib` and `dev-main` to `3.0.x-dev`.
