# Utopia SMTP

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/smtp`](https://github.com/utopia-php/monorepo/tree/main/packages/smtp) — please open issues and pull requests there.

Lite and fast micro PHP SMTP library that is easy to learn.

Submits mail to a configured server: RFC 5321 on the wire, RFC 5322 and MIME for the message, with STARTTLS and authentication. It reports which recipients were accepted and whether a refusal is worth retrying.

## Installation

```bash
composer require utopia-php/smtp
```

## Usage

```php
<?php

use Utopia\SMTP\Address;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Client;
use Utopia\SMTP\Message;
use Utopia\SMTP\Transport\Native;

$client = new Client(
    transport: new Native('smtp.example.com', 587),
    domain: 'app.example.com',
    authenticators: [new Plain('username', 'password')],
);

$result = $client->send(new Message(
    from: new Address('jane@example.com', 'Jane Doe'),
    to: [new Address('john@example.com')],
    subject: 'Hello',
    text: 'Plain text body',
));

$client->close();
```

The connection opens on the first send, so nothing in the constructor touches the network.

## Envelope and content

`MAIL FROM` and `RCPT TO` carry the addresses that decide where the message goes. The headers decide what the reader sees. They are allowed to disagree, and for a blind recipient they must: `Bcc` addresses go in the envelope and appear in no header.

`send()` derives the envelope from the message. To send bytes built elsewhere, pass the envelope yourself:

```php
use Utopia\SMTP\Envelope;

$client->sendRaw(
    new Envelope('jane@example.com', ['john@example.com']),
    "Subject: Hello\r\n\r\nBody\r\n",
);
```

Content may be a string or an iterable of chunks. The client never parses it.

## Results and failures

A server may refuse some recipients and accept others, and the message still reaches the rest.

```php
$result = $client->send($message);

$result->accepted;      // list of addresses the server took
$result->rejected;      // address => Reply, for the ones it would not
$result->messageId;     // the queue identifier, when the server offers one
$result->isComplete();  // nothing was rejected
```

A `TransactionException` is thrown only when every recipient is refused. Its `Reply` says whether the same message could succeed later:

```php
use Utopia\SMTP\TransactionException;

try {
    $client->send($message);
} catch (TransactionException $exception) {
    if ($exception->isTransient()) {
        // 4yz — put it back on the queue
    }
}
```

Every `Reply` carries an `Outcome`, which is the four reply classes of RFC 5321 section 4.2.1 rather than a set of booleans:

| Case | Codes | Means |
| --- | --- | --- |
| `Success` | 2yz | The command worked. |
| `Intermediate` | 3yz | Understood, and the server wants more — the `354` before message data. |
| `Transient` | 4yz | Failed, but the same command may work later. |
| `Permanent` | 5yz | Failed, and sending it again changes nothing. |

The other failures are `ConnectionException` for the socket, `ProtocolException` for a reply that makes no sense, and `AuthenticationException` for credentials. All extend `Utopia\SMTP\Exception`.

## Encryption

```php
use Utopia\SMTP\Encryption;

new Client($transport, encryption: Encryption::StartTls);
```

| Case | Behaviour |
| --- | --- |
| `Opportunistic` | Upgrade when the server advertises `STARTTLS`. The default. |
| `StartTls` | Upgrade, and fail when the server does not offer it. |
| `Implicit` | TLS from the first byte, as RFC 8314 asks for on port 465. |
| `None` | Plaintext, never upgraded. |

After an upgrade the client reissues `EHLO` and discards everything the server said beforehand, per RFC 3207 section 4.2.

Certificate checking lives on the transport. Who signed it and who presented it are separate questions, so refusing to ask the first is not a reason to skip the second:

```php
use Utopia\SMTP\Tls;
use Utopia\SMTP\Verification;

new Native('smtp.example.com', 587, new Tls(caFile: '/etc/ssl/certs/ca.pem'));

// A private authority or a test rig: any issuer, but still the host we dialled.
new Native('smtp.internal', 587, new Tls(verify: Verification::SelfSigned));
```

| Case | Issuer | Hostname |
| --- | --- | --- |
| `Full` | Trusted chain required. The default. | Checked. |
| `SelfSigned` | Any. | Checked. |
| `None` | Any. | Not checked. |

## Authentication

Authenticators are tried in the order given, against what the server advertises, so the order is the security policy. `Auth\Plain`, `Auth\Login` and `Auth\XOAuth2` ship with the library, and the `Authenticator` interface takes anything else.

```php
use Utopia\SMTP\Auth\XOAuth2;

new Client($transport, authenticators: [new XOAuth2('jane@example.com', $token)]);
```

## Messages

The MIME structure follows from what is set, rather than being chosen:

| Content | Structure |
| --- | --- |
| Text alone | `text/plain` |
| Markup alone | `text/html` |
| Both | `multipart/alternative` |
| An inline attachment | `multipart/related` around the above |
| An ordinary attachment | `multipart/mixed` around the above |

```php
use Utopia\SMTP\Attachment;

new Message(
    from: new Address('jane@example.com'),
    to: [new Address('john@example.com')],
    subject: 'Report',
    text: 'The figures are attached.',
    html: '<p>The figures are attached. <img src="cid:logo"></p>',
    cc: [new Address('ada@example.com')],
    bcc: [new Address('archive@example.com')],
    attachments: [
        Attachment::fromPath('/srv/reports/q3.pdf'),
        Attachment::fromString($png, 'logo.png', 'image/png')->inline('logo'),
    ],
    headers: ['X-Mailer' => 'Utopia'],
);
```

Attachments added by path are read while the message is written, so a large file is never held in memory twice. Encoding is decided for you: quoted-printable for text, base64 for attachments, RFC 2047 encoded words for headers outside ASCII.

Header fields are folded to the 78 octet line length of RFC 5322, and encoded words are split on character boundaries so none passes the 75 the specification allows it. A long recipient list or a subject full of accents therefore stays within the limits a server enforces.

## Transports

`Transport\Native` uses streams and is the default choice. Under Swoole's runtime hook it yields the scheduler like any other hooked stream.

`Transport\Swoole` is coroutine-native and yields whether or not the hook is on, which matters where the hook for streams is switched off. It needs `ext-swoole`, and it must be built inside a coroutine.

One difference is worth knowing before choosing it. Swoole checks a certificate name with `X509_check_host()`, which reads the DNS entries and not the address ones, so dialling an IP literal cannot pass verification however the certificate is written. The stream transport checks both. Give `Tls` a `peerName` the certificate carries, or ask for `Verification::None`; the transport says as much rather than letting the handshake fail with nothing to go on.

A `Client` is one connection. Pooling belongs to [`utopia-php/pools`](https://github.com/utopia-php/pools), so there is no keep-alive setting here.

## Extensions

`STARTTLS`, `AUTH`, `SIZE`, `8BITMIME`, `SMTPUTF8` and `ENHANCEDSTATUSCODES` are used when the server advertises them. A message with a non-ASCII local part is refused before sending when the server cannot carry it. `PIPELINING` is parsed but not yet used.

Timeouts are one value rather than the six minimums of RFC 5321 section 4.5.3.2, which were written for relays under load.

## Testing

```bash
composer test       # unit, no network
composer test:e2e   # against Mailpit in docker compose
```

## License

MIT
