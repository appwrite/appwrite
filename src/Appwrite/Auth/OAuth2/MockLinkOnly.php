<?php

namespace Appwrite\Auth\OAuth2;

/**
 * A mock provider whose identity can only be linked, like Bird's shared workspace ID. It returns no
 * email, so linking it never collides with accounts other tests create from the mock email.
 */
class MockLinkOnly extends MockNoEmail
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'mock-link-only';
    }

    /**
     * @return bool
     */
    public function isLinkOnly(): bool
    {
        return true;
    }
}
