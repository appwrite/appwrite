#!/bin/sh
# Standalone KEDA ScaledJob e2e: stand up kind + KEDA + Redis + the ScaledJob,
# then run KedaTest, which pushes messages and asserts KEDA spawns worker Jobs
# that drain the queue. Needs docker + kind + kubectl + helm (auto-installed if
# missing). tests/e2e.sh runs the same setup as part of the full suite in CI.
set -e
cd "$(dirname "$0")/.."

. tests/keda-lib.sh

trap keda_down EXIT INT TERM
keda_up

../../vendor/bin/phpunit tests/Queue/E2E/Adapter/KedaTest.php
