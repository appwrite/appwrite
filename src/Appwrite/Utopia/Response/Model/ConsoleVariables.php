<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ConsoleVariables extends Model
{
    public function __construct()
    {
        $this
            ->addRule('_APP_DOMAIN_TARGET', [
                'type' => self::TYPE_STRING,
                'description' => 'CNAME target for your Appwrite custom domains.',
                'default' => '',
                'example' => '1.3.0',
            ])
            ->addRule('_APP_STORAGE_LIMIT', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum file size allowed for file upload in bytes.',
                'default' => '',
                'example' => '1.3.0',
            ])
            ->addRule('_APP_FUNCTIONS_SIZE_LIMIT', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum file size allowed for deployment in bytes.',
                'default' => '',
                'example' => '1.3.0',
            ])
            ->addRule('_APP_USAGE_STATS', [
                'type' => self::TYPE_STRING,
                'description' => 'Defines if usage stats are enabled. This value is set to \'enabled\' by default, to disable the usage stats set the value to \'disabled\'.',
                'default' => '',
                'example' => '1.3.0',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Console Variables';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_CONSOLE_VARIABLES;
    }
}
