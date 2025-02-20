#!/bin/sh

# Fail build if any command fails
set -e

max_attempts=180
attempt=0
success=0
while [ $attempt -lt $max_attempts ]; do
    command="curl -s \$_APP_EXECUTOR_HOST/health -H \"Authorization: Bearer \$_APP_EXECUTOR_SECRET\""
    response=$(docker compose exec appwrite sh -c "$command")

    if [[ $response == *"runtimes"* ]]; then
        success=1
        break
    else
        echo "Health check failed, retrying..."
        sleep 1
        ((attempt++))
    fi
done

if [ $success -eq 0 ]; then
    echo "Failed to start Open Runtimes executor"
    exit 1
fi

echo "Open Runtimes executor started"