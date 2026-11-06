<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Auth\LDAP\Client;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;
use Utopia\Database\Document;

class Project extends Model
{
    public function __construct()
    {
        $this
            // Basic project information
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Project ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Project creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Project update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Project name.',
                'default' => '',
                'example' => 'New Project',
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project team ID.',
                'default' => '',
                'example' => '1592981250',
            ])
            ->addRule('region', [
                'type' => self::TYPE_STRING,
                'description' => 'Project region.',
                'default' => 'default',
                'example' => 'fra',
            ])

            // Resource: SMTP
            ->addRule('smtpEnabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Status for custom SMTP',
                'default' => false,
                'example' => false,
                'array' => false
            ])
            ->addRule('smtpSenderName', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP sender name',
                'default' => '',
                'example' => 'John Appwrite',
            ])
            ->addRule('smtpSenderEmail', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP sender email',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('smtpReplyToName', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP reply to name',
                'default' => '',
                'example' => 'Support Team',
            ])
            ->addRule('smtpReplyToEmail', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP reply to email',
                'default' => '',
                'example' => 'support@appwrite.io',
            ])
            ->addRule('smtpHost', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server host name',
                'default' => '',
                'example' => 'mail.appwrite.io',
            ])
            ->addRule('smtpPort', [
                'type' => self::TYPE_INTEGER,
                'description' => 'SMTP server port',
                'default' => 0,
                'example' => 25,
            ])
            ->addRule('smtpUsername', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server username',
                'default' => '',
                'example' => 'emailuser',
            ])
            ->addRule('smtpPassword', [
                'type' => self::TYPE_STRING,
                'format' => 'password',
                'description' => 'SMTP server password. This property is write-only and always returned empty.',
                'default' => '',
                'example' => 'smtp-password',
            ])
            ->addRule('smtpSecure', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server secure protocol',
                'default' => '',
                'example' => 'tls',
            ])

            // Resource: LDAP
            ->addRule('ldapHosts', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP directory hosts, tried in order.',
                'default' => [],
                'example' => ['ldap.example.com'],
                'array' => true,
            ])
            ->addRule('ldapPort', [
                'type' => self::TYPE_INTEGER,
                'description' => 'LDAP directory port.',
                'default' => 0,
                'example' => 636,
            ])
            ->addRule('ldapEncryption', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP transport encryption: none, ldaps or starttls.',
                'default' => '',
                'example' => 'ldaps',
            ])
            ->addRule('ldapTls', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the LDAP directory certificate is verified.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('ldapCaCertificate', [
                'type' => self::TYPE_STRING,
                'description' => 'CA certificate in PEM format that signed the LDAP directory certificate, empty for the system trust store.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('ldapTimeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Seconds to wait for the LDAP directory to connect and answer.',
                'default' => 0,
                'example' => 10,
            ])
            ->addRule('ldapBaseDn', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP subtree the user search starts from.',
                'default' => '',
                'example' => 'ou=people,dc=example,dc=com',
            ])
            ->addRule('ldapBindDn', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP service account used to search for users, empty for anonymous search.',
                'default' => '',
                'example' => 'cn=appwrite,ou=services,dc=example,dc=com',
            ])
            ->addRule('ldapBindPassword', [
                'type' => self::TYPE_STRING,
                'format' => 'password',
                'description' => 'LDAP service account password. This property is write-only and always returned empty.',
                'default' => '',
                'example' => 'ldap-password',
            ])
            ->addRule('ldapUsernameAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP attribute the username is matched against.',
                'default' => '',
                'example' => 'uid',
            ])
            ->addRule('ldapUserFilter', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP filter every user must also match.',
                'default' => '',
                'example' => '(objectClass=inetOrgPerson)',
            ])
            ->addRule('ldapIdAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP attribute holding the identifier that stays the same when an entry is renamed or moved.',
                'default' => '',
                'example' => 'entryUUID',
            ])
            ->addRule('ldapEmailAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP attribute holding the email address.',
                'default' => '',
                'example' => 'mail',
            ])
            ->addRule('ldapNameAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP attribute holding the display name.',
                'default' => '',
                'example' => 'cn',
            ])
            ->addRule('ldapPhoneAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP attribute holding the phone number, empty when unmapped.',
                'default' => '',
                'example' => 'mobile',
            ])
            ->addRule('ldapRequiredGroupDn', [
                'type' => self::TYPE_STRING,
                'description' => 'LDAP group a user must belong to in order to sign in, empty to allow every user.',
                'default' => '',
                'example' => 'cn=staff,ou=groups,dc=example,dc=com',
            ])

            // Resource: Ping
            ->addRule('pingCount', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of times the ping was received for this project.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('pingedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Last ping datetime in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])

            // Resource: Labels
            ->addRule('labels', [
                'type' => self::TYPE_STRING,
                'description' => 'Labels for the project.',
                'default' => [],
                'example' => ['vip'],
                'array' => true,
            ])

            // Resource: Preferences
            ->addRule('prefs', [
                'type' => Response::MODEL_PREFERENCES,
                'description' => 'Project preferences as a key-value object',
                'required' => false,
                'default' => new \stdClass(),
                'example' => ['theme' => 'pink', 'timezone' => 'UTC'],
            ])

            // Resource: Billing
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Project status.',
                'default' => 'active',
                'example' => 'active',
            ])
            ->addRule('onboarding', [
                'type' => self::TYPE_JSON,
                'description' => 'Stage progress (completed or skipped) with timestamps and actor types, keyed by stage id.',
                'default' => new \stdClass(),
                'example' => new \stdClass(),
            ])

            // Resource: Auth methods
            ->addRule('authMethods', [
                'type' => Response::MODEL_PROJECT_AUTH_METHOD,
                'description' => 'List of auth methods.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])

            // Resource: Services
            ->addRule('services', [
                'type' => Response::MODEL_PROJECT_SERVICE,
                'description' => 'List of services.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])

            // Resource: Protocols
            ->addRule('protocols', [
                'type' => Response::MODEL_PROJECT_PROTOCOL,
                'description' => 'List of protocols.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
            ->addRule('blocks', [
                'type' => self::TYPE_STRING,
                'description' => 'Project blocks information.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('consoleAccessedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Last time the project was accessed via console.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('wafEnabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether WAF enforcement is enabled for the project.',
                'default' => false,
                'example' => false,
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Project';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROJECT;
    }

    /**
     * Filter document structure
     *
     * @return Document
     */
    public function filter(Document $document): Document
    {
        $this->expandSmtpFields($document);
        $this->expandLdapFields($document);
        $this->expandServices($document);
        $this->expandProtocols($document);
        $this->expandAuthMethods($document);
        $this->expandConsoleAccessedAt($document);
        $document->setAttribute('wafEnabled', (bool) $document->getAttribute('wafEnabled', false));

        $onboarding = $document->getAttribute('onboarding', []);
        if (\is_array($onboarding) && empty($onboarding)) {
            $document->setAttribute('onboarding', new \stdClass());
        }

        $prefs = $document->getAttribute('prefs', []);
        if (\is_array($prefs) && empty($prefs)) {
            $document->setAttribute('prefs', new \stdClass());
        }

        return $document;
    }

    private function expandConsoleAccessedAt(Document $document): void
    {
        $document->setAttribute('consoleAccessedAt', $document->getAttribute('accessedAt', ''));
    }

    private function expandSmtpFields(Document $document): void
    {
        if (!$document->isSet('smtp')) {
            return;
        }

        $smtp = $document->getAttribute('smtp', []);

        $document->setAttribute('smtpEnabled', $smtp['enabled'] ?? false);
        $document->setAttribute('smtpSenderEmail', $smtp['senderEmail'] ?? '');
        $document->setAttribute('smtpSenderName', $smtp['senderName'] ?? '');
        $document->setAttribute('smtpReplyToEmail', $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? ''); // Includes backwards compatibility
        $document->setAttribute('smtpReplyToName', $smtp['replyToName'] ?? '');
        $document->setAttribute('smtpHost', $smtp['host'] ?? '');
        $document->setAttribute('smtpPort', (int) ($smtp['port'] ?? 0));
        $document->setAttribute('smtpUsername', $smtp['username'] ?? '');
        $document->setAttribute('smtpPassword', ''); // Write-only: never expose the stored value
        $document->setAttribute('smtpSecure', $smtp['secure'] ?? '');
    }

    private function expandLdapFields(Document $document): void
    {
        if (!$document->isSet('ldap')) {
            return;
        }

        $ldap = $document->getAttribute('ldap', []);

        // The values a sign-in would use, defaults included, and the port that
        // follows the encryption unless one was chosen.
        $encryption = $ldap['encryption'] ?? Client::ENCRYPTION_LDAPS;

        $document->setAttribute('ldapHosts', \array_values((array) ($ldap['hosts'] ?? [])));
        $document->setAttribute('ldapPort', (int) ($ldap['port'] ?? 0) ?: Client::port($encryption));
        $document->setAttribute('ldapEncryption', $encryption);
        $document->setAttribute('ldapTls', (bool) ($ldap['tls'] ?? true));
        $document->setAttribute('ldapCaCertificate', $ldap['caCertificate'] ?? '');
        $document->setAttribute('ldapTimeout', (int) ($ldap['timeout'] ?? 0) ?: Client::TIMEOUT_DEFAULT);
        $document->setAttribute('ldapBaseDn', $ldap['baseDn'] ?? '');
        $document->setAttribute('ldapBindDn', $ldap['bindDn'] ?? '');
        $document->setAttribute('ldapBindPassword', ''); // Write-only: never expose the stored value
        $document->setAttribute('ldapUsernameAttribute', $ldap['usernameAttribute'] ?? Client::ATTRIBUTE_USERNAME);
        $document->setAttribute('ldapUserFilter', $ldap['userFilter'] ?? '');
        $document->setAttribute('ldapIdAttribute', $ldap['idAttribute'] ?? Client::ATTRIBUTE_ID);
        $document->setAttribute('ldapEmailAttribute', $ldap['emailAttribute'] ?? Client::ATTRIBUTE_EMAIL);
        $document->setAttribute('ldapNameAttribute', $ldap['nameAttribute'] ?? Client::ATTRIBUTE_NAME);
        $document->setAttribute('ldapPhoneAttribute', $ldap['phoneAttribute'] ?? '');
        $document->setAttribute('ldapRequiredGroupDn', $ldap['requiredGroupDn'] ?? '');
    }

    private function expandServices(Document $document): void
    {
        $values = $document->getAttribute('services', []);
        $services = [];

        foreach (Config::getParam('services', []) as $id => $service) {
            if (!$service['optional']) {
                continue;
            }

            $services[] = new Document([
                '$id' => $id,
                'enabled' => $values[$service['key']] ?? true,
            ]);
        }

        $document->setAttribute('services', $services);
    }

    private function expandProtocols(Document $document): void
    {
        $values = $document->getAttribute('apis', []);
        $protocols = [];

        foreach (Config::getParam('protocols', []) as $id => $api) {
            $protocols[] = new Document([
                '$id' => $id,
                'enabled' => $values[$api['key']] ?? true,
            ]);
        }

        $document->setAttribute('protocols', $protocols);
    }

    private function expandAuthMethods(Document $document): void
    {
        $values = $document->getAttribute('auths', []);
        $authMethods = [];

        foreach (Config::getParam('auth', []) as $id => $method) {
            $authMethods[] = new Document([
                '$id' => $id,
                'enabled' => $values[$method['key']] ?? $method['enabled'] ?? true,
            ]);
        }

        $document->setAttribute('authMethods', $authMethods);
    }
}
