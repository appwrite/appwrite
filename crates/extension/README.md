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
`Utopia\UserAgent\UserAgent`, `Utopia\Domains\Domain` and `Utopia\Emails\Email` are always
registered: they have no PHP implementation. Their signatures are in `stubs/`, `packages/image/stubs/`,
`packages/user-agent/stubs/`, `packages/domains/stubs/` and `packages/emails/stubs/` for PHPStan,
which runs without the extension. Every other class is registered only when
`_APP_EXTENSION_LIBRARIES` names its package. Otherwise Composer loads the package's PHP class as
before:

```sh
_APP_EXTENSION_LIBRARIES=<package>,<package>   # those packages' classes from the extension
```

A package ported in batches has one switch per batch, `<package>-<batch>`, which needs the
package's own: `validators,validators-scalar` registers the validators' base and the scalar leaf
validators, `validators,validators-text` the base and the text ones, `validators,validators-network`
the base and the network ones, `validators` alone only the base.

The extension reads it once, when PHP loads the module, so changing it takes a restart and no
deploy. Each library is canaried on Cloud this way before it becomes the default.

CI runs `crates/extension/tests/run.sh` in the development image. It finds the switchable libraries
from the `enabled()` calls in `src/lib.rs` and requires a parity corpus at `tests/<name>.php` for
each. For every one it runs `packages/<name>/tests` on the extension and diffs the corpus on both
implementations, first one library at a time (a batch with its package's switch, running the
package's tests), then with all of them on. A new library needs only
its `enabled()` call, its corpus and its package tests; there is no CI step to add.

Once a library has run on the extension everywhere, its PHP class is removed and the class is
registered unconditionally. Its corpus goes with it, since there is nothing left to compare
against; the package's own tests keep running on the extension. The package stays published at its
last release for projects outside this repository; the root `composer.json` keeps `replace`-ing a
package removed whole so it is never installed here.

## Utopia\DSN\DSN

Over `crates/utopia/dsn`, always registered. Its PHP package, `packages/dsn`, was removed once the
extension had served it in every Cloud region (appwrite-labs/cloud#7503); `utopia-php/dsn` stays
published at 1.0.0 for other projects. It declares the PHP class's protected properties, so `==`,
`var_dump()` and `(array)` see what they saw in PHP. The DSN, `from()`'s user and its password are
`#[\SensitiveParameter]`, as in PHP, so exception traces never hold them.

Known differences from the PHP class it replaced:

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
PHP's warnings apply. A file is read when it is registered, as in PHP, but a JSON object of strings
(every Appwrite translation file) is kept as its text and decoded on the language's first lookup,
once per distinct file: Appwrite registers 131 codes at boot and a process reads a few, so this
keeps about 4.5 MB per process out of memory. Anything else is decoded at registration, so its
errors are raised at the same point as before. `getTranslations()` builds a language's array once and returns it by
refcount afterwards, as PHP returns `self::$language[$name]`.

Known differences from the PHP class it replaced:

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

`packages/user-agent/tests` run on the extension. Known differences from the PHP class it replaced:

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

`packages/domains/tests` run on the extension. Known differences from the PHP class it replaced:

- The exception for a URL (`http://`, `https://`) holding bytes that are not UTF-8 shows them as
  U+FFFD; PHP's message keeps the bytes.

A PHP subclass, with properties of its own, clones as in PHP (fixed in ext-php-rs 0.16.5,
extphprs/ext-php-rs#838).

## Utopia\Emails\Email

Over `crates/utopia/emails`, always registered: parsing, the local-part and `FILTER_VALIDATE_EMAIL`
checks, the free and disposable lists and the canonical forms of `packages/emails`. Once the
extension had served it in every Cloud region (appwrite-labs/cloud#7503), the PHP class
(`src/Email.php`) was removed from `packages/emails`, which now requires `ext-utopia` like
`packages/image`; 0.8.x on Packagist is the last release that parses in PHP. The validators and the
`Canonicals` provider classes stay PHP; the validators make `Email`s, so they use the extension's.
The crate embeds the lists as `crates/utopia/emails/data/{free,disposable}-domains.txt`, exported from
the package's `data/*-domains.php` (which `import.php` refreshes, and which stay the lists' source)
by `data/export.php`.

What `Email` asks of its domain is `Utopia\Domains\Domain`'s: the binding makes the
`$domainInstance` object with `new Domain()` and calls `isKnown()`, `isTest()`, `getRegisterable()`
and `getSub()` on it as PHP does, so it is whichever implementation `domains` selects, throws the
same exception for a URL, and fills the same properties. `==`, `var_dump()`, `(array)` and `clone`
(which shares `$domainInstance`) see what they see in PHP.

`packages/emails/tests` run on the extension. Known differences from the PHP class it replaced:

- The protected helpers `initializeProviders()`, `getProviderForDomain()` and `isDomainSupported()`
  and the protected static properties `$freeDomains`, `$disposableDomains` and `$providers` do not
  exist: a subclass cannot call or read them. Nothing in Appwrite or Cloud subclasses `Email`.

## Utopia\Validator\Validator

`_APP_EXTENSION_LIBRARIES=validators` registers the abstract base of every validator, its seven
`TYPE_*` constants and its four abstract methods. It holds nothing, so the leaf validators of
`packages/validators`, Appwrite's and utopia-php/database's stay PHP and extend it as they would
extend `Exception`. `src/validators/mod.rs` builds it with ext-php-rs's `ClassBuilder` rather than
`#[php_class]`: that macro gives a class a Rust object and a public `__construct` that throws
without a Rust constructor, which a subclass without a constructor of its own would inherit. Built
by hand it has neither, as in PHP: `new` on a subclass, `parent::__construct()` (an `Error`),
clone, `var_dump()` and serialization behave as on the PHP base.

The migration goes in phases: this base first, then the leaf validators in batches, each batch
behind its own switch (`validators-<batch>`) so it can be canaried alone, with its own corpus.

`crates/extension/tests/validators.php` prints a corpus that must be identical on both
implementations: the base's reflection (constants, modifiers, return types, parameters); every
validator of the package in 52 configurations over 48 values of every kind (scalars, numeric
strings, hostnames, URLs, IPs, colours, JSON, phone numbers, bytes that are not UTF-8, arrays,
objects); PHP subclasses (a clone with properties of its own, `var_dump()`, serialization, a leaf's
subclass calling `parent::` and reading its protected state, `parent::__construct()`); constants
through a leaf; the `Utopia\Validator` alias of `compat.php`; and, from a child process, the
declarations the engine refuses or accepts (a missing method, a missing or wrong return type, a
`mixed` or extra optional parameter, a static override). There is no known difference: `isValid()`'s
`$value` is untyped, as in PHP (ext-php-rs types every argument it builds; the binding clears the
type in the engine's copy after registration).

## Utopia\Validator's scalar validators

`_APP_EXTENSION_LIBRARIES=validators,validators-scalar` also registers `Boolean`, `Integer`,
`FloatValidator`, `Numeric`, `Range` (a `Numeric`), `Port`, `HexColor` and `Wildcard` over
`crates/utopia/validators`. Nothing outside the package extends them.

`src/validators/scalar.rs` builds them by hand like the base: a class has a constructor only where
PHP's has one, and no Rust object. What a PHP constructor keeps is in real typed protected
properties, declared as `packages/validators` declares them (types, defaults, order, promoted
parameters), so a subclass reads and writes them with their type checks, and `var_dump()`,
`(array)`, `==`, `clone`, `serialize()`, `unserialize()` and reflection see what they see in PHP.
Methods read the properties on every call, in PHP's order (an uninitialized one throws the same
`Error`), straight from the object's slot on an object of the class itself and through the engine
otherwise. Arguments go through the engine's own conversions, and a rejected one throws the
`TypeError` or `ArgumentCountError` of a userland function, `called in` file and line included.
`src/validators/engine.rs` holds the engine calls ext-php-rs does not wrap, which are worth
proposing upstream: typed property declarations, union and untyped argument types (the engine
copies a typed method's argument info at registration, so they are patched in its copy after the
inheritance checks), and `FunctionHandler`, which is not exported.

`crates/extension/tests/validators-scalar.php` prints a corpus that must be identical on both
implementations (10,008 lines): each class's reflection (constants, properties, methods,
parameters with their types, defaults and promotion); every constructor over arguments of every
kind PHP converts or rejects, named arguments, too few arguments, strict types (in a child
process), reflection's `newInstance()` and `newInstanceWithoutConstructor()`; the getters and
`isValid()` of 69 validators over 127 values (ints and floats at every bound, `NAN`, `INF`,
numeric strings with whitespace, exponents, overflow, hex and signs, ports with IPv4 and IPv6
hosts, colours with a trailing newline, bytes that are not UTF-8, arrays, objects, `Stringable`);
dumps, copies, comparisons, JSON, iteration and serialization, crafted serializations included;
PHP subclasses of the leaves (overriding `isUnsigned()` that `getFormat()` calls, writing the
protected bounds, skipping the parent constructor); and, from a child process, the declarations
the engine checks against them. There is no known difference: only `isInternal()`, the file, the
doc comments and the methods' attributes (`#[\Override]`) differ, by construction.

## Utopia\Validator's text validators

`_APP_EXTENSION_LIBRARIES=validators,validators-text` also registers `Text`, `Identifier` (a `Text`),
`WhiteList`, `Contains` and `Label` over `crates/utopia/validators`. Outside the package, Appwrite's
`Compute\Validator\VariableKey` and `Database\Validator\Filename` extend `Text`; they read its
protected `$length` and call `parent::__construct()`, `parent::getDescription()` and
`parent::isValid()`, and work unchanged on either implementation.

`src/validators/text.rs` builds them by hand like the scalar batch (shared helpers in
`src/validators/leaf.rs`), with their state in real typed protected properties, read in PHP's
order. `Text`'s `NUMBERS`, `ALPHABET_UPPER` and `ALPHABET_LOWER` are persistent immutable arrays, and
`Label::MAX_LENGTH` is a typed (`int`) constant; each class's own constants come before the ones it
inherits, as PHP orders them. Comparisons with the PHP values a validator holds go through the
engine: the loose `in_array()` of `Text`'s allow list and of `WhiteList` (`zend_compare`, or bytes
when two strings cannot both be numeric), `WhiteList`'s strict `in_array()` (`zend_is_identical`),
and `(string)` of a list item or a pattern, and `implode()` (`zval_get_string_func`, with its
`Array to string conversion` warning and its `Error` for an object without `__toString()`). A non-strict
`WhiteList` copies its list with the engine (`zend_array_dup`, keys and order kept) and lowers the
values in place. `Text`'s blank check and `Contains`'s case folding are `php-std`'s PCRE2 and
`mb_strtolower()`, PHP's own.

`crates/extension/tests/validators-text.php` prints a corpus that must be identical on both
implementations (13,716 lines): each class's reflection (constants with their types, properties,
methods and their order, parameters); every constructor over arguments of every kind PHP converts or
rejects, named arguments, strict types (in a child process) and `newInstanceWithoutConstructor()`;
the getters and `isValid()` of 80 validators over 105 values (multibyte, combining and emoji
characters, every Unicode separator and format character, final sigma, bytes that are not UTF-8,
numeric strings, arrays, objects, `Stringable`, a throwing `__toString()`, an enum); every code
point through the blank check and through `Contains`'s case folding; the character count of every
two-byte string and of 4,000 seeded random byte strings; allow lists, white lists (strict and not)
and patterns of every kind of value against values of every kind; dumps, copies, comparisons and
serialization; Appwrite's two `Text` subclasses; PHP subclasses writing every protected property,
skipping or catching the parent constructor (a `WhiteList` whose list fails half way); and, from a
child process, the declarations the engine checks against them. It asserts one known difference:

- The native classes run their regular expressions without PHP's `preg_*` functions, so they leave
  `preg_last_error()` as it was: after `Text`'s blank check on bytes that are not UTF-8 it is not
  `PREG_BAD_UTF8_ERROR`, and after `Identifier::isValid()` it is not reset.

A warning PHP raises inside a method (`Array to string conversion`) names the caller's file and line,
since the class has no PHP source.

## Utopia\Validator's network validators

`_APP_EXTENSION_LIBRARIES=validators,validators-network` also registers `Hostname`, `Domain` (the
validator, not `Utopia\Domains\Domain`), `Host`, `IP`, `URL` and `Phone` over
`crates/utopia/validators`. Outside the package, Appwrite's `Network\Validator\PublicURL` extends
`URL`: it calls `parent::__construct()`, `parent::isValid()` and `parent::getDescription()`, and
works unchanged on either implementation.

`src/validators/network.rs` builds them by hand like the scalar and text batches, with their state
in real typed protected properties (`IP`'s `$type` declared in the class body, the rest promoted),
read in PHP's order; `Domain::createRestriction()` and `Phone::normalize()` are static methods, and
`URL::isPrivateUseSchemeURI()` stays private (a subclass's method of that name does not replace it).
`filter_var()` (`FILTER_VALIDATE_URL`, `IP` and `DOMAIN`), `parse_url()` and
`Uri\Rfc3986\Uri::parse()` run as `php-std`'s ports of PHP 8.5's; a value that is not a string
reaches `filter_var()` as PHP converts it (a `Stringable` once per conversion, which the corpus
counts; `NAN` with its warning). What PHP does with the values a validator holds goes through the
engine: `Domain`'s restrictions (`zend_fetch_dimension_const` for `$restriction['key']`, with its
`Undefined array key` and `Trying to access array offset` warnings, its errors and `ArrayAccess`;
`add_function` for `$levels + 1` and the strict `explode()` limit's `TypeError`; `foreach` over an
array, a `Traversable`, an object's visible properties, or its warning), `(string)` casts, and a
`Hostname` allow-list entry that is not a string (`str_starts_with()` and `substr()` called as
`Hostname.php`, without strict types, calls them).

`crates/extension/tests/validators-network.php` prints a corpus that must be identical on both
implementations (18,599 lines): each class's reflection; 95 validators from every constructor over
arguments of every kind PHP converts or rejects (malformed `Domain` restrictions included) and
strict types (in a child process); the getters and `isValid()` over 179 values (hostnames at the 63
and 253 limits, IDN and punycode, wildcards and underscores; IPv4 and IPv6 forms with embedded IPv4,
zone ids, leading zeros, octal and hex look-alikes; URLs with every scheme, user info, port, IPv6
host, fragment, query and IRI oddity, private-use schemes; phone numbers in every format and with a
trailing newline; bytes that are not UTF-8, arrays, objects, `Stringable`, a throwing
`__toString()`, an enum); the static methods; the private helper through reflection; how often each
validator converts a `Stringable`; dumps, copies, comparisons and serialization; Appwrite's
`PublicURL`; PHP subclasses writing every protected property or skipping the parent constructor;
and, from a child process, the declarations the engine checks against them. It asserts one known
difference:

- `Phone` runs its regular expressions without PHP's `preg_*` functions, so `isValid()` and
  `normalize()` leave `preg_last_error()` as it was.

## Benchmarks

Every class's PR carries a benchmark against the PHP class. `crates/extension/bench/<name>.php`
times one implementation; `run.py` runs it on both, alternating, and prints the medians:

```sh
cargo build --release -p utopia-extension
python3 crates/extension/bench/run.py crates/extension/bench/<name>.php . target/release/libutopia.so 5
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
