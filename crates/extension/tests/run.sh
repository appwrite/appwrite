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
    _APP_EXTENSION_LIBRARIES=$library vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php "packages/$library/tests"
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

# Classes with no PHP implementation left: their packages' own tests, on the extension.
for package in user-agent domains emails; do
    echo "== $package (no PHP)" >&2
    vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php "packages/$package/tests"
done
