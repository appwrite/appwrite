<?php

namespace Utopia\Messaging\Adapter\SMS;

// Reference Material
// https://www.kwtsms.com/api-documentation.html

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

class Kwtsms extends SMSAdapter
{
    protected const NAME = 'kwtSMS';

    /**
     * Errors that mean the recipient itself can never be reached, so a retry is pointless.
     */
    private const PERMANENT_ERRORS = ['ERR006', 'ERR025', 'ERR026'];

    /**
     * @param  string  $username kwtSMS API username (not the account login)
     * @param  string  $password kwtSMS API password
     * @param  string  $sender Registered kwtSMS Sender ID. The default KWT-SMS is for testing only.
     * @param  bool  $test When true, kwtSMS queues the message but never delivers it (credits are returned when it is deleted from the queue)
     */
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $sender = 'KWT-SMS',
        private readonly bool $test = false,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 200;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function process(SMSMessage $message): array
    {
        // kwtSMS wants digits only with the country code: no +, no 00 prefix.
        $to = array_map(
            fn (string $to): string => (string) preg_replace('/^00/', '', (string) preg_replace('/\D+/', '', $to)),
            $message->getTo(),
        );

        $response = new Response($this->getType());
        $result = $this->request(
            method: 'POST',
            url: 'https://www.kwtsms.com/API/send/',
            headers: [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            body: [
                'username' => $this->username,
                'password' => $this->password,
                'sender' => $this->sender,
                'mobile' => implode(',', $to),
                'message' => $message->getContent(),
                'test' => $this->test ? '1' : '0',
            ],
        );

        $body = \is_array($result['response']) ? $result['response'] : [];

        if (($body['result'] ?? null) === 'OK') {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $recipient) {
                $response->addResult($recipient);
            }

            return $response->toArray();
        }

        $code = (string) ($body['code'] ?? '');
        $error = (string) ($body['description'] ?? '');
        if ($error === '') {
            $error = $result['error'] !== '' ? $result['error'] : 'Unknown error';
        }
        $permanent = \in_array($code, self::PERMANENT_ERRORS, true);
        $numeric = (int) preg_replace('/\D+/', '', $code);

        foreach ($message->getTo() as $recipient) {
            $response->addResult($recipient, $error, $permanent, $numeric);
        }

        return $response->toArray();
    }
}
