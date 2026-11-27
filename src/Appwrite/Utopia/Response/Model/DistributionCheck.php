<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionCheck extends Model
{
    public function __construct()
    {
        $this
            ->addRule('ready', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether no issue blocks the next build or submission.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('issues', [
                'type' => Response::MODEL_DISTRIBUTION_CHECK_ISSUE,
                'description' => 'Issues found by the check.',
                'default' => [],
                'required' => false,
                'example' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'DistributionCheck';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_CHECK;
    }
}
