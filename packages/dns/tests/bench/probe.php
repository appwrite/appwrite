<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

try {
    $client = new Client('127.0.0.1', (int) ($argv[1] ?? 8053), 1);
    $client->query(Message::query(new Question('dev.appwrite.io', Record::TYPE_A)));
} catch (Throwable) {
    exit(1);
}
