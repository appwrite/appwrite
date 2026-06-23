<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MFAType extends Model
{
    public function __construct()
    {
        $this
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Secret token used for TOTP factor.',
                'default' => '',
                'example' => '[SHARED_SECRET]'
            ])
            ->addRule('uri', [
                'type' => self::TYPE_STRING,
                'description' => 'URI for authenticator apps.',
                'default' => '',
                'example' => 'otpauth://totp/appwrite:user@example.com?secret=[SHARED_SECRET]&issuer=appwrite'
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
        return 'MFAType';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MFA_TYPE;
    }
}
