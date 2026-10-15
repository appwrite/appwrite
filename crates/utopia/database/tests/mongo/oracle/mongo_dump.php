<?php

require '/usr/src/code/vendor/autoload.php';

use MongoDB\BSON\Document as BsonDocument;

$manager = new MongoDB\Driver\Manager('mongodb://root:password@compat-database-mongo:27017/?directConnection=true');

function dump(MongoDB\Driver\Manager $manager, string $collection): array
{
    $cursor = $manager->executeQuery("crosscheck.$collection", new MongoDB\Driver\Query([], ['sort' => ['_uid' => 1]]));
    $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
    $rows = [];
    foreach ($cursor as $row) {
        $row['_id'] = is_string($row['_id']) && strlen($row['_id']) === 36 ? 'UUID' : $row['_id'];
        $json = BsonDocument::fromPHP($row)->toCanonicalExtendedJSON();
        $rows[] = $json;
    }
    $indexes = [];
    $cursor = $manager->executeCommand('crosscheck', new MongoDB\Driver\Command(['listIndexes' => $collection]));
    $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
    foreach ($cursor as $index) {
        unset($index['v']);
        $indexes[] = BsonDocument::fromPHP($index)->toCanonicalExtendedJSON();
    }
    sort($indexes);

    return ['rows' => $rows, 'indexes' => $indexes];
}

$php = dump($manager, 'php_movies');
$rust = dump($manager, 'rust_movies');
echo json_encode($php, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
echo $php === $rust ? "IDENTICAL\n" : "DIFFERENT\n";
if ($php !== $rust) {
    echo json_encode($rust, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
