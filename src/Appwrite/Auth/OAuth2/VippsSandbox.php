<?php

namespace Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.vippsmobilepay.com/docs/knowledge-base/test-environment/

class VippsSandbox extends Vipps
{
    protected string $endpoint = 'https://apitest.vipps.no';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'vippsSandbox';
    }
}
