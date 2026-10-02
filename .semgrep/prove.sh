#!/usr/bin/env bash
# Prove ERROR classes fire on known-bad snippets and stay quiet on known-ok
# gated skips. Scratch files live under src/Appwrite only for this process
# (rule path includes) and are removed on exit.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SEMGREP="${SEMGREP:-semgrep}"
SCRATCH="$ROOT/src/Appwrite/Platform/Modules/_semgrep_prove"
mkdir -p "$SCRATCH"
trap 'rm -rf "$SCRATCH"' EXIT

fail() {
    echo "prove: $*" >&2
    exit 1
}

scan_error() {
    "$SEMGREP" scan \
        --config .semgrep \
        --metrics=off \
        --quiet \
        --error \
        --severity ERROR \
        --exclude .semgrep \
        "$@"
}

# Known-bad: ungated skip of a non-allowlisted collection must ERROR.
cat >"$SCRATCH/bad-skip.php" <<'PHP'
<?php
$transaction = $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId));
PHP

if scan_error "$SCRATCH/bad-skip.php"; then
    fail "expected ERROR on ungated skip+getDocument('transactions')"
fi
echo "ok: known-bad skip+load of transactions failed ERROR scan"

# Known-ok: privileged/API-key gate must not ERROR.
cat >"$SCRATCH/ok-skip.php" <<'PHP'
<?php
$transaction = ($isAPIKey || $isPrivilegedUser)
    ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
    : $dbForProject->getDocument('transactions', $transactionId);
PHP

scan_error "$SCRATCH/ok-skip.php" || fail "gated skip+load of transactions should be clean"
echo "ok: gated skip+load of transactions is clean"

# Known-ok: allowlisted metadata skip must not ERROR.
cat >"$SCRATCH/ok-meta.php" <<'PHP'
<?php
$database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
PHP

scan_error "$SCRATCH/ok-meta.php" || fail "metadata skip of databases should be clean"
echo "ok: allowlisted metadata skip is clean"
