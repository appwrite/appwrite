<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Android;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Signing\Mode;
use Appwrite\Distribution\Signing\Status;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Id;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Signing\Keystore;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    private const int LIMIT = 500_000;

    private const int BASE64_BLOCK_BYTES = 3;

    private const int BASE64_BLOCK_CHARACTERS = 4;

    public static function getName(): string
    {
        return 'createAndroidSigningIdentity';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/signing-identities/android')
            ->desc('Create Android signing identity')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionSigningIdentities.[signingIdentityId].create')
            ->label('audits.event', 'distribution.signingIdentity.create')
            ->label('audits.resource', 'distributionSigningIdentity/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'signingIdentities',
                name: 'createAndroidSigningIdentity',
                description: <<<EOT
                Import an Android upload keystore. A PKCS#12 keystore is validated with its password, which must be the same for the store and the key. A JKS keystore is accepted as is and verified by the first sign job, so its fingerprint and expiry stay empty until then. The key alias is not checked against the keystore for either format, so a wrong alias fails the first sign job. JCEKS keystores are rejected. The keystore is stored encrypted and never returned except through the download endpoint.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('signingIdentityId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), "Signing identity ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars.", false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Signing identity name. Max length: 128 chars.')
            ->param('keystore', '', new Text(0), 'Base64-encoded keystore file, PKCS#12 or JKS.')
            ->param('storePassword', '', new Text(0, 0), 'Keystore password.')
            ->param('keyAlias', '', new Text(0, 1), 'Alias of the upload key inside the keystore.')
            ->param('keyPassword', '', new Text(0, 0), 'Password of the upload key. Must equal the keystore password for a PKCS#12 keystore.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $signingIdentityId,
        string $name,
        #[\SensitiveParameter]
        string $keystore,
        #[\SensitiveParameter]
        string $storePassword,
        string $keyAlias,
        #[\SensitiveParameter]
        string $keyPassword,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
    ): void {
        $signingIdentityId = $signingIdentityId === 'unique()' ? Id::unique() : $signingIdentityId;

        if (!$dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId)->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_ALREADY_EXISTS);
        }

        if (\strlen($keystore) > $this->encodedLimit()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Signing files must not exceed ' . self::LIMIT . ' bytes in total once decoded.');
        }

        $archive = \base64_decode($keystore, true);
        if ($archive === false || $archive === '') {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Param "keystore" must be base64-encoded.');
        }

        if (\strlen($archive) > self::LIMIT) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Signing files must not exceed ' . self::LIMIT . ' bytes in total once decoded.');
        }

        if (Keystore::isJceks($archive)) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'JCEKS keystores are not supported: convert it to PKCS#12 with `keytool -importkeystore -deststoretype PKCS12`.');
        }

        $fingerprint = '';
        $expiresAt = null;

        if (!Keystore::isJks($archive)) {
            if ($keyPassword !== $storePassword) {
                throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'A PKCS#12 keystore has a single password: keyPassword must equal storePassword.');
            }

            try {
                $certificate = Keystore::fromPkcs12($archive, $storePassword, $keyAlias)->certificate;
            } catch (Invalid $error) {
                throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getMessage());
            }

            if ($certificate->expiresAt() <= new \DateTimeImmutable()) {
                throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'The keystore certificate has expired.');
            }

            $fingerprint = $certificate->fingerprint();
            $expiresAt = DateTime::format(\DateTime::createFromImmutable($certificate->expiresAt()));
        }

        try {
            $signingIdentity = $dbForProject->createDocument('distributionSigningIdentities', new Document([
                '$id' => $signingIdentityId,
                '$permissions' => [],
                'platform' => Platform::Android->value,
                'name' => $name,
                'mode' => Mode::Imported->value,
                'secret' => [
                    'keystore' => \base64_encode($archive),
                    'storePassword' => $storePassword,
                    'keyAlias' => $keyAlias,
                    'keyPassword' => $keyPassword,
                ],
                'fingerprint' => $fingerprint,
                'teamId' => '',
                'identifiers' => [],
                'connectionId' => '',
                'connectionInternalId' => '',
                'externalId' => '',
                'expiresAt' => $expiresAt,
                'status' => Status::Active->value,
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_ALREADY_EXISTS);
        } catch (Structure $error) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getMessage());
        }

        $queueForEvents->setParam('signingIdentityId', $signingIdentity->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY);
    }

    private function encodedLimit(): int
    {
        return self::BASE64_BLOCK_CHARACTERS * \intdiv(self::LIMIT + self::BASE64_BLOCK_BYTES - 1, self::BASE64_BLOCK_BYTES);
    }
}
