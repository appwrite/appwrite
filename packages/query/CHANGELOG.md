# Changelog

All notable changes to `utopia-php/query` are documented in this file.

## 0.7.0

### Breaking

- Joins have a single form with a required alias:
  `join|leftJoin|rightJoin|fullOuterJoin(string $collection, string $alias, array $on)`
  and `crossJoin|naturalJoin(string $collection, string $alias)`. The column
  form (`join($table, $left, $right, $operator, $alias)`) and the alias-less
  and alias-last overloads are gone. An empty alias throws
  `ValidationException`.
- The ON list is validated when the join is built, against the same set the
  builders compile (`Method::isJoinCondition()`, new): `on()`, the
  comparisons, `between`, null checks, `contains`/`containsAny`/`notContains`,
  the `startsWith`/`endsWith` family, and `and`/`or` of those. Anything else
  (`limit()`, `select()`, ordering, cursors, aggregates, joins, `search()`,
  `regex()`, spatial, JSON, `raw()`, JSON strings) throws `ValidationException`
  (`Unsupported join ON condition: <method>`), at any depth.
- The alias is a property: `Query::getAlias()` replaces `getJoinAlias()`, and
  aggregates (`count('*', 'total')`, …) keep it there instead of in
  `values[0]`. `toArray()` writes it as `"alias"`, and `new Query()` takes it
  as a fourth argument, so subclasses that override the constructor should
  pass it on.
- `cursorAfter()`/`cursorBefore()` take `array|object` (the row a page starts
  after or ends before), on `Query` and on `Builder\Feature\Selects`. The
  builders bind the row's `_cursor` value and throw `ValidationException`
  when it has none; a scalar cursor parsed from JSON still binds as-is.
- Protected builder hooks lose their abbreviation: `compileSearchExpr`,
  `compileJsonContainsExpr`, `compileJsonOverlapsExpr`, `compileJsonPathExpr`
  and `compileVectorOrderExpr` are now `compile*Expression`. Subclasses that
  override them must rename their overrides; an un-renamed override of an
  abstract method fails to load, and one of `compileVectorOrderExpression()`
  is silently no longer called.

### Fixed

- `join($table, [$conditions], $alias)` dropped the alias, and an ON list
  accepted members such as `limit()` that only failed, or were ignored, when
  the query was compiled (BUG-05). Both are rejected or impossible now.
- The MongoDB builder reads joins built by the `Query` factories: a single
  `on()` equality becomes the `$lookup`, under the join's alias. Filtered ON
  lists throw `UnsupportedException` instead of producing a broken stage.

### Upgrading from 0.6

| 0.6 | 0.7 |
|---|---|
| `Query::join('orders', 'users.id', 'orders.user_id')` | `Query::join('orders', 'orders', [Query::on('users.id', 'orders.user_id')])` |
| `Query::leftJoin('orders', 'u.id', 'o.uid', '!=', 'o')` | `Query::leftJoin('orders', 'o', [Query::on('u.id', 'o.uid', '!=')])` |
| `Query::join('orders', [$on], 'o')` / `Query::join('orders', 'o', [$on])` | `Query::join('orders', 'o', [$on])` |
| `Query::crossJoin('colors')` | `Query::crossJoin('colors', 'colors')` |
| `$join->getJoinAlias()` | `$join->getAlias()` |
| `$aggregate->getValue('')` for the alias | `$aggregate->getAlias()` |
| `Query::cursorAfter('id')` | `Query::cursorAfter(['_cursor' => 'id'])` for the bundled builders, or the row your own compiler pages on (a document, `['id' => …]`) |
| `$builder->cursorAfter('value')` | `$builder->cursorAfter(['_cursor' => 'value'])` |
| `protected function compileSearchExpr(...)` | `protected function compileSearchExpression(...)` (likewise for the other four) |

Serialised 0.6 queries still parse: `parseQuery()` reads `"alias"` and falls
back to `values[0]` for aggregates, cross/natural joins and alias-first joins,
and to `values[3]` for column-form joins, which become a single `on()`
condition. A join that ends up with no alias throws `ValidationException`.

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
