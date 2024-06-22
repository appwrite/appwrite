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
use Webauthn\PublicKeyCredential;
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

    public static function createUserEntity(Document $user): PublicKeyCredentialUserEntity
    {
        $name = $user->getAttribute('name') ?? $user->getAttribute('email');

        return new PublicKeyCredentialUserEntity(
            $name,
            $user->getId(),
            $name,
        );
    }

    public static function createRegisterChallenge(PublicKeyCredentialRpEntity $rpEntity, PublicKeyCredentialUserEntity $userEntity, int $timeout): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            rp: $rpEntity,
            user: $userEntity,
            challenge: random_bytes(32),
            timeout: $timeout
        );
    }

    public static function createLoginChallenge(PublicKeyCredentialRpEntity $rpEntity, array $allowedCredentials, int $timeout): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            rpId: $rpEntity->id,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_DEFAULT,
            challenge: random_bytes(32),
            timeout: $timeout,
            allowCredentials: $allowedCredentials
        );
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

        $authenticators = array_filter($authenticators, function ($authenticator) {
            /** @var Document $authenticator */
            return $authenticator->getAttribute('verified') === true;
        });

        if (empty($authenticators)) {
            return [];
        }

        return array_map(function ($authenticator) {
            /** @var Document $authenticator */
            return PublicKeyCredentialSource::createFromArray($authenticator->getArrayCopy());
        }, $authenticators);
    }

    /**
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
     * @throws \Throwable
     */
    public function verifyLoginChallenge(array $challenge, string $challengeResponse, string $hostname, PublicKeyCredentialSource $authenticatorPublicKey): PublicKeyCredentialSource
    {
        $publicKeyCredential = $this->publicKeyCredentialLoader->load($challengeResponse);

        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw new Exception('Invalid response');
        }

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            rpId: $challenge['rp']['id'],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_DEFAULT,
            challenge: Base64UrlSafe::decode($challenge['challenge']),
            timeout: $challenge['timeout'],
            allowCredentials: $challenge['allowCredentials']
        );

        return $this->authenticatiorAssertionResponseValdiator->check(
            credentialId: $authenticatorPublicKey,
            authenticatorAssertionResponse: $publicKeyCredential->response,
            publicKeyCredentialRequestOptions: $requestOptions,
            request: $hostname,
            userHandle: $authenticatorPublicKey->userHandle
        );
    }

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
