<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;
use Utopia\Psr7\Request\Multipart\Part;

class Mailgun extends EmailAdapter
{
    protected const NAME = 'Mailgun';

    /**
     * @param  string  $apiKey Your Mailgun API key to authenticate with the API.
     * @param  string  $domain Your Mailgun domain to send messages from.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $domain,
        private readonly bool $isEU = false,
    ) {
        parent::__construct();
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * Get adapter description.
     */
    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    /**
     * {@inheritdoc}
     *
     * Uses Mailgun's batch sending feature to send multiple emails at once.
     *
     * @link https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/#batch-sending
     */
    protected function process(EmailMessage $message): array
    {
        $usDomain = 'api.mailgun.net';
        $euDomain = 'api.eu.mailgun.net';

        $domain = $this->isEU ? $euDomain : $usDomain;

        $recipients = $message->getTo();
        $toEmails = array_map(fn(array $to): string => $to['email'], $recipients);

        $body = [
            'to' => implode(',', array_map(
                fn(array $to): string => $this->formatAddress($to['email'], $to['name'] ?? null),
                $recipients,
            )),
            'from' => $this->formatAddress($message->getFromEmail(), $message->getFromName()),
            'subject' => $message->getSubject(),
            'text' => $message->isHtml() ? null : $message->getContent(),
            'html' => $message->isHtml() ? $message->getContent() : null,
            'h:Reply-To' => $this->formatAddress($message->getReplyToEmail(), $message->getReplyToName()),
        ];

        if (\count($recipients) > 1) {
            $body['recipient-variables'] = json_encode(array_fill_keys($toEmails, []));
        }

        if (!\is_null($message->getCC())) {
            foreach ($message->getCC() as $cc) {
                if (!empty($cc['email'])) {
                    $ccString = $this->formatAddress($cc['email'], $cc['name'] ?? null);

                    $body['cc'] = empty($body['cc'])
                        ? $ccString
                        : "{$body['cc']},{$ccString}";
                }
            }
        }

        if (!\is_null($message->getBCC())) {
            foreach ($message->getBCC() as $bcc) {
                if (!empty($bcc['email'])) {
                    $bccString = $this->formatAddress($bcc['email'], $bcc['name'] ?? null);

                    $body['bcc'] = empty($body['bcc'])
                        ? $bccString
                        : "{$body['bcc']},{$bccString}";
                }
            }
        }

        $isMultipart = false;

        if (!\is_null($message->getAttachments())) {
            $size = 0;

            foreach ($message->getAttachments() as $attachment) {
                $size += filesize($attachment->getPath());
            }

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new \Exception('Attachments size exceeds the maximum allowed size of ');
            }

            foreach ($message->getAttachments() as $index => $attachment) {
                $isMultipart = true;

                $body["attachment[$index]"] = Part::file(
                    "attachment[$index]",
                    $attachment->getPath(),
                    $attachment->getName(),
                    $attachment->getType(),
                );
            }
        }

        $response = new Response($this->getType());

        $headers = [
            'Authorization: Basic ' . base64_encode("api:$this->apiKey"),
        ];

        $headers[] = $isMultipart ? 'Content-Type: multipart/form-data' : 'Content-Type: application/x-www-form-urlencoded';

        $result = $this->request(
            method: 'POST',
            url: "https://$domain/v3/$this->domain/messages",
            headers: $headers,
            body: $body,
        );

        $statusCode = $result['statusCode'];

        if ($statusCode >= 200 && $statusCode < 300) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to['email']);
            }
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            foreach ($message->getTo() as $to) {
                if (\is_string($result['response'])) {
                    $response->addResult($to['email'], $result['response']);
                } elseif (isset($result['response']['message'])) {
                    $response->addResult($to['email'], $result['response']['message']);
                } else {
                    $response->addResult($to['email'], 'Unknown error');
                }
            }
        }

        return $response->toArray();
    }
}
