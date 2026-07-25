<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ProviderRepositoryRuntime extends ProviderRepository
{
    public function __construct()
    {
        parent::__construct();

        $this->addRule('runtime', [
            'type' => self::TYPE_STRING,
            'description' => 'Auto-detected runtime. Empty if type is not "runtime".',
            'default' => '',
            'example' => 'node-22',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ProviderRepositoryRuntime';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROVIDER_REPOSITORY_RUNTIME;
    }
}
