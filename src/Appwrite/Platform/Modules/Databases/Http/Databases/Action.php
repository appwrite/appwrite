<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Utopia\Platform\Action as UtopiaAction;
use Utopia\System\System;

class Action extends UtopiaAction
{
    private string $context = 'legacy';

    public function getDatabaseType(): string
    {
        return $this->context;
    }

    public function getDatabaseDSN(): string
    {
        $database = System::getEnv('_APP_DATABASE_NAME', 'appwrite');
        $namespace = System::getEnv('_APP_DATABASE_SHARED_NAMESPACE', '');

        $schema = match ($this->context) {
            'documentsdb' => 'mongodb',
            'tablesdb'    => 'mysql',
            default       => 'mysql',
        };

        $dsn = $schema . '://' . $database;

        if (!empty($namespace)) {
            $dsn .= '?namespace=' . $namespace;
        }

        return $dsn;
    }

    public function setHttpPath(string $path): UtopiaAction
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = 'tablesdb';
        }
        if (\str_contains($path, '/documentsdb')) {
            $this->context = 'documentsdb';
        }
        return parent::setHttpPath($path);
    }
}
