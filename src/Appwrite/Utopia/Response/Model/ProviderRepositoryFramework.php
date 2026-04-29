<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ProviderRepositoryFramework extends ProviderRepository
{
    public function __construct()
    {
        parent::__construct();

        $this->addRule('framework', [
            'type' => self::TYPE_STRING,
            'description' => 'Auto-detected framework. Empty if type is not "framework".',
            'default' => '',
            'example' => 'nextjs',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ProviderRepositoryFramework';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK;
    }
}
