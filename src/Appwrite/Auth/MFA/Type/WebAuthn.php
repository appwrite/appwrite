<?php

namespace Appwrite\Auth\MFA\Type;

use Appwrite\Auth\MFA\Type;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Utopia\App;
use Utopia\Database\Document;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

class WebAuthn extends Type
{
    protected PublicKeyCredentialLoader $publicKeyCredentialLoader;
    protected AuthenticatorAttestationResponseValidator $authenticatorAttestationResponseValidator;
    protected AuthenticatorAssertionResponseValidator $authenticatiorAssertionResponseValdiator;


    public function __construct()
    {
        $attestationSupportManager = AttestationStatementSupportManager::create();
        $attestationObjectLoader = AttestationObjectLoader::create(
            $attestationSupportManager
        );
        $this->publicKeyCredentialLoader = PublicKeyCredentialLoader::create($attestationObjectLoader);

        $this->authenticatorAttestationResponseValidator = AuthenticatorAttestationResponseValidator::create(
            $attestationSupportManager
        );

        $this->authenticatiorAssertionResponseValdiator = AuthenticatorAssertionResponseValidator::create();
    }

    /**
     * Create a new relying party entity, uses the platform if possible
     * 
     * @param Document $project
     * @param Request $request
     * @return PublicKeyCredentialRpEntity
     */
    public static function createRelyingParty(Document $project, Request $request): PublicKeyCredentialRpEntity
    {
        // Calculate Relying Party ID and Name
        $platforms = $project->getAttribute('platforms', []);
        $platformName = '';
        $platformId = '';

        // Detect platform and set platform name and id for Relying Party.
        switch ($request->getHeader('x-sdk-name', '')) {
            case 'Flutter':
                $packageName = explode('/', $request->getHeader('user-agent', ''))[0] ?? '';

                foreach ($platforms as $platform) {
                    if (str_starts_with($platform['type'], 'flutter') && $platform['key'] === $packageName) {
                        $platformName = $platform['name'];
                        $platformId = $platform['hostname'];
                        break;
                    }
                }
                break;

            case 'Apple':
                $packageName = explode('/', $request->getHeader('user-agent', ''))[0] ?? '';

                foreach ($platforms as $platform) {
                    if (str_starts_with($platform['type'], 'apple') && $platform['key'] === $packageName) {
                        $platformName = $platform['name'];
                        $platformId = $platform['hostname'];
                        break;
                    }
                }
                break;

            case 'Android':
                $packageName = explode('/', $request->getHeader('user-agent', ''))[0] ?? '';

                foreach ($platforms as $platform) {
                    if ($platform['type'] === 'android' && $platform['key'] === $packageName) {
                        $platformName = $platform['name'];
                        $platformId = $platform['hostname'];
                        break;
                    }
                }
                break;

            case 'Web':
            default:
                // Fallback to any web platform that matches the domain
                foreach ($platforms as $platform) {
                    if ($platform['type'] === 'web' && $platform['hostname'] == $request->getHostname()) {
                        $platformName = $platform['name'];
                        $platformId = $platform['hostname'];
                        break;
                    }
                }
                break;
        }

        // Console
        if ($project->getId() === 'console') {
            $platformName = 'Appwrite';
            $platformId = App::getEnv('_APP_DOMAIN', '');
        }

        return new PublicKeyCredentialRpEntity(
            $platformName,
            $platformId
        );
    }

    /**
     * Create a new user entity from an Appwrite user document
     * 
     * @param Document $user
     * @return PublicKeyCredentialUserEntity
     */
    public static function createUserEntity(Document $user): PublicKeyCredentialUserEntity
    {
        $name = $user->getAttribute('name') ?? $user->getAttribute('email');

        return new PublicKeyCredentialUserEntity(
            $name,
            $user->getId(),
            $name,
        );
    }

    /**
     * Create a new register challenge
     * 
     * @param PublicKeyCredentialRpEntity $rpEntity
     * @param PublicKeyCredentialUserEntity $userEntity
     * @param int $timeout Timeout in seconds
     * @return array
     */
    public static function createRegisterChallenge(PublicKeyCredentialRpEntity $rpEntity, PublicKeyCredentialUserEntity $userEntity, int $timeout): array
    {
        $nonce = random_bytes(32);

        return [
            'rp' => $rpEntity->jsonSerialize(),
            'user' => $userEntity->jsonSerialize(),
            'challenge' => Base64UrlSafe::encode($nonce),
            'pubKeyCredParams' => [],
            'timeout' => $timeout * 1000, // Convert seconds to milliseconds
        ];
    }

    /**
     * Create a new login challenge
     * 
     * @param PublicKeyCredentialRpEntity $rpEntity
     * @param PublicKeyCredentialSource[] $allowedCredentials
     * @param int $timeout Timeout in seconds
     * @return PublicKeyCredentialRequestOptions
     */
    public static function createLoginChallenge(PublicKeyCredentialRpEntity $rpEntity, array $allowedCredentials, int $timeout): array
    {
        $nonce = random_bytes(32);
        return [
            'rpId' => $rpEntity->id,
            'challenge' => Base64UrlSafe::encode($nonce),
            'userVerification' => PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_DEFAULT,
            'timeout' => $timeout * 1000,
            'allowCredentials' => array_map(function ($credential) {
                /** @var PublicKeyCredentialSource $credential */
                return $credential->jsonSerialize();
            }, $allowedCredentials),
        ];
    }

    public static function deserializePublicKeyCredentialSource(array $publicKeyCredentialSource): PublicKeyCredentialSource
    {
        return PublicKeyCredentialSource::createFromArray($publicKeyCredentialSource);
    }

    /**
     * Get all allowed credentials for a user
     * 
     * @param Document $user
     * @return PublicKeyCredentialSource[]
     */
    public static function getAllowedCredentials(Document $user): array
    {
        $authenticators = self::getAuthenticatorsFromUser($user);

        if (empty($authenticators)) {
            throw new Exception(Exception::USER_AUTHENTICATOR_NOT_FOUND);
        }

        $authenticators = array_filter($authenticators, function ($authenticator) {
            /** @var Document $authenticator */
            return $authenticator->getAttribute('verified') === true;
        });

        if (empty($authenticators)) {
            return [];
        }

        return array_map(function ($authenticator) {
            /** @var Document $authenticator */
            return PublicKeyCredentialSource::createFromArray($authenticator->getAttribute('data', ''))->getPublicKeyCredentialDescriptor();
        }, $authenticators);
    }

    /**
     * Verify a register challenge
     * 
     * @param array $challenge The challenge data deserialized from the database
     * @param string $challengeResponse The challenge response from the client
     * 
     * @return PublicKeyCredentialSource
     * @throws \Throwable
     */
    public function verifyRegisterChallenge(array $challenge, string $challengeResponse): PublicKeyCredentialSource
    {
        $publicKeyCredential = $this->publicKeyCredentialLoader->load($challengeResponse);

        $relyingParty = PublicKeyCredentialRpEntity::create(
            $challenge['rp']['name'],
            $challenge['rp']['id']
        );

        $userEntity = PublicKeyCredentialUserEntity::create(
            $challenge['user']['name'],
            $challenge['user']['id'],
            $challenge['user']['displayName'],
        );

        $publicKeyCreationOptions = PublicKeyCredentialCreationOptions::create(
            $relyingParty,
            $userEntity,
            Base64UrlSafe::decode($challenge['challenge']),
        );

        return $this->authenticatorAttestationResponseValidator->check(
            $publicKeyCredential->response,
            $publicKeyCreationOptions,
            $challenge['rp']['id'],
            App::isDevelopment() ? ['localhost'] : [],
        );
    }

    /**
     * Verify a login challenge
     * 
     * @param array $challenge The challenge data deserialized from the database
     * @param string $challengeResponse The challenge response from the client
     * @param string $hostname The hostname of the request
     * @param int $timeout The timeout of the challenge, MUST be the same as the challenge
     * @param array $allowCredentials The allowed credentials for the challenge, MUST be the same as the challenge
     * @param PublicKeyCredentialSource $authenticatorPublicKey The public key of the authenticator
     * 
     * @throws \Throwable
     */
    public function verifyLoginChallenge(array $challenge, string $challengeResponse, string $hostname, int $timeout, array $allowCredentials, PublicKeyCredentialRpEntity $rpEntity, PublicKeyCredentialSource $authenticatorPublicKey): PublicKeyCredentialSource
    {
        $publicKeyCredential = $this->publicKeyCredentialLoader->load($challengeResponse);

        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw new Exception('Invalid response');
        }

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            rpId: $rpEntity->id,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_DEFAULT,
            challenge: Base64UrlSafe::decode($challenge['code']),
            timeout: $timeout * 1000,
            allowCredentials: $allowCredentials
        );

        return $this->authenticatiorAssertionResponseValdiator->check(
            credentialId: $authenticatorPublicKey,
            authenticatorAssertionResponse: $publicKeyCredential->response,
            publicKeyCredentialRequestOptions: $requestOptions,
            request: $hostname,
            userHandle: $authenticatorPublicKey->userHandle,
            securedRelyingPartyId: App::isDevelopment() ? ['localhost'] : [],
        );
    }

    /**
     * Get all authenticators from a user
     * 
     * @param Document $user
     * @return Document[]|null
     * @throws Exception
     */
    public static function getAuthenticatorsFromUser(Document $user): ?array
    {
        $authenticators = array_filter($user->getAttribute('authenticators', []), function ($authenticator) {
            /** @var Document $authenticator */
            return $authenticator->getAttribute('type') === Type::WEBAUTHN;
        });

        if (empty($authenticators)) {
            return null;
        }

        return $authenticators;
    }
}
