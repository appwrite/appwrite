<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DetectionRuntime extends Model
{
    public function __construct()
    {
        $this
            ->addRule('runtime', [
                'type' => self::TYPE_STRING,
                'description' => 'Runtime',
                'default' => '',
                'example' => 'node',
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'Function Entrypoint',
                'default' => '',
                'example' => 'index.js',
            ])
            ->addRule('commands', [
                'type' => self::TYPE_STRING,
                'description' => 'Function install and build commands',
                'default' => '',
                'example' => 'npm install && npm run build',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'DetectionRuntime';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DETECTION_RUNTIME;
    }
}
