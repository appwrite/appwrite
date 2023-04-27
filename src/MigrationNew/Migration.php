<?php

namespace Appwrite\Migration;

use Swoole\Runtime;
use Utopia\Database\Document;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Exception;
use Utopia\App;
use Utopia\Database\ID;
use Utopia\Database\Validator\Authorization;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

class MigrationNew
{
    public function __construct()
    {
        $schema1 = $this->loadSchema(__DIR__ . '/../../app/config/collections/1.1.x.php');
        $schema2 = $this->loadSchema(__DIR__ . '/../../app/config/collections/1.2.x.php');

        $differences = $this->compareSchemas($schema1, $schema2);
    }

    protected function loadSchema(string $path): array
    {
        $path = realpath($path);
        if (!file_exists($path)) {
            throw new Exception("Schema file not found: " . $path);
        }

        return include $path;
    }

    protected function compareSchemas(array $schema1, array $schema2): array
    {
        $differences = [];

        // Compare collections
        foreach ($schema1 as $key => $collection) {
            if (!isset($schema2[$key])) {
                $differences[] = [
                    'type' => 'collection_removed',
                    'collection' => $key
                ];
                continue;
            }

            $collection2 = $schema2[$key];

            // Compare attributes
            foreach ($collection['attributes'] as $attribute) {
                $id = $attribute['$id'];
                $found = false;
                foreach ($collection2['attributes'] as $attribute2) {
                    if ($attribute2['$id'] === $id) {
                        $found = true;
                        if ($attribute != $attribute2) {
                            $differences[] = [
                                'type' => 'attribute_updated',
                                'collection' => $key,
                                'attribute' => $id,
                                'old' => $attribute,
                                'new' => $attribute2
                            ];
                        }
                        break;
                    }
                }

                if (!$found) {
                    $differences[] = [
                        'type' => 'attribute_removed',
                        'collection' => $key,
                        'attribute' => $id
                    ];
                }
            }

            foreach ($collection2['attributes'] as $attribute2) {
                $id = $attribute2['$id'];
                $found = false;
                foreach ($collection['attributes'] as $attribute) {
                    if ($attribute['$id'] === $id) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $differences[] = [
                        'type' => 'attribute_added',
                        'collection' => $key,
                        'attribute' => $id
                    ];
                }
            }
        }

        return $differences;
    }
}
