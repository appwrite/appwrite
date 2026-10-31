#!/usr/bin/env bash
# Ports packages/vcs/tests/E2E to tests/compat/vcs/cases/E2E-*.json (see
# record.php): runs each E2E test class in a throwaway container of the dev
# image against the fixture server started inside it, then fills in the
# results with `bin/compat record vcs`.
#
#   tests/compat/vcs/recorder/record.sh [E2E/GiteaTest GiteaTest ...]
set -euo pipefail
cd "$(dirname "$0")/../../../.."
classes=("$@")
if [ ${#classes[@]} -eq 0 ]; then
    classes=(BitbucketTest ForgejoTest GitHubTest GitLabTest GiteaTest GogsTest
        E2E/BitbucketTest E2E/ForgejoTest E2E/GitHubTest E2E/GitLabTest E2E/GiteaTest E2E/GogsTest)
fi
certs=/tmp/compat-fs/vcs-certs
for class in "${classes[@]}"; do
    # --network none: nothing the tests ask for can leave the container
    docker run --rm -i --network none --user "$(id -u):$(id -g)" \
        -v "$PWD/tests:/usr/src/code/tests" -v "$PWD/packages:/usr/src/code/packages" -v "$certs:$certs" \
        -w /usr/src/code --entrypoint sh "${COMPAT_IMAGE:-appwrite-dev}" -c '
            php tests/compat/vcs/fixtures/run.php 8080 8443 '"$certs"' > /tmp/fixtures.log 2>&1 &
            server=$!
            for _ in $(seq 1 50); do
                curl -sf -o /dev/null http://127.0.0.1:8080/_fixture/ready/caught && break
                sleep 0.2
            done
            php -d memory_limit=1G tests/compat/vcs/recorder/record.php tests/compat/vcs/cases "$0" || true
            kill "$server"
        ' "$class"
done
