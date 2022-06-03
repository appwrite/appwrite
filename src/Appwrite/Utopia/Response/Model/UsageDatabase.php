<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageDatabase extends Model
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
            ->addRule('documentsCount', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of documents.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('collectionsCount', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of collections.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('documentsCreate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents created.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('documentsRead', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents read.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('documentsUpdate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents updated.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('documentsDelete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents deleted.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('collectionsCreate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections created.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('collectionsRead', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections read.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('collectionsUpdate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections updated.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('collectionsDelete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections delete.',
                'default' => [],
                'example' => new \stdClass(),
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
        return 'UsageDatabase';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_DATABASE;
    }
}
