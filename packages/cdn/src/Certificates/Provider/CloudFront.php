<?php

declare(strict_types=1);

namespace Utopia\Cdn\Certificates\Provider;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Aws\Signature;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Certificates\ValidationToken;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Psr7\Stream;

/**
 * Certificates for domains served by a CloudFront multi-tenant distribution.
 *
 * Every domain gets its own distribution tenant, and the tenant asks CloudFront
 * for an AWS-managed certificate. AWS issues the certificate and renews it for
 * as long as the domain points at CloudFront, so there is no key, no ACME
 * account and nothing to import.
 *
 * @phpstan-type Tenant array{id:string,etag:string,name:string,distributionId:string,connectionGroupId:string|null,enabled:bool,domains:list<string>,parameters:array<string, string>,customizations:\SimpleXMLElement|null}
 */
class CloudFront implements Provider
{
    /**
     * CloudFront serves the validation token itself, which only works once the
     * domain already points at CloudFront.
     */
    public const string VALIDATION_TOKEN_HOST_CLOUDFRONT = 'cloudfront';

    /**
     * Whatever serves the domain today answers the validation request, so the
     * certificate is issued before the domain's traffic moves to CloudFront.
     * See `getValidationTokens()`.
     */
    public const string VALIDATION_TOKEN_HOST_SELF_HOSTED = 'self-hosted';

    /**
     * AWS renews managed certificates without being asked, so an issued
     * certificate has no renew date. Callers that schedule one get a date to
     * check the certificate again instead.
     */
    public const string RECHECK_INTERVAL = '+30 days';

    private const string SIGNING_SERVICE = 'cloudfront';

    private const string SIGNING_REGION = 'us-east-1';

    private const string API_VERSION = '2020-05-31';

    private const string NAMESPACE = 'http://cloudfront.amazonaws.com/doc/2020-05-31/';

    /** Tenant names are 3 to 128 characters of letters, digits, `-` and `.`. */
    private const int NAME_LENGTH = 128;

    private readonly Signature $signature;

    private readonly ClientInterface $client;

    /**
     * @param string $distributionId Multi-tenant distribution every tenant is created under.
     * @param string|null $connectionGroupId Connection group for new tenants; CloudFront uses the account's default group when null.
     * @param string $validationTokenHost One of the VALIDATION_TOKEN_HOST_* constants.
     * @param array<string, string> $parameters Tenant parameters, required when the distribution declares parameters without defaults.
     */
    public function __construct(
        string $accessKeyId,
        string $secretAccessKey,
        private readonly string $distributionId,
        private readonly ?string $connectionGroupId = null,
        private readonly string $validationTokenHost = self::VALIDATION_TOKEN_HOST_SELF_HOSTED,
        private readonly array $parameters = [],
        ?string $sessionToken = null,
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://cloudfront.amazonaws.com',
    ) {
        if (!\in_array($validationTokenHost, [self::VALIDATION_TOKEN_HOST_CLOUDFRONT, self::VALIDATION_TOKEN_HOST_SELF_HOSTED], true)) {
            throw new \InvalidArgumentException('Validation token host must be "cloudfront" or "self-hosted".');
        }

        $this->signature = new Signature($accessKeyId, $secretAccessKey, $sessionToken);
        $this->client = $client ?? new Client(new CurlAdapter());
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $domain = Domain::validate($domain);
        $tenant = $this->findTenant($domain);
        if ($tenant === null) {
            $this->createTenant($domain);

            return null;
        }

        // A failed certificate is not retried by AWS, and a tenant without
        // one serves the distribution's default certificate, so both get a
        // fresh request.
        $certificate = $this->findCertificate($tenant['id']);
        if ($certificate === null || $this->mapStatus($certificate['status']) === Status::FAILED) {
            $this->updateTenant($tenant, enabled: true, requestCertificate: true);

            return null;
        }

        if (!$tenant['enabled']) {
            $this->updateTenant($tenant, enabled: true);
        }

        if ($this->mapStatus($certificate['status']) !== Status::ISSUED) {
            return null;
        }

        return new \DateTimeImmutable()->modify(self::RECHECK_INTERVAL)->format('Y-m-d H:i:s.v');
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        Domain::validate($domain);

        return false;
    }

    /**
     * @throws Certificate When AWS has stopped trying to issue the certificate, or it expired or was revoked.
     */
    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        $domain = Domain::validate($domain);
        $tenant = $this->findTenant($domain);
        if ($tenant === null) {
            return Status::UNKNOWN;
        }

        $certificate = $this->findCertificate($tenant['id']);
        if ($certificate === null) {
            return Status::UNKNOWN;
        }

        $status = $this->mapStatus($certificate['status']);
        if ($status === Status::FAILED) {
            throw new Certificate(
                "CloudFront stopped trying to issue a certificate for {$domain}: the certificate is {$certificate['status']}.",
                Status::FAILED,
            );
        }

        return $status;
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        $tenant = $this->findTenant(Domain::validate($domain));
        if ($tenant === null) {
            return true;
        }

        $certificate = $this->findCertificate($tenant['id']);

        return $certificate === null || $this->mapStatus($certificate['status']) === Status::FAILED;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $tenant = $this->findTenant(Domain::validate($domain));
        if ($tenant === null) {
            return;
        }

        // CloudFront refuses to delete a tenant that still serves traffic.
        if ($tenant['enabled']) {
            $tenant = $this->updateTenant($tenant, enabled: false);
        }

        $result = $this->request(Method::DELETE, '/distribution-tenant/' . rawurlencode($tenant['id']), etag: $tenant['etag']);
        if ($result['statusCode'] === 404) {
            return;
        }

        if ($result['statusCode'] === 409 && $this->errorCode($result) === 'ResourceNotDisabled') {
            throw new \RuntimeException("CloudFront distribution tenant {$tenant['id']} for {$tenant['name']} is disabled but not yet deployed; delete it again once the change has propagated.");
        }

        $this->assertSuccess('delete CloudFront distribution tenant', $result);
    }

    /**
     * What the certificate authority requests to validate the domain, while
     * the certificate waits on validation. Empty once it is issued, or when
     * the domain has no tenant.
     *
     * @return list<ValidationToken>
     */
    public function getValidationTokens(string $domain): array
    {
        $tenant = $this->findTenant(Domain::validate($domain));
        if ($tenant === null) {
            return [];
        }

        $certificate = $this->findCertificate($tenant['id']);
        if ($certificate === null || $certificate['status'] !== 'pending-validation') {
            return [];
        }

        return $certificate['tokens'];
    }

    /**
     * The tenant this provider created for the domain: on its distribution
     * and holding that domain alone. CloudFront also answers with a tenant
     * whose wildcard covers the domain, one that shares the domain with
     * others, or one on another distribution, and changing or deleting any
     * of those would affect domains this call was not about.
     *
     * @return Tenant|null
     */
    private function findTenant(string $domain): ?array
    {
        $result = $this->request(Method::GET, '/distribution-tenant?' . http_build_query(['domain' => $domain]));
        if ($result['statusCode'] === 404) {
            return null;
        }

        $this->assertSuccess('fetch CloudFront distribution tenant', $result);

        $tenant = $this->parseTenant($result);
        if ($tenant['distributionId'] !== $this->distributionId || array_map(strtolower(...), $tenant['domains']) !== [$domain]) {
            return null;
        }

        return $tenant;
    }

    /**
     * @return Tenant
     */
    private function createTenant(string $domain): array
    {
        $body = new \SimpleXMLElement('<CreateDistributionTenantRequest xmlns="' . self::NAMESPACE . '"/>');
        $body->addChild('DistributionId', $this->escape($this->distributionId));
        $body->addChild('Name', $this->escape($this->tenantName($domain)));
        $body->addChild('Domains')->addChild('DomainItem')->addChild('Domain', $this->escape($domain));
        $this->appendParameters($body, $this->parameters);
        if ($this->connectionGroupId !== null) {
            $body->addChild('ConnectionGroupId', $this->escape($this->connectionGroupId));
        }
        $this->appendCertificateRequest($body, $domain);
        $body->addChild('Enabled', 'true');

        $result = $this->request(Method::POST, '/distribution-tenant', $body);

        // A concurrent issue created it first.
        if ($result['statusCode'] === 409 && $this->errorCode($result) === 'EntityAlreadyExists') {
            $tenant = $this->findTenant($domain);
            if ($tenant !== null) {
                return $tenant;
            }
        }

        $this->assertSuccess('create CloudFront distribution tenant', $result, [201]);

        return $this->parseTenant($result);
    }

    /**
     * Sends the tenant back as it is, enabled or not, and with a fresh
     * certificate request when one is asked for. Every field is sent because
     * the update replaces the tenant's configuration.
     *
     * @param Tenant $tenant
     * @return Tenant
     */
    private function updateTenant(array $tenant, bool $enabled, bool $requestCertificate = false): array
    {
        $body = new \SimpleXMLElement('<UpdateDistributionTenantRequest xmlns="' . self::NAMESPACE . '"/>');
        $body->addChild('DistributionId', $this->escape($tenant['distributionId']));

        $domains = $body->addChild('Domains');
        foreach ($tenant['domains'] as $domain) {
            $domains->addChild('DomainItem')->addChild('Domain', $this->escape($domain));
        }

        // The customizations are copied from the tenant's own XML, so an
        // override set elsewhere survives. A certificate request replaces
        // the certificate the tenant had, so that one is left out.
        if ($tenant['customizations'] !== null) {
            $customizations = $body->addChild('Customizations');
            foreach ($tenant['customizations']->children() as $customization) {
                if ($requestCertificate && $customization->getName() === 'Certificate') {
                    continue;
                }
                $this->copy($customization, $customizations);
            }
        }

        $this->appendParameters($body, $tenant['parameters']);
        if ($tenant['connectionGroupId'] !== null) {
            $body->addChild('ConnectionGroupId', $this->escape($tenant['connectionGroupId']));
        }
        if ($requestCertificate) {
            $this->appendCertificateRequest($body, $tenant['domains'][0] ?? '');
        }
        $body->addChild('Enabled', $enabled ? 'true' : 'false');

        $result = $this->request(Method::PUT, '/distribution-tenant/' . rawurlencode($tenant['id']), $body, $tenant['etag']);
        $this->assertSuccess('update CloudFront distribution tenant', $result);

        return $this->parseTenant($result);
    }

    /**
     * @return array{status:string,tokens:list<ValidationToken>}|null Null when the tenant has no managed certificate.
     */
    private function findCertificate(string $tenantId): ?array
    {
        $result = $this->request(Method::GET, '/managed-certificate/' . rawurlencode($tenantId));
        if ($result['statusCode'] === 404) {
            return null;
        }

        $this->assertSuccess('fetch CloudFront managed certificate', $result);

        $details = $result['response'];
        if (!$details instanceof \SimpleXMLElement) {
            throw new \RuntimeException('CloudFront managed certificate response was not valid XML.');
        }

        $status = $this->text($details, 'CertificateStatus');
        if ($status === null) {
            return null;
        }

        $tokens = [];
        foreach ($this->list($details, 'ValidationTokenDetails', 'ValidationTokenDetail') as $detail) {
            $domain = $this->text($detail, 'Domain');
            $from = $this->text($detail, 'RedirectFrom');
            $to = $this->text($detail, 'RedirectTo');
            if ($domain === null || $from === null || $to === null) {
                continue;
            }

            $tokens[] = new ValidationToken(strtolower($domain), $from, $to);
        }

        return ['status' => strtolower($status), 'tokens' => $tokens];
    }

    private function appendCertificateRequest(\SimpleXMLElement $body, string $domain): void
    {
        $request = $body->addChild('ManagedCertificateRequest');
        $request->addChild('ValidationTokenHost', $this->validationTokenHost);
        $request->addChild('PrimaryDomainName', $this->escape($domain));
        $request->addChild('CertificateTransparencyLoggingPreference', 'enabled');
    }

    /**
     * @param array<string, string> $parameters
     */
    private function appendParameters(\SimpleXMLElement $body, array $parameters): void
    {
        if ($parameters === []) {
            return;
        }

        $list = $body->addChild('Parameters');
        foreach ($parameters as $name => $value) {
            $parameter = $list->addChild('Parameter');
            $parameter->addChild('Name', $this->escape((string) $name));
            $parameter->addChild('Value', $this->escape($value));
        }
    }

    private function copy(\SimpleXMLElement $source, \SimpleXMLElement $parent): void
    {
        $children = $source->children();
        if ($children->count() === 0) {
            $parent->addChild($source->getName(), $this->escape((string) $source));

            return;
        }

        $node = $parent->addChild($source->getName());
        foreach ($children as $child) {
            $this->copy($child, $node);
        }
    }

    /**
     * The domain itself when it fits, since tenant names are unique per
     * account; longer domains keep a prefix and a hash of the whole name.
     */
    private function tenantName(string $domain): string
    {
        if (\strlen($domain) <= self::NAME_LENGTH) {
            return $domain;
        }

        $hash = hash('sha256', $domain);

        return rtrim(substr($domain, 0, self::NAME_LENGTH - \strlen($hash) - 1), '.-') . '-' . $hash;
    }

    /**
     * @param array{statusCode:int,response:\SimpleXMLElement|string|null,etag:string|null,error:string|null} $result
     * @return Tenant
     */
    private function parseTenant(array $result): array
    {
        $tenant = $result['response'];
        if (!$tenant instanceof \SimpleXMLElement) {
            throw new \RuntimeException('CloudFront distribution tenant response was not valid XML.');
        }

        $id = $this->text($tenant, 'Id');
        if ($id === null) {
            throw new \RuntimeException('CloudFront distribution tenant response was missing its ID.');
        }

        if ($result['etag'] === null) {
            throw new \RuntimeException('CloudFront distribution tenant response was missing its ETag.');
        }

        $domains = [];
        foreach ($this->list($tenant, 'Domains', 'DomainResult') as $domain) {
            $name = $this->text($domain, 'Domain');
            if ($name !== null) {
                $domains[] = $name;
            }
        }

        $parameters = [];
        foreach ($this->list($tenant, 'Parameters', 'Parameter') as $parameter) {
            $name = $this->text($parameter, 'Name');
            if ($name !== null) {
                $parameters[$name] = $this->text($parameter, 'Value') ?? '';
            }
        }

        return [
            'id' => $id,
            'etag' => $result['etag'],
            'name' => $this->text($tenant, 'Name') ?? $id,
            'distributionId' => $this->text($tenant, 'DistributionId') ?? $this->distributionId,
            'connectionGroupId' => $this->text($tenant, 'ConnectionGroupId'),
            'enabled' => strtolower($this->text($tenant, 'Enabled') ?? 'true') === 'true',
            'domains' => $domains,
            'parameters' => $parameters,
            'customizations' => $this->child($tenant, 'Customizations'),
        ];
    }

    private function text(\SimpleXMLElement $element, string $name): ?string
    {
        $child = $this->child($element, $name);
        if ($child === null) {
            return null;
        }

        $value = trim((string) $child);

        return $value === '' ? null : $value;
    }

    private function child(\SimpleXMLElement $element, string $name): ?\SimpleXMLElement
    {
        foreach ($element->children() as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function list(\SimpleXMLElement $element, string $name, string $item): array
    {
        $items = [];
        foreach ($this->child($element, $name)?->children() ?? [] as $child) {
            if ($child->getName() === $item) {
                $items[] = $child;
            }
        }

        return $items;
    }

    /**
     * `addChild()` escapes `<` and `>` but reads `&` as the start of an entity.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'pending-validation' => Status::PENDING,
            'issued' => Status::ISSUED,
            'validation-timed-out', 'failed', 'expired', 'revoked' => Status::FAILED,
            default => Status::UNKNOWN,
        };
    }

    /**
     * @param array{statusCode:int,response:\SimpleXMLElement|string|null,etag:string|null,error:string|null} $result
     */
    private function errorCode(array $result): ?string
    {
        if (!$result['response'] instanceof \SimpleXMLElement) {
            return null;
        }

        $error = $this->child($result['response'], 'Error');

        return $error === null ? null : $this->text($error, 'Code');
    }

    /**
     * @param array{statusCode:int,response:\SimpleXMLElement|string|null,etag:string|null,error:string|null} $result
     * @param list<int>|null $expectedStatuses
     */
    private function assertSuccess(string $operation, array $result, ?array $expectedStatuses = null): void
    {
        $success = $expectedStatuses === null
            ? $result['statusCode'] >= 200 && $result['statusCode'] < 300
            : \in_array($result['statusCode'], $expectedStatuses, true);

        if ($success) {
            return;
        }

        $message = $result['error'];
        if ($message === null && $result['response'] instanceof \SimpleXMLElement) {
            $error = $this->child($result['response'], 'Error');
            if ($error !== null) {
                $message = implode(': ', array_filter([$this->text($error, 'Code'), $this->text($error, 'Message')]));
            }
        }

        throw new \RuntimeException('Failed to ' . $operation . ' with status ' . $result['statusCode'] . ': ' . ($message ?: 'Unknown CloudFront error'));
    }

    /**
     * @return array{statusCode:int,response:\SimpleXMLElement|string|null,etag:string|null,error:string|null}
     */
    private function request(string $method, string $path, ?\SimpleXMLElement $body = null, ?string $etag = null): array
    {
        $request = new RequestFactory()
            ->createRequest($method, $this->apiBase . '/' . self::API_VERSION . $path)
            ->withHeader(Header::USER_AGENT, 'Utopia CDN CloudFront Certificates Provider');

        if ($body !== null) {
            $request = $request
                ->withHeader(Header::CONTENT_TYPE, 'application/xml')
                ->withBody(new Stream((string) $body->asXML()));
        }

        if ($etag !== null) {
            $request = $request->withHeader('If-Match', $etag);
        }

        try {
            $response = $this->client->sendRequest($this->signature->sign($request, self::SIGNING_SERVICE, self::SIGNING_REGION));
        } catch (ClientExceptionInterface $error) {
            return ['statusCode' => 0, 'response' => null, 'etag' => null, 'error' => $error->getMessage()];
        }

        $contents = (string) $response->getBody();
        $decoded = $contents === '' ? null : $contents;
        if ($decoded !== null) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($contents, options: LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $decoded = $xml === false ? $contents : $xml;
        }

        $etag = $response->getHeaderLine('ETag');

        return [
            'statusCode' => $response->getStatusCode(),
            'response' => $decoded,
            'etag' => $etag === '' ? null : $etag,
            'error' => null,
        ];
    }
}
