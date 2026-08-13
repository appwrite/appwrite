# Prior art

Notes taken while designing `Utopia\SMTP\Client`, from four implementations worth copying from. The specifications they implement are vendored under [`docs/rfc`](rfc).

## What was surveyed

| Implementation | Why it is worth reading |
| --- | --- |
| Go `net/smtp` | The smallest correct client. One `Client` struct, one command method per verb, and `Data()` returning a writer. |
| `emersion/go-smtp` | A modern take on the same shape, with typed `MailOptions`/`RcptOptions` for the extension parameters and `MaxMessageSize()` off the capability list. |
| Symfony Mailer `EsmtpTransport` | The most complete PHP client. Capability parsing, opportunistic TLS, a pluggable authenticator list, streaming `DATA`. |
| PHPMailer `SMTP` | The incumbent. `packages/messaging` wraps it in `Adapter\Email\SMTP`, so its behaviour is the compatibility target. |

## What they agree on

Every one of them separates the **envelope** from the **content**. `MAIL FROM` and `RCPT TO` carry addresses that need not appear anywhere in the message, and the message itself is an opaque blob the client never parses. Go takes `io.Reader`, Symfony takes `RawMessage` and iterates it. Only PHPMailer merges the two, which is why its `SMTP` class cannot be used without the message builder wrapped around it.

They also agree that:

- `EHLO` is issued first and falls back to `HELO` when it is refused. The reply lines are parsed into a keyword map.
- The map is thrown away and `EHLO` reissued after `STARTTLS`, per RFC 3207 section 4.2. Anything the server said before the handshake is unauthenticated.
- `RCPT TO` accepts `250`, `251` and `252`. Treating anything but `250` as a failure rejects valid forwarding and cannot-verify replies.
- `DATA` is written as a stream with dot-stuffing applied on the way past, not built in memory.

## Where they differ

**Transport.** Symfony has an `AbstractStream` seam with socket and process implementations. Go passes a `net.Conn`. PHPMailer hard-codes `stream_socket_client`. A seam is what lets the same client run on a Swoole coroutine socket, and `packages/dns` already sets that precedent.

**TLS policy.** Symfony spells it as two booleans, `autoTls` and `requireTls`, whose four combinations include one that is meaningless. `emersion/go-smtp` instead offers three dial functions: `Dial`, `DialTLS`, `DialStartTLS`. The second reads better as an enum.

**Authentication.** Symfony and `emersion/go-smtp` both take an ordered list of mechanisms and pick the first the server advertises. PHPMailer takes a string and guesses. The list is the right shape; the order is the security policy.

**Failure reporting.** This is the widest gap. Go returns an error carrying the reply code. Symfony throws with the code attached. PHPMailer sets an `ErrorInfo` string, and `packages/messaging` copies that string into every per-recipient result, so a caller cannot tell a `4xx` worth retrying from a `5xx` that never will be. A queue-backed sender needs that distinction more than it needs anything else on this list.

**Partial acceptance.** RFC 5321 lets some recipients be refused while others are accepted, and the message is still delivered to the rest. None of the four surface this: all treat a send as one boolean. Recording which addresses were accepted is close to free once the `RCPT TO` replies are being read anyway.

**Connection reuse.** PHPMailer has `SMTPKeepAlive`, Symfony has a restart threshold and a ping threshold. Both are pooling policies living inside a mail client. `utopia-php/pools` already owns that concern.

## Limits and timeouts worth encoding

From RFC 5321 section 4.5.3:

| Limit | Value |
| --- | --- |
| Local part | 64 octets |
| Domain | 255 octets |
| Reverse or forward path | 256 octets |
| Command line | 512 octets |
| Reply line | 512 octets |
| Text line | 1000 octets, not counting the stuffed dot |
| Recipients buffered | at least 100 |

Section 4.5.3.2 asks for per-command timeouts rather than one transaction timeout, with minimums of five minutes for the greeting, `MAIL` and `RCPT`, two minutes to get `354` back from `DATA`, three minutes per data block, and ten minutes for the `250` after the terminating dot. Those numbers were written for relays under load. Both PHPMailer and Symfony ship a single thirty second timeout instead, and no submission service needs more.

## Extensions that change the wire

| Extension | Specification | Effect on the client |
| --- | --- | --- |
| `STARTTLS` | RFC 3207 | Upgrade, then reissue `EHLO` and discard the old capabilities. |
| `AUTH` | RFC 4954 | Advertised mechanisms decide which authenticator runs. |
| `SIZE` | RFC 1870 | Declare `SIZE=` on `MAIL FROM`, and refuse oversized messages before sending them. |
| `8BITMIME` | RFC 6152 | Declare `BODY=8BITMIME` when the content is not seven bit. |
| `SMTPUTF8` | RFC 6531 | Required before a non-ASCII local part can be sent. Refuse otherwise. |
| `ENHANCEDSTATUSCODES` | RFC 2034, RFC 3463 | Replies carry a `class.subject.detail` triplet that classifies the failure. |
| `PIPELINING` | RFC 2920 | Send command groups without waiting. Cuts a round trip per recipient. |
