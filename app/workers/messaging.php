<?php

require_once __DIR__ . '/../worker.php';

use Utopia\App;
use Utopia\Database\Validator\Authorization;
use Utopia\CLI\Console;
use Utopia\DSN\DSN;
use Utopia\Messaging\Adapter;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Messages\SMS;
use Utopia\Queue\Message;
use Utopia\Queue\Server;

Authorization::disable();
Authorization::setDefaultStatus(false);

$dsn = new DSN(App::getEnv('_APP_SMS_PROVIDER'));
$user = $dsn->getUser();
$secret = $dsn->getPassword();

Server::setResource('sms', function () use ($dsn, $user, $secret) {
    return match ($dsn->getHost()) {
        'mock' => new Mock($user, $secret), // used for tests
        'twilio' => new Twilio($user, $secret),
        'text-magic' => new TextMagic($user, $secret),
        'telesign' => new Telesign($user, $secret),
        'msg91' => new Msg91($user, $secret),
        'vonage' => new Vonage($user, $secret),
        default => null
    };
});

Server::setResource('execute', function () {
    return function (string $recipient, string $message, Adapter $sms) {
        $from = App::getEnv('_APP_SMS_FROM');

        if (empty(App::getEnv('_APP_SMS_PROVIDER'))) {
            Console::info('Skipped sms processing. No Phone provider has been set.');
            return;
        }

        if (empty($from)) {
            Console::info('Skipped sms processing. No phone number has been set.');
            return;
        }

        $message = new SMS(
            to: [$recipient],
            content: $message,
            from: $from,
        );

        try {
            $sms->send($message);
        } catch (\Exception $error) {
            throw new Exception('Error sending message: ' . $error->getMessage(), 500);
        }
    };
});

$server->job()
    ->inject('message')
    ->inject('execute')
    ->inject('sms')
    ->action(function (Message $message, callable $execute, Adapter $sms) {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        if (empty($payload['recipient'])) {
            throw new Exception('Missing recipient');
        }

        if (empty($payload['message'])) {
            throw new Exception('Missing message');
        }

        $execute($payload['recipient'], $payload['message'], $sms);
    });

$server->workerStart();
$server->start();
