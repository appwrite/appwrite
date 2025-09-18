<?php

namespace Appwrite\Deletes;

use Utopia\Database\Database;
use Utopia\Database\Query;

class Identities
{
    public static function delete(Database $database, Query $query): void
    {
        $database->deleteDocuments(
            'identities',
            [
                $query,
                Query::orderAsc()
            ],
            Database::DELETE_BATCH_SIZE
        );
    }

}
