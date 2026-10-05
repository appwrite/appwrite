<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class PoliciesCustomServerTest extends Scope
{
    use PoliciesBase;
    use ProjectCustom;
    use SideServer;

    public function testPasswordPersonalDataPolicyRejectsNullPassword(): void
    {
        $this->updatePasswordPersonalDataPolicy(true);

        try {
            // An explicit null password is checked against personal data rather than treated as an empty password
            $response = $this->client->call(Client::METHOD_POST, '/users', $this->buildHeaders(), [
                'userId' => ID::unique(),
                'email' => \uniqid() . '@example.com',
                'password' => null,
            ]);

            $this->assertSame(400, $response['headers']['status-code']);
            $this->assertSame(Exception::USER_PASSWORD_PERSONAL_DATA, $response['body']['type']);
        } finally {
            $this->updatePasswordPersonalDataPolicy(false);
        }
    }
}
