<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Box extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'box',
    ];

    public function getProviderLabel(): string
    {
        return 'Box';
    }

    public function getClientIdExample(): string
    {
        return 'deglcs00000000000000000000x2og6y';
    }

    public function getClientSecretExample(): string
    {
        return 'OKM1f100000000000000000000eshEif';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Box';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_BOX;
    }
}
