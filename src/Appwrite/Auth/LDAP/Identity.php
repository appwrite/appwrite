<?php

namespace Appwrite\Auth\LDAP;

use Appwrite\Extend\Exception as AppwriteException;

/**
 * A user as the directory describes them, after a successful bind.
 *
 * Only construct this from Client::authenticate(): the values here are trusted
 * because the bind that produced them succeeded.
 */
class Identity
{
    /**
     * @param string $dn The entry's distinguished name, stable across renames of
     *                   display attributes and used as the provider identifier.
     * @param string $email
     * @param string $name
     *
     * @throws Exception when the directory released no usable email address.
     */
    private readonly string $email;
    private readonly string $name;

    public function __construct(
        private readonly string $dn,
        string $email,
        string $name = '',
    ) {
        // Normalize before validating: directories pad values more often than
        // you would like, and a trailing space is not a malformed address.
        $this->email = \strtolower(\trim($email));
        $this->name = \trim($name);

        if ($this->email === '') {
            throw new Exception('The LDAP directory did not return an email address for this user. Check the email attribute mapping, or ensure the entry has one set.', AppwriteException::USER_UNAUTHORIZED);
        }

        if (!\filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('The LDAP directory returned an email attribute that is not a valid email address.', AppwriteException::USER_UNAUTHORIZED);
        }
    }

    public function getDn(): string
    {
        return $this->dn;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
