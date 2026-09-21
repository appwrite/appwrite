<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;

class ProjectAuthMethod extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_ENUM,
                'description' => 'Auth method ID.',
                'default' => '',
                'example' => 'email-password',
                'enum' => \array_keys(Config::getParam('auth', [])),
                'enumSDKName' => 'ProjectAuthMethodId',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Auth method status.',
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
        return 'ProjectAuthMethod';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROJECT_AUTH_METHOD;
    }
}
