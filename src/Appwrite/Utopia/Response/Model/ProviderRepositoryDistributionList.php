<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ProviderRepositoryDistributionList extends BaseList
{
    public array $conditions = [
        'type' => 'distribution',
    ];

    public function __construct()
    {
        parent::__construct(
            'Distribution Provider Repositories List',
            Response::MODEL_PROVIDER_REPOSITORY_DISTRIBUTION_LIST,
            'distributionProviderRepositories',
            Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK
        );

        $this->addRule('type', [
            'type' => self::TYPE_STRING,
            'description' => 'Provider repository list type.',
            'default' => 'distribution',
            'example' => 'distribution',
        ]);
    }
}
