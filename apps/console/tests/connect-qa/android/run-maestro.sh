#!/usr/bin/env bash
# Runs the shared Maestro auth flow against the emulator, retrying once.
# Lives in its own file because android-emulator-runner executes each line
# of its `script` input as a separate `sh -c`, which breaks multi-line
# shell constructs. Expects APP_ID in the environment and adb on PATH.
set -u

mkdir -p artifacts/maestro

run_flow() {
  mise run test \
    --debug-output="$PWD/artifacts/maestro" \
    --flatten-debug-output \
    --format=junit \
    --output="$PWD/artifacts/maestro/report.xml"
}

status=0
run_flow || {
  echo "::warning::Maestro flow failed - retrying once"
  adb shell am force-stop "$APP_ID" || true
  run_flow || status=1
}

adb logcat -d > artifacts/logcat.txt 2>&1 || true
exit $status
