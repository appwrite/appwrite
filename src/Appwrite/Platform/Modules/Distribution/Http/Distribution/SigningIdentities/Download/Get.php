<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Download;

use Appwrite\Distribution\Platform;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Distribution\Signing\Keystore;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getSigningIdentityDownload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/signing-identities/:signingIdentityId/download')
            ->desc('Get signing identity download')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('audits.event', 'distribution.signingIdentity.download')
            ->label('audits.resource', 'distributionSigningIdentity/{request.signingIdentityId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'signingIdentities',
                name: 'getSigningIdentityDownload',
                description: <<<EOT
                Download the files and passwords of a signing identity. Each file content is base64-encoded. The response is never cached and every download is audited.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY_DOWNLOAD,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('signingIdentityId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Signing identity ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $signingIdentityId,
        Response $response,
        Database $dbForProject,
    ): void {
        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);

        if ($signingIdentity->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_NOT_FOUND);
        }

        $secret = $signingIdentity->getAttribute('secret', []);

        $download = match ($signingIdentity->getAttribute('platform')) {
            Platform::Ios->value => $this->ios($secret),
            default => $this->android($secret),
        };

        $response
            ->addHeader('Cache-Control', 'no-store')
            ->dynamic(new Document($download), Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY_DOWNLOAD);
    }

    /**
     * @param array<string, mixed> $secret
     * @return array{files: list<array{name: string, content: string}>, passwords: array<string, string>, alias: string}
     */
    private function ios(array $secret): array
    {
        $files = [['name' => 'certificate.p12', 'content' => $secret['certificate'] ?? '']];
        foreach ($secret['profiles'] ?? [] as $identifier => $profile) {
            $files[] = ['name' => $identifier . '.mobileprovision', 'content' => $profile];
        }

        return [
            'files' => $files,
            'passwords' => ['certificate' => $secret['password'] ?? ''],
            'alias' => '',
        ];
    }

    /**
     * @param array<string, mixed> $secret
     * @return array{files: list<array{name: string, content: string}>, passwords: array<string, string>, alias: string}
     */
    private function android(array $secret): array
    {
        $keystore = $secret['keystore'] ?? '';
        $extension = Keystore::isJks((string) \base64_decode($keystore, true)) ? 'jks' : 'p12';

        return [
            'files' => [['name' => 'keystore.' . $extension, 'content' => $keystore]],
            'passwords' => [
                'storePassword' => $secret['storePassword'] ?? '',
                'keyPassword' => $secret['keyPassword'] ?? '',
            ],
            'alias' => $secret['keyAlias'] ?? '',
        ];
    }
}
