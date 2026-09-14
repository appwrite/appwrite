<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyOauthTrustProviderEmail extends PolicyBase
{
    public array $conditions = [
        '$id' => 'oauth-trust-provider-email',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('providers', [
            'type' => self::TYPE_STRING,
            'description' => 'OAuth provider IDs whose reported email is trusted for account linking even when the provider does not flag it as verified. Empty when the policy is disabled.',
            'default' => [],
            'example' => ['microsoft'],
            'array' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy OAuth Trust Provider Email';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_OAUTH_TRUST_PROVIDER_EMAIL;
    }
}
