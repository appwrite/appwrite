<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;

class Sendgrid extends EmailAdapter
{
    protected const NAME = 'Sendgrid';

    /**
     * @param  string  $apiKey Your Sendgrid API key to authenticate with the API.
     */
    public function __construct(private readonly string $apiKey)
    {
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
     * Get max messages per request.
     */
    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    /**
    * {@inheritdoc}
    *
    * Uses Sendgrid's personalization recipient variables to send multiple emails at once.
    *
    * @link https://www.twilio.com/docs/sendgrid/for-developers/sending-email/personalizations#-Sending-Two-Different-Emails-to-Two-Different-Groups-of-Recipients
    */
    protected function process(EmailMessage $message): array
    {
        $personalizations = array_map(
            fn (array $to): array => [
                'to' => [empty($to['name'])
                    ? ['email' => $to['email']]
                    : ['email' => $to['email'], 'name' => $to['name']]],
                'subject' => $message->getSubject(),
            ],
            $message->getTo(),
        );

        if (!\in_array($message->getCC(), [null, []], true)) {
            foreach ($personalizations as &$personalization) {
                foreach ($message->getCC() as $cc) {
                    $entry = ['email' => $cc['email']];
                    if (!empty($cc['name'])) {
                        $entry['name'] = $cc['name'];
                    }
                    $personalization['cc'][] = $entry;
                }
            }
            unset($personalization);
        }

        if (!\in_array($message->getBCC(), [null, []], true)) {
            foreach ($personalizations as &$personalization) {
                foreach ($message->getBCC() as $bcc) {
                    $entry = ['email' => $bcc['email']];
                    if (!empty($bcc['name'])) {
                        $entry['name'] = $bcc['name'];
                    }
                    $personalization['bcc'][] = $entry;
                }
            }
            unset($personalization);
        }

        $attachments = [];

        if (!\is_null($message->getAttachments())) {
            $size = 0;

            foreach ($message->getAttachments() as $attachment) {
                $size += filesize($attachment->getPath());
            }

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new \Exception('Attachments size exceeds the maximum allowed size of 25MB');
            }

            foreach ($message->getAttachments() as $attachment) {
                $attachments[] = [
                    'content' => base64_encode(file_get_contents($attachment->getPath())),
                    'filename' => $attachment->getName(),
                    'type' => $attachment->getType(),
                    'disposition' => 'attachment',
                ];
            }
        }

        $body = [
            'personalizations' => $personalizations,
            'reply_to' => [
                'name' => $message->getReplyToName(),
                'email' => $message->getReplyToEmail(),
            ],
            'from' => [
                'name' => $message->getFromName(),
                'email' => $message->getFromEmail(),
            ],
            'content' => [
                [
                    'type' => $message->isHtml() ? 'text/html' : 'text/plain',
                    'value' => $message->getContent(),
                ],
            ],
        ];

        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }

        if ($message->getHeaders() !== []) {
            $body['headers'] = $message->getHeaders();
        }

        $response = new Response($this->getType());
        $result = $this->request(
            method: 'POST',
            url: 'https://api.sendgrid.com/v3/mail/send',
            headers: [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            body: $body,
        );

        $statusCode = $result['statusCode'];

        if ($statusCode === 202) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to['email']);
            }
        } else {
            foreach ($message->getTo() as $to) {
                if (\is_string($result['response'])) {
                    $response->addResult($to['email'], $result['response']);
                } elseif (!\is_null($result['response']['errors'][0]['message'] ?? null)) {
                    $response->addResult($to['email'], $result['response']['errors'][0]['message']);
                } else {
                    $response->addResult($to['email'], 'Unknown error');
                }
            }
        }

        return $response->toArray();
    }
}
