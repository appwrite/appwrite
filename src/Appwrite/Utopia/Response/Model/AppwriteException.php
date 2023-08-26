<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;

class AppwriteException extends Model
{
    public function __construct()
    {
        $enum = [];
        foreach (Config::getParam('errors', []) as $key => $value) {
            $enum[] = $key;
        }
        $this
            ->addRule('message', [
              'type' => self::TYPE_STRING,
                'description' => 'Error message.',
                'example' => 'Invalid id: Parameter must be a valid number',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Error type.',
                'enum' => $enum,
                'example' => "argument_invalid"
            ])
            ->addRule("code", [
              "type" => self::TYPE_INTEGER,
              "description" => "Error code.",
              "example" => 400,
              "format" => "int32"
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
        return 'Appwrite Exception';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_APPWRITE_EXCEPTION;
    }
}
