<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Auth\LDAP\Settings;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

/**
 * A project's LDAP configuration.
 *
 * The bind password is deliberately absent: it is write-only, like every other
 * stored credential.
 */
class AuthLdap extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Auth method ID.',
                'default' => '',
                'example' => 'ldap',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'LDAP sign-in is active and can be used to create sessions.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('host', [
                'type' => self::TYPE_STRING,
                'description' => 'Directory hostname or IP address.',
                'default' => '',
                'example' => 'ldap.example.com',
            ])
            ->addRule('port', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Directory port.',
                'default' => Settings::DEFAULT_PORT,
                'example' => 389,
            ])
            ->addRule('encryption', [
                'type' => self::TYPE_STRING,
                'description' => 'Transport security: none, ssl or tls.',
                'default' => Settings::ENCRYPTION_TLS,
                'example' => 'tls',
            ])
            ->addRule('baseDn', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtree the user search starts from.',
                'default' => '',
                'example' => 'ou=people,dc=example,dc=com',
            ])
            ->addRule('bindDn', [
                'type' => self::TYPE_STRING,
                'description' => 'Service account used to search for users.',
                'default' => '',
                'example' => 'cn=service,dc=example,dc=com',
            ])
            ->addRule('userFilter', [
                'type' => self::TYPE_STRING,
                'description' => 'Search filter locating the user, containing the ' . Settings::PLACEHOLDER . ' placeholder.',
                'default' => '',
                'example' => '(uid=' . Settings::PLACEHOLDER . ')',
            ])
            ->addRule('provisionFilter', [
                'type' => self::TYPE_STRING,
                'description' => 'Filter a user must also match to be allowed an account. Empty means no restriction.',
                'default' => '',
                'example' => '(&(cn=staff)(member=' . Settings::PLACEHOLDER . '))',
            ])
            ->addRule('emailAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute holding the email address.',
                'default' => 'mail',
                'example' => 'mail',
            ])
            ->addRule('nameAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute holding the display name.',
                'default' => 'cn',
                'example' => 'cn',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AuthLdap';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_AUTH_LDAP;
    }
}
