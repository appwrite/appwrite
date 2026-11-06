<?php

namespace Appwrite\Auth\LDAP;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use FreeDSx\Ldap\Entry\Entry;
use FreeDSx\Ldap\Exception\BindException;
use FreeDSx\Ldap\Exception\ConnectionException;
use FreeDSx\Ldap\Exception\FilterParseException;
use FreeDSx\Ldap\Exception\OperationException;
use FreeDSx\Ldap\LdapClient;
use FreeDSx\Ldap\Operation\ResultCode;
use FreeDSx\Ldap\Operations;
use FreeDSx\Ldap\Search\Filter\FilterContainerInterface;
use FreeDSx\Ldap\Search\Filter\FilterInterface;
use FreeDSx\Ldap\Search\Filter\MatchingRuleFilter;
use FreeDSx\Ldap\Search\Filter\NotFilter;
use FreeDSx\Ldap\Search\Filters;
use FreeDSx\Socket\Exception\ConnectionException as SocketException;
use InvalidArgumentException;

/**
 * The directory a project authenticates users against: search for the user as the
 * service account, check the required group, then bind as the user to prove the password.
 *
 * Every question the directory can answer is asked of the directory. Membership is
 * evaluated server-side by filter, so DN spelling, group object classes, large Active
 * Directory groups and nested groups are the server's problem, not ours.
 */
class Client
{
    public const string ENCRYPTION_NONE = 'none';
    public const string ENCRYPTION_LDAPS = 'ldaps';
    public const string ENCRYPTION_STARTTLS = 'starttls';
    public const array ENCRYPTIONS = [
        self::ENCRYPTION_NONE,
        self::ENCRYPTION_LDAPS,
        self::ENCRYPTION_STARTTLS,
    ];

    public const int PORT_LDAP = 389;
    public const int PORT_LDAPS = 636;
    public const int TIMEOUT_DEFAULT = 10;
    public const int TIMEOUT_MAX = 30;
    public const int HOSTS_MAX = 10;

    public const string ATTRIBUTE_USERNAME = 'uid';
    public const string ATTRIBUTE_ID = 'entryUUID';
    public const string ATTRIBUTE_EMAIL = 'mail';
    public const string ATTRIBUTE_NAME = 'cn';

    /**
     * Active Directory's LDAP_MATCHING_RULE_IN_CHAIN: matches a group through any
     * depth of nesting. Servers that do not know the rule evaluate it as Undefined
     * (RFC 4511 section 4.5.1.7), so it is harmless everywhere else.
     */
    public const string RULE_IN_CHAIN = '1.2.840.113556.1.4.1941';

    /** @var list<string> */
    private readonly array $hosts;
    private readonly int $port;
    private readonly string $encryption;
    private readonly bool $tls;
    private readonly string $caCertificate;
    private readonly int $timeout;
    private readonly string $baseDn;
    private readonly string $bindDn;
    private readonly string $bindPassword;
    private readonly string $usernameAttribute;
    private readonly string $userFilter;
    private readonly string $idAttribute;
    private readonly string $emailAttribute;
    private readonly string $nameAttribute;
    private readonly string $phoneAttribute;
    private readonly string $requiredGroupDn;

    private ?string $caFile = null;

    /**
     * @param PublicHostname $hostname Resolves each host and refuses private addresses; the connection dials the address it returned
     * @param array<string, mixed> $config The project's stored LDAP configuration
     *
     * @throws InvalidArgumentException When the configuration cannot describe a directory
     */
    public function __construct(
        private readonly PublicHostname $hostname,
        array $config,
    ) {
        $hosts = \array_values(\array_filter(\array_map(fn ($host) => \trim((string) $host), (array) ($config['hosts'] ?? [])), fn (string $host) => $host !== ''));
        if ($hosts === []) {
            throw new InvalidArgumentException('At least one LDAP host is required.');
        }
        if (\count($hosts) > self::HOSTS_MAX) {
            throw new InvalidArgumentException('At most ' . self::HOSTS_MAX . ' LDAP hosts are allowed.');
        }

        $encryption = (string) ($config['encryption'] ?? self::ENCRYPTION_LDAPS);
        if (!\in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidArgumentException('Unsupported LDAP encryption: ' . $encryption);
        }

        $port = (int) ($config['port'] ?? 0) ?: self::port($encryption);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('LDAP port must be between 1 and 65535.');
        }

        $timeout = (int) ($config['timeout'] ?? self::TIMEOUT_DEFAULT);
        if ($timeout < 1 || $timeout > self::TIMEOUT_MAX) {
            throw new InvalidArgumentException('LDAP timeout must be between 1 and ' . self::TIMEOUT_MAX . ' seconds.');
        }

        $baseDn = \trim((string) ($config['baseDn'] ?? ''));
        if ($baseDn === '') {
            throw new InvalidArgumentException('LDAP base DN is required.');
        }

        foreach (['usernameAttribute' => self::ATTRIBUTE_USERNAME, 'idAttribute' => self::ATTRIBUTE_ID, 'emailAttribute' => self::ATTRIBUTE_EMAIL, 'nameAttribute' => self::ATTRIBUTE_NAME] as $key => $default) {
            if (\trim((string) ($config[$key] ?? $default)) === '') {
                throw new InvalidArgumentException('LDAP ' . $key . ' is required.');
            }
        }

        $this->hosts = $hosts;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->tls = (bool) ($config['tls'] ?? true);
        $this->caCertificate = \trim((string) ($config['caCertificate'] ?? ''));
        $this->timeout = $timeout;
        $this->baseDn = $baseDn;
        $this->bindDn = \trim((string) ($config['bindDn'] ?? ''));
        $this->bindPassword = (string) ($config['bindPassword'] ?? '');
        $this->usernameAttribute = \trim((string) ($config['usernameAttribute'] ?? self::ATTRIBUTE_USERNAME));
        $this->userFilter = \trim((string) ($config['userFilter'] ?? ''));
        $this->idAttribute = \trim((string) ($config['idAttribute'] ?? self::ATTRIBUTE_ID));
        $this->emailAttribute = \trim((string) ($config['emailAttribute'] ?? self::ATTRIBUTE_EMAIL));
        $this->nameAttribute = \trim((string) ($config['nameAttribute'] ?? self::ATTRIBUTE_NAME));
        $this->phoneAttribute = \trim((string) ($config['phoneAttribute'] ?? ''));
        $this->requiredGroupDn = \trim((string) ($config['requiredGroupDn'] ?? ''));
    }

    /**
     * The port an encryption mode listens on by default: LDAPS has its own, the rest share 389.
     */
    public static function port(string $encryption): int
    {
        return $encryption === self::ENCRYPTION_LDAPS ? self::PORT_LDAPS : self::PORT_LDAP;
    }

    /**
     * Prove a user's credentials and return their entry as Appwrite maps it.
     *
     * An unknown username, an ambiguous one, a wrong password and a missing group
     * membership are all the same failure to the caller: telling them apart would
     * let anyone probe the directory for valid usernames. A directory that cannot
     * be reached, searched or bound as the service account is a directory error.
     *
     * @return array{id: string, dn: string, email: string, name: string, phone: string}
     *
     * @throws Exception USER_INVALID_CREDENTIALS when the user cannot be signed in; USER_LDAP_DIRECTORY_ERROR when the directory failed
     */
    public function authenticate(string $username, string $password): array
    {
        // RFC 4513 lets a directory accept an empty password as an anonymous bind and
        // report success, which would sign in anyone who knows a username.
        if ($username === '' || $password === '') {
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $client = $this->connect();

        try {
            $entries = $this->find($client, $username);

            if (\count($entries) !== 1) {
                throw new Exception(Exception::USER_INVALID_CREDENTIALS);
            }

            $entry = $this->map($entries[0]);

            if ($this->requiredGroupDn !== '' && !$this->isMember($client, $entry['dn'], $username)) {
                throw new Exception(Exception::USER_INVALID_CREDENTIALS);
            }

            if (!$this->bindUser($client, $entry['dn'], $password)) {
                throw new Exception(Exception::USER_INVALID_CREDENTIALS);
            }

            return $entry;
        } finally {
            $this->disconnect($client);
        }
    }

    /**
     * Walk the configuration the way a sign-in would and say which step fails: connect
     * and bind as the service account, read the base DN, and with a username find the
     * user, check the group and, with a password, bind as them.
     *
     * @throws Exception PROJECT_LDAP_CONFIG_INVALID naming the failing step
     */
    public function test(?string $username = null, ?string $password = null): void
    {
        try {
            $client = $this->connect();
        } catch (Exception $error) {
            throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, $error->getMessage(), previous: $error);
        }

        try {
            try {
                if ($client->read($this->baseDn, ['1.1']) === null) {
                    throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "Base DN {$this->baseDn} does not exist or the service account cannot read it.");
                }
            } catch (OperationException $error) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "Base DN {$this->baseDn} could not be read: " . ($error->getCodeShort() ?? $error->getMessage()) . '.', previous: $error);
            }

            // The user filter is only ever sent with a username, so it is parsed here as well.
            $this->filter($username ?? '');

            if ($username === null || $username === '') {
                return;
            }

            $entries = $this->find($client, $username);

            if (\count($entries) === 0) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "No entry under {$this->baseDn} has {$this->usernameAttribute} equal to the username" . ($this->userFilter !== '' ? " and matches the user filter {$this->userFilter}." : '.'));
            }

            if (\count($entries) > 1) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "More than one entry under {$this->baseDn} has {$this->usernameAttribute} equal to the username. Use a user filter or a different username attribute.");
            }

            $entry = $this->map($entries[0]);

            if ($this->requiredGroupDn !== '' && !$this->isMember($client, $entry['dn'], $username)) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "Entry {$entry['dn']} is not a member of {$this->requiredGroupDn}.");
            }

            if ($password !== null && $password !== '' && !$this->bindUser($client, $entry['dn'], $password)) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "The directory refused the password for {$entry['dn']}.");
            }
        } catch (Exception $error) {
            if ($error->getType() === Exception::USER_LDAP_DIRECTORY_ERROR) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, $error->getMessage(), previous: $error);
            }

            throw $error;
        } finally {
            $this->disconnect($client);
        }
    }

    /**
     * The filter that finds a user: the username attribute equal to the username, and the
     * configured user filter when there is one. The username is carried as a typed
     * assertion value, so it needs no escaping and cannot change the filter's shape.
     *
     * @throws Exception USER_LDAP_DIRECTORY_ERROR when the configured user filter does not parse
     */
    public function filter(string $username): FilterInterface
    {
        $filter = Filters::equal($this->usernameAttribute, $username);

        if ($this->userFilter === '') {
            return $filter;
        }

        try {
            $userFilter = Filters::raw($this->userFilter);
        } catch (FilterParseException $error) {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "User filter {$this->userFilter} is not a valid LDAP filter.", previous: $error);
        }

        // FreeDSx 0.9 reads every extensible match as a DN match (`isset` on an empty
        // optional capture), which would turn the common Active Directory filter
        // (userAccountControl:1.2.840.113556.1.4.803:=2) into one the server rejects.
        // Unless the filter asks for DN matching somewhere, none of its nodes may.
        if (\stripos($this->userFilter, ':dn:') === false) {
            self::withoutDnAttributes($userFilter);
        }

        return Filters::and($filter, $userFilter);
    }

    private static function withoutDnAttributes(FilterInterface $filter): void
    {
        if ($filter instanceof MatchingRuleFilter) {
            $filter->setUseDnAttributes(false);
        } elseif ($filter instanceof NotFilter) {
            self::withoutDnAttributes($filter->get());
        } elseif ($filter instanceof FilterContainerInterface) {
            foreach ($filter->get() as $child) {
                self::withoutDnAttributes($child);
            }
        }
    }

    /**
     * A directory identifier as it is stored: Active Directory's objectGUID rendered the
     * way its own tools render it, any other binary value as hex, text as is.
     */
    public static function identifier(string $attribute, string $value): string
    {
        if (\strcasecmp($attribute, 'objectGUID') === 0 && \strlen($value) === 16) {
            $hex = \bin2hex($value);

            return \implode('-', [
                \implode('', \array_reverse(\str_split(\substr($hex, 0, 8), 2))),
                \implode('', \array_reverse(\str_split(\substr($hex, 8, 4), 2))),
                \implode('', \array_reverse(\str_split(\substr($hex, 12, 4), 2))),
                \substr($hex, 16, 4),
                \substr($hex, 20, 12),
            ]);
        }

        if (!\mb_check_encoding($value, 'UTF-8') || \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            return \bin2hex($value);
        }

        return $value;
    }

    /**
     * Connect to the first host that answers and bind as the service account.
     *
     * Each host is resolved through the public hostname policy and dialled on the
     * address it resolved to, with the certificate still checked against the name.
     * Hosts are replicas of one directory, so a refused service bind is final rather
     * than a reason to try the next one.
     *
     * @throws Exception USER_LDAP_DIRECTORY_ERROR
     */
    private function connect(): LdapClient
    {
        $failure = 'No LDAP host is configured.';

        foreach ($this->hosts as $host) {
            // The certificate is checked against the name it was issued to; an address
            // literal has no name to check, so verification cannot succeed.
            if ($this->tls && $this->encryption !== self::ENCRYPTION_NONE && \filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $failure = "LDAP host {$host} is an IP address, which cannot be checked against a certificate. Use the hostname on the directory's certificate, or turn certificate verification off.";
                continue;
            }

            try {
                $address = $this->hostname->address($host);
            } catch (InvalidArgumentException $error) {
                $failure = $error->getMessage();
                continue;
            }

            $client = new LdapClient([
                'servers' => [\str_contains($address, ':') ? "[{$address}]" : $address],
                'port' => $this->port,
                'base_dn' => $this->baseDn,
                'use_ssl' => $this->encryption === self::ENCRYPTION_LDAPS,
                'ssl_validate_cert' => $this->tls,
                'ssl_ca_cert' => $this->caFile(),
                'ssl_peer_name' => $host,
                'timeout_connect' => $this->timeout,
                'timeout_read' => $this->timeout,
            ]);

            try {
                if ($this->encryption === self::ENCRYPTION_STARTTLS) {
                    $client->startTls();
                }

                // An empty bind DN means the directory allows anonymous search.
                if ($this->bindDn !== '') {
                    $client->bind($this->bindDn, $this->bindPassword);
                }

                return $client;
            } catch (BindException $error) {
                $this->disconnect($client);
                throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "LDAP host {$host}:{$this->port} refused the service account bind. Check the bind DN and bind password.", previous: $error);
            } catch (\Exception $error) {
                // Refused, timed out, or the TLS handshake failed: the next replica may answer.
                $this->disconnect($client);
                $failure = "Could not connect to LDAP host {$host}:{$this->port}. Check the host, port, encryption and certificate settings.";
            }
        }

        throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, $failure);
    }

    private function disconnect(LdapClient $client): void
    {
        try {
            if ($client->isConnected()) {
                $client->unbind();
            }
        } catch (\Throwable) {
            // The connection is being dropped either way.
        }

        if ($this->caFile !== null) {
            @\unlink($this->caFile);
            $this->caFile = null;
        }
    }

    /**
     * The CA certificate as a file, which is how the stream layer takes it, or null for
     * the system trust store. Removed again when the connection closes.
     */
    private function caFile(): ?string
    {
        if ($this->caCertificate === '' || !$this->tls) {
            return null;
        }

        if ($this->caFile === null) {
            $path = \tempnam(\sys_get_temp_dir(), 'ldap-ca-');

            if ($path === false || \file_put_contents($path, $this->caCertificate) === false) {
                throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, 'Could not store the CA certificate for the connection.');
            }

            \chmod($path, 0600);
            $this->caFile = $path;
        }

        return $this->caFile;
    }

    /**
     * The entries matching the username under the base DN, at most two: one is a user,
     * more is an ambiguous configuration.
     *
     * @return list<Entry>
     *
     * @throws Exception USER_LDAP_DIRECTORY_ERROR when the search fails
     */
    private function find(LdapClient $client, string $username): array
    {
        $attributes = \array_values(\array_unique(\array_filter([
            $this->idAttribute,
            $this->emailAttribute,
            $this->nameAttribute,
            $this->phoneAttribute,
        ])));

        $search = Operations::search($this->filter($username), ...$attributes)
            ->base($this->baseDn)
            ->useSubtreeScope()
            ->sizeLimit(2);

        try {
            return $client->search($search)->toArray();
        } catch (OperationException $error) {
            if ($error->getCode() === ResultCode::SIZE_LIMIT_EXCEEDED) {
                // The server stopped at the limit: that is still more than one entry.
                return [new Entry(''), new Entry('')];
            }

            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "Searching for users under {$this->baseDn} failed: " . ($error->getCodeShort() ?? $error->getMessage()) . '. Check the base DN, user filter and the service account permissions.', previous: $error);
        } catch (ConnectionException | SocketException $error) {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, 'The LDAP connection was lost while searching for the user.', previous: $error);
        }
    }

    /**
     * Whether the user belongs to the required group, asked of the directory so that it
     * compares the DNs. First the group's own membership attributes, which is how
     * OpenLDAP and POSIX groups record it, then the user's memberOf, which is how
     * Active Directory records it, including through nested groups.
     *
     * @throws Exception USER_LDAP_DIRECTORY_ERROR when the group does not exist or the search fails
     */
    private function isMember(LdapClient $client, string $userDn, string $username): bool
    {
        $group = $this->requiredGroupDn;

        $onGroup = Operations::search(Filters::or(
            Filters::equal('member', $userDn),
            Filters::equal('uniqueMember', $userDn),
            Filters::equal('memberUid', $username),
        ), '1.1')->base($group)->useBaseScope()->sizeLimit(1);

        $onUser = Operations::search(Filters::or(
            Filters::equal('memberOf', $group),
            Filters::extensible('memberOf', $group, self::RULE_IN_CHAIN),
        ), '1.1')->base($userDn)->useBaseScope()->sizeLimit(1);

        try {
            if (\count($client->search($onGroup)) > 0) {
                return true;
            }
        } catch (OperationException $error) {
            if ($error->getCode() === ResultCode::NO_SUCH_OBJECT) {
                throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "Required group {$group} does not exist or the service account cannot read it.", previous: $error);
            }

            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "Reading the required group {$group} failed: " . ($error->getCodeShort() ?? $error->getMessage()) . '.', previous: $error);
        } catch (ConnectionException | SocketException $error) {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, 'The LDAP connection was lost while checking the group membership.', previous: $error);
        }

        try {
            return \count($client->search($onUser)) > 0;
        } catch (OperationException $error) {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "Reading the user's group membership failed: " . ($error->getCodeShort() ?? $error->getMessage()) . '.', previous: $error);
        } catch (ConnectionException | SocketException $error) {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, 'The LDAP connection was lost while checking the group membership.', previous: $error);
        }
    }

    /**
     * Bind as the user on the connection that found them. Success is authentication;
     * any refusal, whatever its reason, is a failed sign-in.
     *
     * @throws Exception USER_LDAP_DIRECTORY_ERROR when the connection fails before the directory answers
     */
    private function bindUser(LdapClient $client, string $dn, string $password): bool
    {
        try {
            $client->bind($dn, $password);

            return true;
        } catch (BindException) {
            return false;
        } catch (\Throwable $error) {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, 'The LDAP connection was lost while binding as the user.', previous: $error);
        }
    }

    /**
     * The entry as Appwrite reads it: the stable identifier, the DN, and the mapped
     * email, name and phone, trimmed and with the email lower-cased.
     *
     * @return array{id: string, dn: string, email: string, name: string, phone: string}
     *
     * @throws Exception USER_LDAP_DIRECTORY_ERROR when the entry has no identifier
     */
    private function map(Entry $entry): array
    {
        $value = fn (string $attribute): string => $attribute === '' ? '' : \trim((string) ($entry->get($attribute)?->firstValue() ?? ''));

        $id = $entry->get($this->idAttribute)?->firstValue();

        if ($id === null || $id === '') {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "Entry {$entry->getDn()} has no {$this->idAttribute} attribute. Check the ID attribute setting, and that the service account may read it.");
        }

        return [
            'id' => self::identifier($this->idAttribute, (string) $id),
            'dn' => $entry->getDn()->toString(),
            'email' => \strtolower($value($this->emailAttribute)),
            'name' => $value($this->nameAttribute),
            'phone' => $value($this->phoneAttribute),
        ];
    }
}
