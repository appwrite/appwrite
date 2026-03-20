<?php

namespace Appwrite\Auth\OAuth2;

class MockUnverified extends Mock
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'mock-unverified';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'http://localhost/' . $this->version . '/mock/tests/general/oauth2/user-unverified?token=' . \urlencode($accessToken));

            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}
