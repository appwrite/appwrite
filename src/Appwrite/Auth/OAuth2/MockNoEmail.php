<?php

namespace Appwrite\Auth\OAuth2;

class MockNoEmail extends Mock
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'mock-no-email';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'http://localhost/' . $this->version . '/mock/tests/general/oauth2/user-no-email?token=' . \urlencode($accessToken));

            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}
