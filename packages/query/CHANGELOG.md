# Changelog

All notable changes to `utopia-php/query` are documented in this file.

## 0.6.2

### Fixed

- PostgreSQL: the keys of a nested object path in a filter on an object
  attribute (`Query::equal('meta.a.b', …)` with attribute type `object`) are
  now written as escaped string literals. A key containing `'` used to end the
  literal early, so the rest of the key was read as SQL; it now names a single
  key, whatever it contains. Keys containing a backslash use the `E''` form, so
  they read the same with `standard_conforming_strings` on or off. A key
  containing a NUL byte throws `ValidationException`.
- PostgreSQL: `setJsonPath()` quotes path segments that are not plain
  identifiers in the `text[]` path it binds, so a segment containing `,`, `{`,
  `}`, `"`, a backslash or whitespace addresses that one key instead of
  splitting into several path elements.
- MongoDB: filters (`equal`, comparisons, `contains`, `isNull`, `exists`, …,
  including filters nested in `and`/`or`) reject an empty field name or one
  starting with `$` with `ValidationException`, as `set()`/`push()` already
  did, so a filter's field can no longer become a query operator such as
  `$where` or `$expr`.
