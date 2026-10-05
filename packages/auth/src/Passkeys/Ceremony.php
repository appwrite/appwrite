<?php

declare(strict_types=1);

namespace Utopia\Auth\Passkeys;

use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn registration and sign-in for one relying party: discoverable credentials, required user
 * verification and no attestation. Everything crossing this interface is plain arrays and strings.
 */
class Ceremony
{
    /**
     * Seconds a ceremony stays valid, the WebAuthn Level 3 recommended default.
     */
    public const int TIMEOUT = 300;

    private const int ALGORITHM_ES256 = -7;

    private const int ALGORITHM_RS256 = -257;

    private readonly SerializerInterface $serializer;

    private readonly CeremonyStepManagerFactory $steps;

    public function __construct(public readonly RelyingParty $relyingParty)
    {
        $attestation = new AttestationStatementSupportManager();

        $this->serializer = (new WebauthnSerializerFactory($attestation))->create();

        $this->steps = new CeremonyStepManagerFactory();
        $this->steps->setAttestationStatementSupportManager($attestation);
        $this->steps->setAllowedOrigins($relyingParty->origins);
        $this->steps->setCounterChecker(new Counter());
    }

    /**
     * Start registering a passkey. Pass the user's existing records so the authenticator skips
     * credentials it already holds and reuses the same user handle.
     *
     * @param array<array<mixed>> $records records of the user's existing passkeys
     * @throws Exception
     */
    public function register(string $name, string $displayName, array $records = []): Challenge
    {
        $existing = \array_map($this->decodeRecord(...), $records);
        $userHandle = ($existing[0] ?? null)->userHandle ?? \random_bytes(32);

        return $this->start(PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($this->relyingParty->name, $this->relyingParty->id),
            user: PublicKeyCredentialUserEntity::create($name, $userHandle, $displayName),
            challenge: \random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(self::ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(self::ALGORITHM_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: \array_map(
                fn (CredentialRecord $record): PublicKeyCredentialDescriptor => $record->getPublicKeyCredentialDescriptor(),
                $existing,
            ),
            timeout: self::TIMEOUT * 1000,
        ));
    }

    /**
     * @param string $state from the challenge returned by register()
     * @param array<mixed> $credential result of PublicKeyCredential.toJSON() in the browser
     * @throws Exception
     */
    public function verifyRegistration(string $state, array $credential): Credential
    {
        $options = $this->decode($state, PublicKeyCredentialCreationOptions::class);
        $response = $this->decodeCredential($credential)->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new Exception('Expected an attestation response.');
        }

        $this->assertSameOrigin($response);

        return $this->toCredential($this->guard(fn (): CredentialRecord => AuthenticatorAttestationResponseValidator::create($this->steps->creationCeremony())
            ->check($response, $options, $this->relyingParty->id)));
    }

    /**
     * Start a usernameless sign-in: the user picks any of their passkeys for this relying party.
     */
    public function authenticate(): Challenge
    {
        return $this->start(PublicKeyCredentialRequestOptions::create(
            challenge: \random_bytes(32),
            rpId: $this->relyingParty->id,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: self::TIMEOUT * 1000,
        ));
    }

    /**
     * The identifier of the passkey a sign-in credential claims to be, to look up its stored record.
     *
     * @param array<mixed> $credential
     * @throws Exception
     */
    public function identify(array $credential): string
    {
        return $this->getIdentifier($this->decodeCredential($credential)->rawId);
    }

    /**
     * @param string $state from the challenge returned by authenticate()
     * @param array<mixed> $credential result of PublicKeyCredential.toJSON() in the browser
     * @param array<mixed> $record stored record of the passkey identify() pointed to
     * @return Credential with the record updated (counter, backup state) to store back
     * @throws Exception
     */
    public function verifyAuthentication(string $state, array $credential, array $record): Credential
    {
        $options = $this->decode($state, PublicKeyCredentialRequestOptions::class);
        $response = $this->decodeCredential($credential)->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new Exception('Expected an assertion response.');
        }

        $this->assertSameOrigin($response);

        $stored = $this->decodeRecord($record);
        $backupEligible = $stored->backupEligible;

        $verified = $this->guard(fn (): CredentialRecord => AuthenticatorAssertionResponseValidator::create($this->steps->requestCeremony())
            ->check($stored, $response, $options, $this->relyingParty->id, $stored->userHandle));

        // Eligibility is fixed at creation; a change means a different authenticator is answering
        if ($backupEligible !== null && $verified->backupEligible !== $backupEligible) {
            throw new Exception('Backup eligibility changed.');
        }

        return $this->toCredential($verified);
    }

    private function start(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): Challenge
    {
        $state = $this->serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);

        return new Challenge($this->toArray($state), $state);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     * @throws Exception
     */
    private function decode(string $json, string $type): object
    {
        try {
            return $this->serializer->deserialize($json, $type, 'json');
        } catch (\Throwable $throwable) {
            throw new Exception('Invalid ceremony state.', $throwable->getCode(), previous: $throwable);
        }
    }

    /**
     * @param array<mixed> $credential
     * @throws Exception
     */
    private function decodeCredential(array $credential): PublicKeyCredential
    {
        foreach (['id', 'rawId', 'type'] as $key) {
            if (!\is_string($credential[$key] ?? null)) {
                throw new Exception('Invalid credential: missing "' . $key . '".');
            }
        }

        if (!\is_array($credential['response'] ?? null)) {
            throw new Exception('Invalid credential: missing "response".');
        }

        try {
            return $this->serializer->deserialize(\json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');
        } catch (\Throwable $throwable) {
            throw new Exception('Invalid credential: ' . $throwable->getMessage(), $throwable->getCode(), previous: $throwable);
        }
    }

    /**
     * @param array<mixed> $record
     * @throws Exception
     */
    private function decodeRecord(array $record): CredentialRecord
    {
        try {
            return $this->serializer->deserialize(\json_encode($record, JSON_THROW_ON_ERROR), CredentialRecord::class, 'json');
        } catch (\Throwable $throwable) {
            throw new Exception('Invalid credential record.', $throwable->getCode(), previous: $throwable);
        }
    }

    private function toCredential(CredentialRecord $record): Credential
    {
        return new Credential(
            $this->getIdentifier($record->publicKeyCredentialId),
            $this->toArray($this->serializer->serialize($record, 'json')),
            $record->backupStatus === true,
        );
    }

    /**
     * @return array<mixed>
     */
    private function toArray(string $json): array
    {
        $value = \json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('Expected a JSON object.');
        }

        return $value;
    }

    /**
     * Malformed input can surface as CBOR, COSE or assertion errors; report every failure the same way.
     *
     * @param callable(): CredentialRecord $check
     * @throws Exception
     */
    private function guard(callable $check): CredentialRecord
    {
        try {
            return $check();
        } catch (\Throwable $throwable) {
            throw new Exception('Credential verification failed: ' . $throwable->getMessage(), $throwable->getCode(), previous: $throwable);
        }
    }

    /**
     * Embedded (iframe) ceremonies would let another site phish for assertions.
     *
     * @throws Exception
     */
    private function assertSameOrigin(AuthenticatorAttestationResponse|AuthenticatorAssertionResponse $response): void
    {
        if ($response->clientDataJSON->crossOrigin || $response->clientDataJSON->topOrigin !== null) {
            throw new Exception('Cross-origin ceremonies are not allowed.');
        }
    }

    private function getIdentifier(string $credentialId): string
    {
        return \hash('sha256', $credentialId);
    }
}
