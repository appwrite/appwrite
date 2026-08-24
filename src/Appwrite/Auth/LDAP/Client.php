<?php

namespace Appwrite\Auth\LDAP;

use Appwrite\Extend\Exception as AppwriteException;
use FreeDSx\Ldap\Entry\Dn;
use FreeDSx\Ldap\Exception\BindException;
use FreeDSx\Ldap\LdapClient;
use FreeDSx\Ldap\Operations;
use FreeDSx\Ldap\Search\Filters;
use Utopia\Database\Document;

/**
 * Authenticates a user against one directory server.
 *
 * Deliberately describes a single directory. Supporting several per project is
 * then a matter of holding a list of configurations and choosing between them,
 * rather than reshaping the configuration itself.
 *
 * The flow is search-then-bind, which is what directories in the wild expect:
 * bind as a service account, search the subtree for the entry matching the
 * value the user signed in with, then attempt a second bind as that entry's DN
 * using the password they supplied. A successful second bind is the proof of
 * identity; the password is never compared by us and never stored.
 *
 * FreeDSx is used rather than ext-ldap deliberately. It is pure PHP over
 * stream_socket_client, which Swoole's SWOOLE_HOOK_TCP hooks, so binds are
 * non-blocking inside a coroutine. ext-ldap has no Swoole hook and would stall
 * the worker's event loop on every sign-in.
 */
class Client
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

    /**
     * Long enough for a directory across a VPN, short enough that a dead server
     * fails the request rather than holding it open.
     */
    private const int TIMEOUT_CONNECT = 5;
    private const int TIMEOUT_OPERATION = 10;

    /**
     * Naming attributes whose equality rule is caseIgnoreMatch in the standard
     * schemas (RFC 4519) and in Active Directory, so two DNs differing only by
     * the case of these values denote the same entry. Anything outside this
     * list is compared exactly, because a schema is free to define a case-exact
     * naming attribute and folding one would let a directory-distinct entry
     * satisfy another's membership.
     *
     * @var array<int, string>
     */
    private const array CASE_INSENSITIVE_RDNS = [
        'uid',
        'cn',
        'ou',
        'o',
        'dc',
        'l',
        'st',
        'c',
        'sn',
        'givenname',
        'samaccountname',
        'userprincipalname',
        'mail',
    ];

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
     * Build a client from a project's stored LDAP configuration.
     *
     * Reads a single directory today. The stored shape is a list so that
     * supporting several per project later is a matter of choosing between
     * entries rather than reshaping what is already persisted.
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

    /**
     * Verify a user's credentials and return their directory entry.
     *
     * Returns null when the user does not exist, the password is wrong, or the
     * user does not satisfy the provisioning filter. These are deliberately
     * indistinguishable to the caller: telling them apart would let anyone probe
     * the directory for valid usernames.
     *
     * The returned dn is the entry's distinguished name, stable across renames
     * of display attributes and used as the provider identifier. The values are
     * trusted because the bind that produced them succeeded.
     *
     * @param string $username
     * @param string $password
     *
     * @return array{dn: string, email: string, name: string}|null
     *
     * @throws Exception when the directory cannot be reached or queried, which
     *                   is a server fault rather than a failed sign-in.
     */
    public function authenticate(string $username, string $password): ?array
    {
        // A directory will happily accept an empty password as an "unauthenticated
        // bind" and report success, which would authenticate anyone.
        if ($password === '') {
            return null;
        }

        $client = $this->connect();

        try {
            $this->bindService($client);

            $entry = $this->findEntry($client, $username);

            if ($entry === null) {
                return null;
            }

            if (!$this->bindUser($entry['dn'], $password)) {
                return null;
            }

            // Normalize before validating: directories pad values more often
            // than you would like, and a trailing space is not a malformed
            // address. Only checked after the bind proved the password, so a
            // misconfigured entry is reported to its owner alone and a failed
            // sign-in stays indistinguishable from an unknown user.
            $email = \strtolower(\trim($entry['email']));

            if ($email === '') {
                throw new Exception('The LDAP directory did not return an email address for this user. Check the email attribute mapping, or ensure the entry has one set.', AppwriteException::USER_UNAUTHORIZED);
            }

            if (!\filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('The LDAP directory returned an email attribute that is not a valid email address.', AppwriteException::USER_UNAUTHORIZED);
            }

            return [
                'dn' => $entry['dn'],
                'email' => $email,
                'name' => \trim($entry['name']),
            ];
        } finally {
            $client->unbind();
        }
    }

    /**
     * Prove the configuration works, for the console's benefit.
     *
     * Only connects and binds the service account: it deliberately does not need
     * a real user, so an admin can check host, TLS and service credentials before
     * anyone tries to sign in.
     *
     * @return void
     *
     * @throws Exception when the directory cannot be reached or the service bind fails.
     */
    public function verify(): void
    {
        $client = $this->connect();

        try {
            $this->bindService($client);
        } finally {
            $client->unbind();
        }
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

    public function hasProvisionFilter(): bool
    {
        return !empty(\trim($this->provisionFilter));
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

    public function useSsl(): bool
    {
        return $this->encryption === self::ENCRYPTION_SSL;
    }

    public function useStartTls(): bool
    {
        return $this->encryption === self::ENCRYPTION_TLS;
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

    /**
     * @return LdapClient
     */
    private function connect(): LdapClient
    {
        return new LdapClient([
            'servers' => $this->host,
            'port' => $this->port,
            'base_dn' => $this->baseDn,
            'use_ssl' => $this->useSsl(),
            'timeout_connect' => self::TIMEOUT_CONNECT,
            'timeout_read' => self::TIMEOUT_OPERATION,
        ]);
    }

    /**
     * Bind as the service account used to search for users.
     *
     * @param LdapClient $client
     *
     * @return void
     */
    private function bindService(LdapClient $client): void
    {
        try {
            if ($this->useStartTls()) {
                $client->startTls();
            }

            // An empty bind DN means the directory allows anonymous search.
            if ($this->bindDn !== '') {
                $client->bind($this->bindDn, $this->bindPassword);
            }
        } catch (BindException $error) {
            throw new Exception('Could not authenticate with the LDAP service account. Check the bind DN and password.', AppwriteException::GENERAL_ARGUMENT_INVALID, $error);
        } catch (\Throwable $error) {
            throw new Exception('Could not reach the LDAP server. Check the host, port and encryption settings.', AppwriteException::GENERAL_SERVER_ERROR, $error);
        }
    }

    /**
     * Find the single entry matching the user filter, and the provisioning
     * filter when one is configured.
     *
     * @param LdapClient $client
     * @param string $username
     *
     * @return array{dn: string, email: string, name: string}|null
     */
    private function findEntry(LdapClient $client, string $username): ?array
    {
        try {
            $entries = $client->search(Operations::search(
                Filters::raw($this->getUserFilter($username)),
                $this->emailAttribute,
                $this->nameAttribute
            ));
        } catch (\Throwable $error) {
            throw new Exception('Could not search the LDAP directory. Check the base DN and user filter.', AppwriteException::GENERAL_SERVER_ERROR, $error);
        }

        // More than one match means the filter is not specific enough. Binding
        // as an arbitrary one of them would be a coin toss over identity.
        if (\count($entries) !== 1) {
            return null;
        }

        $entry = $entries->first();

        if ($this->hasProvisionFilter() && !$this->matchesProvisionFilter($client, (string)$entry->getDn())) {
            return null;
        }

        return [
            'dn' => (string)$entry->getDn(),
            'email' => (string)($entry->get($this->emailAttribute) ?? ''),
            'name' => (string)($entry->get($this->nameAttribute) ?? ''),
        ];
    }

    /**
     * Whether the user also satisfies the provisioning restriction, typically a
     * group membership.
     *
     * Evaluated on every sign-in rather than only at first sign-in, so removing
     * someone from the group in the directory revokes their access rather than
     * only preventing a new account.
     *
     * @param LdapClient $client
     * @param string $dn
     *
     * @return bool
     */
    private function matchesProvisionFilter(LdapClient $client, string $dn): bool
    {
        $filter = $this->getProvisionFilter($dn);

        try {
            // Searched across the subtree so both filter shapes work: one that
            // describes the user's own entry, and one that describes a group
            // listing them as a member.
            $entries = $client->search(Operations::search(
                Filters::raw($filter),
                'member',
                'uniqueMember'
            ));
        } catch (\Throwable $error) {
            throw new Exception('Could not evaluate the LDAP provisioning filter.', AppwriteException::GENERAL_SERVER_ERROR, $error);
        }

        // A match on its own proves nothing: the result has to name the entry
        // being authenticated. Otherwise a filter satisfied by some unrelated
        // object would authorise anyone able to bind.
        foreach ($entries as $entry) {
            if (self::sameDn($dn, (string)$entry->getDn())) {
                return true;
            }

            foreach (['member', 'uniqueMember'] as $attribute) {
                $values = $entry->get($attribute);

                if ($values === null) {
                    continue;
                }

                foreach ($values->getValues() as $value) {
                    if (self::sameDn($dn, (string)$value)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether two distinguished names denote the same entry.
     *
     * A directory treats `uid=Alice,OU=People,DC=example` and
     * `uid=alice, ou=people, dc=example` as the same entry, so a byte-exact
     * comparison would reject a group whose member values differ only in case
     * or spacing from what the search returned. The comparison is therefore over
     * parsed components: same length, and each attribute name and value equal
     * case-insensitively.
     *
     * Values are compared case-insensitively because the attribute types used
     * for naming in practice are all case-insensitive. This is deliberately
     * more permissive than the byte comparison it replaces, and no more
     * permissive than the directory itself.
     *
     * @param string $left
     * @param string $right
     *
     * @return bool
     */
    private static function sameDn(string $left, string $right): bool
    {
        if (\hash_equals(\trim($left), \trim($right))) {
            return true;
        }

        try {
            $a = (new Dn($left))->toArray();
            $b = (new Dn($right))->toArray();
        } catch (\Throwable) {
            // Unparseable on either side: fall back to the literal comparison
            // already made above, which failed.
            return false;
        }

        if (\count($a) === 0 || \count($a) !== \count($b)) {
            return false;
        }

        foreach ($a as $i => $rdn) {
            // Attribute descriptions are case-insensitive per RFC 4512.
            if (\strcasecmp($rdn->getName(), $b[$i]->getName()) !== 0) {
                return false;
            }

            $left = \trim($rdn->getValue());
            $right = \trim($b[$i]->getValue());

            // Values are only folded for the naming attributes whose equality
            // rule is caseIgnoreMatch. A schema may define a case-exact naming
            // attribute, and folding those would let one entry satisfy
            // another's membership.
            if (\in_array(\strtolower($rdn->getName()), self::CASE_INSENSITIVE_RDNS, true)) {
                if (\strcasecmp($left, $right) !== 0) {
                    return false;
                }

                continue;
            }

            if (!\hash_equals($left, $right)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to bind as the user. Success is authentication.
     *
     * A fresh connection is used: the service account's bind is still active on
     * the search connection, and rebinding it would break subsequent lookups.
     *
     * @param string $dn
     * @param string $password
     *
     * @return bool
     */
    private function bindUser(string $dn, string $password): bool
    {
        $client = $this->connect();

        try {
            if ($this->useStartTls()) {
                $client->startTls();
            }

            $client->bind($dn, $password);

            return true;
        } catch (BindException) {
            // Wrong password. A normal outcome, not an error.
            return false;
        } catch (\Throwable $error) {
            throw new Exception('Could not complete the LDAP bind.', AppwriteException::GENERAL_SERVER_ERROR, $error);
        } finally {
            $client->unbind();
        }
    }
}
