<?php

namespace Appwrite\Platform\Modules\Databases;

use Appwrite\Extend\Exception;
use Utopia\Config\Config;
use Utopia\DSN\DSN;
use Utopia\System\System;

final class Pool
{
    public static function dsn(string $type, string $region, ?string $projectDsn): string
    {
        $databases = [];
        $keys = '';
        $override = null;
        $scheme = '';
        $sharedTables = [];

        switch ($type) {
            case DATABASE_TYPE_DOCUMENTSDB:
                $databases = Config::getParam('pools-documentsdb', []);
                $keys = System::getEnv('_APP_DATABASE_DOCUMENTSDB_KEYS', '');
                $override = System::getEnv('_APP_DATABASE_DOCUMENTSDB_OVERRIDE');
                $scheme = System::getEnv('_APP_DB_HOST_DOCUMENTSDB', 'mongodb');
                $sharedTables = \array_filter(\explode(',', System::getEnv('_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES', '')));
                break;
            case DATABASE_TYPE_VECTORSDB:
                $databases = Config::getParam('pools-vectorsdb', []);
                $keys = System::getEnv('_APP_DATABASE_VECTORSDB_KEYS', '');
                $override = System::getEnv('_APP_DATABASE_VECTORSDB_OVERRIDE');
                $scheme = System::getEnv('_APP_DB_HOST_VECTORSDB', 'postgresql');
                $sharedTables = \array_filter(\explode(',', System::getEnv('_APP_DATABASE_VECTORSDB_SHARED_TABLES', '')));
                break;
            default:
                if (empty($projectDsn)) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "Project database DSN is required for {$type} databases");
                }

                return $projectDsn;
        }

        $projectUsesSharedTables = false;

        if (!empty($projectDsn)) {
            try {
                $parsedDsn = new DSN($projectDsn);
                $projectHost = $parsedDsn->getHost();
            } catch (\InvalidArgumentException) {
                $projectHost = $projectDsn;
            }

            $projectSharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));
            $projectUsesSharedTables = \in_array($projectHost, $projectSharedTables);
        }

        if ($region !== 'default') {
            $databases = \array_filter(
                \explode(',', $keys),
                fn (string $value): bool => \str_contains($value, $region)
            );
        }

        $index = \array_search($override, $databases);
        if ($index !== false) {
            $selectedDsn = $databases[$index];
        } else {
            if (!empty($projectDsn) && !empty($sharedTables)) {
                if ($projectUsesSharedTables) {
                    $databases = \array_filter($databases, fn (string $value): bool => \in_array($value, $sharedTables));
                } else {
                    $databases = \array_filter($databases, fn (string $value): bool => !\in_array($value, $sharedTables));
                }
            }

            if (empty($databases)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, "No {$type} database pool available for the current shared-tables mode");
            }

            $selectedDsn = $databases[\array_rand($databases)];
        }

        if (\in_array($selectedDsn, $sharedTables)) {
            $namespace = System::getEnv('_APP_DATABASE_SHARED_NAMESPACE', '');
            $selectedDsn = 'appwrite://' . $selectedDsn . '?database=appwrite';

            if (!empty($namespace)) {
                $selectedDsn .= '&namespace=' . $namespace;
            }
        }

        try {
            new DSN($selectedDsn);
        } catch (\InvalidArgumentException) {
            $selectedDsn = $scheme . '://' . $selectedDsn;
        }

        return $selectedDsn;
    }
}
