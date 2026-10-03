<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Explanation extends Model
{
    public function getName(): string
    {
        return 'Explanation';
    }

    public function getType(): string
    {
        return Response::MODEL_EXPLANATION;
    }

    public function __construct()
    {
        $this
            ->addRule('queries', [
                'type' => Response::MODEL_QUERY_PLAN_ENTRY,
                'description' => 'One plan entry per physical query Appwrite ran for this read, including find, optional count, and relationship sub-queries.',
                'default' => [],
                'example' => [],
                'array' => true,
            ]);
    }
}
