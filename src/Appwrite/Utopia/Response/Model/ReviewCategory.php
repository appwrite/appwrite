<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewCategory extends Model
{
    public function __construct()
    {
        $this
            ->addRule('category', [
                'type' => self::TYPE_STRING,
                'description' => 'Finding category, such as bug, security or performance.',
                'default' => '',
                'example' => 'bug',
            ])
            ->addRule('findings', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings of the category posted on pull requests.',
                'default' => 0,
                'example' => 31,
            ])
            ->addRule('resolved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings fixed by a later commit.',
                'default' => 0,
                'example' => 18,
            ])
            ->addRule('dismissed', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings settled without a change: declined in their thread, or whose thread a maintainer resolved.',
                'default' => 0,
                'example' => 3,
            ]);
    }

    public function getName(): string
    {
        return 'Review Category';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_CATEGORY;
    }
}
