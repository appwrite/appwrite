<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageDatabases extends Model
{
    public function __construct()
    {
        $this
            ->addRule('range', [
                'type' => self::TYPE_STRING,
                'description' => 'The time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('databasesCount', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of documents.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsCount', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of documents.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('collectionsCount', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of collections.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databasesCreate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents created.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databasesRead', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents read.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databasesUpdate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents updated.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databasesDelete', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of collections.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsCreate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents created.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsRead', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents read.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsUpdate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents updated.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsDelete', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents deleted.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('collectionsCreate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for collections created.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('collectionsRead', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for collections read.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('collectionsUpdate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for collections updated.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('collectionsDelete', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for collections delete.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'UsageDatabases';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_DATABASES;
    }
}
