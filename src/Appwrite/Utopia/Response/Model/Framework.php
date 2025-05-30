<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Framework extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework key.',
                'default' => '',
                'example' => 'sveltekit',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework Name.',
                'default' => '',
                'example' => 'SvelteKit'
            ])
            ->addRule('buildRuntime', [
                'type' => self::TYPE_STRING,
                'description' => 'Default runtime version.',
                'default' => '',
                'example' => 'node-22',
            ])
            ->addRule('runtimes', [
                'type' => self::TYPE_STRING,
                'description' => 'List of supported runtime versions.',
                'default' => '',
                'example' => ['static-1', 'node-22'],
                'array' => true,
            ])
            ->addRule('adapters', [
                'type' => Response::MODEL_FRAMEWORK_ADAPTER,
                'description' => 'List of supported adapters.',
                'default' => '',
                'example' => [[ 'key' => 'static', 'buildRuntime' => 'node-22', 'buildCommand' => 'npm run build', 'installCommand' => 'npm install', 'outputDirectory' => './dist' ]],
                'array' => true,
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
        return 'Framework';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FRAMEWORK;
    }
}
