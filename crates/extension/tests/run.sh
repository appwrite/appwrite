#!/bin/sh
# Parity corpus of every library in src/lib.rs (see README): a switchable library against its PHP
# package, alone (a batch with its package's switch) and with all on; a library without PHP against tests/<name>.expected.jsonl.
set -eu

tests=crates/extension/tests
libraries=$(grep -o 'enabled("[a-z-]*")' crates/extension/src/lib.rs | sed 's/enabled("\(.*\)")/\1/' | sort -u)
[ -n "$libraries" ] || { echo "no libraries found in crates/extension/src/lib.rs" >&2; exit 1; }
all=$(echo "$libraries" | paste -sd, -)
output=$(mktemp -d)
trap 'rm -r "$output"' EXIT

php --ri utopia > /dev/null
php "$tests/canvas.php" > /dev/null
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php packages/image/tests

for library in $libraries; do
    echo "== $library" >&2
    [ -f "$tests/$library.php" ] || { echo "missing parity corpus $tests/$library.php" >&2; exit 1; }
    # A batch of a package's classes (`validators-scalar`) is switched on with the package's
    # own switch (`validators`), and runs that package's tests.
    package=$library
    with=$library
    if [ ! -d "packages/$library" ]; then
        package=${library%-*}
        with="$package,$library"
    fi
    _APP_EXTENSION_LIBRARIES=$with vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php "packages/$package/tests"
    _APP_EXTENSION_LIBRARIES= php "$tests/$library.php" > "$output/$library.php.jsonl"
    _APP_EXTENSION_LIBRARIES=$with php "$tests/$library.php" > "$output/$library.rust.jsonl"
    diff "$output/$library.php.jsonl" "$output/$library.rust.jsonl"
    [ -s "$output/$library.rust.jsonl" ] || { echo "$library corpus printed nothing" >&2; exit 1; }
done

echo "== $all" >&2
for library in $libraries; do
    _APP_EXTENSION_LIBRARIES=$all php "$tests/$library.php" > "$output/$library.all.jsonl"
    diff "$output/$library.php.jsonl" "$output/$library.all.jsonl"
done

for expected in "$tests"/*.expected.jsonl; do
    library=$(basename "$expected" .expected.jsonl)
    echo "== $library (no PHP)" >&2
    if [ -d "packages/$library/tests" ]; then
        vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php "packages/$library/tests"
    fi
    _APP_EXTENSION_LIBRARIES=$all php "$tests/$library.php" > "$output/$library.jsonl"
    diff "$expected" "$output/$library.jsonl"
done
