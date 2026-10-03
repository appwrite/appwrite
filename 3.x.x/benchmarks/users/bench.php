# PHP twin for Users create+get benchmark.
# Run against a live PHP Appwrite endpoint (see run.sh).
# This file documents the PHP side of the comparison; the actual load is
# driven by run.sh curl loops so both backends share the same client path.

<?php

declare(strict_types=1);

/**
 * Expected env: APPWRITE_ENDPOINT, PROJECT_ID, API_KEY, N
 * Prefer ./run.sh which hits both backends identically.
 */
echo "Use 3.x.x/benchmarks/users/run.sh with PHP_DIRECT and RUST_DIRECT\n";
