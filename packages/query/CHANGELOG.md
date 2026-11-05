# Changelog

All notable changes to `utopia-php/query` are documented in this file.

## 0.7.3

### Changed

- String literals in `toRawSql()`, the schema builder and the AST serializer
  are quoted by the new `StringLiteral` enum, one case per dialect:
  `Backslash` (MySQL, MariaDB), `Extended` (PostgreSQL), `Standard` (SQLite)
  and `BackslashUtf8` (ClickHouse). `unquote()` reads a literal back. MySQL,
  MariaDB and PostgreSQL throw `ValidationException` for a value with a
  backslash right after a byte of `0x80` or above, which a GBK, BIG5,
  SJIS/CP932 or GB18030 connection reads as the lead byte of a character that
  absorbs the escaping backslash. PostgreSQL and SQLite throw for a NUL byte.
  The ClickHouse schema builder's `Enum8` values double quotes like its other
  literals instead of escaping them with a backslash.
  The MySQL and MariaDB literals assume the server's default `sql_mode`:
  under `NO_BACKSLASH_ESCAPES` a backslash is an ordinary character, so a
  comment or default `C:\x` is stored as `C:\\x`. Doubled quotes hold in both
  modes, so a value still cannot close its literal.
- `Tokenizer` and `AST\Parser` take a `StringLiteral`; each dialect tokenizer
  defaults to its dialect's case and `Parser` to `Backslash`. The SQLite
  tokenizer also reads backtick-quoted identifiers, which its builder emits.
- MySQL and MariaDB LIKE predicates compile to `col LIKE ? ESCAPE '!'` (and
  `NOT LIKE`) with `!`-escaped bindings, SQLite's to `col LIKE ? ESCAPE '\'`,
  and MySQL's `setJsonRemove()` to `JSON_SEARCH(col, 'one', ?, '!')`.
  Statement text and bindings matched in tests or caches change accordingly.

### Fixed

- MySQL and MariaDB `setJsonRemove()` leaves the column unchanged when the
  value is not in the array. `JSON_SEARCH()` returned NULL for a missing value,
  and for any non-string value on MySQL, and `JSON_REMOVE()` with a NULL path
  set the whole column to NULL, or failed on a `NOT NULL` column in strict
  mode. The statement is now wrapped in `COALESCE(..., col)`.
- Raw conditions are enclosed in parentheses where they are ANDed with other
  conditions: `Query::raw()` filters, `whereRaw()` in SELECT, UPDATE and
  DELETE, `havingRaw()`, `JoinBuilder::whereRaw()` and `onRaw()`, the
  PostgreSQL builder's `updateFromWhere()` and `deleteUsing()`, and `Raw`
  operands of AND in the AST serializer (`toAst()`, `FilterInjector`). AND
  binds tighter than OR, so an OR inside one escaped the conditions beside
  it, including those filter and join-filter hooks add: `whereRaw('a = ? OR
  b = ?')` with `Hook\Filter\Tenant` compiled to `tenant_id IN (?) AND a = ?
  OR b = ?` and read, updated and deleted other tenants' rows. Generated SQL
  now carries the parentheses; an empty `Query::raw()` still compiles to
  `1 = 1`. A hook's own condition is used as returned, so a hook returning an
  OR must enclose it.
- `updateFromWhere()` and `deleteUsing()` bound their condition's values
  ahead of the WHERE clause's, though the condition follows it in the SQL, so
  with a filter or hook the values reached the wrong placeholders.
- `toRawSql()` escapes backslashes as well as quotes, as the AST serializer and
  the schema builder do, so under MySQL's or ClickHouse's backslash escaping a
  value cannot close its literal. SQLite reads a backslash literally, so its
  builder doubles quotes only; PostgreSQL's uses `E''` for a value with a
  backslash. Its output is still for logs and debugging only.
- The PostgreSQL and SQLite schema builders doubled backslashes in comments,
  defaults, enum and type values, collation options and `nextVal()` names,
  which SQLite, and PostgreSQL under its default `standard_conforming_strings`,
  read literally, so the stored value gained a backslash. SQLite now keeps them; PostgreSQL emits
  `E''` with escaped backslashes for a value that has one.
- The AST serializer escaped backslashes for every dialect, so `fromAst()` on
  the PostgreSQL and SQLite builders doubled them in string literals. Each
  dialect's serializer, and `Serializer` given a `StringLiteral`, now quotes
  as its builder does.
- The tokenizer and parser read string literals as the serializer writes
  them. The tokenizer treated `\` as an escape in every dialect, so SQLite's
  `'C:\'` was reported unterminated and PostgreSQL's `E'..'` was not one
  string; the parser undid `''` and `\'` but not `\\`, so `parse()` of a
  serialized `a\b` re-serialized with its backslash doubled. MySQL, MariaDB
  and ClickHouse now decode backslash escapes, SQLite reads `\` literally, and
  PostgreSQL decodes them only inside `E''`. `toAst()` parses CTE bodies with
  its dialect's tokenizer and literal.
- The ClickHouse schema builder escapes backslashes in `ReplicatedMergeTree`'s
  ZooKeeper path and replica name and in string skip-index arguments, as it
  already did in its other literals.
- The allowlists that validate names, JSON paths, types, hints and settings
  no longer accept a trailing newline, which their `$` anchors matched
  before. Among them: JSON paths in the MySQL, PostgreSQL and SQLite builders,
  `JoinBuilder::on()` and `where()` columns, and the `Tenant` hook's column.
- The MySQL and MariaDB builders escape LIKE wildcards with `!` and declare it
  with `ESCAPE '!'`. They escaped with a backslash, which under
  `NO_BACKSLASH_ESCAPES` is no escape character, so `contains('50%')` also
  matched `500`. A backslash in a value is now matched as itself.
- The SQLite builder declares `ESCAPE '\'`. SQLite's LIKE has no escape
  character by default, so the backslash-escaped `%` and `_` matched a
  backslash followed by any characters instead of themselves.
- The MySQL builder's `setJsonRemove()` escapes the LIKE pattern
  `JSON_SEARCH()` matches its value with and passes `!` as the escape
  character, so a value containing `%` or `_` removes only the element equal
  to it.

## 0.7.2

### Changed

- A join given an empty alias is read under the name of the collection it
  joins instead of throwing `ValidationException`, from the factories and from
  `parse()`/`parseQuery()` (the `alias` property, the legacy alias-first and
  column forms, the nested ON form, and cross and natural joins). Only a join
  with neither a collection nor an alias still throws.
- `Query::isAliasDefaulted()` (new) reports a join read under its collection's
  name. It is derived when the query is constructed (the alias equals the
  collection), so a `toArray()`/`parse()` round trip re-derives it and a later
  `setAttribute()` on the built query keeps it. A join serialized after its
  collection was renamed keeps its alias, which `parse()` reads as explicit.

## 0.7.1

### Added

- `Method::Relationship` and `Query::relationship(string $attribute, array $queries)`:
  queries written against the collection a relationship attribute points to.
  It is nested, so its children parse and serialize as queries and contribute
  to `shape()` and `fingerprint()`. `Query::validate()` skips it, since its
  children name another collection's attributes. No bundled builder compiles
  it; consumers that populate related documents read it themselves.

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
