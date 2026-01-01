<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Certificate;

use Appwrite\Extend\Exception;
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
        if (filter_var($domain, FILTER_VALIDATE_URL)) {
            $domain = parse_url($domain, PHP_URL_HOST);
        }

        $sslContext = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
            ],
        ]);
        $sslSocket = stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $sslContext);
        if (!$sslSocket) {
            throw new Exception(Exception::HEALTH_INVALID_HOST);
        }

        $streamContextParams = stream_context_get_params($sslSocket);
        $peerCertificate = $streamContextParams['options']['ssl']['peer_certificate'];
        $certificatePayload = openssl_x509_parse($peerCertificate);
        
        fclose($sslSocket); // Close the socket to prevent resource leak
        
        if ($certificatePayload === false) {
            throw new Exception(Exception::HEALTH_INVALID_HOST);
        }

        $sslExpiration = $certificatePayload['validTo_time_t'];
        $status = $sslExpiration < time() ? 'fail' : 'pass';

        if ($status === 'fail') {
            throw new Exception(Exception::HEALTH_CERTIFICATE_EXPIRED);
        }

        $response->dynamic(new Document([
            'name' => $certificatePayload['name'] ?? '',
            'subjectCN' => $certificatePayload['subject']['CN'] ?? '',
            'issuerOrganisation' => $certificatePayload['issuer']['O'] ?? '',
            'validFrom' => $certificatePayload['validFrom_time_t'],
            'validTo' => $certificatePayload['validTo_time_t'],
            'signatureTypeSN' => $certificatePayload['signatureTypeSN'] ?? '',
        ]), Response::MODEL_HEALTH_CERTIFICATE);
}
