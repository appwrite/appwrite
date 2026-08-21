<?php

namespace Appwrite\Auth\LDAP;

use Appwrite\Extend\Exception as AppwriteException;
use Utopia\Database\Document;

/**
 * Immutable connection and search configuration for one directory server.
 *
 * Deliberately describes a single directory. Supporting several per project is
 * then a matter of holding a list of these and choosing between them, rather
 * than reshaping the configuration itself.
 */
class Settings
{
    /**
     * Transport security. Plaintext is offered because directories on a private
     * network sometimes have no certificate at all, but a simple bind sends the
     * password in the clear, so it is never the default and the console should
     * warn on it.
     */
    public const string ENCRYPTION_NONE = 'none';
    public const string ENCRYPTION_SSL = 'ssl';
    public const string ENCRYPTION_TLS = 'tls';

    public const array ENCRYPTIONS = [
        self::ENCRYPTION_NONE,
        self::ENCRYPTION_SSL,
        self::ENCRYPTION_TLS,
    ];

    /**
     * Placeholder replaced with the value the user signed in with. Chosen to
     * match the templating other directory-aware products use, so filters can be
     * copied across with no edits.
     */
    public const string PLACEHOLDER = '{{username}}';

    public const int DEFAULT_PORT = 389;
    public const int DEFAULT_PORT_SSL = 636;

    /**
     * @param string $host Directory hostname or IP.
     * @param int $port Directory port.
     * @param string $encryption One of ENCRYPTIONS.
     * @param string $baseDn Subtree the user search starts from.
     * @param string $bindDn Service account used to search for users. Empty for an anonymous bind.
     * @param string $bindPassword Service account password.
     * @param string $userFilter Search filter locating the user, containing PLACEHOLDER.
     * @param string $provisionFilter Optional extra filter a user must also match to be granted an account.
     * @param string $emailAttribute Attribute holding the email address.
     * @param string $nameAttribute Attribute holding the display name.
     *
     * @throws Exception when the configuration cannot describe a usable directory.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = self::DEFAULT_PORT,
        private readonly string $encryption = self::ENCRYPTION_TLS,
        private readonly string $baseDn = '',
        private readonly string $bindDn = '',
        private readonly string $bindPassword = '',
        private readonly string $userFilter = '(uid=' . self::PLACEHOLDER . ')',
        private readonly string $provisionFilter = '',
        private readonly string $emailAttribute = 'mail',
        private readonly string $nameAttribute = 'cn',
    ) {
        if (empty(\trim($this->host))) {
            throw new Exception('LDAP host is required.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new Exception('LDAP port must be between 1 and 65535.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (!\in_array($this->encryption, self::ENCRYPTIONS, true)) {
            throw new Exception('Unsupported LDAP encryption: ' . $this->encryption, AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (empty(\trim($this->baseDn))) {
            throw new Exception('LDAP base DN is required.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        // Without the placeholder the filter matches the same entry for every
        // sign-in attempt, which would let anyone in as whoever it resolves to.
        if (!\str_contains($this->userFilter, self::PLACEHOLDER)) {
            throw new Exception('LDAP user filter must contain the ' . self::PLACEHOLDER . ' placeholder.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (empty(\trim($this->emailAttribute))) {
            throw new Exception('LDAP email attribute is required. Appwrite cannot create an account without an email address.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }
    }

    /**
     * Build settings from a project's stored LDAP configuration.
     *
     * Reads a single directory today. The stored shape is a list so that
     * supporting several per project later is a matter of choosing between
     * entries rather than reshaping what is already persisted; see
     * docs/decisions/ldap-auth.md.
     *
     * @param Document $project
     *
     * @return self
     *
     * @throws Exception when no directory is configured, or its configuration is invalid.
     */
    public static function fromProject(Document $project): self
    {
        $auths = $project->getAttribute('auths', []);
        $directories = \json_decode($auths['ldapDirectories'] ?? '[]', true);
        $directories = \is_array($directories) ? $directories : [];

        if (\count($directories) === 0) {
            throw new Exception('No LDAP directory is configured for this project.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        $directory = $directories[0];

        return new self(
            host: $directory['host'] ?? '',
            port: (int)($directory['port'] ?? self::DEFAULT_PORT),
            encryption: $directory['encryption'] ?? self::ENCRYPTION_TLS,
            baseDn: $directory['baseDn'] ?? '',
            bindDn: $directory['bindDn'] ?? '',
            bindPassword: $directory['bindPassword'] ?? '',
            userFilter: $directory['userFilter'] ?? '(uid=' . self::PLACEHOLDER . ')',
            provisionFilter: $directory['provisionFilter'] ?? '',
            emailAttribute: $directory['emailAttribute'] ?? 'mail',
            nameAttribute: $directory['nameAttribute'] ?? 'cn',
        );
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getEncryption(): string
    {
        return $this->encryption;
    }

    public function useSsl(): bool
    {
        return $this->encryption === self::ENCRYPTION_SSL;
    }

    public function useStartTls(): bool
    {
        return $this->encryption === self::ENCRYPTION_TLS;
    }

    public function getBaseDn(): string
    {
        return $this->baseDn;
    }

    public function getBindDn(): string
    {
        return $this->bindDn;
    }

    public function getBindPassword(): string
    {
        return $this->bindPassword;
    }

    public function getEmailAttribute(): string
    {
        return $this->emailAttribute;
    }

    public function getNameAttribute(): string
    {
        return $this->nameAttribute;
    }

    public function hasProvisionFilter(): bool
    {
        return !empty(\trim($this->provisionFilter));
    }

    /**
     * The user-search filter with the placeholder replaced.
     *
     * @param string $username
     *
     * @return string
     */
    public function getUserFilter(string $username): string
    {
        return \str_replace(self::PLACEHOLDER, self::escape($username), $this->userFilter);
    }

    /**
     * The provisioning filter with the placeholder replaced, or an empty string
     * when no restriction is configured.
     *
     * The placeholder receives the authenticated entry's DN rather than the
     * value typed at sign-in, because this filter is evaluated against that
     * entry directly. A membership check therefore reads
     * `(memberOf=cn=staff,ou=groups,dc=example,dc=com)` and does not need the
     * placeholder at all; it is substituted for filters that do want the DN.
     *
     * @param string $dn
     *
     * @return string
     */
    public function getProvisionFilter(string $dn): string
    {
        if (!$this->hasProvisionFilter()) {
            return '';
        }

        return \str_replace(self::PLACEHOLDER, self::escape($dn), $this->provisionFilter);
    }

    /**
     * Escape a value before it is placed inside a search filter, per RFC 4515.
     *
     * Without this a username such as `*` would match every entry in the
     * subtree, which is the LDAP equivalent of SQL injection.
     *
     * @param string $value
     *
     * @return string
     */
    public static function escape(string $value): string
    {
        return \str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\5c', '\2a', '\28', '\29', '\00'],
            $value
        );
    }
}
