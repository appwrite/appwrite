<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ReviewRepository extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Repository ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date code review was turned on for the repository, in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Repository update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('installationId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) installation ID the repository is connected through.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('providerRepositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) repository ID.',
                'default' => '',
                'example' => '583127841',
            ]);
    }

    public function getName(): string
    {
        return 'Review Repository';
    }

    public function getType(): string
    {
        return Response::MODEL_REVIEW_REPOSITORY;
    }
}
