<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MFAProvider extends Model
{
    public function __construct()
    {
        $this
            ->addRule('backups', [
                'type' => self::TYPE_STRING,
                'description' => 'backup codes',
                'array' => true,
                'default' => [],
                'example' => true
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'secret used for top auth',
                'default' => '',
                'example' => true
            ])
            ->addRule('uri', [
                'type' => self::TYPE_STRING,
                'description' => 'uri for otp app',
                'default' => '',
                'example' => true
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
        return 'MFAProvider';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MFA_PROVIDER;
    }
}
