<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ProviderRepositoryFrameworkList extends BaseList
{
    public array $conditions = [
        'type' => 'framework',
    ];

    public function __construct()
    {
        parent::__construct(
            'Framework Provider Repositories List',
            Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK_LIST,
            'frameworkProviderRepositories',
            Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK
        );

        $this->addRule('type', [
            'type' => self::TYPE_STRING,
            'description' => 'Provider repository list type.',
            'default' => 'framework',
            'example' => 'framework',
        ]);
    }
}
