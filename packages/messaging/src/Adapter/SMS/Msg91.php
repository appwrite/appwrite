<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\Msg91\MetadataParameter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://docs.msg91.com/p/tf9GTextN/e/7WESqQ4RLu/MSG91

class Msg91 extends SMSAdapter
{
    protected const NAME = 'Msg91';

    /**
     * @param  string  $senderId Msg91 Sender ID
     * @param  string  $authKey Msg91 Auth Key
     * @param  string  $templateId Msg91 Template ID
     */
    public function __construct(
        private readonly string $senderId,
        private readonly string $authKey,
        private readonly string $templateId,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        // TODO: Find real limit
        return 100;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(SMSMessage $message): array
    {
        $metadata = $message->getMetadata() ?? [];
        $metadata = array_intersect_key($metadata, array_flip(array_column(MetadataParameter::cases(), 'value')));

        foreach ($metadata as $key => $value) {
            if (!\is_string($value)) {
                throw new \InvalidArgumentException("Msg91 {$key} metadata must be a string.");
            }
        }

        foreach ([MetadataParameter::CRQID, MetadataParameter::UUID] as $parameter) {
            $key = $parameter->value;

            if (!\array_key_exists($key, $metadata)) {
                continue;
            }

            if (\strlen($metadata[$key]) > 80 || !preg_match('/^[A-Za-z0-9_.-]+$/', $metadata[$key])) {
                throw new \InvalidArgumentException("Msg91 {$key} metadata must be 80 characters or less and contain only alphanumeric characters, underscores, dots, or hyphens.");
            }
        }

        $recipients = [];
        foreach ($message->getTo() as $recipient) {
            $recipients[] = [
                'mobiles' => ltrim($recipient, '+'),
                'content' => $message->getContent(),
                'otp' => $message->getContent(),
            ];
        }

        $body = [
            'sender' => $this->senderId,
            'template_id' => $this->templateId,
            'recipients' => $recipients,
        ];

        foreach ($metadata as $key => $value) {
            $body[$key] = $value;
        }

        $response = new Response($this->getType());
        $result = $this->request(
            method: 'POST',
            url: 'https://api.msg91.com/api/v5/flow/',
            headers: [
                'Content-Type: application/json',
                'Authkey: ' . $this->authKey,
            ],
            body: $body,
        );

        if ($result['statusCode'] === 200) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to);
            }
        } else {
            foreach ($message->getTo() as $to) {
                $response->addResult($to, 'Unknown error');
            }
        }

        return $response->toArray();
    }
}
