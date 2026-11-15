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
use FreeDSx\Ldap\Search\Filter\ApproximateFilter;
use FreeDSx\Ldap\Search\Filter\EqualityFilter;
use FreeDSx\Ldap\Search\Filter\FilterContainerInterface;
use FreeDSx\Ldap\Search\Filter\FilterInterface;
use FreeDSx\Ldap\Search\Filter\GreaterThanOrEqualFilter;
use FreeDSx\Ldap\Search\Filter\LessThanOrEqualFilter;
use FreeDSx\Ldap\Search\Filter\MatchingRuleFilter;
use FreeDSx\Ldap\Search\Filter\NotFilter;
use FreeDSx\Ldap\Search\Filter\SubstringFilter;
use FreeDSx\Ldap\Search\Filters;
use FreeDSx\Socket\Exception\ConnectionException as SocketException;
use InvalidArgumentException;
use Swoole\Coroutine;
use Swoole\Timer;

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

    public const string ATTRIBUTE_ID = 'entryUUID';
    public const string ATTRIBUTE_EMAIL = 'mail';
    public const string ATTRIBUTE_NAME = 'cn';

    /** Where the username a person types goes in the user filter. */
    public const string USERNAME_PLACEHOLDER = '{username}';
    public const string USER_FILTER_DEFAULT = '(uid={username})';

    /**
     * Active Directory's LDAP_MATCHING_RULE_IN_CHAIN: matches a group through any
     * depth of nesting. Servers that do not know the rule evaluate it as Undefined
     * (RFC 4511 section 4.5.1.7), so it is harmless everywhere else.
     */
    public const string RULE_IN_CHAIN = '1.2.840.113556.1.4.1941';

    /** The attribute POSIX accounts keep their login name in, and posixGroup lists members by. */
    private const string ATTRIBUTE_UID = 'uid';

    /** C0 controls other than tab, line feed and carriage return: text never holds them. */
    private const string CONTROL_CHARACTERS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";

    /** @var list<string> */
    private readonly array $hosts;
    private readonly int $port;
    private readonly string $encryption;
    private readonly bool $verification;
    private readonly string $certificate;
    private readonly int $timeout;
    private readonly string $baseDn;
    private readonly string $bindDn;
    private readonly string $bindPassword;
    private readonly string $userFilter;
    private readonly string $idAttribute;
    private readonly string $emailAttribute;
    private readonly string $nameAttribute;
    private readonly string $phoneAttribute;
    private readonly string $requiredGroupDn;

    private ?string $certificateFile = null;

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

        foreach (['idAttribute' => self::ATTRIBUTE_ID, 'emailAttribute' => self::ATTRIBUTE_EMAIL, 'nameAttribute' => self::ATTRIBUTE_NAME] as $key => $default) {
            if (\trim((string) ($config[$key] ?? $default)) === '') {
                throw new InvalidArgumentException('LDAP ' . $key . ' is required.');
            }
        }

        $this->hosts = $hosts;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->verification = (bool) ($config['verification'] ?? true);
        $this->certificate = \trim((string) ($config['certificate'] ?? ''));
        $this->timeout = $timeout;
        $this->baseDn = $baseDn;
        $this->bindDn = \trim((string) ($config['bindDn'] ?? ''));
        $this->bindPassword = (string) ($config['bindPassword'] ?? '');
        $this->userFilter = \trim((string) ($config['userFilter'] ?? '')) ?: self::USER_FILTER_DEFAULT;
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

            if ($this->requiredGroupDn !== '' && !$this->isMember($client, $entries[0])) {
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
                $base = $client->read($this->baseDn, ['1.1']);
            } catch (OperationException $error) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "Base DN {$this->baseDn} could not be read: " . ($error->getCodeShort() ?? $error->getMessage()) . '.', previous: $error);
            } catch (\Throwable $error) {
                // The socket can still drop after connecting, which FreeDSx does not declare
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "The LDAP connection was lost while reading the base DN {$this->baseDn}.", previous: $error);
            }

            if ($base === null) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "Base DN {$this->baseDn} does not exist or the service account cannot read it.");
            }

            // The user filter is only ever sent with a username, so it is parsed here as well.
            $this->filter($username ?? '');

            if ($username === null || $username === '') {
                return;
            }

            $entries = $this->find($client, $username);

            if (\count($entries) === 0) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "No entry under {$this->baseDn} matches the user filter {$this->userFilter} for this username.");
            }

            if (\count($entries) > 1) {
                throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, "More than one entry under {$this->baseDn} matches the user filter {$this->userFilter} for this username. Narrow the user filter so it finds one person.");
            }

            $entry = $this->map($entries[0]);

            if ($this->requiredGroupDn !== '' && !$this->isMember($client, $entries[0])) {
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
     * The filter that finds a user: the configured user filter with the username in place
     * of every {username} in its values. The filter is parsed before the username goes in,
     * and the username is carried as a typed assertion value, so it needs no escaping and
     * cannot change the filter's shape: a typed `*` or `)(` stays literal text.
     *
     * @throws Exception USER_LDAP_DIRECTORY_ERROR when the user filter does not parse or has no {username}
     */
    public function filter(string $username): FilterInterface
    {
        try {
            return self::userFilter($this->userFilter, $username);
        } catch (InvalidArgumentException $error) {
            throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, $error->getMessage(), previous: $error);
        }
    }

    /**
     * Check a user filter before it is stored: it must parse, and {username} must appear
     * in at least one of its values, or every sign-in would look up the same entries.
     *
     * @throws InvalidArgumentException naming what is wrong
     */
    public static function checkUserFilter(string $filter): void
    {
        self::userFilter($filter, '');
    }

    /**
     * @throws InvalidArgumentException when the filter does not parse or has no {username} in a value
     */
    private static function userFilter(string $template, string $username): FilterInterface
    {
        try {
            $filter = Filters::raw($template);
        } catch (FilterParseException $error) {
            throw new InvalidArgumentException("User filter {$template} is not a valid LDAP filter.", previous: $error);
        }

        // FreeDSx 0.9 reads every extensible match as a DN match (`isset` on an empty
        // optional capture), which would turn the common Active Directory filter
        // (userAccountControl:1.2.840.113556.1.4.803:=2) into one the server rejects.
        // Unless the filter asks for DN matching somewhere, none of its nodes may.
        if (\stripos($template, ':dn:') === false) {
            self::withoutDnAttributes($filter);
        }

        if (self::substitute($filter, $username) === 0) {
            throw new InvalidArgumentException("User filter {$template} must contain " . self::USERNAME_PLACEHOLDER . ' where the username goes, for example ' . self::USER_FILTER_DEFAULT . '.');
        }

        return $filter;
    }

    /**
     * Put the username in place of every {username} in the filter's assertion values, and
     * count the places. Attribute names and matching rules are left alone.
     */
    private static function substitute(FilterInterface $filter, string $username): int
    {
        $count = 0;

        if ($filter instanceof EqualityFilter || $filter instanceof ApproximateFilter || $filter instanceof GreaterThanOrEqualFilter || $filter instanceof LessThanOrEqualFilter || $filter instanceof MatchingRuleFilter) {
            $filter->setValue(self::replace($filter->getValue(), $username, $count));
        } elseif ($filter instanceof SubstringFilter) {
            $startsWith = $filter->getStartsWith();
            $endsWith = $filter->getEndsWith();
            $filter->setStartsWith($startsWith === null ? null : self::replace($startsWith, $username, $count));
            $filter->setEndsWith($endsWith === null ? null : self::replace($endsWith, $username, $count));
            // A loop rather than array_map: an arrow function would count into a copy.
            $contains = [];
            foreach ($filter->getContains() as $part) {
                $contains[] = self::replace($part, $username, $count);
            }
            $filter->setContains(...$contains);
        } elseif ($filter instanceof NotFilter) {
            $count += self::substitute($filter->get(), $username);
        } elseif ($filter instanceof FilterContainerInterface) {
            foreach ($filter->get() as $child) {
                $count += self::substitute($child, $username);
            }
        }

        return $count;
    }

    private static function replace(string $value, string $username, int &$count): string
    {
        $count += \substr_count($value, self::USERNAME_PLACEHOLDER);

        return \str_replace(self::USERNAME_PLACEHOLDER, $username, $value);
    }

    /**
     * Check a certificate before it is stored: one or more PEM certificates, each of which
     * reads, and no other PEM block, such as a private key pasted along with them.
     *
     * @throws InvalidArgumentException naming what is wrong
     */
    public static function checkCertificate(string $pem): void
    {
        $invalid = 'Certificate must be in PEM format: each certificate from its -----BEGIN CERTIFICATE----- line to its -----END CERTIFICATE----- line.';
        $blocks = [];
        $offset = 0;

        while (($begin = \strpos($pem, '-----BEGIN ', $offset)) !== false) {
            $typeStart = $begin + \strlen('-----BEGIN ');
            $typeEnd = \strpos($pem, '-----', $typeStart);
            $footer = $typeEnd === false ? null : '-----END ' . \substr($pem, $typeStart, $typeEnd - $typeStart) . '-----';
            $end = $footer === null ? false : \strpos($pem, $footer, (int) $typeEnd);

            // A block cut short would otherwise run on into the next one's END line.
            if ($footer === null || $end === false || \substr_count(\substr($pem, $begin, $end - $begin), '-----BEGIN ') !== 1) {
                throw new InvalidArgumentException($invalid);
            }

            $end += \strlen($footer);
            $blocks[] = [\substr($pem, $typeStart, (int) $typeEnd - $typeStart), \substr($pem, $begin, $end - $begin)];
            $offset = $end;
        }

        if ($blocks === []) {
            throw new InvalidArgumentException($invalid);
        }

        foreach ($blocks as $index => [$type, $block]) {
            if ($type !== 'CERTIFICATE') {
                throw new InvalidArgumentException('Certificate must only hold certificates, but block ' . ($index + 1) . " is a {$type}.");
            }

            if (@\openssl_x509_read($block) === false) {
                while (\openssl_error_string() !== false) {
                    // Drain OpenSSL's error queue so the next call starts clean.
                }

                throw new InvalidArgumentException('Certificate ' . ($index + 1) . ' could not be read. Paste the whole PEM block, including the BEGIN and END lines.');
            }
        }
    }

    /**
     * A phone number the way E.164 writes it, when the directory wrote it for people: an
     * international number loses its spaces, dashes, dots, slashes and brackets, and the
     * trunk prefix some countries show as (0). Anything else is returned as it is.
     */
    public static function phone(string $value): string
    {
        if (!\str_starts_with($value, '+')) {
            return $value;
        }

        $number = \str_replace('(0)', '', \substr($value, 1));

        return '+' . \str_replace([' ', "\t", "\n", "\r", "\u{00A0}", "\u{2009}", "\u{202F}", '-', '.', '/', '(', ')'], '', $number);
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

        if (!\mb_check_encoding($value, 'UTF-8') || \strcspn($value, self::CONTROL_CHARACTERS) !== \strlen($value)) {
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
            if ($this->verification && $this->encryption !== self::ENCRYPTION_NONE && \filter_var($host, FILTER_VALIDATE_IP) !== false) {
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
                'ssl_validate_cert' => $this->verification,
                'ssl_ca_cert' => $this->certificateFile(),
                'ssl_peer_name' => $host,
                'timeout_connect' => $this->timeout,
                'timeout_read' => $this->timeout,
            ]);

            // Under Swoole's coroutine hooks the TLS handshake of an LDAPS connection ignores
            // the connect timeout and waits Swoole's own 60 seconds. Cancel the wait once the
            // connect and the first answer could each have used up their timeout, so a host
            // that accepts and then stays silent fails over like one that refuses.
            $deadline = self::deadline(2 * $this->timeout);

            try {
                if ($this->encryption === self::ENCRYPTION_STARTTLS) {
                    $client->startTls();
                }

                // An empty bind DN means the directory allows anonymous search.
                if ($this->bindDn !== '') {
                    $client->bind($this->bindDn, $this->bindPassword);
                } elseif ($this->encryption !== self::ENCRYPTION_STARTTLS) {
                    // FreeDSx dials on the first request, and nothing above sent one: read the root
                    // DSE now so an unreachable host fails over here, not on the first search.
                    try {
                        $client->read('', ['1.1']);
                    } catch (OperationException) {
                        // The host answered; it only keeps its root DSE to itself.
                    }
                }

                return $client;
            } catch (BindException $error) {
                $this->disconnect($client);
                throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, "LDAP host {$host}:{$this->port} refused the service account bind. Check the bind DN and bind password.", previous: $error);
            } catch (\Exception $error) {
                // Refused, timed out, or the TLS handshake failed: the next replica may answer.
                $this->disconnect($client);
                $failure = "Could not connect to LDAP host {$host}:{$this->port}. Check the host, port, encryption and certificate settings.";
            } finally {
                if ($deadline !== null) {
                    Timer::clear($deadline);
                }
            }
        }

        throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, $failure);
    }

    /**
     * Cancel what the current coroutine waits on once the given time has passed, which
     * makes a hooked socket call fail as a connection error. Outside a coroutine there is
     * nothing to cancel, and plain PHP streams keep to their own timeouts.
     *
     * @return int|null The timer to clear when the work finishes in time
     */
    private static function deadline(int $seconds): ?int
    {
        $coroutine = Coroutine::getCid();

        if ($coroutine < 0) {
            return null;
        }

        $timer = Timer::after($seconds * 1000, fn () => Coroutine::cancel($coroutine));

        return $timer === false ? null : $timer;
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

        if ($this->certificateFile !== null) {
            @\unlink($this->certificateFile);
            $this->certificateFile = null;
        }
    }

    /**
     * The certificate as a file, which is how the stream layer takes it, or null for the
     * system trust store. Removed again when the connection closes.
     */
    private function certificateFile(): ?string
    {
        if ($this->certificate === '' || !$this->verification) {
            return null;
        }

        if ($this->certificateFile === null) {
            $path = \tempnam(\sys_get_temp_dir(), 'ldap-ca-');

            if ($path === false || \file_put_contents($path, $this->certificate) === false) {
                if ($path !== false) {
                    @\unlink($path);
                }

                throw new Exception(Exception::USER_LDAP_DIRECTORY_ERROR, 'Could not store the certificate for the connection.');
            }

            \chmod($path, 0600);
            $this->certificateFile = $path;
        }

        return $this->certificateFile;
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
            // POSIX groups list members by uid, see isMember()
            $this->requiredGroupDn !== '' ? self::ATTRIBUTE_UID : '',
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
    private function isMember(LdapClient $client, Entry $entry): bool
    {
        $group = $this->requiredGroupDn;
        $userDn = $entry->getDn()->toString();

        // A POSIX group's memberUid holds the user's uid exactly as their entry stores it
        // (RFC 2307, matched case-exactly), not what they typed or their username attribute.
        $uids = \array_map(\strval(...), $entry->get(self::ATTRIBUTE_UID)?->getValues() ?? []);

        $onGroup = Operations::search(Filters::or(
            Filters::equal('member', $userDn),
            Filters::equal('uniqueMember', $userDn),
            ...\array_map(fn (string $uid) => Filters::equal('memberUid', $uid), $uids),
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
            'phone' => self::phone($value($this->phoneAttribute)),
        ];
    }
}
