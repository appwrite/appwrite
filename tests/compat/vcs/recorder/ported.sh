#!/usr/bin/env bash
# Writes the hand-ported vcs cases (see ported.php) in a throwaway container
# of the dev image, with no network. Fill in their results afterwards with
# `bin/compat record vcs`.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
docker run --rm -i --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/tests:/usr/src/code/tests" -w /usr/src/code \
    --entrypoint php "${COMPAT_IMAGE:-appwrite-dev}" tests/compat/vcs/recorder/ported.php
