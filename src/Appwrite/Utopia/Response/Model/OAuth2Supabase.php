<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Supabase extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'supabase',
    ];

    public function getProviderLabel(): string
    {
        return 'Supabase';
    }

    public function getClientIdExample(): string
    {
        return '00000000-0000-0000-0000-000000000000';
    }

    public function getClientSecretExample(): string
    {
        return '00000000000000000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Supabase';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_SUPABASE;
    }
}
