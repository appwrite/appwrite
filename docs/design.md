# Client design

The shape proposed for `Utopia\SMTP\Client` and the message builder it sends. Background reading is in [prior art](prior-art.md); the specifications are vendored under [`docs/rfc`](rfc).

Scope for the first version is **submission**: hand a message to a configured server on port 587, 465 or 25, with authentication and TLS. Resolving MX records and delivering to strangers is a different job with a different failure model, and is out of scope.

## The one split everything hangs off

RFC 5321 addresses and RFC 5322 addresses are not the same addresses. The envelope decides where bytes go; the headers decide what the reader sees. They are allowed to disagree, and for `Bcc` they must: a blind recipient belongs in `RCPT TO` and must not appear in any header.

So the envelope is its own type, and the client never parses what it sends.

```php
final class Envelope
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        public readonly string $sender,        // reverse-path; '' for a bounce
        public readonly array $recipients,     // forward-paths
        private readonly bool $utf8 = false,   // headers need SMTPUTF8 anyway
    ) {}

    public static function fromMessage(Message $message): self;   // to + cc + bcc
}
```

Go and Symfony Mailer both draw this line. PHPMailer does not, which is why its `SMTP` class cannot be used on its own.

## Client

```php
final class Client
{
    /**
     * @param  list<Authenticator>  $authenticators  Tried in order against what the server advertises.
     */
    public function __construct(
        Transport $transport,
        string $domain = 'localhost',          // the EHLO argument
        array $authenticators = [],
        Encryption $encryption = Encryption::Opportunistic,
        float $timeout = 30.0,
    ) {}

    public function send(Message $message): Result;
    public function sendRaw(Envelope $envelope, string|iterable $content): Result;

    public function capabilities(): Capabilities;
    public function command(string $command, int|array $expect): Reply;
    public function noop(): void;
    public function reset(): void;
    public function close(): void;
}
```

`send()` is the common path and `sendRaw()` is the primitive underneath it, for callers holding bytes from somewhere else. `command()` is the escape hatch for verbs the library does not model, so an unsupported extension never becomes a reason to fork.

The connection opens on the first send, not in the constructor. Nothing in the constructor touches the network.

There is no keep-alive flag, no restart threshold and no ping threshold. A `Client` **is** one connection; reusing connections is what `utopia-php/pools` is for.

## Reporting what happened

The widest gap in every implementation surveyed. A queue-backed sender needs two facts the others throw away: whether a failure is worth retrying, and which recipients actually made it.

```php
final class Reply
{
    public readonly int $code;                 // 250
    public readonly ?string $status;           // '2.1.5', when ENHANCEDSTATUSCODES is advertised
    public readonly string $text;

    public readonly Outcome $outcome;          // Success | Intermediate | Transient | Permanent
}

final class Result
{
    public readonly string $messageId;         // parsed from the final 250, may be ''
    public readonly array $accepted;           // list<string>
    public readonly array $rejected;           // array<string, Reply>
}
```

RFC 5321 lets a server refuse some recipients and accept others, and the message still reaches the rest. `send()` therefore throws only when **every** recipient is refused; a partial refusal returns and the caller decides. The `RCPT TO` replies are being read either way, so recording them costs nothing.

`RCPT TO` counts `250`, `251` and `252` as acceptance. Insisting on `250` rejects valid forwarding and cannot-verify replies.

Failures divide by cause, not by layer:

| Exception | Raised when |
| --- | --- |
| `ConnectionException` | The socket fails, or a read passes the timeout. |
| `ProtocolException` | A reply is malformed, or its code is not one the command allows. |
| `AuthenticationException` | No advertised mechanism matches, or credentials are refused. |
| `TransactionException` | The envelope or the data is refused. Carries the `Reply`. |

## Encryption

Symfony spells this as `autoTls` and `requireTls`, two booleans whose four combinations include one that means nothing. An enum says it once:

```php
enum Encryption
{
    case None;            // plaintext, never upgrade
    case Opportunistic;   // STARTTLS when advertised — the default
    case StartTls;        // STARTTLS required, fail when it is missing
    case Implicit;        // TLS from the first byte, per RFC 8314 (port 465)
}
```

After `STARTTLS` the capability map is discarded and `EHLO` reissued, per RFC 3207 section 4.2. Anything the server said before the handshake was unauthenticated and cannot be trusted.

## Transport

Two implementations behind one interface, following the `packages/dns` version 2 precedent:

```php
interface Transport
{
    public function connect(float $timeout, bool $tls): void;   // $tls: wrap immediately
    public function read(int $length, float $timeout): string;
    public function write(string $data, float $timeout): void;
    public function startTls(float $timeout): void;             // upgrade in place
    public function isTls(): bool;
    public function close(): void;
}
```

`Transport\Native` is `stream_socket_client` plus `stream_socket_enable_crypto`. `Transport\Swoole` is `Swoole\Coroutine\Client`, whose `enableSSL()` performs exactly the same post-connect upgrade. Both take a shared `Tls` value object holding peer verification, a certificate authority file and the name to check against, so the two do not drift apart.

They do drift in one place, and running them against the same server is what found it. Swoole checks the name with `X509_check_host()`, which reads only the DNS entries of a certificate, while the stream transport checks the address entries too. An IP literal therefore fails verification under Swoole whatever the certificate says. Nothing in PHP can change that, so the transport refuses the combination up front with an explanation rather than letting the handshake fail with `SSL verify failed` and nothing else. The end-to-end suite names the certificate rather than the address for that transport.

Transports move bytes and nothing else. Line assembly and the reply grammar live in the client, in one place, so a scripted in-memory `Transport` is all the unit tests need.

## Authentication

An ordered list, intersected with what the server advertises. The order is the security policy.

```php
interface Authenticator
{
    public function mechanism(): string;                  // 'PLAIN'
    public function initial(): ?string;                   // AUTH <mechanism> <initial-response>
    public function respond(string $challenge, int $step): string;
}
```

The step is passed in rather than counted, so no mechanism holds state. `LOGIN` needs two answers in order, and an authenticator outlives the connection it was built for — an instance that counted for itself would answer the first prompt of a reconnect with the password.

Shipping `Auth\Plain`, `Auth\Login` and `Auth\XOAuth2`. `CRAM-MD5` is left out: it is weaker than `PLAIN` over TLS, and the interface is open for anyone who needs it.

## Message

```php
final class Message implements \Stringable
{
    public function __construct(
        Address $from,
        array $to,
        string $subject,
        ?string $text = null,
        ?string $html = null,
        array $cc = [],
        array $bcc = [],
        array $replyTo = [],
        array $attachments = [],
        array $headers = [],
        ?\DateTimeImmutable $date = null,
        ?string $messageId = null,
    ) {}

    public function __toString(): string;
    public function toIterable(): iterable;    // streams attachments instead of holding them twice
}
```

One constructor with named arguments rather than a fluent builder. Named arguments already read as well as chained setters, and a builder would add a clone-per-setter method for every field.

`$date` and `$messageId` default to generated values and are injectable, so rendering is deterministic under test.

Structure follows from what is set, and is not something the caller picks:

| Content | Structure |
| --- | --- |
| Text alone | `text/plain` |
| Markup alone | `text/html` |
| Both | `multipart/alternative` |
| Any inline attachment | `multipart/related` around the above |
| Any ordinary attachment | `multipart/mixed` around the above |

```php
final class Attachment
{
    public static function fromPath(string $path, ?string $name = null, ?string $type = null): self;
    public static function fromString(string $content, string $name, string $type = 'application/octet-stream'): self;

    public function inline(string $cid): self;   // referenced from markup as cid:<value>
}
```

Encoding decisions are made for the caller: quoted-printable for text parts, base64 for attachments, RFC 2047 encoded words for headers that are not pure ASCII. A non-ASCII local part in any address needs the `SMTPUTF8` extension, and is refused when the server does not advertise it.

## What the interface audit changed

Three findings, all of them the same shape: a type that could name states it did not have, or could not name states it did.

`Reply` carried `isPositive()`, `isTransient()` and `isPermanent()`. Three booleans span eight combinations for a set of four, and `354` — the reply every `DATA` depends on — answered false to all three. RFC 5321 section 4.2.1 already names the four classes, so `Outcome` does too.

`Tls` carried a `verifyPeer` boolean that switched off the issuer check and the hostname check together. They are separate questions: a private authority is a reason to stop trusting the chain, not a reason to stop caring who answered. `Verification` splits them, and the end-to-end suite now runs on `SelfSigned` rather than throwing away a check it could keep.

`Mime\Encoding` was seven static one-liners wrapping built-ins — an interface as large as its implementation, with the composition left to callers. That shallowness was hiding two real defects: nothing folded header lines, so thirty recipients produced a 1482 octet `To` field against a 998 octet limit, and nothing split encoded words, so an accented subject produced a 200 character word against a limit of 75. `Mime\Header` owns the whole question instead — encode, split, fold — and `Message` no longer knows that encoding exists.

## Deliberate omissions

**Pipelining** (RFC 2920) saves a round trip per recipient, but it moves error attribution from "the reply to this command" to "the third reply in this group". The reply reader is shaped so replies can be read in batch later; the first version waits.

**Telemetry.** The `packages/dns` version 2 redesign dropped tracing from the package and let callers decorate the interface. Same here. The surface is small enough to wrap.

**Per-command timeouts.** RFC 5321 section 4.5.3.2 asks for six of them, from two minutes to ten. Those minimums were written for relays under load; PHPMailer and Symfony both ship a single thirty second timeout and no submission service needs more. One `timeout`, documented as a deviation.

## Two things that need tests from the first commit

**Dot-stuffing across chunk boundaries.** When the content is an iterable, a chunk ending in `\r\n` followed by a chunk opening with `.` has to be stuffed as though it were one string. Getting this wrong truncates the message at that point, and only for messages large enough to split there.

**`Bcc` never reaching the headers.** `Envelope::fromMessage()` puts blind recipients in `RCPT TO`, and the renderer must leave them out. The failure discloses the recipient list to everyone who received it.

**A connection dropped part way through `DATA`.** The terminating dot never arrives, so the server is still reading message data with no way to be told otherwise. The connection has to be discarded rather than returned to the pool, or the next `MAIL FROM` is written as the body of this message.

**A connection that dies or falls out of step on the reply to the dot.** The narrower reading — that anything after the terminating dot is recoverable — is wrong. A `4yz` or `5yz` there is an answer, and the session continues. A socket that failed, or a reply that cannot be parsed, is not: the stream is dead or no longer aligned with the protocol, and every reply after it would answer the wrong command. The rule is therefore about the kind of failure, not where it happened, so it lives in one place that every command goes through.

**`SMTPUTF8` for a header that needs it.** `Reply-To` is a header and never a path, so a non-ASCII one appears in no `RCPT TO` — which is why the envelope carries a `utf8` flag rather than deriving the answer from its paths alone. Missing it means a conforming server refuses the message.

**A stateful authenticator reused after a reconnect.** `LOGIN` needs two answers in order, and an authenticator outlives the connection it was built for. Rather than resetting a counter, the client passes the step, which makes the failure impossible to express — and lets every mechanism be a `final readonly class`.

## Testing

The unit tier drives a scripted in-memory `Transport`: no sockets, no containers, full coverage of the reply grammar, capability parsing, the encryption policy matrix, authentication exchanges and message rendering.

The end-to-end tier runs against two Mailpit servers in compose, which speak real SMTP and expose a read-back API for asserting what arrived. The first upgrades through STARTTLS and refuses any recipient outside `example.test`, on host ports 11026 and 18026. The second takes TLS from the first byte and no more than two recipients a message, on 11027 and 18027 — neither implicit TLS nor a transient per-recipient refusal is reachable on the first. The server presents a committed self-signed pair from `tests/fixtures/certs`, so the handshake needs no package manager or network inside the container, and `MP_SMTP_ALLOWED_RECIPIENTS` gives the suite an address the server genuinely refuses, which is what makes partial acceptance testable against real replies.

The split is by question rather than by class: `ClientTest` covers the session — encryption, authentication, recipients, reuse across sends and across a close — and `MessageTest` covers what a real parser reads back, which is the only way to settle whether folding, encoded words and the MIME tree mean what we think. Attachment bytes are fetched back through the API and compared, so a base64 boundary handled wrongly cannot pass by looking plausible.

Two things the end-to-end tier turned up that a double would not have. Mailpit annotates a stored message with its own `Bcc`, `Return-Path` and `Received` fields, so an assertion that no blind recipient reaches the headers has to be made about the bytes we wrote rather than the bytes the server kept. And it advertises `SIZE 0`, which exercises the rule that a declared zero means no fixed maximum.
