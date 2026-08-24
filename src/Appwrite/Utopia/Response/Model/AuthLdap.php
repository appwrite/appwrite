<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Auth\LDAP\Client;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

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
                'default' => Client::DEFAULT_PORT,
                'example' => 389,
            ])
            ->addRule('encryption', [
                'type' => self::TYPE_STRING,
                'description' => 'Transport security: none, ssl or tls.',
                'default' => Client::ENCRYPTION_TLS,
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
                'description' => 'Search filter locating the user, containing the ' . Client::PLACEHOLDER . ' placeholder.',
                'default' => '',
                'example' => '(uid=' . Client::PLACEHOLDER . ')',
            ])
            ->addRule('provisionGroupDn', [
                'type' => self::TYPE_STRING,
                'description' => 'Distinguished name of the group a user must belong to for an account to be created. Empty means no restriction.',
                'default' => '',
                'example' => 'cn=staff,ou=groups,dc=example,dc=com',
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
