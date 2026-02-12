<?php

/**
 * Patch: Fix PostgreSQL boolean type mismatch in batch inserts.
 *
 * In utopia-php/database's SQL adapter, createDocuments() unconditionally casts
 * boolean values to integers. PostgreSQL rejects this because it has a native
 * BOOLEAN type and doesn't accept integer expressions for boolean columns.
 *
 * The single-document path (updateDocument) already has this fix (instanceof check),
 * but createDocuments() was missed.
 *
 * TODO: Remove once utopia-php/database is updated with the upstream fix.
 */

$file = '/usr/src/code/vendor/utopia-php/database/src/Database/Adapter/SQL.php';
$content = file_get_contents($file);

if ($content === false) {
    echo "ERROR: Could not read {$file}\n";
    exit(1);
}

$search = '} else {
                        $value = (\is_bool($value)) ? (int)$value : $value;';

$replace = '} else {
                        if (!($this instanceof \Utopia\Database\Adapter\Postgres && \is_bool($value))) { $value = (\is_bool($value)) ? (int)$value : $value; }';

$patched = str_replace($search, $replace, $content);

if ($patched === $content) {
    echo "WARNING: Patch pattern not found in {$file} - may already be fixed upstream\n";
    exit(0);
}

file_put_contents($file, $patched);
echo "Patched PostgreSQL boolean type handling in {$file}\n";
