<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class RuntimeProviderRepository extends ProviderRepository
{
    public function __construct()
    {
        parent::__construct();

        $this->addRule('runtime', [
            'type' => self::TYPE_STRING,
            'description' => 'Auto-detected runtime suggestion. Empty if getting response of getRuntime().',
            'default' => '',
            'example' => 'node',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'RuntimeProviderRepository';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_RUNTIME_PROVIDER_REPOSITORY;
    }
}
