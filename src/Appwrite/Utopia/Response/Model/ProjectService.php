<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;

class ProjectService extends Model
{
    public function __construct()
    {
        $this
        ->addRule('$id', [
            'type' => self::TYPE_ENUM,
            'description' => 'Service ID.',
            'default' => '',
            'example' => 'sites',
            'enum' => \array_keys(\array_filter(Config::getParam('services', []), fn ($element) => $element['optional'])),
            'enumSDKName' => 'ProjectServiceId',
        ])
        ->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Service status.',
            'example' => false,
            'default' => true,
        ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ProjectService';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROJECT_SERVICE;
    }
}
