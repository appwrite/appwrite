<?php

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
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn registration and authentication ceremonies for one relying party.
 * Discoverable credentials, required user verification and no attestation.
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
     * @param array<string> $exclude raw credential IDs the authenticator must not register again
     */
    public function createRegistration(string $userHandle, string $userName, string $displayName, array $exclude): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($this->relyingParty->name, $this->relyingParty->id),
            user: PublicKeyCredentialUserEntity::create($userName, $userHandle, $displayName),
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
                fn (string $id): \Webauthn\PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, $id),
                $exclude,
            ),
            timeout: self::TIMEOUT * 1000,
        );
    }

    public function createAuthentication(): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: \random_bytes(32),
            rpId: $this->relyingParty->id,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: self::TIMEOUT * 1000,
        );
    }

    /**
     * JSON form of ceremony options, as accepted by PublicKeyCredential.parseCreationOptionsFromJSON()
     * and parseRequestOptionsFromJSON() in the browser.
     */
    public function encode(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);
    }

    public function decodeRegistration(string $options): PublicKeyCredentialCreationOptions
    {
        return $this->serializer->deserialize($options, PublicKeyCredentialCreationOptions::class, 'json');
    }

    public function decodeAuthentication(string $options): PublicKeyCredentialRequestOptions
    {
        return $this->serializer->deserialize($options, PublicKeyCredentialRequestOptions::class, 'json');
    }

    /**
     * @param array<string, mixed> $credential result of PublicKeyCredential.toJSON() in the browser
     * @throws AuthenticatorResponseVerificationException
     */
    public function decodeCredential(array $credential): PublicKeyCredential
    {
        foreach (['id', 'rawId', 'type'] as $key) {
            if (!\is_string($credential[$key] ?? null)) {
                throw AuthenticatorResponseVerificationException::create('Invalid credential: missing "' . $key . '".');
            }
        }

        if (!\is_array($credential['response'] ?? null)) {
            throw AuthenticatorResponseVerificationException::create('Invalid credential: missing "response".');
        }

        try {
            return $this->serializer->deserialize(\json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');
        } catch (\Throwable $throwable) {
            throw AuthenticatorResponseVerificationException::create('Invalid credential: ' . $throwable->getMessage(), $throwable);
        }
    }

    /**
     * @throws WebauthnException
     */
    public function verifyRegistration(PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): CredentialRecord
    {
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw AuthenticatorResponseVerificationException::create('Expected an attestation response.');
        }

        $this->assertSameOrigin($response);

        return $this->guard(fn (): \Webauthn\CredentialRecord => AuthenticatorAttestationResponseValidator::create($this->steps->creationCeremony())
            ->check($response, $options, $this->relyingParty->id));
    }

    /**
     * @throws WebauthnException
     */
    public function verifyAuthentication(PublicKeyCredential $credential, CredentialRecord $record, PublicKeyCredentialRequestOptions $options): CredentialRecord
    {
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw AuthenticatorResponseVerificationException::create('Expected an assertion response.');
        }

        $this->assertSameOrigin($response);

        $backupEligible = $record->backupEligible;

        $record = $this->guard(fn (): \Webauthn\CredentialRecord => AuthenticatorAssertionResponseValidator::create($this->steps->requestCeremony())
            ->check($record, $response, $options, $this->relyingParty->id, $record->userHandle));

        // Eligibility is fixed at creation; a change means a different authenticator is answering
        if ($backupEligible !== null && $record->backupEligible !== $backupEligible) {
            throw AuthenticatorResponseVerificationException::create('Backup eligibility changed.');
        }

        return $record;
    }

    /**
     * Malformed input can surface as CBOR, COSE or assertion errors; report every failure the same way.
     *
     * @param callable(): CredentialRecord $check
     * @throws WebauthnException
     */
    private function guard(callable $check): CredentialRecord
    {
        try {
            return $check();
        } catch (WebauthnException $th) {
            throw $th;
        } catch (\Throwable $th) {
            throw AuthenticatorResponseVerificationException::create('Invalid credential: ' . $th->getMessage(), $th);
        }
    }

    /**
     * Embedded (iframe) ceremonies would let another site phish for assertions.
     *
     * @throws AuthenticatorResponseVerificationException
     */
    private function assertSameOrigin(AuthenticatorAttestationResponse|AuthenticatorAssertionResponse $response): void
    {
        if ($response->clientDataJSON->crossOrigin || $response->clientDataJSON->topOrigin !== null) {
            throw AuthenticatorResponseVerificationException::create('Cross-origin ceremonies are not allowed.');
        }
    }

    /**
     * @return array<mixed>
     */
    public function encodeRecord(CredentialRecord $record): array
    {
        $record = \json_decode($this->serializer->serialize($record, 'json'), true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($record)) {
            throw new \UnexpectedValueException('Credential record did not serialize to an object.');
        }

        return $record;
    }

    /**
     * @param array<mixed> $record
     */
    public function decodeRecord(array $record): CredentialRecord
    {
        return $this->serializer->deserialize(\json_encode($record, JSON_THROW_ON_ERROR), CredentialRecord::class, 'json');
    }

    /**
     * Indexed lookup key for a raw credential ID.
     */
    public static function getIdentifier(string $credentialId): string
    {
        return \hash('sha256', $credentialId);
    }
}
