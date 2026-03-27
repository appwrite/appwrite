<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response;

class PlatformWeb extends PlatformBase
{
    public function __construct()
    {
        $this->conditions = [
            'type' => Platform::TYPE_WEB,
        ];

        parent::__construct();

        $this
            ->addRule('hostname', [
                'type' => self::TYPE_STRING,
                'description' => 'Web app hostname. Empty string for other platforms.',
                'default' => '',
                'example' => 'app.example.com',
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
        return 'Platform Web';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_WEB;
    }
}
