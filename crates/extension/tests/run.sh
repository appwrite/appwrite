#!/bin/sh
# Parity corpus of every switchable library in src/lib.rs (see README) against its PHP package,
# alone and with all on; then the package tests of the classes that have no PHP left.
set -eu

tests=crates/extension/tests
libraries=$(grep -o 'enabled("[a-z-]*")' crates/extension/src/lib.rs | sed 's/enabled("\(.*\)")/\1/' | sort -u)
all=$(echo "$libraries" | paste -sd, -)
output=$(mktemp -d)
trap 'rm -r "$output"' EXIT

php --ri utopia > /dev/null
php "$tests/canvas.php" > /dev/null
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php packages/image/tests

for library in $libraries; do
    echo "== $library" >&2
    [ -f "$tests/$library.php" ] || { echo "missing parity corpus $tests/$library.php" >&2; exit 1; }
    # packages/query/tests/E2E needs MySQL, PostgreSQL, ClickHouse and MongoDB servers.
    unit=; [ "$library" = query ] && unit='--exclude-filter \\E2E\\'
    # database is utopia-php/database in vendor/: the unit tests of the classes the extension serves.
    suite="packages/$library/tests"
    [ "$library" = database ] && suite=$(printf 'vendor/utopia-php/database/tests/unit/%sTest.php ' Id Role Permission DateTime)
    # shellcheck disable=SC2086
    _APP_EXTENSION_LIBRARIES=$library vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php $unit $suite
    _APP_EXTENSION_LIBRARIES= php "$tests/$library.php" > "$output/$library.php.jsonl"
    _APP_EXTENSION_LIBRARIES=$library php "$tests/$library.php" > "$output/$library.rust.jsonl"
    diff "$output/$library.php.jsonl" "$output/$library.rust.jsonl"
    [ -s "$output/$library.rust.jsonl" ] || { echo "$library corpus printed nothing" >&2; exit 1; }
done

[ -z "$libraries" ] || echo "== $all" >&2
for library in $libraries; do
    _APP_EXTENSION_LIBRARIES=$all php "$tests/$library.php" > "$output/$library.all.jsonl"
    diff "$output/$library.php.jsonl" "$output/$library.all.jsonl"
done

# query has two more corpora: its builders, and the builders when a build yields and another
# runs on the same thread.
for corpus in query-builders query-coroutines; do
    echo "== $corpus" >&2
    _APP_EXTENSION_LIBRARIES= php "$tests/$corpus.php" > "$output/$corpus.php.jsonl"
    _APP_EXTENSION_LIBRARIES=query php "$tests/$corpus.php" > "$output/$corpus.rust.jsonl"
    diff "$output/$corpus.php.jsonl" "$output/$corpus.rust.jsonl"
    [ -s "$output/$corpus.rust.jsonl" ] || { echo "$corpus corpus printed nothing" >&2; exit 1; }
done

# A PHP subclass of every class, cached by opcache: what the extension's defaults and constants
# must survive (opcache frees what it copies into shared memory as PHP's allocator's).
for with in "" ${libraries:+"$all"}; do
    echo "== opcache (${with:-no switches})" >&2
    _APP_EXTENSION_LIBRARIES=$with php -d opcache.enable_cli=1 "$tests/opcache.php"
done

# utopia.libraries (README, "Using it outside Appwrite"). Unset or empty, the extension registers
# exactly the classes pinned in libraries.expected (_APP_EXTENSION_LIBRARIES switches the rest on);
# set, only the libraries it names, whatever _APP_EXTENSION_LIBRARIES says.
echo "== utopia.libraries" >&2
gate="$tests/libraries.php"
every=dsn,domains,emails,image,locale,user-agent,validators,auth,detector,compression,query,balancer,database
_APP_EXTENSION_LIBRARIES= php "$gate" > "$output/libraries.unset"
diff "$tests/libraries.expected" "$output/libraries.unset" ||
    { echo "the default classes changed: update tests/libraries.expected" >&2; exit 1; }
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries= "$gate" | diff "$tests/libraries.expected" -
_APP_EXTENSION_LIBRARIES= php -d 'utopia.libraries= , ' "$gate" | diff "$tests/libraries.expected" -
_APP_EXTENSION_LIBRARIES= php "$gate" ini= > /dev/null
_APP_EXTENSION_LIBRARIES=$all php "$gate" > "$output/libraries.switched"
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=$every "$gate" | diff "$output/libraries.switched" -
# Edge and DNS, with _APP_EXTENSION_LIBRARIES naming what they leave out.
_APP_EXTENSION_LIBRARIES=$all php -d utopia.libraries=validators,dsn,user-agent "$gate" \
    ini=validators,dsn,user-agent \
    +'Utopia\Validator\Validator' +'Utopia\Validator\Text' +'Utopia\Validator\Domain' +'Utopia\Validator\AnyOf' \
    +'Utopia\DSN\DSN' +'Utopia\UserAgent\UserAgent' -'Utopia\Domains\Domain' -'Utopia\Emails\Email' \
    -'Utopia\Auth\Hash' -'Utopia\Auth\Hashes\Argon2' -'Utopia\Image\Canvas' -'Utopia\Image\Font' \
    -'Utopia\Locale\Locale' -'Utopia\Detector\Detector' -'Utopia\Compression\Compression' -'Utopia\Balancer\Balancer' > /dev/null
_APP_EXTENSION_LIBRARIES=$all php -d utopia.libraries=validators,dsn,domains "$gate" \
    +'Utopia\Validator\Text' +'Utopia\DSN\DSN' +'Utopia\Domains\Domain' -'Utopia\Emails\Email' \
    -'Utopia\UserAgent\UserAgent' -'Utopia\Auth\Hashes\Argon2' -'Utopia\Image\Canvas' -'Utopia\Locale\Locale' \
    -'Utopia\Detector\Detector' -'Utopia\Compression\Compression' -'Utopia\Balancer\Option' > /dev/null
# The same from a scanned .ini file, as a service's utopia.ini sets it.
mkdir "$output/conf.d"
printf 'utopia.libraries = "validators, dsn, domains"\n' > "$output/conf.d/zz-libraries.ini"
PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')}:$output/conf.d" php "$gate" \
    +'Utopia\Domains\Domain' -'Utopia\UserAgent\UserAgent' > /dev/null
php -d utopia.libraries=validators,dsn,user-agent --ri utopia | grep -q '^utopia.libraries => validators,dsn,user-agent' ||
    { echo "php --ri utopia does not show utopia.libraries" >&2; exit 1; }
# A switchable library named registers without _APP_EXTENSION_LIBRARIES; an old validators group
# name means all of them; emails needs domains (Email builds a Domain), so it brings it.
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=detector "$gate" \
    +'Utopia\Detector\Detector' -'Utopia\Compression\Compression' -'Utopia\Validator\Validator' > /dev/null
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=compression "$gate" \
    +'Utopia\Compression\Compression' -'Utopia\Detector\Detector' -'Utopia\Balancer\Balancer' > /dev/null
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=balancer "$gate" \
    +'Utopia\Balancer\Balancer' +'Utopia\Balancer\Option' +'Utopia\Balancer\Algorithm\RoundRobin' +'Utopia\Balancer\Group' \
    -'Utopia\Compression\Compression' -'Utopia\Validator\Validator' > /dev/null
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=validators-scalar "$gate" \
    +'Utopia\Validator\Integer' +'Utopia\Validator\Text' +'Utopia\Validator\URL' +'Utopia\Validator\AnyOf' > /dev/null
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=emails "$gate" \
    +'Utopia\Emails\Email' +'Utopia\Domains\Domain' -'Utopia\Validator\Validator' > /dev/null
php -d utopia.libraries=emails -r 'new Utopia\Emails\Email("a@example.com");'
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=database "$gate" \
    +'Utopia\Database\Id' +'Utopia\Database\Role' +'Utopia\Database\Permission' +'Utopia\Database\DateTime' \
    -'Utopia\Database\PermissionType' -'Utopia\Balancer\Balancer' -'Utopia\Validator\Validator' > /dev/null
_APP_EXTENSION_LIBRARIES= php -d utopia.libraries=query "$gate" \
    +'Utopia\Query\NativeQuery' +'Utopia\Query\Builder\MySQL' -'Utopia\Validator\Validator' > /dev/null
# An unknown name: a startup warning naming it, and the rest of the list still applies.
warnings=$(php -d utopia.libraries=validators,bogus,dsn "$gate" +'Utopia\Validator\Text' +'Utopia\DSN\DSN' -'Utopia\Locale\Locale' 2>&1)
echo "$warnings" | grep -q 'utopia.libraries: unknown library "bogus" ignored' ||
    { echo "no warning for an unknown library: $warnings" >&2; exit 1; }
echo "$warnings" | grep -q '^ok$'

# Classes with no PHP implementation left: their packages' own tests, on the extension.
for package in user-agent domains emails validators auth; do
    echo "== $package (no PHP)" >&2
    vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php "packages/$package/tests"
done

# Locale has no package to test, so its corpus is checked against the output pinned from the PHP
# class it replaced (utopia-php/locale).
echo "== locale (pinned PHP output)" >&2
php -d precision=-1 "$tests/locale.php" > "$output/locale.jsonl"
diff "$tests/locale.expected.jsonl" "$output/locale.jsonl"
