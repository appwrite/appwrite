#!/bin/sh
# Parity corpus of every class in src/lib.rs (see README): each library's package tests on the
# extension, and its corpus against tests/<name>.expected.jsonl, the output of the PHP class it
# replaced.
set -eu

tests=crates/extension/tests
output=$(mktemp -d)
trap 'rm -r "$output"' EXIT

php --ri utopia > /dev/null
php "$tests/canvas.php" > /dev/null
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php packages/image/tests

found=0
for expected in "$tests"/*.expected.jsonl; do
    library=$(basename "$expected" .expected.jsonl)
    echo "== $library" >&2
    if [ -d "packages/$library/tests" ]; then
        vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php "packages/$library/tests"
    fi
    php "$tests/$library.php" > "$output/$library.jsonl"
    diff "$expected" "$output/$library.jsonl"
    found=$((found + 1))
done
[ "$found" -gt 0 ] || { echo "no tests/<name>.expected.jsonl found" >&2; exit 1; }
