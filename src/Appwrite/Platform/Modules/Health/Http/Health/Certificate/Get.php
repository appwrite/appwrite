<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Certificate;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Domain;
use Utopia\Validator\Multiple;
use Utopia\Validator\URL;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getCertificate';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/certificate')
            ->desc('Get the SSL certificate for a domain')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'health',
                name: 'getCertificate',
                description: '/docs/references/health/get-certificate.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_CERTIFICATE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('domain', null, new Multiple([new AnyOf([new URL(), new Domain()]), new PublicDomain()]), Multiple::TYPE_STRING, 'Domain name')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $domain, Response $response): void
    {
        if (\filter_var($domain, FILTER_VALIDATE_URL)) {
            $domain = (string) \parse_url($domain, PHP_URL_HOST);
        }

        // Connect to the address just checked, so DNS cannot answer differently
        try {
            $address = (new PublicHostname())->address($domain);
        } catch (\InvalidArgumentException $exception) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $exception->getMessage());
        }

        if (\filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $address = '[' . $address . ']';
        }

        $sslContext = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $domain,
            ],
        ]);

        $sslSocket = @\stream_socket_client('ssl://' . $address . ':443', $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $sslContext);

        if (!$sslSocket) {
            throw new Exception(Exception::HEALTH_INVALID_HOST, 'Failed to connect to host ' . $domain . '.');
        }

        try {
            $streamContextParams = stream_context_get_params($sslSocket);
            $peerCertificate = $streamContextParams['options']['ssl']['peer_certificate'] ?? null;

            if ($peerCertificate === null) {
                throw new Exception(Exception::HEALTH_INVALID_HOST, 'Peer certificate not available for ' . $domain);
            }

            $certificatePayload = @openssl_x509_parse($peerCertificate);
            if ($certificatePayload === false) {
                throw new Exception(Exception::HEALTH_INVALID_HOST, 'Failed to parse peer certificate for ' . $domain);
            }

            $validFrom = $certificatePayload['validFrom_time_t'] ?? null;
            $validTo = $certificatePayload['validTo_time_t'] ?? null;

            if ($validFrom === null || $validTo === null) {
                throw new Exception(Exception::HEALTH_INVALID_HOST, 'Certificate missing validity period for ' . $domain);
            }

            $sslExpiration = $validTo;
            $status = $sslExpiration < time() ? 'fail' : 'pass';

            if ($status === 'fail') {
                throw new Exception(Exception::HEALTH_CERTIFICATE_EXPIRED);
            }

            $name = $certificatePayload['name'] ?? null;
            if (empty($name) && !empty($certificatePayload['subject']['CN'])) {
                $name = '/CN=' . $certificatePayload['subject']['CN'];
            }

            $response->dynamic(new Document([
                'name' => $name ?? '',
                'subjectSN' => $certificatePayload['subject']['CN'] ?? '',
                'issuerOrganisation' => $certificatePayload['issuer']['O'] ?? '',
                'validFrom' => $validFrom,
                'validTo' => $validTo,
                'signatureTypeSN' => $certificatePayload['signatureTypeSN'] ?? '',
            ]), Response::MODEL_HEALTH_CERTIFICATE);
        } finally {
            @fclose($sslSocket);
        }
    }
}
