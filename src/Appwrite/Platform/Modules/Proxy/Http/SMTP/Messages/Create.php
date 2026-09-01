<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Proxy\Http\SMTP\Messages;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Platform\Action;
use Appwrite\Smtp\Gateway;
use Appwrite\Smtp\Mime\Parser;
use Appwrite\Smtp\Storage;
use Appwrite\Utopia\Request\Validator\File;
use Appwrite\Utopia\Response;
use InvalidArgumentException;
use JsonException;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Stream;
use Utopia\System\System;
use Utopia\Validator\Text;

final class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSMTPMessage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/internal/v1/smtp/messages')
            ->groups(['api'])
            ->label('scope', 'public')
            ->param('manifest', '', new Text(65_536), 'SMTP delivery manifest.')
            ->param('raw', [], new File(), 'Raw RFC 822 message.', skipValidation: true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('storageForSmtp')
            ->inject('publisherForFunctions')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $manifestJson,
        mixed $rawParam,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Storage $storage,
        FunctionPublisher $publisherForFunctions,
        Authorization $authorization,
    ): void {
        if (! Gateway::authorized($request)) {
            $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED)->json(['error' => 'Invalid SMTP gateway credential.']);

            return;
        }

        try {
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)->json(['error' => 'Invalid SMTP manifest.']);

            return;
        }
        if (! is_array($manifest)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)->json(['error' => 'Invalid SMTP manifest.']);

            return;
        }

        $deliveryId = $request->getHeaderLine('idempotency-key');
        if (! preg_match('/^[a-f0-9]{32}$/', $deliveryId) || ($manifest['id'] ?? null) !== $deliveryId) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)->json(['error' => 'Invalid SMTP delivery identifier.']);

            return;
        }

        $raw = $request->getFiles('raw');
        $temporaryPath = $raw['tmp_name'] ?? '';
        $uploadError = (int) ($raw['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($raw['size'] ?? -1);
        $maximum = (int) System::getEnv('_APP_SMTP_MAX_MESSAGE_BYTES', '26214400');
        if ($uploadError !== UPLOAD_ERR_OK || ! is_string($temporaryPath) || $temporaryPath === '' || $size < 0) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)->json(['error' => 'Raw SMTP message is missing.']);

            return;
        }
        if ($size > $maximum || ($manifest['size'] ?? null) !== $size) {
            $response->setStatusCode(Response::STATUS_CODE_REQUEST_ENTITY_TOO_LARGE)->json(['error' => 'Raw SMTP message is too large or has an invalid size.']);

            return;
        }

        $rawContent = file_get_contents($temporaryPath);
        if ($rawContent === false || strlen($rawContent) !== $size) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)->json(['error' => 'Unable to read raw SMTP message.']);

            return;
        }
        try {
            $email = (new Parser(
                $maximum,
                (int) System::getEnv('_APP_SMTP_MAX_BODY_BYTES', '8388608'),
            ))->parse($rawContent);
            $destinations = $this->destinations($manifest);
        } catch (InvalidArgumentException) {
            $response->setStatusCode(Response::STATUS_CODE_UNPROCESSABLE_ENTITY)->json(['error' => 'Invalid SMTP message or recipient token.']);

            return;
        }

        foreach ($destinations as $destination) {
            $projectId = $destination['projectId'];
            $functionId = $destination['functionId'];
            $destinationId = substr(hash('sha256', $projectId.':'.$functionId), 0, 32);

            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
            if ($project->isEmpty()) {
                $response->setStatusCode(Response::STATUS_CODE_UNPROCESSABLE_ENTITY)->json(['error' => 'SMTP destination is unavailable.']);

                return;
            }
            /** @var Database $dbForProject */
            $dbForProject = $getProjectDB($project);
            $function = $authorization->skip(fn () => $dbForProject->getDocument('functions', $functionId));
            $deployment = $authorization->skip(fn () => $dbForProject->getDocument('deployments', $destination['deploymentId']));
            if ($function->isEmpty()
                || ! $function->getAttribute('enabled', false)
                || $deployment->isEmpty()
                || $deployment->getAttribute('status') !== 'ready'
                || $deployment->getAttribute('resourceId') !== $function->getId()) {
                $response->setStatusCode(Response::STATUS_CODE_UNPROCESSABLE_ENTITY)->json(['error' => 'SMTP destination is unavailable.']);

                return;
            }

            $device = $storage->getDevice($projectId);
            $prefix = "smtp/{$deliveryId}/{$destinationId}";
            $receiptPath = $device->getPath($prefix.'/receipt.json');
            if ($device->exists($receiptPath)) {
                continue;
            }

            $rawPath = $device->getPath($prefix.'/message.eml');
            if (! $device->exists($rawPath) && ! $device->write($rawPath, new Stream($rawContent), 'message/rfc822')) {
                throw new \RuntimeException('Failed to persist raw SMTP message.');
            }

            $attachments = [];
            foreach ($email->attachments as $index => $attachment) {
                $fileId = 'attachment-'.$index;
                $attachmentPath = $device->getPath($prefix.'/attachments/'.$index);
                if (! $device->exists($attachmentPath)
                    && ! $device->write($attachmentPath, new Stream($attachment->content), $attachment->contentType)) {
                    throw new \RuntimeException('Failed to persist SMTP attachment.');
                }
                $attachments[] = [
                    'id' => $fileId,
                    'name' => $attachment->filename,
                    'contentType' => $attachment->contentType,
                    'contentId' => $attachment->contentId,
                    'disposition' => $attachment->disposition,
                    'size' => strlen($attachment->content),
                    'path' => $attachmentPath,
                ];
            }

            $emailPath = $device->getPath($prefix.'/email.json');
            $normalized = [
                'deliveryId' => $deliveryId,
                'projectId' => $projectId,
                'domain' => $destination['domain'],
                'envelope' => [
                    'mailFrom' => $manifest['mailFrom'] ?? '',
                    'recipients' => $destination['recipients'],
                ],
                'from' => $email->from,
                'to' => $email->to,
                'cc' => $email->cc,
                'replyTo' => $email->replyTo,
                'subject' => $email->subject,
                'messageId' => $email->messageId,
                'date' => $email->date,
                'text' => $email->text,
                'html' => $email->html,
                'headers' => $email->headers,
                'attachments' => $attachments,
                'raw' => [
                    'id' => 'raw',
                    'name' => 'message.eml',
                    'contentType' => 'message/rfc822',
                    'size' => $size,
                    'path' => $rawPath,
                ],
                'transport' => $manifest['transport'] ?? [],
                'receivedAt' => $manifest['receivedAt'] ?? '',
            ];
            $normalizedJson = json_encode($normalized, JSON_THROW_ON_ERROR);
            if (! $device->exists($emailPath)
                && ! $device->write($emailPath, new Stream($normalizedJson), 'application/json')) {
                throw new \RuntimeException('Failed to persist normalized SMTP message.');
            }

            $executionId = substr(hash('sha256', $deliveryId.':'.$destinationId), 0, 36);
            $execution = new Document([
                '$id' => $executionId,
                '$permissions' => [],
                '$createdAt' => DateTime::now(),
                '$updatedAt' => DateTime::now(),
                'resourceInternalId' => $function->getSequence(),
                'resourceId' => $function->getId(),
                'resourceType' => 'functions',
                'deploymentInternalId' => $deployment->getSequence(),
                'deploymentId' => $deployment->getId(),
                'trigger' => 'email',
                'status' => 'waiting',
                'responseStatusCode' => 0,
                'responseHeaders' => [],
                'requestPath' => '/',
                'requestMethod' => 'POST',
                'requestHeaders' => [],
                'errors' => '',
                'logs' => '',
                'duration' => 0.0,
            ]);

            $published = $publisherForFunctions->enqueue(new FunctionMessage(
                project: $project,
                function: $function,
                functionId: $function->getId(),
                execution: $execution,
                type: 'email',
                bodyPath: $emailPath,
                path: '/',
                headers: [
                    'content-type' => 'application/json',
                    'x-appwrite-email-id' => $deliveryId,
                    'x-appwrite-email-domain' => $destination['domain'],
                    'x-appwrite-email-recipient' => $destination['recipients'][0] ?? '',
                ],
                method: 'POST',
            ));
            if ($published === false) {
                throw new \RuntimeException('Failed to enqueue SMTP function execution.');
            }
            if (! $device->write(
                $receiptPath,
                new Stream(json_encode(['deliveryId' => $deliveryId], JSON_THROW_ON_ERROR)),
                'application/json',
            )) {
                throw new \RuntimeException('Failed to persist SMTP delivery receipt.');
            }
        }

        $response->setStatusCode(Response::STATUS_CODE_ACCEPTED)->json(['deliveryId' => $deliveryId]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{projectId: string, functionId: string, deploymentId: string, domain: string, recipients: list<string>}>
     */
    private function destinations(array $manifest): array
    {
        $recipients = $manifest['recipients'] ?? null;
        if (! is_array($recipients) || $recipients === []) {
            throw new InvalidArgumentException('SMTP manifest has no recipients.');
        }

        $groups = [];
        $tokens = Gateway::recipientTokens();
        foreach ($recipients as $recipient) {
            if (! is_array($recipient) || ! is_string($recipient['address'] ?? null) || ! is_string($recipient['token'] ?? null)) {
                throw new InvalidArgumentException('Invalid SMTP recipient manifest.');
            }
            $claims = $tokens->verify($recipient['token']);
            $address = strtolower($recipient['address']);
            if (($claims['recipient'] ?? null) !== $address) {
                throw new InvalidArgumentException('SMTP token recipient mismatch.');
            }
            foreach (['projectId', 'functionId', 'deploymentId', 'domain'] as $claim) {
                if (! is_string($claims[$claim] ?? null) || $claims[$claim] === '') {
                    throw new InvalidArgumentException('SMTP token is missing routing claims.');
                }
            }
            $key = $claims['projectId'].':'.$claims['functionId'];
            $groups[$key] ??= [
                'projectId' => $claims['projectId'],
                'functionId' => $claims['functionId'],
                'deploymentId' => $claims['deploymentId'],
                'domain' => $claims['domain'],
                'recipients' => [],
            ];
            if ($groups[$key]['deploymentId'] !== $claims['deploymentId']) {
                throw new InvalidArgumentException('Inconsistent SMTP routing tokens.');
            }
            $groups[$key]['recipients'][] = $address;
        }

        return array_values($groups);
    }
}
