<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewSummary extends Model
{
    public function __construct()
    {
        $this
            ->addRule('periods', [
                'type' => Response::MODEL_REVIEW_PERIOD,
                'description' => 'The range, then the same length of time before it.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('days', [
                'type' => Response::MODEL_REVIEW_DAY,
                'description' => 'Each day of the range, oldest first.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('repositories', [
                'type' => Response::MODEL_REVIEW_REPOSITORY_SUMMARY,
                'description' => 'Each repository with a review in the range, the most reviewed pull requests first.',
                'default' => [],
                'example' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'Review Summary';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_SUMMARY;
    }
}
