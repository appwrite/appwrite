# Adding A New Messaging Adapter

This document is a part of Utopia PHP contributors guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/utopia-php/monorepo/blob/main/CODE_OF_CONDUCT.md) and the [Contribution Guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## Getting started

Messaging adapter allow utilization of different messaging services in a consistent way. This document will guide you through the process of adding a new messaging adapter to the Utopia PHP Messaging library.

## 1. Prerequisites

This library is developed in the [utopia-php monorepo](https://github.com/utopia-php/monorepo) — `utopia-php/messaging` is a read-only mirror. Fork the monorepo, clone your fork, and create a `feat-XXX-YYY-messaging-adapter` branch from `main`, where `XXX` is the issue ID and `YYY` the messaging adapter name. The library lives in `packages/messaging`.

## 2. Implement a new messaging adapter

In order to start implementing a new messaging adapter, add new file inside `packages/messaging/src/Utopia/Messaging/Adapters/XXX/YYY.php` where `XXX` is the type of adapter (**Email**, **SMS** or **Push**), and `YYY` is the name of the messaging provider in `PascalCase` casing. Inside the file you should create a class that extends the base `Email`, `SMS` or `Push` abstract adapter class.

Inside the class, you need to implement four methods:

- `__construct()` - This method should accept all the required parameters for the adapter to work. For example, the `SendGrid` adapter requires an API key, so the constructor should look like this:

```php
public function __construct(private string $apiKey)
```

- `getName()` - This method should return the display name of the adapter. For example, the `SendGrid` adapter should return `SendGrid`:

```php
public function getName(): string
{
    return 'SendGrid';
}
```

- `getMaxMessagesPerRequest()` - This method should return the maximum number of messages that can be sent in a single request. For example, `SendGrid` can send 1000 messages per request, so this method should return 1000:

```php
public function getMaxMessagesPerRequest(): int
{
    return 1000;
}
```

- `process()` - This method should accept a message object of the same type as the base adapter, and send it to the messaging provider, returning the response as a string. For example, the `SendGrid` adapter should accept an `Email` message object and send it to the SendGrid API:

```php
public function process(Email $message): string
{
    // Send message to SendGrid API
}
```

The base `Adapter` class includes a two helper functions called `request()` and `requestMulti()` that can be used to send HTTP requests to the messaging provider.

The `request()` function will send a single request and accepts the following parameters:

- `method` - The HTTP method to use. For example, `POST`, `GET`, `PUT`, `PATCH` or `DELETE`.
- `url` - The URL to send the request to.
- `headers` - An array of headers to send with the request.
- `body` - The body of the request as a string, or null if no body is required.
- `timeout` - The timeout in seconds for the request.

The `requestMulti()` function will send multiple concurrent requests via HTTP/2 multiplexing, and accepts the following parameters:

- `method` - The HTTP method to use. For example, `POST`, `GET`, `PUT`, `PATCH` or `DELETE`.
- `urls` - An array of URLs to send the requests to.
- `headers` - An array of headers to send with the requests.
- `bodies` - An array of bodies of the requests as strings, or an empty array if no body is required.
- `timeout` - The timeout in seconds for the requests.

`urls` and `bodies` must either be the same length, or one of them must contain only a single element. If `urls` contains only a single element, it will be used for all requests. If `bodies` contains only a single element, it will be used for all requests.

The default content type of the request is `x-www-form-urlencoded`, but you can change it by adding a `Content-Type` header. No encoding is applied to the body, so you need to make sure it is encoded properly before sending the request.

Putting it all together, the `SendGrid` adapter should look like this:

### Full example

```php
<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Adapter\Email as EmailAdapter;

class Sendgrid extends EmailAdapter
{
    public function __construct(private string $apiKey) 
    {
    }

    public function getName(): string
    {
        return 'Sendgrid';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    protected function process(Email $message): string
    {
        return $this->request(
            method: 'POST',
            url: 'https://api.sendgrid.com/v3/mail/send',
            headers: [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            body: \json_encode([
                'personalizations' => [
                    [
                        'to' => \array_map(
                            fn($to) => ['email' => $to],
                            $message->getTo()
                        ),
                        'subject' => $message->getSubject(),
                    ],
                ],
                'from' => [
                    'email' => $message->getFrom(),
                ],
                'content' => [
                    [
                        'type' => $message->isHtml() ? 'text/html' : 'text/plain',
                        'value' => $message->getContent(),
                    ],
                ],
            ]),
        );
    }
}
```

## 3. Test your adapter

After you finish adding your new adapter, you need to ensure that it is usable. Use your newly created adapter to make some sample requests to your messaging service.

If everything goes well, raise a pull request and be ready to respond to any feedback which can arise during code review.

## 4. Raise a pull request

First of all, commit the changes with the message `Added YYY adapter` and push the branch to your fork of the monorepo. If you visit your fork on GitHub, you will see a new alert saying you are ready to submit a pull request against `utopia-php/monorepo`. Follow the steps GitHub provides, and at the end, you will have your pull request submitted.

## 🤕 Stuck ?
If you need any help with the contribution, feel free to head over to [our discord channel](https://appwrite.io/discord) and we'll be happy to help you out.
