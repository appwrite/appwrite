<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class HealthAntivirus extends Model
{
    public function __construct()
    {
        $this
            ->addRule('version', [
                'type' => self::TYPE_STRING,
                'description' => 'Antivirus version.',
                'default' => '',
                'example' => '1.0.0',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Antivirus status. Possible values can are: `disabled`, `offline`, `online`',
                'default' => '',
                'example' => 'online',
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
        return 'Health Antivirus';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_HEALTH_ANTIVIRUS;
    }
}
