# utopia PHP extension

Utopia crates registered as PHP classes. A class the extension registers has no PHP
source; the rest of its package (other classes, a stub for static analysis, tests)
stays in `packages/<name>`.

```sh
cargo build --release -p utopia-extension
php -d extension="$PWD/target/release/libutopia.so" ...   # libutopia.dylib on macOS
```

The Appwrite image builds and loads it:

```sh
docker compose exec appwrite php --ri utopia
```

Built on [ext-php-rs](https://github.com/extphprs/ext-php-rs); it needs `php-config`
and libclang at build time.

## Switching a class on

`Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`, `Utopia\Locale\Locale`,
`Utopia\UserAgent\UserAgent` and `Utopia\Domains\Domain` are always registered: they have no PHP
implementation. Their signatures are in `stubs/`, `packages/image/stubs/`, `packages/user-agent/stubs/`
and `packages/domains/stubs/` for PHPStan, which runs without the extension. Every other class is
registered only when `_APP_EXTENSION_LIBRARIES` names its package. Otherwise Composer loads the package's PHP class as
before:

```sh
_APP_EXTENSION_LIBRARIES=emails   # Utopia\Emails\Email from the extension
```

The extension reads it once, when PHP loads the module, so changing it takes a restart and no
deploy. Each library is canaried on Cloud this way before it becomes the default.

CI runs `crates/extension/tests/run.sh` in the development image. It finds the libraries from the
`enabled()` calls in `src/lib.rs` and requires a parity corpus at `tests/<name>.php` for each. For
every library it runs `packages/<name>/tests` on the extension and diffs the corpus on both
implementations, first one library at a time, then with all of them on. A new library needs only
its `enabled()` call, its corpus and its package tests; there is no CI step to add.

Once a library has run on the extension everywhere, its PHP package is removed: the class is
registered unconditionally, `tests/<name>.expected.jsonl` holds the output of the PHP class it
replaced, and `run.sh` diffs the corpus against that file. The package stays published at its last
release for projects outside this repository; the root `composer.json` keeps `replace`-ing it so it
is never installed here.

## Utopia\DSN\DSN

Over `crates/utopia/dsn`, always registered. Its PHP package, `packages/dsn`, was removed once the
extension had served it in every Cloud region (appwrite-labs/cloud#7503); `utopia-php/dsn` stays
published at 1.0.0 for other projects. It declares the PHP class's protected properties, so `==`,
`var_dump()` and `(array)` see what they saw in PHP. The DSN, `from()`'s user and its password are
`#[\SensitiveParameter]`, as in PHP, so exception traces never hold them.

`crates/extension/tests/dsn.php` prints a corpus of results (#14308's `tests/compat/dsn` cases, plus
edge cases) that must match `dsn.expected.jsonl`, the output of the PHP class, and asserts the known
differences from it:

- A DSN that is not UTF-8 is refused as malformed, where `parse_url` took any bytes.
- `from()` takes string parameters; PHP also round-tripped a list value (`['a' => ['b', 'c']]`).
- `serialize()` refuses the object, as it does every class with Rust state.
- PHP filled the protected `$params` with the parsed query on the first `getParam()`, which then
  showed in `var_dump()` and `==`; it stays `null` here.
- A user, password or path that URL-decodes to bytes that are not UTF-8 (`%FF`) reads exactly
  through its getter and lossily through `var_dump()`.

Its protected properties are getter-backed: they are computed from the parsed DSN only when PHP
reads them, so construction stores each component once, in `utopia-dsn`'s single buffer.

## Utopia\Locale\Locale

Over `crates/utopia/locale`, always registered. Its PHP package, `packages/locale`, was removed once
the extension had served it in every Cloud region (appwrite-labs/cloud#7503); `utopia-php/locale`
stays published at its last release for other projects. The registered languages are the PHP
class's static state, so they live once per process and are cleared when the request ends, as
PHP clears static properties. `Locale::$exceptions` is still a PHP static property:
`Locale::$exceptions = false` works unchanged, and every call reads it. Translation files are read
through PHP's `file_exists()` and `file_get_contents()`, so Swoole's hooks, stream wrappers and
PHP's warnings apply. `getTranslations()` builds a language's array once and returns it by
refcount afterwards, as PHP returns `self::$language[$name]`.

`crates/extension/tests/locale.php` prints a corpus of results that must match
`locale.expected.jsonl`, the output of the PHP class: real Appwrite translations pinned in
`tests/fixtures/locale` (English, Hebrew, Arabic, Japanese, Chinese, Russian, Hindi), with
placeholders; JSON files of
every shape; every combination of default, fallback and missing key with and without
exceptions; placeholder values of every type; the scalar arguments PHP converts; and the warnings
each case raises. It asserts these known differences from the PHP class:

- `setLanguageFromArray()` refuses a translation that is not a string; PHP stored it and returned it.
- A language name, translation key or text that is not UTF-8 is refused with an `Exception` when it
  is registered, where PHP stores the bytes; translation files are always UTF-8 (`json_decode`
  refuses anything else). A key or name looked up with such bytes is read with U+FFFD in their
  place, so it can only match one that holds U+FFFD itself; the `{{key}}` returned for a missing key
  keeps the exact bytes.
- Reading an unregistered default language raises the same `TypeError` without PHP's
  `Undefined array key` warning before it.
- `serialize()` refuses the object, as it does every class with Rust state.

A warning PHP raises inside the class (`file_get_contents()` of a missing file) names the caller's
file and line, since the class has no PHP source.

## Utopia\UserAgent\UserAgent

Over `crates/utopia/user-agent`, always registered. Once the extension had served it in every Cloud
region (appwrite-labs/cloud#7503), the PHP parser (`UserAgent.php` and `Detection\*`) was removed
from `packages/user-agent`, which now requires `ext-utopia` like `packages/image`; 0.1.x on
Packagist is the last release that parses in PHP. What `UserAgent` returns are the package's own
`OperatingSystem`, `Client`, `Device` and `Bot` objects, built by their PHP constructors once per
agent and returned as the same object on every call, as in PHP. The class is final with a private constructor
and the PHP class's private properties, so `==`, `var_dump()` and `(array)` see what they see in PHP.

`crates/extension/tests/user-agent.php` prints a corpus of results that must match
`user-agent.expected.jsonl`, the output of the PHP class: every string in the package's tests, agents Appwrite sees (SDKs, the CLI, HTTP
libraries, browsers, previews, monitors, bots), edge cases (empty, case, control bytes, bytes that are
not UTF-8, 100 KB inputs) and seeded mutations of all of them, read through every method and through
`Appwrite\Detector\Detector`. `packages/user-agent/tests` run on the extension too. It asserts these
known differences from the PHP class:

- A `null`, array or object argument and a missing argument raise the same `TypeError` and
  `ArgumentCountError` with ext-php-rs's message.
- `isset()` on a private property from outside the class is `true`.
- `serialize()` refuses the object, as it does every class with Rust state.

## Utopia\Domains\Domain

Over `crates/utopia/domains`, always registered: the Public Suffix List parser of `packages/domains`.
Once the extension had served it in every Cloud region (appwrite-labs/cloud#7503), the PHP class
(`src/Domain.php`) was removed from `packages/domains`, which now requires `ext-utopia` like
`packages/image`; 4.4.x on Packagist is the last release that parses in PHP. The registrars make
network calls, so they and the validators stay PHP. The crate embeds the list as
`crates/utopia/domains/data/public-suffix-list.txt`, exported from the package's `data/data.php`,
which stays the list's source. PHP fills the class's protected properties as
its getters run (`getTLD()` sets `$TLD`, a matching `getSuffix()` sets `$suffix` and `$rule`), and the
binding shows the same properties, so `==`, `var_dump()` and `(array)` see what they see in PHP.

`crates/extension/tests/domains.php` prints a corpus of results that must match
`domains.expected.jsonl`, the output of the PHP class: every rule of `data.php` as a suffix, under a name, under a subdomain and in upper
case, so a list refreshed on one side and not the other fails it; hostnames Appwrite and Cloud parse;
edge cases (empty and dotted names, `0`, IDN and punycode, IPs, ports, bytes that are not UTF-8,
URLs); the properties after each getter and after every getter in reverse; the scalar arguments PHP
converts; and `==`, clone, `var_dump()`, reflection and a subclass. `packages/domains/tests` run on
the extension too. It asserts one known difference from the PHP class:

- The exception for a URL (`http://`, `https://`) holding bytes that are not UTF-8 shows them as
  U+FFFD; PHP's message keeps the bytes.

A PHP subclass, with properties of its own, clones as in PHP (fixed in ext-php-rs 0.16.5,
extphprs/ext-php-rs#838); the corpus clones one.

## Utopia\Emails\Email

Over `crates/utopia/emails` (`_APP_EXTENSION_LIBRARIES=emails`): parsing, the local-part and
`FILTER_VALIDATE_EMAIL` checks, the free and disposable lists and the canonical forms of
`packages/emails`. The validators and the `Canonicals` provider classes stay PHP; the validators make
`Email`s, so they use the extension's. The crate embeds the lists as
`crates/utopia/emails/data/{free,disposable}-domains.txt`, exported from the PHP package's
`data/*-domains.php` by `data/export.php`.

What `Email` asks of its domain is `Utopia\Domains\Domain`'s: the binding makes the
`$domainInstance` object with `new Domain()` and calls `isKnown()`, `isTest()`, `getRegisterable()`
and `getSub()` on it as PHP does, so it is whichever implementation `domains` selects, throws the
same exception for a URL, and fills the same properties. `==`, `var_dump()`, `(array)` and `clone`
(which shares `$domainInstance`) see what they see in PHP.

`crates/extension/tests/emails.php` prints a corpus of results that must be identical on both
implementations: every domain of both lists (and a subdomain of every 25th), so a list refreshed on
one side and not the other fails it; every domain of every provider under local parts the rules
change; addresses Appwrite and Cloud see and edge cases (whitespace, case, `0`, quoted and IP
literal addresses, IDN, lengths at the limits, bytes that are not UTF-8, URLs as domains); 20,000
seeded random addresses through `isValid()`, `hasValidLocal()`, `hasValidDomain()` and
`getCanonical()`; the properties after each getter and after every getter in reverse; the scalar
arguments PHP converts; `==`, clone, `var_dump()`, reflection, constants, subclasses and their clones; and the
package's validators. It asserts these known differences on the extension:

- The protected helpers `initializeProviders()`, `getProviderForDomain()` and `isDomainSupported()`
  and the protected static properties `$freeDomains`, `$disposableDomains` and `$providers` do not
  exist: a subclass cannot call or read them. Nothing in Appwrite or Cloud subclasses `Email`.

## Utopia\Query: the classifiers, the tokenizers, Query, the AST and the schemas

Over `crates/utopia/query` (`_APP_EXTENSION_LIBRARIES=query`). `packages/query` is about 300 classes,
so it moves to the extension one part at a time, and `query` switches on whatever part is here. So
far that is the statement classifiers a database proxy routes with: the `Classifier` interface,
`Classifier\SQL` (abstract), `Classifier\MySQL`, `Classifier\PostgreSQL`, `Classifier\MongoDB` and
the `Type` enum they return; the tokenizers; `Query`; the AST's parser and serializers; and the
schemas, each below. Every other class of the package (`Method` and the other enums, the builders,
the schema's blueprints, the AST's nodes and walker) is still its PHP source and works with these
unchanged.

The interface and the enum are registered too, not left in PHP: a class registered when the module
starts cannot implement a userland interface, and returning the extension's own enum case costs no
lookup. PHP code that implements `Classifier`, extends `Classifier\SQL` with its own `classify()`,
or extends a concrete classifier and calls `parent::classify()` works as before.

`crates/extension/tests/query.php` prints a corpus of results that must be identical on both
implementations: every keyword the classifiers know, in three spellings and seven positions; SQL
with comments, quoted and dollar-quoted text, `COPY` and CTEs, bytes that are not UTF-8 and inputs
of 100 KB; seeded mutations of all of them; MySQL and PostgreSQL packets of every command and
message type around those statements; MongoDB `OP_MSG` messages for every command name with and
without `startTransaction`, with every BSON value type before it, truncated and with a byte
changed; 3,000 random messages; the enum's cases, `from()` and `tryFrom()`; `instanceof`,
reflection, `==` and `clone`; PHP classes that implement the interface or extend a classifier; and
the scalar arguments PHP converts. `packages/query/tests` run on the extension too, without
`tests/E2E`, which needs database servers. The corpus asserts one known difference:

- `ReflectionClass::isFinal()` is `false` for `Type`; a PHP enum reports `true`. Extending it is
  refused either way.

### The tokenizers

`Tokenizer\Tokenizer` and its dialects `MySQL`, `MariaDB`, `PostgreSQL`, `SQLite` and `ClickHouse`,
which the AST parser and the builders' statement checks call. `Token` and `TokenType` stay PHP: a
token is a value object, so `tokenize()` returns the package's own `Token`s, made by its constructor
with the cases of its enum, and `filter()` returns the objects it was given. An unterminated string
or identifier throws the package's `ValidationException`.

The corpus adds, on all six dialects: every statement of the classifier corpus; numbers,
placeholders, operators (PostgreSQL's multi-character ones, and near misses of them), strings and
identifiers with every escape, comments in all three forms and `#` inside each kind of quote; seeded
mutations of all of them; `filter()` of tokens it made, of tokens made by hand, of a keyed array and
of nothing; the class hierarchy; subclasses that read the quote character or change `tokenize()`;
and the scalar arguments PHP converts. It asserts two known differences:

- A tokenizer holds nothing. PHP keeps the SQL it last read, its length and its position in
  private properties, so a PHP tokenizer that has tokenized is not `==` a new one.
- `getIdentifierQuoteChar()` exists and returns the dialect's quote, but `tokenize()` does not ask
  it: a subclass that overrides it does not change the quote. Nothing in Appwrite or Cloud does.

Five tests in `packages/query/tests/Tokenizer/MySQLTest.php` reached the private
`replaceHashComments()` by reflection. They now read the rewritten SQL back from `tokenize()`,
whose tokens cover every byte, so they run on both implementations.

### Query

`Utopia\Query\Query`, the value object every query is. The extension registers its methods and
state as `Utopia\Query\NativeQuery`, and the package declares `Query` over it
(`packages/query/src/extension.php` loads `packages/query/extension/Query.php` before the autoloader
looks for `src/Query.php`). That one PHP class holds the two constants that are arrays,
`LOGICAL_TYPES` and `GROUP_BY_TIME_BUCKET_INTERVALS`, which a class registered when the module
starts cannot. `Utopia\Database\Query` reads the first.

A query's values are PHP values (any value, nested queries among them), so the binding keeps them
as PHP holds them and uses the crate only for what a method is (`utopia_query::Method`): a nested
query stays the same object, `getValues()` returns the array that was stored, and changing a nested
query through it changes the one the parent holds. `clone` copies nested queries. `Method`,
`NullsPosition`, `CursorDirection`, `ParsedQuery` and the exceptions stay PHP; the binding returns
their cases and constructs them. `json_encode()` and `json_decode()` are PHP's own, called from the
binding, so `toString()` and `parse()` write and refuse exactly what they did. `contains()` raises
the deprecation `#[\Deprecated]` raised.

Subclasses are what most of the binding is about. `Utopia\Database\Query`, `Utopia\Audit\Query`
and `Utopia\Usage\UsageQuery` extend `Query`:

- `new static` makes an object of the class a method was called on, directly when the class keeps
  the extension's constructor and through its own when it has one, including through `parent::`.
- Where `Query.php` calls a method through `static` (`parseQuery()` from `parse()` and for nested
  queries, `isMethod()`, `on()`, `parse()` from `parseQueries()` and `fingerprint()`, `lessThan()`,
  `greaterThan()` and `between()` from the date helpers, `limit()` and `offset()` from `page()`,
  `validate()` for nested queries), a subclass's own runs, in the same order.
  `Utopia\Database\Query::parseQuery()`, which decodes children given as JSON strings, depends on it.
- Where `Query.php` calls `toArray()` or `shape()` on a query object (`toString()`, nested queries in
  `toArray()`, `diff()`, `fingerprint()`), a subclass's own runs. `Utopia\Database\Query::toArray()`,
  which writes a cursor document as its id, depends on it.
- An override must be compatible with the method it overrides, and PHP checks that against what the
  extension declares. Each array parameter is declared `array`. The cursor factories take
  `array|object` and are declared `array`, the nearest type an extension method can declare that an
  `array|object` override is compatible with; they accept an object all the same.
- The seven protected properties are declared and getter-backed, so a subclass reads
  `$this->method` and `var_dump()`, `==` and `(array)` see what they see in PHP.

The corpus adds: every method constructed on the class and on two subclasses, with and without
values, nested queries and an alias; every factory with arguments it takes and sixty seeded
argument lists from a pool of thirty values (strings, numbers, `NAN`, arrays, objects, enum cases,
queries); 470 JSON documents through `parse()`, `parseQuery()`, `parseQueries()` and
`fingerprint()` on the class and on subclasses, the 0.6 join layouts and malformed JSON among them;
setters, `getValue()`, `clone`, identity of nested queries; `groupByType()`, `getByType()`,
`getCursorQueries()`, `merge()`, `diff()`, `validate()` and `page()` over lists of every shape;
`compile()` through a recording compiler; named arguments; what `var_dump()`, `==`, `(array)` and
reflection show; a subclass that overrides every method reached through `static` and records the
order they ran in; and `Utopia\Database\Query` itself, with a `Document` as a cursor and children
given as JSON strings. It asserts these known differences:

- `Query` has a parent class, `NativeQuery`. In PHP it has none.
- More arguments than a method declares are an `ArgumentCountError`; PHP ignores the extras.
- A call that both omits an argument and passes a wrong one raises `ArgumentCountError`, a subclass
  of the `TypeError` PHP raises for it. The corpus compares the two as `TypeError`.
- `isset()` on a protected property from outside the class is `true`. Reading it is refused.
- `serialize()` refuses the object, as it does every class with Rust state. Nothing in Appwrite
  serializes a query.

One difference the corpus does not assert: `groupByType()`, `getByType()`, `getCursorQueries()`,
`merge()` and `validate()` read a listed query's method, attribute and values directly, where PHP
calls its `getMethod()`, `getAttribute()` and `getValues()`. A subclass that overrides those getters
is not consulted there. The three subclasses above do not override them.

One test in `packages/query/tests/API/HelperTest.php` read the `#[\Deprecated]` attribute of
`contains()` by reflection. It now asserts the deprecation the call raises, on both implementations.

### The AST's parser and serializers

`AST\Grammar`, `AST\Parser`, and `AST\Serializer` with its dialects `MySQL`, `MariaDB`,
`PostgreSQL`, `SQLite` and `ClickHouse`. The builders are their only callers. The 25 node classes
stay PHP. They are readonly data that PHP code builds, reads and matches with `instanceof`, and a
native class would only make `new` slower. `AST\Walker` and the three visitors stay PHP as well,
because a walk is nothing but calls into a PHP visitor.

The parser returns the package's own node objects and the serializers read them. Both go through
the nodes' property slots, not their constructors and not their property tables. The constructors
only assign, and the two that check something (a function's name, a cast's type) check what the
parser has already checked. The first use in a request verifies that each node class declares the
properties the extension expects in the order it expects, and throws an `Error` naming the class
if the package and the extension are out of step.

PHP recurses on its own heap, so it parses and writes a tree as deep as its memory allows. Rust
recurses on the native stack, which a Swoole coroutine keeps at 2 MB. Reading one level of a tree
takes 3.1 KB of it in a release build. So the binding measures a tree first, and works on anything
deeper than 32 levels on a stack allocated for that depth (`stacker`). Nothing that runs there
calls PHP code. With this, the extension takes every tree PHP takes: the corpus goes to 20,000
levels, and under a 512 KB or 128 KB fiber stack both implementations stop at the same input,
where PHP's own engine overflows freeing the objects.

The corpus adds: `Grammar` on names and types with every kind of whitespace and bytes that are not
UTF-8; 209 statements, valid and not, parsed from two tokenizers and written by all six
serializers, then parsed again from what was written; each of those statements cut short after
every token, with every token dropped and every pair swapped, for the parser's errors; token
arrays that are empty, keyed, gapped, unfiltered or hold other values, and tokens made by hand with
values a tokenizer never produces; every node made by hand with values a parser never produces,
every pair of 31 operators nested on either side, floats at four `precision` settings, wrong
values in every array a node holds, and subclasses of nodes; trees 20,000 levels deep and chains
8,000 terms long; subclasses of the parser and the serializers; and the PHP walker and visitors
over parsed statements. It asserts two known differences:

- `serialize()` writes the whole statement itself. PHP's calls `serializeExpression()` and
  `quoteIdentifier()` on `$this`, so a subclass that overrides either changes the statement. Here
  the overrides apply only when called directly. The package's dialects are the only subclasses in
  Appwrite and Cloud.
- A chain of more than 1,024 `NOT` or sign operators is refused with the package's
  `ValidationException` (`Expression nesting too deep`). PHP parses it until its memory runs out.
  A tree deeper than 100,000 levels is refused the same way, past the depth PHP itself survives.

This PR also changes the PHP serializer, for both implementations. `crates/utopia/query` was
ported from a `Serializer.php` that keeps the parentheses of an equal-precedence right operand,
and the one on `main` drops them for every operator but `-`, `/` and `%`. So `a = (b = c)` was
written as `a = b = c`, which reads back as `(a = b) = c`. `packages/query/src/AST/Serializer.php`
is now the fixed version, with two tests in `tests/AST/SerializerTest.php` that fail on the old
one.

### The schemas

`Schema` and `Schema\SQL` (both abstract), the dialects `Schema\MySQL`, `PostgreSQL`, `SQLite`,
`ClickHouse` and `MongoDB`, and the 16 `Schema\Feature\*` interfaces they implement. The
blueprints stay PHP: `Table`, `Column`, `ForeignKey`, `Index`, `CheckConstraint`, `RenameColumn`,
their dialect classes and traits, the forwarders and the enums. They are what PHP code fills with
fluent calls, and they validate as they are filled.

`$schema->table($name)` returns the package's own `Table\<Dialect>`, holding the schema. When the
table calls back (`compileCreate($this)`), the schema reads it through its property slots, which
it finds by name once a request for each class, builds the crate's blueprint and compiles it. The statement it returns is the package's
`Builder\Statement`, carrying the closure given to `setExecutor()`. A view's query is still a PHP
builder: the schema calls its `build()`, passes the SQL to the crate and keeps the bindings as PHP
built them.

This part is not faster. PHP compiles a `CREATE TABLE` of 18 columns in 9 microseconds, which is
string concatenation it does well, and the extension has to read the blueprint out of PHP objects
first: it takes 6% to 45% longer a statement, 1 to 4 microseconds. Only DDL calls a schema, and the
statement then takes the database milliseconds. What this part buys is that
`crates/utopia/query`'s DDL is checked against PHP's, statement for statement, before anything
written in Rust relies on it.

SQL names and text are bytes to PHP, so they are widened going into the crate and narrowed coming
out, as the AST's are. MongoDB's statements are JSON, so its strings must be UTF-8.

The corpus adds, for all five dialects: 97 blueprints, one for each column factory, modifier,
index, key, check, alteration, partitioning and engine option, compiled as `CREATE` and as
`ALTER`; every column type at six sizes, added and modified, and as a `Column` made directly with
ten sets of arguments; 37 default values at four `precision` settings; 31 names (qualified, empty,
quoted, with every quote character, with bytes that are not UTF-8, with Unicode spaces) in each of
35 places a blueprint or a statement holds one; tables with properties set past the fluent
methods; a table made by each dialect compiled by every schema; every other public method with
every name, both flags and arguments of every wrong type; `createIndex()` with 33 sets of options,
foreign keys with every action, procedures, triggers at every timing and event, collations, types,
sequences and comments; views over six builders with their bindings; the executor, on a schema, a
clone and a statement; and the signatures, parents and interfaces of the classes. The database
library's unit tests that create collections, attributes or indexes give the same results on both.
The corpus asserts six known differences:

- The classes have the public methods only. PHP's dialects are built from protected steps
  (`quote()`, `compileColumnType()`, `compileColumnDefinition()` and others) that a subclass can
  override or call, and the abstract `Schema` holds the shared ones. Here a schema compiles a
  statement whole, so a subclass can override a public method but not a step, and a dialect written
  in PHP has nothing in `Schema` to build on. Nothing in Appwrite, Cloud or the database library
  subclasses a schema.
- The `Feature` interfaces declare no methods. ext-php-rs checks a class's methods against its
  interface's when PHP starts, and these classes return their statements untyped. Every class has
  every method of the interfaces it implements, and `instanceof` answers as before.
- A schema reads the whole blueprint before it compiles. PHP reads only what the statement uses.
  They differ only on a blueprint holding what its fluent methods refuse, such as a half-made
  `CheckConstraint`, which takes a subclass or reflection to make: PHP compiles an `ALTER` that does
  not write the check, and the extension throws.
- An index length must be a number. PHP writes whatever it is given between the parentheses.
- A procedure parameter's direction must be a `ParameterDirection`. PHP reads `->value` off
  anything, with a warning.
- MongoDB refuses a string that is not UTF-8 when it reads it, with the `JsonException` that
  `json_encode()` throws. PHP throws it only if the statement uses the string.

The executor closure is held in Rust, where PHP's cycle collector does not look. A closure that
reaches its own schema (`$schema->setExecutor(fn () => $schema)`) is freed at the end of the
request, not before. The database library's executor is bound to the database, which does not keep
the schema.

## Benchmarks

Every class's PR carries a benchmark against the PHP class. `crates/extension/bench/<name>.php`
times one implementation; `run.py` runs it on both, alternating, and prints the medians:

```sh
cargo build --release -p utopia-extension
python3 crates/extension/bench/run.py crates/extension/bench/emails.php . target/release/libutopia.so 5
```

## Utopia\Image\Canvas and Utopia\Image\Font

Over `crates/utopia/image`, with the signatures in `packages/image/stubs/Canvas.php`. The value
objects a canvas takes (`Limits`, `Blank`, `Transform`, `Border`, `Point`, `Path`, `Text`) and the
`Format` and `Gravity` enums are plain PHP in `packages/image/src`; the binding reads their public
properties.

```php
$bytes = (new Canvas($data, new Limits(width: 16000, height: 16000)))
    ->apply(new Transform(width: 300, height: 300, border: new Border(2, '#ff0000'), radius: 20))
    ->encode(Format::Webp, quality: 80);
```

- **Immutable.** `apply()` and `draw()` return a new canvas that shares the source bytes. Both
  classes are final and readonly, with no static methods, and cannot be cloned or serialized.
- **Lazy.** The constructor reads the header: `width`, `height`, `orientation` and `format` cost
  no decode. `encode()` decodes, runs every step and writes the result in one pass, so a file with
  a sound header and damaged pixel data fails there.
- **Limits belong to a canvas.** Width, height and decoded bytes are bounded by the `Limits` it was
  made with (16,000 pixels a side and 512 MiB when none is given); there is no global setting and
  no "unlimited". `Limits::$formats` narrows which formats are read at all.
- **Bytes out only.** There is no method that writes to a path. No metadata is written.
- **Drawing.** `draw(new Path(...))` fills SVG path data; `draw(new Text(...))` draws one line from
  a `Font`, without kerning or shaping. That is how the fallback, initials and QR avatars are made.

`crates/extension/tests/canvas.php` runs without Composer; `packages/image/tests` is the full
suite, and is skipped where the extension is not loaded.

The extension turns on the crate's `libwebp` and `libheif` features, so it needs
libheif's headers to build (`libheif-dev`) and the library at run time; libwebp is
built from source. AVIF and HEIC follow the system's libheif: Appwrite's base image decodes both,
encodes AVIF, and has no HEVC encoder, so HEIC output is unavailable unless `libheif-x265`
(GPL-2.0-or-later) is installed.

How it behaves inside a Swoole worker:

- **Errors.** One hierarchy: `Exception\ImageException` and
  its `UnsupportedFormatException`, `CorruptImageException` and `LimitExceededException`
  for what an image or the system makes fail, `\InvalidArgumentException` for an argument
  that could never work. The classes load from the Composer package (without it they are
  `\Exception`).
- **Panics.** A panic in a Rust codec is caught and thrown as a PHP `Error` whose message
  starts with `Rust panic:`; the worker keeps running. The release profile must not set
  `panic = "abort"`.
- **Threads and forks.** The extension starts no thread pool. The pure-Rust AVIF encoder
  (the crate's default `ravif` feature) is left out: with its pool, a process that had
  encoded an AVIF and then forked left the child waiting forever on its next encode.


`crates/support/php-std` here holds only the functions `utopia-image` calls, copied
unchanged from the full crate on `feat/rust-utopia-conversion`.
