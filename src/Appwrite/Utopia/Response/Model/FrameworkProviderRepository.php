<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class FrameworkProviderRepository extends ProviderRepository
{
    public function __construct()
    {
        parent::__construct();

        $this->addRule('framework', [
            'type' => self::TYPE_STRING,
            'description' => 'Auto-detected framework suggestion. Empty if getting response of getFramework().',
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
        return 'FrameworkProviderRepository';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FRAMEWORK_PROVIDER_REPOSITORY;
    }
}
