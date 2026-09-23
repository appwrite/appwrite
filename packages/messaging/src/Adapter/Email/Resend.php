<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;

class Resend extends EmailAdapter
{
    protected const NAME = 'Resend';

    protected const MAX_ATTACHMENT_BYTES = 40 * 1024 * 1024;

    /**
     * @param  string  $apiKey  Your Resend API key to authenticate with the API.
     */
    public function __construct(
        private readonly string $apiKey,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 100;
    }

    /**
     * @link https://resend.com/docs/api-reference/emails/send-batch-emails
     * @link https://resend.com/docs/api-reference/emails/send-email
     */
    protected function process(EmailMessage $message): array
    {
        $attachments = [];
        if (! \is_null($message->getAttachments()) && $message->getAttachments() !== []) {
            $size = 0;
            foreach ($message->getAttachments() as $attachment) {
                if ($attachment->getContent() !== null) {
                    $size += \strlen($attachment->getContent());
                } else {
                    $fileSize = filesize($attachment->getPath());
                    if ($fileSize === false) {
                        throw new \Exception('Failed to read attachment file: ' . $attachment->getPath());
                    }
                    $size += $fileSize;
                }
            }

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new \Exception('Total attachment size exceeds ' . self::MAX_ATTACHMENT_BYTES . ' bytes');
            }

            foreach ($message->getAttachments() as $attachment) {
                if ($attachment->getContent() !== null) {
                    $content = base64_encode($attachment->getContent());
                } else {
                    $data = file_get_contents($attachment->getPath());
                    if ($data === false) {
                        throw new \Exception('Failed to read attachment file: ' . $attachment->getPath());
                    }
                    $content = base64_encode($data);
                }

                $attachments[] = [
                    'filename' => $attachment->getName(),
                    'content' => $content,
                    'content_type' => $attachment->getType(),
                ];
            }
        }

        $response = new Response($this->getType());

        $emails = [];
        foreach ($message->getTo() as $to) {
            $email = [
                'from' => $this->formatAddress($message->getFromEmail(), $message->getFromName()),
                'to' => [$this->formatAddress($to['email'], $to['name'] ?? null)],
                'subject' => $message->getSubject(),
            ];

            if ($message->isHtml()) {
                $email['html'] = $message->getContent();
            } else {
                $email['text'] = $message->getContent();
            }

            if (!\in_array($message->getReplyToEmail(), ['', '0'], true)) {
                $email['reply_to'] = [$this->formatAddress($message->getReplyToEmail(), $message->getReplyToName())];
            }

            if (! \is_null($message->getCC()) && $message->getCC() !== []) {
                $email['cc'] = array_map(
                    fn (array $cc): string => $this->formatAddress($cc['email'], $cc['name'] ?? null),
                    $message->getCC(),
                );
            }

            if ($attachments !== []) {
                $email['attachments'] = $attachments;
            }

            if (! \is_null($message->getBCC()) && $message->getBCC() !== []) {
                $email['bcc'] = array_map(
                    fn (array $bcc): string => $this->formatAddress($bcc['email'], $bcc['name'] ?? null),
                    $message->getBCC(),
                );
            }

            if ($message->getHeaders() !== []) {
                $email['headers'] = $message->getHeaders();
            }

            $emails[] = $email;
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ];

        if ($attachments !== []) {
            return $this->sendIndividually($message, $emails, $headers, $response);
        }

        return $this->sendBatch($message, $emails, $headers, $response);
    }

    /**
     * @param  array<array<string, mixed>>  $emails
     * @param  array<string>  $headers
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    private function sendBatch(EmailMessage $message, array $emails, array $headers, Response $response): array
    {
        $result = $this->request(
            method: 'POST',
            url: 'https://api.resend.com/emails/batch',
            headers: $headers,
            body: $emails,
        );

        $statusCode = $result['statusCode'];

        // 422: Resend refused the request as malformed, so a retry cannot help.
        if ($statusCode === 422) {
            $recipients = $message->getTo();

            throw new InvalidArgumentException(
                InvalidArgumentException::PROVIDER_REJECTED,
                $this->extractErrorMessage($result['response'], 'Unprocessable request'),
                \count($recipients) === 1 ? $recipients[0]['email'] : null,
            );
        }

        if ($statusCode === 200) {
            $responseData = $result['response'];

            if (\is_array($responseData) && isset($responseData['errors']) && ! empty($responseData['errors'])) {
                $failedIndices = [];
                foreach ($responseData['errors'] as $error) {
                    $failedIndices[$error['index']] = $error['message'];
                }

                foreach ($message->getTo() as $index => $to) {
                    if (isset($failedIndices[$index])) {
                        $response->addResult($to['email'], $failedIndices[$index]);
                    } else {
                        $response->addResult($to['email']);
                    }
                }

                $successCount = \count($message->getTo()) - \count($failedIndices);
                $response->setDeliveredTo($successCount);
            } else {
                $response->setDeliveredTo(\count($message->getTo()));
                foreach ($message->getTo() as $to) {
                    $response->addResult($to['email']);
                }
            }
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            $errorMessage = $this->extractErrorMessage($result['response'], 'Unknown error');

            foreach ($message->getTo() as $to) {
                $response->addResult($to['email'], $errorMessage);
            }
        } elseif ($statusCode >= 500) {
            $errorMessage = $this->extractErrorMessage($result['response'], 'Server error');

            foreach ($message->getTo() as $to) {
                $response->addResult($to['email'], $errorMessage);
            }
        }

        return $response->toArray();
    }

    /**
     * @param  array<array<string, mixed>>  $emails
     * @param  array<string>  $headers
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    private function sendIndividually(EmailMessage $message, array $emails, array $headers, Response $response): array
    {
        $recipients = $message->getTo();
        $deliveredTo = 0;

        foreach ($emails as $index => $email) {
            $to = $recipients[$index];

            $result = $this->request(
                method: 'POST',
                url: 'https://api.resend.com/emails',
                headers: $headers,
                body: $email,
            );

            $statusCode = $result['statusCode'];

            // With several recipients a refusal stays per-recipient so the rest deliver.
            if ($statusCode === 422 && \count($emails) === 1) {
                throw new InvalidArgumentException(
                    InvalidArgumentException::PROVIDER_REJECTED,
                    $this->extractErrorMessage($result['response'], 'Unprocessable request'),
                    $to['email'],
                );
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                $response->addResult($to['email']);
                $deliveredTo++;
            } elseif ($statusCode >= 400 && $statusCode < 500) {
                $errorMessage = $this->extractErrorMessage($result['response'], 'Unknown error');
                $response->addResult($to['email'], $errorMessage);
            } else {
                $errorMessage = $this->extractErrorMessage($result['response'], 'Server error');
                $response->addResult($to['email'], $errorMessage);
            }
        }

        $response->setDeliveredTo($deliveredTo);

        return $response->toArray();
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     */
    private function extractErrorMessage(array|string|null $body, string $default): string
    {
        if (\is_string($body)) {
            return $body;
        }

        if (\is_array($body)) {
            if (isset($body['message']) && \is_string($body['message'])) {
                return $body['message'];
            }

            if (isset($body['error']) && \is_string($body['error'])) {
                return $body['error'];
            }
        }

        return $default;
    }
}
