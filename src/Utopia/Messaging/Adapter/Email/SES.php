<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;

/**
 * Amazon Simple Email Service (SES) adapter using the SES API v2 (sesv2).
 *
 * Sends are split into two paths:
 *
 *  - No attachments: the bulk path. Uses `SendBulkEmail`, which is template
 *    based, so the message content is registered once as a deterministically
 *    named template (the name is a hash of subject + body + isHtml) and reused
 *    across every batch of the same send. Each recipient becomes one
 *    `BulkEmailEntry`, and SES returns a per-recipient delivery status.
 *
 *  - Attachments present: the fallback path. Uses `SendEmail` with a
 *    `Content.Raw` MIME payload, one request per recipient, because
 *    SES templates cannot carry attachments.
 *
 * Templates created by the bulk path are never deleted, so one persists per
 * unique (subject, content, isHtml) triple. High-variety or multi-tenant
 * senders should periodically purge stale `utopia-` templates to stay under
 * the per-account template quota (default 20,000).
 *
 * Authentication is AWS Signature Version 4, hand-rolled (no AWS SDK
 * dependency), supporting both long-lived credentials and temporary
 * credentials via an optional session token.
 *
 * @link https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendBulkEmail.html
 * @link https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_CreateEmailTemplate.html
 * @link https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html
 * @link https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 */
class SES extends EmailAdapter
{
    protected const NAME = 'SES';

    protected const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * SigV4 service name. Overridable so the signing implementation can be
     * verified against AWS's published test vectors (which use 'service').
     */
    protected string $service = 'ses';

    /**
     * SES caps SendBulkEmail at 50 destinations per request.
     *
     * @link https://docs.aws.amazon.com/ses/latest/dg/quotas.html
     */
    protected const MAX_DESTINATIONS = 50;

    /**
     * SES caps a full MIME message (after base64 encoding of attachments) at
     * 10MB, well below the 25MB adapter default. Enforcing the real limit lets
     * oversized sends fail fast instead of being rejected by SES.
     *
     * @link https://docs.aws.amazon.com/ses/latest/dg/quotas.html
     */
    protected const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024; // 10MB

    /**
     * The SES BulkEmailEntryResult status that indicates the message was
     * accepted. Any other status is treated as a per-recipient failure.
     */
    protected const STATUS_SUCCESS = 'SUCCESS';

    /**
     * Prefix for the deterministic, content-hashed template names.
     */
    protected const TEMPLATE_NAME_PREFIX = 'utopia-';

    /**
     * SES limits template names to 64 characters, so the content hash is
     * truncated to fit alongside the prefix.
     *
     * @link https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_CreateEmailTemplate.html
     */
    protected const TEMPLATE_NAME_MAX_LENGTH = 64;

    /**
     * Tracks template names this instance has already ensured exist, so the
     * same send does not re-issue CreateEmailTemplate for every batch.
     *
     * @var array<string, true>
     */
    private array $ensuredTemplates = [];

    /**
     * @param  string  $accessKey  AWS access key ID.
     * @param  string  $secretKey  AWS secret access key.
     * @param  string  $region  AWS region, e.g. 'us-east-1'.
     * @param  string|null  $sessionToken  Optional session token for temporary credentials.
     */
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly ?string $sessionToken = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return self::MAX_DESTINATIONS;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(EmailMessage $message): array
    {
        $response = new Response($this->getType());

        $hasAttachments = ! \is_null($message->getAttachments()) && $message->getAttachments() !== [];

        if ($hasAttachments) {
            return $this->sendRaw($message, $response);
        }

        return $this->sendBulk($message, $response);
    }

    /**
     * Primary path: template-based bulk send via SES SendBulkEmail.
     *
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     *
     * @throws \Exception
     */
    private function sendBulk(EmailMessage $message, Response $response): array
    {
        $templateName = $this->templateName($message);

        $cc = array_map(
            fn(array $recipient): string => $this->formatAddress($recipient['email'], $recipient['name'] ?? null),
            $message->getCC() ?? [],
        );
        $bcc = array_map(
            fn(array $recipient): string => $this->formatAddress($recipient['email'], $recipient['name'] ?? null),
            $message->getBCC() ?? [],
        );

        $entries = array_map(
            function (array $to) use ($cc, $bcc): array {
                $destination = ['ToAddresses' => [$to['email']]];

                if ($cc !== []) {
                    $destination['CcAddresses'] = $cc;
                }
                if ($bcc !== []) {
                    $destination['BccAddresses'] = $bcc;
                }

                return [
                    'Destination' => $destination,
                    'ReplacementEmailContent' => [
                        'ReplacementTemplate' => [
                            'ReplacementTemplateData' => '{}',
                        ],
                    ],
                ];
            },
            $message->getTo(),
        );

        $body = [
            'FromEmailAddress' => $this->formatAddress($message->getFromEmail(), $message->getFromName()),
            'DefaultContent' => [
                'Template' => [
                    'TemplateName' => $templateName,
                    'TemplateData' => '{}',
                ],
            ],
            'BulkEmailEntries' => $entries,
        ];

        if (!\in_array($message->getReplyToEmail(), ['', '0'], true)) {
            $body['ReplyToAddresses'] = [
                $this->formatAddress($message->getReplyToEmail(), $message->getReplyToName()),
            ];
        }

        $result = $this->dispatch('POST', '/v2/email/outbound-bulk-emails', $body);

        // If the template does not exist yet, create it once and retry.
        if ($this->isTemplateMissing($result)) {
            $this->ensureTemplate($message, $templateName);
            $result = $this->dispatch('POST', '/v2/email/outbound-bulk-emails', $body);
        }

        return $this->parseBulkResult($message, $result, $response);
    }

    /**
     * Fallback path: one SES SendEmail (Content.Raw) request per recipient.
     * Used when the message carries attachments, which SES templates cannot
     * represent.
     *
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     *
     * @throws \Exception
     */
    private function sendRaw(EmailMessage $message, Response $response): array
    {
        $this->assertAttachmentSize($message);

        $deliveredTo = 0;

        foreach ($message->getTo() as $to) {
            $mime = $this->buildMime($message, $to);

            if (\strlen($mime) > self::MAX_ATTACHMENT_BYTES) {
                throw new \Exception('MIME message size exceeds SES limit of ' . self::MAX_ATTACHMENT_BYTES . ' bytes');
            }

            $body = [
                'FromEmailAddress' => $this->formatAddress($message->getFromEmail(), $message->getFromName()),
                'Destination' => [
                    'ToAddresses' => [$to['email']],
                ],
                'Content' => [
                    'Raw' => [
                        'Data' => base64_encode($mime),
                    ],
                ],
            ];

            if (!\in_array($message->getReplyToEmail(), ['', '0'], true)) {
                $body['ReplyToAddresses'] = [
                    $this->formatAddress($message->getReplyToEmail(), $message->getReplyToName()),
                ];
            }

            $result = $this->dispatch('POST', '/v2/email/outbound-emails', $body);

            $statusCode = $result['statusCode'];

            if ($statusCode >= 200 && $statusCode < 300) {
                $response->addResult($to['email']);
                $deliveredTo++;
            } else {
                $response->addResult($to['email'], $this->errorMessage($result));
            }
        }

        $response->setDeliveredTo($deliveredTo);

        return $response->toArray();
    }

    /**
     * Map a SendBulkEmail response to per-recipient results.
     *
     * On a whole-request failure (non-2xx) every recipient in the batch is
     * marked failed with the SES error. On success each recipient is mapped
     * from its corresponding BulkEmailEntryResults entry.
     *
     * @param  array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string|null}  $result
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    private function parseBulkResult(EmailMessage $message, array $result, Response $response): array
    {
        $recipients = $message->getTo();
        $statusCode = $result['statusCode'];

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = $this->errorMessage($result);
            foreach ($recipients as $to) {
                $response->addResult($to['email'], $error);
            }

            return $response->toArray();
        }

        $entryResults = \is_array($result['response'])
            ? ($result['response']['BulkEmailEntryResults'] ?? null)
            : null;

        if (! \is_array($entryResults)) {
            // 2xx without parseable BulkEmailEntryResults: per-recipient
            // delivery cannot be confirmed, so report failure rather than
            // false-positive successes.
            $error = 'SES returned a success status without per-recipient results';
            foreach ($recipients as $to) {
                $response->addResult($to['email'], $error);
            }

            return $response->toArray();
        }

        $deliveredTo = 0;

        foreach ($recipients as $index => $to) {
            $entry = $entryResults[$index] ?? null;
            $status = \is_array($entry) ? ($entry['Status'] ?? null) : null;

            if ($status === self::STATUS_SUCCESS) {
                $response->addResult($to['email']);
                $deliveredTo++;
            } else {
                $error = (\is_array($entry) ? ($entry['Error'] ?? null) : null)
                    ?: ($status ?? 'Unknown error');
                $response->addResult($to['email'], $error);
            }
        }

        $response->setDeliveredTo($deliveredTo);

        return $response->toArray();
    }

    /**
     * Ensure the content-hash template exists in the SES account, creating it
     * from the message's subject/HTML/text if necessary. Idempotent per
     * instance and tolerant of concurrent creation (AlreadyExistsException).
     *
     * @throws \Exception
     */
    private function ensureTemplate(EmailMessage $message, string $templateName): void
    {
        if (isset($this->ensuredTemplates[$templateName])) {
            return;
        }

        $content = $message->isHtml()
            ? ['Subject' => $message->getSubject(), 'Html' => $message->getContent()]
            : ['Subject' => $message->getSubject(), 'Text' => $message->getContent()];

        $result = $this->dispatch('POST', '/v2/email/templates', [
            'TemplateName' => $templateName,
            'TemplateContent' => $content,
        ]);

        $statusCode = $result['statusCode'];
        $created = $statusCode >= 200 && $statusCode < 300;
        $alreadyExists = $this->errorType($result) === 'AlreadyExistsException';

        if (! $created && ! $alreadyExists) {
            throw new \Exception('SES failed to create email template: ' . $this->errorMessage($result));
        }

        $this->ensuredTemplates[$templateName] = true;
    }

    /**
     * Derive a deterministic, SES-valid template name from the message content
     * so identical content reuses a single template across batches and sends.
     *
     * The SHA-256 hash is truncated so the prefixed name stays within the SES
     * 64-character template-name limit; the retained length still leaves ample
     * entropy to keep distinct content on distinct templates.
     *
     * Note: templates created via {@see ensureTemplate()} are never deleted, so
     * one persists per unique (subject, content, isHtml) triple. High-variety
     * or multi-tenant senders should periodically purge stale `utopia-`
     * templates to stay under the per-account template quota (default 20,000).
     */
    private function templateName(EmailMessage $message): string
    {
        $hash = hash('sha256', implode("\0", [
            $message->getSubject(),
            $message->getContent(),
            $message->isHtml() ? '1' : '0',
        ]));

        $hashLength = self::TEMPLATE_NAME_MAX_LENGTH - \strlen(self::TEMPLATE_NAME_PREFIX);

        return self::TEMPLATE_NAME_PREFIX . substr($hash, 0, $hashLength);
    }

    /**
     * Whether a SendBulkEmail result indicates the referenced template is
     * missing, via either the top-level error or per-entry statuses.
     *
     * @param  array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string|null}  $result
     */
    private function isTemplateMissing(array $result): bool
    {
        $errorType = $this->errorType($result);
        if ($errorType === 'NotFoundException' || $errorType === 'BadRequestException') {
            // BadRequestException is generic, so confirm the message is about a
            // missing template rather than another template error (e.g. invalid
            // template content).
            $message = strtolower($this->errorMessage($result));
            if (
                str_contains($message, 'template')
                && (str_contains($message, 'does not exist') || str_contains($message, 'not found'))
            ) {
                return true;
            }
        }

        $entryResults = \is_array($result['response'] ?? null)
            ? ($result['response']['BulkEmailEntryResults'] ?? null)
            : null;

        if (\is_array($entryResults)) {
            foreach ($entryResults as $entry) {
                $status = \is_array($entry) ? ($entry['Status'] ?? null) : null;
                if ($status === 'TEMPLATE_NOT_FOUND' || $status === 'TEMPLATE_DOES_NOT_EXIST') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build a raw RFC 5322 MIME message, with attachments, for one recipient.
     *
     * @param  array<string, string>  $to
     *
     * @throws \Exception
     */
    private function buildMime(EmailMessage $message, array $to): string
    {
        // One copy per recipient, so the To header names only this one. Blind
        // recipients are not passed: SES takes its destinations from the
        // request rather than from the message, and a Bcc header would
        // disclose them to everyone who received it.
        return (string) Mime::message($message, [$to], $message->getCC() ?? []);
    }

    /**
     * Validate total attachment size against the adapter limit.
     *
     * @throws \Exception
     */
    private function assertAttachmentSize(EmailMessage $message): void
    {
        if (Mime::size($message) > self::MAX_ATTACHMENT_BYTES) {
            throw new \Exception('Total attachment size exceeds ' . self::MAX_ATTACHMENT_BYTES . ' bytes');
        }
    }

    /**
     * Sign and dispatch a request to the SES API v2 endpoint for the
     * configured region.
     *
     * @param  array<string, mixed>  $body
     * @return array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string|null}
     *
     * @throws \Exception
     */
    private function dispatch(string $method, string $path, array $body): array
    {
        $host = 'email.' . $this->region . '.amazonaws.com';
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $headers = $this->signature($method, $host, $path, $payload);
        $headers[] = 'Content-Type: application/json';

        return $this->request(
            method: $method,
            url: 'https://' . $host . $path,
            headers: $headers,
            body: $body,
        );
    }

    /**
     * Build the AWS Signature Version 4 request headers using the current
     * timestamp.
     *
     * The signed headers are content-type, host and x-amz-date (plus
     * x-amz-security-token when temporary credentials are used). The returned
     * list contains the Host, X-Amz-Date, optional X-Amz-Security-Token and
     * Authorization headers; the caller adds Content-Type.
     *
     * @return array<string>
     *
     * @link https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
     */
    private function signature(string $method, string $host, string $path, string $payload): array
    {
        $amzDate = gmdate('Ymd\THis\Z');

        $signed = [
            'content-type' => 'application/json',
            'host' => $host,
            'x-amz-date' => $amzDate,
        ];

        if (!\in_array($this->sessionToken, [null, '', '0'], true)) {
            $signed['x-amz-security-token'] = $this->sessionToken;
        }

        $authorization = $this->sign($method, $path, $payload, $signed, $amzDate);

        $headers = [
            'Host: ' . $host,
            'X-Amz-Date: ' . $amzDate,
            'Authorization: ' . $authorization,
        ];

        if (!\in_array($this->sessionToken, [null, '', '0'], true)) {
            $headers[] = 'X-Amz-Security-Token: ' . $this->sessionToken;
        }

        return $headers;
    }

    /**
     * Compute the AWS Signature Version 4 Authorization header value.
     *
     * Pure function of its inputs (no clock, no network): canonical request →
     * string to sign → signing key → signature. Exposed as protected so the
     * signing can be verified against AWS's published test vectors.
     *
     * Header names in $signedHeaders must be lowercase; they are sorted and
     * joined to form both the canonical headers block and the SignedHeaders
     * list, per the SigV4 specification.
     *
     * @param  array<string, string>  $signedHeaders  Lowercase header name => value.
     *
     * @link https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
     */
    protected function sign(string $method, string $path, string $payload, array $signedHeaders, string $amzDate): string
    {
        ksort($signedHeaders);

        $canonicalHeaders = '';
        foreach ($signedHeaders as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedHeaderList = implode(';', array_keys($signedHeaders));

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '',
            $canonicalHeaders,
            $signedHeaderList,
            hash('sha256', $payload),
        ]);

        $dateStamp = substr($amzDate, 0, 8);
        $credentialScope = $dateStamp . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return self::ALGORITHM
            . ' Credential=' . $this->accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaderList
            . ', Signature=' . $signature;
    }

    /**
     * Derive the SigV4 signing key for the given date via the HMAC-SHA256
     * chain over date, region, service and the aws4_request terminator.
     */
    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * Extract a human-readable error message from a SES error response.
     *
     * @param  array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string|null}  $result
     */
    private function errorMessage(array $result): string
    {
        $body = $result['response'];

        if (\is_array($body)) {
            if (isset($body['message']) && \is_string($body['message'])) {
                return $body['message'];
            }
            if (isset($body['Message']) && \is_string($body['Message'])) {
                return $body['Message'];
            }
        }

        if (\is_string($body) && $body !== '') {
            return $body;
        }

        if (! empty($result['error'])) {
            return $result['error'];
        }

        return 'Unknown error';
    }

    /**
     * Extract the SES error type, e.g. "AlreadyExistsException" or
     * "NotFoundException".
     *
     * SES API v2 uses the AWS REST-JSON protocol, which reports the exception
     * type in the x-amzn-ErrorType response header, not the body. The header
     * value can carry a trailing ":<location>" which is stripped. Older AWS
     * JSON-protocol responses instead carry it in a `__type` (optionally
     * "prefix#Type") or `code` body field, which is used as a fallback.
     *
     * @param  array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string|null}  $result
     */
    private function errorType(array $result): ?string
    {
        $header = $result['headers']['x-amzn-errortype'] ?? null;
        if (\is_string($header) && $header !== '') {
            return trim(explode(':', $header)[0]);
        }

        $body = $result['response'];
        if (\is_array($body)) {
            $type = $body['__type'] ?? $body['code'] ?? null;
            if (\is_string($type)) {
                // __type can be "prefix#AlreadyExistsException"; keep the suffix.
                $parts = explode('#', $type);

                return end($parts);
            }
        }

        return null;
    }
}
