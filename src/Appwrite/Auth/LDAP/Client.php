<?php

namespace Appwrite\Auth\LDAP;

use Appwrite\Extend\Exception as AppwriteException;
use FreeDSx\Ldap\Entry\Dn;
use FreeDSx\Ldap\Exception\BindException;
use FreeDSx\Ldap\LdapClient;
use FreeDSx\Ldap\Operations;
use FreeDSx\Ldap\Search\Filters;

/**
 * Authenticates a user against one directory server.
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

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Verify a user's credentials and return their directory entry.
     *
     * Returns null when the user does not exist, the password is wrong, or the
     * user does not satisfy the provisioning filter. These are deliberately
     * indistinguishable to the caller: telling them apart would let anyone probe
     * the directory for valid usernames.
     *
     * @param string $username
     * @param string $password
     *
     * @return Identity|null
     *
     * @throws Exception when the directory cannot be reached or queried, which
     *                   is a server fault rather than a failed sign-in.
     */
    public function authenticate(string $username, string $password): ?Identity
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

            return new Identity($entry['dn'], $entry['email'], $entry['name']);
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
     * @return LdapClient
     */
    private function connect(): LdapClient
    {
        return new LdapClient([
            'servers' => $this->settings->getHost(),
            'port' => $this->settings->getPort(),
            'base_dn' => $this->settings->getBaseDn(),
            'use_ssl' => $this->settings->useSsl(),
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
            if ($this->settings->useStartTls()) {
                $client->startTls();
            }

            // An empty bind DN means the directory allows anonymous search.
            if ($this->settings->getBindDn() !== '') {
                $client->bind($this->settings->getBindDn(), $this->settings->getBindPassword());
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
        $emailAttribute = $this->settings->getEmailAttribute();
        $nameAttribute = $this->settings->getNameAttribute();

        try {
            $entries = $client->search(Operations::search(
                Filters::raw($this->settings->getUserFilter($username)),
                $emailAttribute,
                $nameAttribute
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

        if ($this->settings->hasProvisionFilter() && !$this->matchesProvisionFilter($client, (string)$entry->getDn())) {
            return null;
        }

        return [
            'dn' => (string)$entry->getDn(),
            'email' => (string)($entry->get($emailAttribute) ?? ''),
            'name' => (string)($entry->get($nameAttribute) ?? ''),
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
        $filter = $this->settings->getProvisionFilter($dn);

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
            if ($this->settings->useStartTls()) {
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
