<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

abstract class Detection extends Model
{
    public function __construct(string $type)
    {
        $this
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'Repository detection type.',
                'default' => $type,
                'example' => $type,
                'enum' => [$type],
            ])
            ->addRule('variables', [
                'type' => Response::MODEL_DETECTION_VARIABLE,
                'description' => 'Environment variables found in .env files',
                'required' => false,
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ]);
    }
}
