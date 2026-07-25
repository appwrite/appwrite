<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ProviderRepositoryRuntimeList extends BaseList
{
    public array $conditions = [
        'type' => 'runtime',
    ];

    public function __construct()
    {
        parent::__construct(
            'Runtime Provider Repositories List',
            Response::MODEL_PROVIDER_REPOSITORY_RUNTIME_LIST,
            'runtimeProviderRepositories',
            Response::MODEL_PROVIDER_REPOSITORY_RUNTIME
        );

        $this->addRule('type', [
            'type' => self::TYPE_STRING,
            'description' => 'Provider repository list type.',
            'default' => 'runtime',
            'example' => 'runtime',
        ]);
    }
}
