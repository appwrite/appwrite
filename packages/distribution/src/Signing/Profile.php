<?php

declare(strict_types=1);

namespace Utopia\Distribution\Signing;

use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\Unavailable;

final readonly class Profile
{
    public const string TYPE_APP_STORE = 'app-store';

    public const string TYPE_AD_HOC = 'ad-hoc';

    public const string TYPE_DEVELOPMENT = 'development';

    public const string TYPE_ENTERPRISE = 'enterprise';

    private const array TYPES = [self::TYPE_APP_STORE, self::TYPE_AD_HOC, self::TYPE_DEVELOPMENT, self::TYPE_ENTERPRISE];

    private const string PLIST_START = '<?xml';

    private const string PLIST_END = '</plist>';

    private const string ENTITY = '<!ENTITY';

    private const string DATE = '!Y-m-d\TH:i:s\Z';

    private const string FINGERPRINT = 'sha256';

    private const array WHITESPACE = ["\n", "\r", "\t", ' '];

    /**
     * @param array<string, mixed> $entitlements
     * @param list<string> $fingerprints
     */
    public function __construct(
        public string $name,
        public string $uuid,
        public string $team,
        public string $identifier,
        public string $type,
        public \DateTimeImmutable $expiresAt,
        public array $entitlements,
        public array $fingerprints,
    ) {
        if (!\in_array($type, self::TYPES, true)) {
            throw new Invalid("Profile type \"{$type}\" is not supported");
        }
    }

    public static function parse(string $envelope): self
    {
        $xml = self::unwrap($envelope);

        if (trim($xml) !== self::scan($envelope)) {
            throw new Invalid('Profile envelope content does not match its property list');
        }

        $profile = self::plist($xml);
        $entitlements = self::dictionary($profile, 'Entitlements');

        $teams = $profile['TeamIdentifier'] ?? [];
        $team = $entitlements['com.apple.developer.team-identifier'] ?? (\is_array($teams) ? $teams[0] ?? null : null);
        if (!\is_string($team) || $team === '') {
            throw new Invalid('Profile has no team identifier');
        }

        $prefixes = $profile['ApplicationIdentifierPrefix'] ?? [];
        $prefix = \is_array($prefixes) && \is_string($prefixes[0] ?? null) && $prefixes[0] !== '' ? $prefixes[0] : $team;

        $application = self::string($entitlements, 'application-identifier');
        $identifier = str_starts_with($application, $prefix . '.') ? substr($application, \strlen($prefix) + 1) : $application;

        $expiresAt = \DateTimeImmutable::createFromFormat(self::DATE, self::string($profile, 'ExpirationDate'), new \DateTimeZone('UTC'));
        if ($expiresAt === false) {
            throw new Invalid('Profile expiration date is malformed');
        }

        $certificates = $profile['DeveloperCertificates'] ?? [];
        if (!\is_array($certificates)) {
            throw new Invalid('Profile developer certificates must be a list');
        }

        $fingerprints = [];
        foreach ($certificates as $certificate) {
            $der = \is_string($certificate) ? base64_decode($certificate, true) : false;
            if ($der === false) {
                throw new Invalid('Profile developer certificates must be data');
            }

            $fingerprints[] = hash(self::FINGERPRINT, $der);
        }

        $devices = isset($profile['ProvisionedDevices']);
        $type = match (true) {
            $devices && ($entitlements['get-task-allow'] ?? false) === true => self::TYPE_DEVELOPMENT,
            $devices => self::TYPE_AD_HOC,
            ($profile['ProvisionsAllDevices'] ?? false) === true => self::TYPE_ENTERPRISE,
            default => self::TYPE_APP_STORE,
        };

        return new self(
            self::string($profile, 'Name'),
            self::string($profile, 'UUID'),
            $team,
            $identifier,
            $type,
            $expiresAt,
            $entitlements,
            $fingerprints,
        );
    }

    public function contains(Certificate $certificate): bool
    {
        return \in_array($certificate->fingerprint(self::FINGERPRINT), $this->fingerprints, true);
    }

    private static function unwrap(string $envelope): string
    {
        $input = tempnam(sys_get_temp_dir(), 'profile');
        $output = tempnam(sys_get_temp_dir(), 'profile');

        try {
            if ($input === false || $output === false) {
                throw new Unavailable('Temporary files for the profile could not be created');
            }

            if (file_put_contents($input, $envelope) !== \strlen($envelope)) {
                throw new Unavailable('Profile envelope could not be written to a temporary file');
            }

            if (!openssl_cms_verify($input, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $output, null, null, OPENSSL_ENCODING_DER)) {
                throw new Invalid('Profile is not a signed CMS envelope' . self::error());
            }

            $content = file_get_contents($output);
            if ($content === false) {
                throw new Unavailable('Profile content could not be read from a temporary file');
            }

            return $content;
        } finally {
            foreach ([$input, $output] as $path) {
                if (\is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private static function scan(string $envelope): string
    {
        $start = strpos($envelope, self::PLIST_START);
        $end = strrpos($envelope, self::PLIST_END);

        if ($start === false || $end === false || $end < $start) {
            throw new Invalid('Profile envelope holds no property list');
        }

        return substr($envelope, $start, $end - $start + \strlen(self::PLIST_END));
    }

    /**
     * @return array<string, mixed>
     */
    private static function plist(string $xml): array
    {
        if (str_contains($xml, self::ENTITY)) {
            throw new Invalid('Profile property list must not declare entities');
        }

        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new Invalid('Profile property list is not well-formed XML');
        }

        $root = $document->documentElement;
        $dictionary = $root instanceof \DOMElement ? self::elements($root)[0] ?? null : null;
        if (!$root instanceof \DOMElement || $root->tagName !== 'plist' || $dictionary === null || $dictionary->tagName !== 'dict') {
            throw new Invalid('Profile property list is not a dictionary');
        }

        return self::entries($dictionary);
    }

    private static function value(\DOMElement $node): mixed
    {
        return match ($node->tagName) {
            'dict' => self::entries($node),
            'array' => array_map(self::value(...), self::elements($node)),
            'string', 'date' => $node->textContent,
            'integer' => (int) $node->textContent,
            'real' => (float) $node->textContent,
            'true' => true,
            'false' => false,
            'data' => self::data($node),
            default => throw new Invalid("Profile property list holds an unexpected <{$node->tagName}> element"),
        };
    }

    private static function data(\DOMElement $node): string
    {
        $data = base64_decode(str_replace(self::WHITESPACE, '', $node->textContent), true);
        if ($data === false) {
            throw new Invalid('Profile property list holds malformed data');
        }

        return base64_encode($data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entries(\DOMElement $node): array
    {
        $children = self::elements($node);
        if (\count($children) % 2 !== 0) {
            throw new Invalid('Profile property list holds a malformed dictionary');
        }

        $entries = [];
        for ($index = 0; $index < \count($children); $index += 2) {
            if ($children[$index]->tagName !== 'key') {
                throw new Invalid('Profile property list holds a malformed dictionary');
            }

            $entries[$children[$index]->textContent] = self::value($children[$index + 1]);
        }

        return $entries;
    }

    /**
     * @return list<\DOMElement>
     */
    private static function elements(\DOMElement $node): array
    {
        $elements = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new Invalid("Profile has no {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function dictionary(array $values, string $key): array
    {
        $value = $values[$key] ?? null;
        if (!\is_array($value)) {
            throw new Invalid("Profile has no {$key}");
        }

        $dictionary = [];
        foreach ($value as $name => $entry) {
            $dictionary[(string) $name] = $entry;
        }

        return $dictionary;
    }

    private static function error(): string
    {
        $last = null;
        while (($error = openssl_error_string()) !== false) {
            $last = $error;
        }

        return $last === null ? '' : " ({$last})";
    }
}
