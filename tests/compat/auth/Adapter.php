<?php

namespace Tests\Compat\Auth;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Auth\Enums\Prompt;
use Utopia\Auth\Hash;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Bcrypt;
use Utopia\Auth\Hashes\MD5;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Auth\Hashes\Plaintext;
use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Hashes\ScryptModified;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Issuer;
use Utopia\Auth\Issuers\Asymmetric;
use Utopia\Auth\Issuers\Asymmetric\AccessToken;
use Utopia\Auth\Issuers\Asymmetric\IdToken;
use Utopia\Auth\Issuers\Symmetric;
use Utopia\Auth\Issuers\Symmetric\Jwt;
use Utopia\Auth\Issuers\Symmetric\RefreshToken;
use Utopia\Auth\OAuth2\AuthorizationDetails;
use Utopia\Auth\OAuth2\ClientIdentifierUrl;
use Utopia\Auth\OAuth2\ClientIdMetadataDocument;
use Utopia\Auth\OAuth2\PAR;
use Utopia\Auth\OAuth2\Prompts;
use Utopia\Auth\OAuth2\RedirectUris;
use Utopia\Auth\OAuth2\ResourceIndicators;
use Utopia\Auth\Passkeys\Ceremony;
use Utopia\Auth\Passkeys\Challenge;
use Utopia\Auth\Passkeys\Credential;
use Utopia\Auth\Passkeys\Origin;
use Utopia\Auth\Passkeys\RelyingParty;
use Utopia\Auth\Proof;
use Utopia\Auth\Proofs\Code;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Phrase;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Auth\Verifier;
use Utopia\Auth\Verifiers\Asymmetric as AsymmetricVerifier;
use Utopia\Auth\Verifiers\Symmetric as SymmetricVerifier;

require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/Authenticator.php';

/**
 * Maps tests/compat/auth/spec.json operations onto utopia-php/auth. Glue only.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
            // Hashes
            'hash.new' => fn (array $a, Session $s) => $s->handle(self::newHash((string) $a['algo'])),
            'hash.name' => fn (array $a, Session $s) => self::hash($a, $s)->getName(),
            'hash.options' => fn (array $a, Session $s) => self::hash($a, $s)->getOptions(),
            'hash.get_option' => fn (array $a, Session $s) => self::hash($a, $s)->getOption((string) $a['key'], $a['default'] ?? null),
            'hash.set_option' => function (array $a, Session $s) {
                self::hash($a, $s)->setOption((string) $a['key'], $a['value'] ?? null);

                return;
            },
            'hash.set_options' => function (array $a, Session $s) {
                self::hash($a, $s)->setOptions(self::map($a['options'] ?? []));

                return;
            },
            'hash.setter' => function (array $a, Session $s) {
                self::setter(self::hash($a, $s), (string) $a['method'], $a['value'] ?? null);

                return;
            },
            'hash.hash' => fn (array $a, Session $s) => self::hash($a, $s)->hash((string) $a['value']),
            'hash.verify' => fn (array $a, Session $s) => self::hash($a, $s)->verify((string) $a['value'], (string) $a['hash_value']),
            'hash.once' => function (array $a, Session $s) {
                $hash = self::newHash((string) $a['algo']);
                $hash->setOptions(self::map($a['options'] ?? []));

                return isset($a['hash_value']) ? $hash->verify((string) $a['value'], (string) $a['hash_value']) : $hash->hash((string) $a['value']);
            },

            // Proofs
            'proof.new' => fn (array $a, Session $s) => $s->handle(self::newProof($a, $s)),
            'proof.generate' => fn (array $a, Session $s) => self::proof($a, $s)->generate(),
            'proof.hash' => fn (array $a, Session $s) => self::proof($a, $s)->hash((string) $a['value']),
            'proof.verify' => fn (array $a, Session $s) => self::proof($a, $s)->verify((string) $a['value'], (string) $a['hash_value']),
            'proof.hash_name' => fn (array $a, Session $s) => self::proof($a, $s)->getHash()->getName(),
            'proof.hash_options' => fn (array $a, Session $s) => self::proof($a, $s)->getHash()->getOptions(),
            'proof.hash_setter' => function (array $a, Session $s) {
                self::setter(self::proof($a, $s)->getHash(), (string) $a['method'], $a['value'] ?? null);

                return;
            },
            'proof.hash_set_option' => function (array $a, Session $s) {
                self::proof($a, $s)->getHash()->setOption((string) $a['key'], $a['value'] ?? null);

                return;
            },
            'proof.set_hash' => function (array $a, Session $s) {
                $hash = $s->get($a['hash']);
                $hash instanceof Hash || throw new Fault('not a hash');
                self::proof($a, $s)->setHash($hash);

                return;
            },
            'proof.length' => function (array $a, Session $s) {
                $proof = self::proof($a, $s);

                return $proof instanceof Code || $proof instanceof Token ? $proof->getLength() : throw new Fault('no length');
            },
            'proof.set_length' => function (array $a, Session $s) {
                $proof = self::proof($a, $s);
                if (!$proof instanceof Code && !$proof instanceof Token && !$proof instanceof Password) {
                    throw new Fault('no length');
                }
                $proof->setLength((int) $a['length']);

                return;
            },
            'password.set_charset' => function (array $a, Session $s) {
                self::password($a, $s)->setCharset((string) $a['charset']);

                return;
            },
            'password.add_hash' => function (array $a, Session $s) {
                $hash = $s->get($a['hash']);
                $hash instanceof Hash || throw new Fault('not a hash');
                self::password($a, $s)->addHash((string) $a['name'], $hash);

                return;
            },
            'password.remove_hash' => function (array $a, Session $s) {
                self::password($a, $s)->removeHash((string) $a['name']);

                return;
            },
            'password.hash_by_name' => fn (array $a, Session $s) => self::password($a, $s)->getHashByName((string) $a['name'])->getName(),
            'password.use_hash' => function (array $a, Session $s) {
                $password = self::password($a, $s);
                $password->setHash($password->getHashByName((string) $a['name']));

                return;
            },
            'password.create_hash' => fn (array $a, Session $s) => $s->handle(Password::createHash((string) $a['type'], self::map($a['options'] ?? []))),

            // Store
            'store.new' => fn (array $a, Session $s) => $s->handle(new Store()),
            'store.set_property' => function (array $a, Session $s) {
                self::store($a, $s)->setProperty((string) $a['key'], $a['value'] ?? null);

                return;
            },
            'store.get_property' => fn (array $a, Session $s) => self::store($a, $s)->getProperty((string) $a['key'], $a['default'] ?? null),
            'store.set_key' => function (array $a, Session $s) {
                self::store($a, $s)->setKey(isset($a['key']) ? (string) $a['key'] : null);

                return;
            },
            'store.get_key' => fn (array $a, Session $s) => self::store($a, $s)->getKey(),
            'store.encode' => fn (array $a, Session $s) => self::store($a, $s)->encode(),
            'store.decode_once' => function (array $a, Session $s) {
                $store = new Store();
                $store->decode((string) $a['data']);

                return $store->encode();
            },
            'store.decode' => function (array $a, Session $s) {
                self::store($a, $s)->decode((string) $a['data']);

                return;
            },

            // JWT issuers and verifiers
            'issuer.new' => fn (array $a, Session $s) => $s->handle(self::newIssuer($a)),
            'issuer.issue' => fn (array $a, Session $s) => self::issue($a, $s),
            'issuer.key_id' => function (array $a, Session $s) {
                $issuer = $s->get($a['issuer']);

                return $issuer instanceof Symmetric || $issuer instanceof Asymmetric ? $issuer->getKeyId() : throw new Fault('not an issuer');
            },
            'issuer.public_jwk' => fn (array $a, Session $s) => self::asymmetric($a, $s)->getPublicJwk(),
            'issuer.generate_secret' => fn (array $a, Session $s) => isset($a['bytes']) ? Symmetric::generateSecret(max(1, (int) $a['bytes'])) : Symmetric::generateSecret(),
            'issuer.generate_key_pair' => function (array $a, Session $s) {
                [$private, $public] = isset($a['bits']) ? Asymmetric::generateKeyPair((int) $a['bits']) : Asymmetric::generateKeyPair();

                return ['private' => $private, 'public' => $public];
            },
            'verifier.new' => fn (array $a, Session $s) => $s->handle(self::newVerifier($a)),
            'verifier.verify' => function (array $a, Session $s) {
                $verifier = $s->get($a['verifier']);
                $verifier instanceof Verifier || throw new Fault('not a verifier');
                Clock::$now = isset($a['now']) ? (int) $a['now'] : null;

                return $verifier->verify((string) $a['token']);
            },
            'verifier.check' => function (array $a, Session $s) {
                $verifier = self::newVerifier(['kind' => 'symmetric'] + $a);
                Clock::$now = isset($a['now']) ? (int) $a['now'] : null;

                return $verifier->verify(isset($a['token']) ? (string) $a['token'] : self::jws(['alg' => 'HS256', 'key' => $a['secret']] + $a));
            },
            'verifier.key_id' => function (array $a, Session $s) {
                $verifier = $s->get($a['verifier']);

                return $verifier instanceof AsymmetricVerifier ? $verifier->getKeyId() : throw new Fault('not an asymmetric verifier');
            },

            // OAuth2
            'par.from_id' => fn (array $a, Session $s) => self::par(PAR::fromId((string) $a['prefix'], (string) $a['id'])),
            'par.from_request_uri' => fn (array $a, Session $s) => self::par(PAR::fromRequestUri((string) $a['prefix'], (string) $a['request_uri'])),
            'prompts.from_string' => function (array $a, Session $s) {
                $prompts = Prompts::fromString((string) $a['prompt']);
                $contains = [];
                foreach (Prompt::cases() as $prompt) {
                    $contains[$prompt->value] = $prompts->contains($prompt);
                }

                return ['array' => $prompts->toArray(), 'string' => $prompts->toString(), 'contains' => $contains];
            },
            'redirect_uris.from' => fn (array $a, Session $s) => RedirectUris::from(self::list($a['uris'] ?? []))->toArray(),
            'redirect_uris.matches' => fn (array $a, Session $s) => RedirectUris::from(self::list($a['uris'] ?? []))
                ->matches((string) $a['presented'], (bool) ($a['allow_loopback'] ?? false)),
            'resources.from' => fn (array $a, Session $s) => self::resources($a['value'] ?? null, $a['audience'] ?? null)->toArray(),
            'resources.audience' => fn (array $a, Session $s) => self::resources($a['value'] ?? null, $a['audience'] ?? null)->audience((string) $a['default']),
            'resources.is_subset_of' => fn (array $a, Session $s) => self::resources($a['value'] ?? null)->isSubsetOf(self::resources($a['granted'] ?? null)),
            'resources.equals' => fn (array $a, Session $s) => self::resources($a['value'] ?? null)->equals(self::resources($a['other'] ?? null)),
            'authorization_details.new' => fn (array $a, Session $s) => (new AuthorizationDetails($a['value'] ?? null))->toArray(),
            'authorization_details.grants' => fn (array $a, Session $s) => (new AuthorizationDetails($a['details'] ?? null))
                ->grants((string) $a['type'], (string) $a['value'], (string) $a['field'], isset($a['wildcard']) ? (string) $a['wildcard'] : null),
            'authorization_details.restrict' => function (array $a, Session $s) {
                $calls = [];
                $allow = self::map($a['allow'] ?? []);
                $restricted = (new AuthorizationDetails($a['details'] ?? null))->restrict(
                    (string) $a['field'],
                    function (string $type, array $values) use (&$calls, $allow): ?array {
                        $calls[] = [$type, $values];
                        $allowed = $allow[$type] ?? null;

                        return \is_array($allowed) ? $allowed : null;
                    },
                    isset($a['wildcard']) ? (string) $a['wildcard'] : null,
                );

                return ['details' => $restricted->toArray(), 'calls' => $calls];
            },
            'client_id_url.is_candidate' => fn (array $a, Session $s) => ClientIdentifierUrl::isCandidate((string) $a['value']),
            'client_id_url.from_string' => function (array $a, Session $s) {
                $url = ClientIdentifierUrl::fromString((string) $a['value'], (bool) ($a['allow_http'] ?? false));

                return ['string' => $url->toString(), 'host' => $url->host()];
            },
            'client_metadata.from_json' => fn (array $a, Session $s) => self::metadata(ClientIdMetadataDocument::fromJson(
                ClientIdentifierUrl::fromString((string) $a['client_id'], (bool) ($a['allow_http'] ?? false)),
                (string) $a['json'],
            ), $a),
            'client_metadata.from_array' => fn (array $a, Session $s) => self::metadata(ClientIdMetadataDocument::fromArray(
                ClientIdentifierUrl::fromString((string) $a['client_id'], (bool) ($a['allow_http'] ?? false)),
                self::map($a['metadata'] ?? []),
            ), $a),

            // Passkeys
            'passkeys.ceremony' => fn (array $a, Session $s) => $s->handle(new Ceremony(self::relyingParty($a))),
            'passkeys.fingerprint' => fn (array $a, Session $s) => self::relyingParty($a)->getFingerprint(),
            'passkeys.origin_normalize' => fn (array $a, Session $s) => (new Origin((string) $a['rp_id']))->normalize((string) $a['origin']),
            'passkeys.origin_description' => fn (array $a, Session $s) => (new Origin((string) $a['rp_id']))->getDescription(),
            'passkeys.register' => fn (array $a, Session $s) => self::challenge(self::ceremony($a, $s)->register(
                (string) $a['name'],
                (string) $a['display_name'],
                array_values(array_map(self::map(...), self::list($a['records'] ?? []))),
            )),
            'passkeys.authenticate' => fn (array $a, Session $s) => self::challenge(self::ceremony($a, $s)->authenticate()),
            'passkeys.identify' => fn (array $a, Session $s) => self::ceremony($a, $s)->identify(self::map($a['credential'] ?? [])),
            'passkeys.verify_registration' => fn (array $a, Session $s) => self::credential(self::ceremony($a, $s)->verifyRegistration(
                (string) $a['state'],
                self::map($a['credential'] ?? []),
            )),
            'passkeys.verify_authentication' => fn (array $a, Session $s) => self::credential(self::ceremony($a, $s)->verifyAuthentication(
                (string) $a['state'],
                self::map($a['credential'] ?? []),
                self::map($a['record'] ?? []),
            )),
            'passkeys.authenticator' => fn (array $a, Session $s) => Authenticator::respond($a),
            'passkeys.ceremony_once' => fn (array $a, Session $s) => self::ceremonyOnce($a),

            // Fixtures: the assertions the PHP tests make on random outputs.
            'fixture.matches' => fn (array $a, Session $s) => preg_match((string) $a['pattern'], (string) $a['value']),
            'fixture.strlen' => fn (array $a, Session $s) => \strlen((string) $a['value']),
            'fixture.substr' => fn (array $a, Session $s) => substr((string) $a['value'], (int) $a['start'], isset($a['length']) ? (int) $a['length'] : null),
            'fixture.jws' => fn (array $a, Session $s) => self::jws($a),
            'fixture.jwt' => function (array $a, Session $s) {
                $parts = explode('.', (string) $a['token']);
                $decode = fn (string $segment): mixed => json_decode((string) base64_decode(strtr($segment, '-_', '+/')), true);

                return ['header' => $decode($parts[0]), 'claims' => $decode($parts[1] ?? '')];
            },
        ];
    }

    /**
     * A compact JWS of json_encode(header) and json_encode(claims) (or the raw
     * segments), signed HS256 or RS256 with `key`.
     */
    private static function jws(array $a): string
    {
        $encode = fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $header = isset($a['raw_header']) ? (string) $a['raw_header'] : (string) json_encode($a['header'] ?? null);
        $claims = isset($a['raw_claims']) ? (string) $a['raw_claims'] : (string) json_encode($a['claims'] ?? null);
        $input = $encode($header) . '.' . $encode($claims);
        $signature = '';
        if (($a['alg'] ?? '') === 'HS256') {
            $signature = hash_hmac('sha256', $input, (string) $a['key'], true);
        } elseif (!openssl_sign($input, $signature, (string) $a['key'], OPENSSL_ALGO_SHA256)) {
            throw new Fault('unable to sign');
        }

        return $input . '.' . $encode((string) $signature);
    }

    private static function newHash(string $algo): Hash
    {
        return match ($algo) {
            'argon2' => new Argon2(),
            'bcrypt' => new Bcrypt(),
            'md5' => new MD5(),
            'phpass' => new PHPass(),
            'plaintext' => new Plaintext(),
            'scrypt' => new Scrypt(),
            'scryptMod' => new ScryptModified(),
            'sha' => new Sha(),
            default => throw new Fault("unknown hash {$algo}"),
        };
    }

    private static function setter(Hash $hash, string $method, mixed $value): void
    {
        match (true) {
            $hash instanceof Argon2 && $method === 'setMemoryCost' => $hash->setMemoryCost((int) $value),
            $hash instanceof Argon2 && $method === 'setTimeCost' => $hash->setTimeCost((int) $value),
            $hash instanceof Argon2 && $method === 'setThreads' => $hash->setThreads((int) $value),
            $hash instanceof Bcrypt && $method === 'setCost' => $hash->setCost((int) $value),
            $hash instanceof PHPass && $method === 'setIterationCount' => $hash->setIterationCount((int) $value),
            $hash instanceof PHPass && $method === 'setPortableHashes' => $hash->setPortableHashes((bool) $value),
            $hash instanceof Scrypt && $method === 'setCpuCost' => $hash->setCpuCost((int) $value),
            $hash instanceof Scrypt && $method === 'setMemoryCost' => $hash->setMemoryCost((int) $value),
            $hash instanceof Scrypt && $method === 'setParallelCost' => $hash->setParallelCost((int) $value),
            $hash instanceof Scrypt && $method === 'setLength' => $hash->setLength((int) $value),
            $hash instanceof Scrypt && $method === 'setSalt' => $hash->setSalt((string) $value),
            $hash instanceof ScryptModified && $method === 'setSalt' => $hash->setSalt((string) $value),
            $hash instanceof ScryptModified && $method === 'setSaltSeparator' => $hash->setSaltSeparator((string) $value),
            $hash instanceof ScryptModified && $method === 'setSignerKey' => $hash->setSignerKey((string) $value),
            $hash instanceof Sha && $method === 'setVersion' => $hash->setVersion((string) $value),
            default => throw new Fault("{$method} is not a setter of " . $hash->getName()),
        };
    }

    private static function hash(array $a, Session $s): Hash
    {
        $hash = $s->get($a['hash']);

        return $hash instanceof Hash ? $hash : throw new Fault('not a hash');
    }

    private static function newProof(array $a, Session $s): Proof
    {
        return match ($a['kind'] ?? '') {
            'code' => isset($a['length']) ? new Code((int) $a['length']) : new Code(),
            'token' => isset($a['length']) ? new Token((int) $a['length']) : new Token(),
            'phrase' => new Phrase(),
            'password' => new Password(array_map(
                fn (mixed $h) => $s->get($h) instanceof Hash ? $s->get($h) : throw new Fault('not a hash'),
                self::map($a['hashes'] ?? []),
            )),
            default => throw new Fault('unknown proof'),
        };
    }

    private static function proof(array $a, Session $s): Proof
    {
        $proof = $s->get($a['proof']);

        return $proof instanceof Proof ? $proof : throw new Fault('not a proof');
    }

    private static function password(array $a, Session $s): Password
    {
        $proof = $s->get($a['proof']);

        return $proof instanceof Password ? $proof : throw new Fault('not a password proof');
    }

    private static function store(array $a, Session $s): Store
    {
        $store = $s->get($a['store']);

        return $store instanceof Store ? $store : throw new Fault('not a store');
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @return array<int, mixed>
     */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }

    private static function newIssuer(array $a): Issuer
    {
        $kid = isset($a['key_id']) ? (string) $a['key_id'] : null;

        return match ($a['kind'] ?? '') {
            'jwt' => new Jwt((string) $a['secret'], (string) $a['issuer'], $kid),
            'refresh' => new RefreshToken((string) $a['secret'], (string) $a['issuer'], $kid),
            'access' => new AccessToken((string) $a['private_key'], (string) $a['public_key'], (string) $a['issuer'], $kid),
            'id' => new IdToken((string) $a['private_key'], (string) $a['public_key'], (string) $a['issuer'], $kid),
            default => throw new Fault('unknown issuer'),
        };
    }

    private static function asymmetric(array $a, Session $s): Asymmetric
    {
        $issuer = $s->get($a['issuer']);

        return $issuer instanceof Asymmetric ? $issuer : throw new Fault('not an asymmetric issuer');
    }

    private static function issue(array $a, Session $s): string
    {
        $issuer = $s->get($a['issuer']);
        Clock::$now = isset($a['now']) ? (int) $a['now'] : null;
        $claims = self::map($a['claims'] ?? []);
        $jti = isset($a['jti']) ? (string) $a['jti'] : null;
        $scopes = array_map(fn (mixed $v) => (string) $v, self::list($a['scopes'] ?? []));

        return match (true) {
            $issuer instanceof Jwt => $issuer->issue(
                \is_array($a['audience'] ?? null) ? $a['audience'] : (string) ($a['audience'] ?? ''),
                (int) $a['duration'],
                $claims,
            ),
            $issuer instanceof RefreshToken => $issuer->issue(
                (string) $a['subject'],
                (string) $a['audience'],
                (string) $a['client_id'],
                (int) $a['duration'],
                $scopes,
                $jti,
                $claims,
            ),
            $issuer instanceof AccessToken => $issuer->issue(
                (string) $a['subject'],
                self::map($a['audience'] ?? []),
                (string) $a['client_id'],
                (int) $a['auth_time'],
                (int) $a['duration'],
                $scopes,
                $jti,
                $claims,
            ),
            $issuer instanceof IdToken => $issuer->issue(
                (string) $a['subject'],
                (string) $a['audience'],
                (int) $a['auth_time'],
                (int) $a['duration'],
                isset($a['nonce']) ? (string) $a['nonce'] : null,
                isset($a['access_token']) ? (string) $a['access_token'] : null,
                isset($a['code']) ? (string) $a['code'] : null,
                $claims,
            ),
            default => throw new Fault('not an issuer'),
        };
    }

    private static function newVerifier(array $a): Verifier
    {
        $issuer = isset($a['issuer']) ? (string) $a['issuer'] : null;
        $audience = $a['audience'] ?? null;
        $audience = \is_array($audience) ? array_values($audience) : (isset($audience) ? (string) $audience : null);
        $type = isset($a['type']) ? (string) $a['type'] : null;
        $allowExpired = (bool) ($a['allow_expired'] ?? false);
        $leeway = (int) ($a['leeway'] ?? 0);

        return match ($a['kind'] ?? '') {
            'symmetric' => new SymmetricVerifier((string) $a['secret'], $issuer, $audience, $type, $allowExpired, $leeway),
            'asymmetric' => new AsymmetricVerifier((string) $a['public_key'], $issuer, $audience, $type, $allowExpired, $leeway),
            default => throw new Fault('unknown verifier'),
        };
    }

    /**
     * @return array{id: string, requestUri: string}
     */
    private static function par(PAR $par): array
    {
        return ['id' => $par->id(), 'requestUri' => $par->requestUri()];
    }

    private static function resources(mixed $value, mixed $audience = null): ResourceIndicators
    {
        return ResourceIndicators::from(
            \is_array($value) ? $value : (isset($value) ? (string) $value : null),
            isset($audience) ? (string) $audience : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(ClientIdMetadataDocument $document, array $a): array
    {
        $out = [
            'clientId' => $document->clientId()->toString(),
            'tokenEndpointAuthMethod' => $document->tokenEndpointAuthMethod(),
            'grantTypes' => $document->grantTypes(),
            'responseTypes' => $document->responseTypes(),
            'redirectUris' => $document->redirectUris()->toArray(),
            'metadata' => $document->toArray(),
        ];
        if (isset($a['get'])) {
            $out['get'] = $document->get((string) $a['get'], $a['default'] ?? null);
        }

        return $out;
    }

    private static function relyingParty(array $a): RelyingParty
    {
        return new RelyingParty(
            (string) $a['id'],
            (string) ($a['name'] ?? ''),
            array_map(fn (mixed $o) => (string) $o, self::list($a['origins'] ?? [])),
        );
    }

    /**
     * A registration (and, for `phase` sign-in, a sign-in) on localhost in one
     * call, with the authenticator's output overridden by hex-encoded bytes:
     * fuzzed CBOR, COSE keys, extensions and signatures. CBOR declaring a
     * byte or text string of 64 MiB or more is not run: webauthn-lib's stream
     * fread()s the declared length, which exhausts PHP's memory (a fatal error).
     *
     * @param  array<string, mixed>  $a
     * @return array{identifier: string, record: array<mixed>}|string
     */
    private static function ceremonyOnce(array $a): array|string
    {
        $origin = 'http://localhost:3000';
        $ceremony = new Ceremony(new RelyingParty('localhost', 'Test', [$origin]));
        $challenge = $ceremony->register('user@example.com', 'User', []);
        $signIn = ($a['phase'] ?? 'register') === 'sign-in';
        $authenticator = ['key' => (string) $a['key'], 'credential_id' => '0kzTk-rTMQXiw5KPbsDZlQ', 'origin' => $origin];
        $overrides = static function (array $fields) use ($a): array {
            $out = [];
            foreach ($fields as $field) {
                if (isset($a[$field])) {
                    $hex = (string) $a[$field];
                    $out[$field] = (string) hex2bin(substr($hex, 0, \strlen($hex) - \strlen($hex) % 2));
                }
            }

            return $out;
        };
        foreach ($overrides(['attestation', 'cose', 'extensions']) as $cbor) {
            if (self::oversized($cbor)) {
                return 'skipped: a declared length of 64 MiB or more';
            }
        }
        $response = Authenticator::respond(['kind' => 'register', 'options' => $challenge->options] + $authenticator
            + ($signIn ? [] : $overrides(['attestation', 'cose', 'extensions', 'aaguid'])));
        $registered = $ceremony->verifyRegistration($challenge->state, $response);
        if (! $signIn) {
            return self::credential($registered);
        }
        $request = $ceremony->authenticate();
        $user = self::map($challenge->options['user'] ?? [])['id'] ?? '';
        $response = Authenticator::respond(['kind' => 'authenticate', 'options' => $request->options, 'counter' => 1, 'user_handle' => $user]
            + $authenticator + $overrides(['extensions', 'signature']));

        return self::credential($ceremony->verifyAuthentication($request->state, $response, $registered->record));
    }

    /**
     * Whether some byte could head a byte or text string of 64 MiB or more.
     */
    private static function oversized(string $cbor): bool
    {
        $n = \strlen($cbor);
        for ($i = 0; $i < $n; ++$i) {
            $head = \ord($cbor[$i]);
            $width = match ($head) {
                0x5A, 0x7A => 4,
                0x5B, 0x7B => 8,
                default => 0,
            };
            if ($width === 0 || $i + $width >= $n) {
                continue;
            }
            $length = (int) unpack($width === 4 ? 'N' : 'J', substr($cbor, $i + 1, $width))[1];
            if ($length >= 0x4000000) {
                return true;
            }
        }

        return false;
    }

    private static function ceremony(array $a, Session $s): Ceremony
    {
        $ceremony = $s->get($a['ceremony']);

        return $ceremony instanceof Ceremony ? $ceremony : throw new Fault('not a ceremony');
    }

    /**
     * @return array{options: array<mixed>, state: string}
     */
    private static function challenge(Challenge $challenge): array
    {
        return ['options' => $challenge->options, 'state' => $challenge->state];
    }

    /**
     * @return array{identifier: string, record: array<mixed>}
     */
    private static function credential(Credential $credential): array
    {
        return ['identifier' => $credential->identifier, 'record' => $credential->record];
    }
}
