<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Certificates;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Distribution\Signing\Certificate;
use Utopia\System\System;
use Utopia\Validator\Assoc;

class Create extends Base
{
    private const string TYPE = 'certificates';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes'],
        'attributes' => ['certificateType', 'csrContent'],
    ];
    private const array REQUIRED = ['certificateType', 'csrContent'];
    private const string REQUEST_BEGIN = '-----BEGIN CERTIFICATE REQUEST-----';
    private const int DAYS = 365;
    private const array TYPES = [
        'APPLE_PAY',
        'APPLE_PAY_MERCHANT_IDENTITY',
        'APPLE_PAY_PSP_IDENTITY',
        'APPLE_PAY_RSA',
        'DEVELOPER_ID_KEXT',
        'DEVELOPER_ID_KEXT_G2',
        'DEVELOPER_ID_APPLICATION',
        'DEVELOPER_ID_APPLICATION_G2',
        'DEVELOPMENT',
        'DISTRIBUTION',
        'IDENTITY_ACCESS',
        'IOS_DEVELOPMENT',
        'IOS_DISTRIBUTION',
        'MAC_APP_DISTRIBUTION',
        'MAC_INSTALLER_DISTRIBUTION',
        'MAC_APP_DEVELOPMENT',
        'PASS_TYPE_ID',
        'PASS_TYPE_ID_WITH_NFC',
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleCertificate';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/certificates')
            ->desc('Create App Store Connect certificate')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Certificate resource with `certificateType` and `csrContent` attributes.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $data = \array_intersect_key($data, \array_flip(self::ALLOWED_KEYS['data']));
        $attributes = \is_array($data['attributes'] ?? null) ? \array_intersect_key($data['attributes'], \array_flip(self::ALLOWED_KEYS['attributes'])) : [];

        $team = $this->provisioning($response, $cache, $key['issuerId']);

        if ($team === null) {
            return;
        }

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        foreach (self::REQUIRED as $name) {
            if (!\array_key_exists($name, $attributes)) {
                $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        $type = $attributes['certificateType'];

        if (!\in_array($type, self::TYPES, true)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'certificateType' has an invalid value.", '/data/attributes/certificateType');
            return;
        }

        $this->locked($lock, self::LOCK_TEAM . $team['teamId'], function () use ($cache, $lock, $team, $type, $response, $attributes, $request): void {
            $certificates = $this->certificates($cache, $team['teamId']);
            $current = \array_filter($certificates, fn (array $certificate): bool => $certificate['attributes']['certificateType'] === $type);

            if (\count($current) >= $team['certificates']) {
                $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'There is a problem with the request entity', 'You already have a current Distribution certificate or a pending certificate request.', '/data/attributes/certificateType');
                return;
            }

            $csr = $attributes['csrContent'];
            $pem = \is_string($csr) && \str_starts_with($csr, self::REQUEST_BEGIN) ? $this->sign($csr, $this->authority($cache, $lock)) : null;

            if ($pem === null) {
                $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', 'The provided CSR content is not a valid certificate signing request.', '/data/attributes/csrContent');
                return;
            }

            $issued = new Certificate($pem);
            $parsed = \openssl_x509_parse($pem);

            $certificate = [
                'type' => self::TYPE,
                'id' => $this->resourceId(),
                'attributes' => [
                    'name' => \ucwords(\strtolower(\str_replace('_', ' ', $type))) . ': ' . $team['teamId'],
                    'displayName' => $team['teamId'],
                    'certificateType' => $type,
                    'serialNumber' => \strtoupper($parsed['serialNumberHex'] ?? ''),
                    'platform' => null,
                    'expirationDate' => $issued->expiresAt()->format(self::CERTIFICATE_DATE),
                    'certificateContent' => \base64_encode($issued->der()),
                    'activated' => true,
                ],
            ];

            $certificates[] = $certificate;
            $cache->save(self::CACHE_CERTIFICATE_CERTIFICATES . $team['teamId'], $certificates, '', self::TTL);

            $this->respond($response, [
                'data' => $certificate,
                'links' => ['self' => $this->link($request)],
            ], Response::STATUS_CODE_CREATED);
        });
    }

    /**
     * @param array{key: string, certificate: string} $authority
     */
    private function sign(string $csr, array $authority): ?string
    {
        $options = ['digest_alg' => self::CERTIFICATE_DIGEST, 'config' => Certificate::CONFIG];

        \set_error_handler(static fn (): bool => true);

        try {
            $signed = \openssl_csr_sign($csr, $authority['certificate'], $authority['key'], self::DAYS, $options, \random_int(1, PHP_INT_MAX));
        } finally {
            \restore_error_handler();
        }

        $pem = null;

        if ($signed === false || !\openssl_x509_export($signed, $pem) || !\is_string($pem)) {
            return null;
        }

        return $pem;
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
