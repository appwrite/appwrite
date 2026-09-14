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

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether the email reported by an OAuth provider is trusted for account linking even when the provider does not flag it as verified.',
            'default' => false,
            'example' => false,
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
