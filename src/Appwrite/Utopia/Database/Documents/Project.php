<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Database\Document;

class Project extends Document
{
    /**
     * Get the name of the cookie, and of the X-Fallback-Cookies entry, that carries a session of the project.
     */
    public function getSessionKey(): string
    {
        return 'a_session_' . $this->getId();
    }

    /**
     * Get the custom URL scheme a native app of the project is sent back to after an OAuth2 sign-in.
     */
    public function getCallbackScheme(): string
    {
        return 'appwrite-callback-' . $this->getId();
    }
}
