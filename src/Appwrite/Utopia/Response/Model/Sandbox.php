<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Sandbox extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Sandbox ID.',
                'default' => '',
                'example' => 'agent-run-42',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Sandbox status. Possible values: `creating`, `ready`, `failed`, `deleting`.',
                'default' => '',
                'example' => 'ready',
                'enum' => ['creating', 'ready', 'failed', 'deleting'],
                'enumSDKName' => 'SandboxStatus',
            ])
            ->addRule('url', [
                'type' => self::TYPE_STRING,
                'description' => 'The sandbox URL serving the sandbox contract. Treat it as a secret: anyone who can reach it can run commands in the sandbox. Empty when the sandbox has failed.',
                'default' => '',
                'example' => 'https://s-9f3c1a04b7e28d65f1024c8ba3e7d95f.sandboxes.appwrite.run',
                'sensitive' => true,
            ])
            ->addRule('urls', [
                'type' => self::TYPE_JSON,
                'description' => 'Every URL the sandbox serves, keyed by port number.',
                'default' => new \stdClass(),
                'example' => new \stdClass(),
                'sensitive' => true,
            ])
            ->addRule('error', [
                'type' => self::TYPE_STRING,
                'description' => 'Why the sandbox failed, when it did.',
                'default' => '',
                'example' => '',
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
        return 'Sandbox';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SANDBOX;
    }
}
