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
            ->addRule('useful', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings people found useful: liked with more 👍 than 👎, or, without either, fixed.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule('noisy', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings people found noisy: disliked with more 👎 than 👍, or, without either, dismissed or resolved without a change.',
                'default' => 0,
                'example' => 3,
            ])
            ->addRule('resolved', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings fixed by a later commit or said fixed in their thread.',
                'default' => 0,
                'example' => 18,
            ])
            ->addRule('dismissed', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Findings declined in their thread or whose thread a maintainer resolved without a change.',
                'default' => 0,
                'example' => 3,
            ])
            ->addRule('upvotes', [
                'type' => self::TYPE_INTEGER,
                'description' => '👍 reactions on the findings\' comments.',
                'default' => 0,
                'example' => 7,
            ])
            ->addRule('downvotes', [
                'type' => self::TYPE_INTEGER,
                'description' => '👎 reactions on the findings\' comments.',
                'default' => 0,
                'example' => 1,
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
