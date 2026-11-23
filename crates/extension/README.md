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

## Using it outside Appwrite

The packages it serves (`auth`, `domains`, `emails`, `image`, `user-agent`, `validators`) require
`ext-utopia`, and their `utopia-php/<name>` mirrors ship stubs for the classes it registers. Other
PHP services load the same build from the `appwrite/utopia-extension` image (amd64 and arm64):

```dockerfile
COPY --from=appwrite/utopia-extension:<version> /utopia.so /usr/local/lib/php/utopia.so
RUN apk add --no-cache libheif libstdc++ \
    && echo 'extension=/usr/local/lib/php/utopia.so' > /usr/local/etc/php/conf.d/utopia.ini
```

- **ABI:** PHP 8.5, non-thread-safe, musl (Alpine): `php:8.5-cli-alpine`, `appwrite/base` and
  `appwrite/utopia-base:php-8.5-*` all qualify. A glibc or ZTS PHP cannot load it.
- **Runtime packages:** `libheif` and `libstdc++` are needed for the extension to load at all,
  even if only `Domain` is used. `libheif` installs decoders only; encoding HEIC or AVIF through
  `Utopia\Image` also needs `libheif-plugins-all`.
- **Version:** `<version>` is an Appwrite release, and `phpversion('utopia')` reports it, here and
  in that release's `appwrite/appwrite` image. Every other build reports the crate version, `0.1.0`:
  the `main` tag, a local build, and appwrite-labs/cloud's, which compiles its vendored copy. So a
  package's `"ext-utopia"` constraint has to stay `*` until those builds report a version that
  orders against releases.

- **Tracking main:** a consumer that vendors this repository at a commit and must not compile it
  takes `appwrite/utopia-extension:$(crates/extension/tag.sh)`. The script prints `build-<hash>`, a
  hash of what the build reads (the Cargo workspace and the base image), and every publish from
  `main` also carries that tag.

`.github/workflows/extension.yml` publishes it, smoke-testing each architecture on stock
`php:8.5-cli-alpine` with exactly these packages first.

## Switching a class on

`Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`, `Utopia\Locale\Locale`,
`Utopia\UserAgent\UserAgent`, `Utopia\Domains\Domain`, `Utopia\Emails\Email`, the validators
of `packages/validators` and the password hashes of `packages/auth` are always registered: they
have no PHP implementation. Their signatures are in `stubs/`, `packages/image/stubs/`,
`packages/user-agent/stubs/`, `packages/domains/stubs/`, `packages/emails/stubs/`,
`packages/validators/stubs/` and `packages/auth/stubs/` for PHPStan, which runs without the
extension. A library that still has a PHP implementation is registered only when
`_APP_EXTENSION_LIBRARIES` names its package; none does today. Otherwise Composer loads the
package's PHP class as before:

```sh
_APP_EXTENSION_LIBRARIES=<package>,<package>   # those packages' classes from the extension
```

The extension reads it once, when PHP loads the module, so changing it takes a restart and no
deploy. Each library is canaried on Cloud this way before it becomes the default.

CI runs `crates/extension/tests/run.sh` in the development image. It finds the switchable libraries
from the `enabled()` calls in `src/lib.rs` and requires a parity corpus at `tests/<name>.php` for
each. For every one it runs `packages/<name>/tests` on the extension and diffs the corpus on both
implementations, first one library at a time, then with all of them on. A new library needs only
its `enabled()` call, its corpus and its package tests; there is no CI step to add.

`tests/opcache.php` then declares a PHP subclass of every class the extension registers and runs
with opcache on, with no switch and with all of them: opcache copies a cached subclass, with the
defaults and constants it inherits, into shared memory and frees what it copied from as PHP's
allocator's memory. A default or constant built outside that allocator (an initialized empty array
rather than `engine::empty_array()`) corrupts the heap there, and the script crashes.

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
refcount afterwards, as PHP returns `self::$language[$name]`. The class is built by hand like the
validators (`src/classes/`): `$default` and `$fallback` are real public properties, and its methods
skip ext-php-rs's per-call argument parsing and `zend_try`, which made `#[php_class]` slower than
PHP's class.

Known differences from the PHP class it replaced:

- `setLanguageFromArray()` refuses a translation that is not a string; PHP stored it and returned it.
- A language name, translation key or text that is not UTF-8 is refused with an `Exception` when it
  is registered, where PHP stores the bytes; translation files are always UTF-8 (`json_decode`
  refuses anything else). A key or name looked up with such bytes is read with U+FFFD in their
  place, so it can only match one that holds U+FFFD itself; the `{{key}}` returned for a missing key
  keeps the exact bytes.
- Reading an unregistered default language raises the same `TypeError` without PHP's
  `Undefined array key` warning before it.
- `serialize()` refuses the object, as it did when the class held Rust state.

A warning PHP raises inside the class (`file_get_contents()` of a missing file) names the caller's
file and line, since the class has no PHP source.

## Utopia\UserAgent\UserAgent

Over `crates/utopia/user-agent`, always registered. Once the extension had served it in every Cloud
region (appwrite-labs/cloud#7503), the PHP parser (`UserAgent.php` and `Detection\*`) was removed
from `packages/user-agent`, which now requires `ext-utopia` like `packages/image`; 0.1.x on
Packagist is the last release that parses in PHP. What `UserAgent` returns are the package's own
`OperatingSystem`, `Client`, `Device` and `Bot` objects, built by their PHP constructors once per
agent and returned as the same object on every call, as in PHP. The class is built by hand, like
`Locale`: it is final with a private constructor, and its state is the PHP class's own private
typed properties (the promoted `readonly string $value` last), so `==`, `var_dump()`, `(array)`,
`isset()`, `clone` and `serialize()` behave as in PHP, and arguments are coerced and refused with
PHP's own `TypeError` and `ArgumentCountError` messages.

`packages/user-agent/tests` run on the extension. Known differences from the PHP class it replaced:

- A closure bound to the class's scope (`Closure::bind($fn, $agent, UserAgent::class)`) cannot
  read or write its private properties: PHP refuses to bind to an internal class's scope, with a
  deprecation warning.

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

Over `crates/utopia/validators`, always registered with every validator below. Once the extension
had served them in every Cloud region (appwrite-labs/cloud#7503), the PHP classes were removed from
`packages/validators`, which now requires `ext-utopia` like `packages/image` and keeps their
signatures in `stubs/Validators.php`, the aliases of the old names (`src/compat.php`) and the
PHPStan extension, which stay PHP; 2.0.x on Packagist is the last release with the validators in
PHP. `packages/validators/tests` run on the extension.

The abstract base of every validator has its seven `TYPE_*` constants and its four abstract
methods. It holds nothing, so Appwrite's and utopia-php/database's validators extend it as they
would extend `Exception`. `src/validators/mod.rs` builds it with ext-php-rs's `ClassBuilder`
rather than `#[php_class]`: that macro gives a class a Rust object and a public `__construct` that throws
without a Rust constructor, which a subclass without a constructor of its own would inherit. Built
by hand it has neither, as in PHP: `new` on a subclass, `parent::__construct()` (an `Error`),
clone, `var_dump()` and serialization behave as on the PHP base.

They were ported in batches, each canaried behind its own switch and compared with the PHP
classes by a parity corpus, removed with them. The base has no known difference: `isValid()`'s
`$value` is untyped, as in PHP (ext-php-rs types every argument it builds; the binding clears the
type in the engine's copy after registration).

## Utopia\Validator's scalar validators

`Boolean`, `Integer`, `FloatValidator`, `Numeric`, `Range` (a `Numeric`), `Port`, `HexColor` and
`Wildcard`. Nothing outside the package extends them.

`src/validators/scalar.rs` builds them by hand like the base: a class has a constructor only where
PHP's has one, and no Rust object. What a PHP constructor keeps is in real typed protected
properties, declared as `packages/validators` declared them (types, defaults, order, promoted
parameters), so a subclass reads and writes them with their type checks, and `var_dump()`,
`(array)`, `==`, `clone`, `serialize()`, `unserialize()` and reflection see what they see in PHP.
Methods read the properties on every call, in PHP's order (an uninitialized one throws the same
`Error`), straight from the object's slot on an object of the class itself and through the engine
otherwise. Arguments go through the engine's own conversions, and a rejected one throws the
`TypeError` or `ArgumentCountError` of a userland function, `called in` file and line included.
`src/classes/engine.rs` holds the engine calls ext-php-rs does not wrap, which are worth
proposing upstream: typed property declarations, union and untyped argument types (the engine
copies a typed method's argument info at registration, so they are patched in its copy after the
inheritance checks), and `FunctionHandler`, which is not exported.

The scalar validators have no known difference from the PHP classes they replaced: only
`isInternal()`, the file, the doc comments and the methods' attributes (`#[\Override]`) differ, by
construction.

## Utopia\Validator's text validators

`Text`, `Identifier` (a `Text`), `WhiteList`, `Contains` and `Label`. Outside the package, Appwrite's
`Compute\Validator\VariableKey` and `Database\Validator\Filename` extend `Text`; they read its
protected `$length` and call `parent::__construct()`, `parent::getDescription()` and
`parent::isValid()`, and work unchanged.

`src/validators/text.rs` builds them by hand like the scalar validators (shared helpers in
`src/classes/leaf.rs`), with their state in real typed protected properties, read in PHP's
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

Known differences from the PHP classes they replaced:

- The native classes run their regular expressions without PHP's `preg_*` functions, so they leave
  `preg_last_error()` as it was: after `Text`'s blank check on bytes that are not UTF-8 it is not
  `PREG_BAD_UTF8_ERROR`, and after `Identifier::isValid()` it is not reset.

A warning PHP raises inside a method (`Array to string conversion`) names the caller's file and line,
since the class has no PHP source.

## Utopia\Validator's network validators

`Hostname`, `Domain` (the validator, not `Utopia\Domains\Domain`), `Host`, `IP`, `URL` and
`Phone`. Outside the package, Appwrite's `Network\Validator\PublicURL` extends `URL`: it calls `parent::__construct()`, `parent::isValid()` and `parent::getDescription()`, and
works unchanged.

`src/validators/network.rs` builds them by hand like the scalar and text validators, with their state
in real typed protected properties (`IP`'s `$type` declared in the class body, the rest promoted),
read in PHP's order; `Domain::createRestriction()` and `Phone::normalize()` are static methods, and
`URL::isPrivateUseSchemeURI()` stays private (a subclass's method of that name does not replace it).
`filter_var()` (`FILTER_VALIDATE_URL`, `IP` and `DOMAIN`), `parse_url()` and
`Uri\Rfc3986\Uri::parse()` run as `php-std`'s ports of PHP 8.5's; a value that is not a string
reaches `filter_var()` as PHP converts it (a `Stringable` once per conversion, as often as PHP;
`NAN` with its warning). What PHP does with the values a validator holds goes through the engine: `Domain`'s restrictions (`zend_fetch_dimension_const` for `$restriction['key']`, with its
`Undefined array key` and `Trying to access array offset` warnings, its errors and `ArrayAccess`;
`add_function` for `$levels + 1` and the strict `explode()` limit's `TypeError`; `foreach` over an
array, a `Traversable`, an object's visible properties, or its warning), `(string)` casts, and a
`Hostname` allow-list entry that is not a string (`str_starts_with()` and `substr()` called as
`Hostname.php`, without strict types, calls them).

Known differences from the PHP classes they replaced:

- `Phone` runs its regular expressions without PHP's `preg_*` functions, so `isValid()` and
  `normalize()` leave `preg_last_error()` as it was.

## Utopia\Validator's composing validators

`Nullable`, `ArrayList`, `AllOf`, `AnyOf`, `NoneOf`, `Multiple`, `Assoc`, `JSON`,
`JSON\ArrayValidator`, `JSON\ObjectValidator`, `JSON\FCM` and `Globstar`. Nothing outside the
package extends them. The validators they wrap are whatever they are given: PHP (Appwrite's, utopia-php/database's) or the
extension's.

`src/validators/compose.rs` builds them by hand like the leaf validators, with their state in real
typed properties declared as `packages/validators` declared them: `$validator` typed `Validator`,
`AllOf`'s `?Validator $failedRule` in the class body, `Multiple`'s untyped `$rules`, `Globstar`'s
`private readonly array $patterns`, `FCM`'s `private ?string $error` and private typed array
constants, and the private methods (`hasValidLength()`, `isNonEmptyString()`, `isHttpsUrl()`,
`match()`, `matchGlobstar()`). A wrapped validator is called as PHP calls it, through its object's
`get_method` handler from the calling class's scope (a subclass's override, `__call()`, PHP's
`Error` for a private or missing method) and `zend_call_known_function`: same order, same number of
calls, same short-circuits, and its exceptions pass through untouched. Its results are checked as
the PHP method's return type checks them (`Nullable` and `ArrayList` come from `strict_types` files,
so a non-bool `isValid()` there is a `TypeError`; elsewhere it converts, `NAN` with its warning);
`AllOf`, `AnyOf` and `NoneOf` take anything with an `isValid()`, as PHP's `foreach` does, and
record it in `$failedRule` with the property's type check. `Multiple`'s constructor calls
`$this->addRule()`, so a subclass's override runs, and `addRule()` appends as `$this->rules[] =`
does to whatever a subclass left there (`ArrayAccess::offsetSet()`, the `false` deprecation, the
errors). `getDescription()`'s numbering is the engine's `++$key`. `json_decode()`, `json_encode()`,
`json_last_error()`, `filter_var()`, `parse_url()`, `get_object_vars()` and `preg_match()` are
PHP's own, called through the engine, so `json_last_error()` and `preg_last_error()` after a call,
and `preg_match()`'s warnings for a broken character class, are PHP's. `Globstar` builds its regular
expression in `crates/utopia/validators` (a port of `matchGlobstar()` and of `preg_quote()`), and
casts each pattern as often as PHP does (a `Stringable` pattern counts them).

Known differences from the PHP classes they replaced:

- A `TypeError` for an argument the class passes to its own method (`Multiple`'s constructor
  handing `addRule()` a rule that is not a `Validator`; `Globstar::match()` given a `null` or an
  array pattern) ends at "given": PHP's adds ", called in …/Multiple.php on line N", and the
  extension's class has no PHP source to name.

## Utopia\Auth\Hash and the password hashes

The abstract `Utopia\Auth\Hash` and its eight hashes, `Argon2`, `Bcrypt`, `MD5`, `PHPass`,
`Plaintext`, `Scrypt`, `ScryptModified` and `Sha`, always registered, over
`php-std` (`password_hash()`, `password_verify()`, `crypt()`, `hash()`, `base64_*()`,
`mb_convert_encoding()`) and `crates/utopia/auth` (PECL `scrypt()`, Firebase's modified scrypt, phpass).
Once the extension had served them in every Cloud region (appwrite-labs/cloud#7503), the PHP
classes (`src/Hash.php` and `src/Hashes/*`) were removed from `packages/auth`, which now requires
`ext-utopia` like `packages/image` and keeps their signatures in `stubs/Hash.php`; 0.12.x on
Packagist is the last release with the hashes in PHP. The proofs, tokens, issuers, verifiers and
passkeys of the package stay PHP; `Proofs\Password` takes the native `Hash`, which is why the base
is native too, and PHP subclasses (the package's tests, any app's) extend it and the hashes as they
would the PHP classes. `packages/auth/tests` run on the extension.

`src/auth/` builds them by hand like the validators, with the helpers they share in
`src/classes/` (`engine.rs`, `leaf.rs`). `$options` (and `PHPass::$itoa64`) are real typed
protected properties; a method PHP calls through `$this` (`setOption()` from the constructors and
`setOptions()`, `getOption()`/`getOptions()` from `hash()`, `getName()`, `hash()` from the
`verify()` that hashes again, PHPass's `getRandomBytes()` and `encode64()`) runs a subclass's override.
Options are read as PHP reads them: `password_hash()`'s checks, order and messages (`zval_get_long()`
of any option, the ignored `salt` warning), `$options['key']` with its `Undefined array key`
warning, PHPass's arithmetic on whatever its options hold (the engine's `+`, `/`, `%`, `min()`,
`intval()` and string offsets), and `Hash.php`'s `strict_types` for `setOptions()` with integer keys.

The hashes PHP made verify on the extension and the extension's verify on PHP: `Argon2`
(`$argon2id$`, `$argon2i$`) and `Bcrypt` (`$2y$`; `$2a$`, `$2b$`, `$2x$` through `crypt()`) hash with
`password_hash()`'s salt (`php_password_make_salt()`) and verify with `password_verify()`; `PHPass`
makes blowfish (`$2a$`) and portable (`$P$`, with `$H$` accepted) hashes, and verifies MD5-crypt,
SHA-crypt, DES and extended DES through `crypt()`. `MD5`, `Sha`, `Plaintext`, `Scrypt`,
`ScryptModified` and `PHPass`'s portable hash compare with `hash_equals()`'s constant time.

Argon2 is `php-std`'s own (`src/argon2.rs`), not the `argon2` crate's, which fills lanes one after
the other unless rayon's process-wide pool fills them: like libargon2, it fills each slice's lanes
on threads of their own (`std::thread::scope`), joined before the next slice, so a hash or verify
with the default 3 threads takes PHP's wall-clock time rather than twice it. Its unit tests check
it against the `argon2` crate and the reference implementation's example.

They were canaried behind their own switch and compared with the PHP classes by a parity corpus
(1,374 lines: reflection, the option API, every hash scheme PHP makes and verifies, malformed
hashes, subclass overrides, dumps, clones and serialization), removed with them. Known differences
from the PHP classes they replaced, which no caller depends on:

- `setOptions()` with an integer key throws `Hash.php`'s `TypeError` without PHP's
  `, called in .../Hash.php on line N`.
- `Scrypt` and `ScryptModified` work without the PECL `scrypt` extension, where PHP throws
  `RuntimeException('The scrypt extension is required...')`; a key length PHP cannot allocate is a
  fatal error there and `false` here (then `RuntimeException`).
- `Argon2` refuses more than 4 GiB of memory with `ValueError('Memory allocation error')`, where
  libargon2 tries to allocate it.
- `PHPass::getRandomBytes()` reads `/dev/urandom` without consulting `open_basedir`.
- `Argon2` fills at most 16 lanes at once, where libargon2 starts a thread for every lane; the
  hash is the same.

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
- **Threads and forks.** The extension starts no thread pool. `Argon2` fills its lanes on scoped
  threads that are joined before the call returns, as libargon2's are, so none is left when the
  worker forks. The pure-Rust AVIF encoder
  (the crate's default `ravif` feature) is left out: with its pool, a process that had
  encoded an AVIF and then forked left the child waiting forever on its next encode.


`crates/support/php-std` here holds only the functions `utopia-image` calls, copied
unchanged from the full crate on `feat/rust-utopia-conversion`.
