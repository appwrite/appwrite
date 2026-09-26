<?php

namespace Utopia\DNS;

use Throwable;
use Utopia\DNS\Exception\Message\PartialDecodingException;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;

/**
 * Reference about DNS packet:
 *
 * HEADER
 * > 16 bits identificationField (1-65535. 0 means no ID). ID provided by client. Helps to match async responses. Usage may allow DNS Cache Poisoning
 * > 16 bits flagsField (0-65535). Flags contains:
 * > -- qr (0 = query, 1 = response). Tells if packet is query (request) or response
 * > -- opcode (0-15). Tells type of packet
 * > -- aa (0 = no, 1 = yes. Can only be 1 in response packet). Tells if server is authoritative for queried domain
 * > -- tc (0 = no, 1 = yes. Can only be 1 in response packet). Tells if message was truncated (when message is too long)
 * > -- rd (0 = no, 1 = yes). Tells if client wants recursive resolution of query
 * > -- ra (0 = no, 1 = yes. Can only be 1 in server-to-server communication). Tells if client supports recursive resolution of query
 * > -- z (0-7. Always 0, reserved for future). Gives extra padding, no intention yet
 * > -- rcode (0-15. Can only be 1-15 in response packet. Incomming packet always has 0). Tells response status
 * > 16 bits numberOfQuestions (0-65535)
 * > 16 bits numberOfAnswers (0-65535)
 * > 16 bits numberOfAuthorities (0-65535)
 * > 16 bits numberOfAdditionals (0-65535)
 *
 * QUESTIONS SECTION
 * > Each question contains:
 * > -- dynamic-length name. Includes domain name we are looking for. Split into labels. To get domain, join labels with dot symbol.
 * > -- -- Following pattern repeats:
 * > -- -- -- 8 bits labelLength (0-255). Defines length of label. We use it in next step
 * > -- -- -- X bits label. X length is labelLength.
 * > -- -- When labelLength and label are both 0, it's end of name.
 * > -- 16 bits type (0-65535). Tells what type of record we are asking for, like A, AAAA, or CNAME
 * > -- 16 bits class (0-65535). Usually always 1, meaning internet class
 * > This pattern repeats, as there can be multiple questions. Not sure what the separator is
 *
 * ANSWERS SECTION
 * > Follows same pattern as questions section.
 * > Each answer also has (at the end):
 * > -- 32 bits ttl. Time to live of the answer
 * > -- 16 bit length. Length of the answer data.
 * > -- X bits data X length is length from above. Gives answer itself. Structure changes based on type.
 *
 * AUTHORITIES SECTION
 * ADDITIONALS SECTION
 *
 * RFCs:
 * - RFC 1035: https://datatracker.ietf.org/doc/html/rfc1035
 * - RFC 3596: https://datatracker.ietf.org/doc/html/rfc3596
 * - RFC 6844: https://datatracker.ietf.org/doc/html/rfc6844
 * - RFC 2782: https://datatracker.ietf.org/doc/html/rfc2782
 */

class Server
{
    /** @var array<int, callable> */
    protected array $errors = [];

    protected bool $debug = false;

    /**
     * Telemetry metrics
     */
    protected ?Histogram $duration = null;
    protected ?Counter $queriesTotal = null;
    protected ?Counter $responsesTotal = null;

    public function __construct(protected Adapter $adapter, protected Resolver $resolver)
    {
        $this->setTelemetry(new NoTelemetry());
    }

    /**
     * Set telemetry adapter
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->duration = $telemetry->createHistogram(
            'dns.query.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]],
        );

        // Initialize additional telemetry metrics
        $this->queriesTotal = $telemetry->createCounter('dns.queries.total');
        $this->responsesTotal = $telemetry->createCounter('dns.responses.total');
    }

    /**
     * Add Error Handler
     */
    public function error(callable $handler): self
    {
        $this->errors[] = $handler;
        return $this;
    }

    /**
     * On Worker Start
     *
     * @param callable(Server $server, int $workerId): void $handler
     * @phpstan-param callable(Server $server, int $workerId): void $handler
     */
    public function onWorkerStart(callable $handler): self
    {
        $this->adapter->onWorkerStart(function (int $workerId) use ($handler): void {
            \call_user_func($handler, $this, $workerId);
        });

        return $this;
    }

    /**
     * Set Debug Mode
     */
    public function setDebug(bool $status): self
    {
        $this->debug = $status;
        return $this;
    }

    /**
     * Handle Error
     */
    protected function handleError(Throwable $error): void
    {
        foreach ($this->errors as $handler) {
            \call_user_func($handler, $error);
        }
    }

    /**
     * Handle packet
     *
     *
     */
    protected function onPacket(string $buffer, string $ip, int $port, Protocol $protocol): string
    {
        $maxResponseSize = $protocol->maxResponseSize();
        $question = null;
        $response = null;

        try {
            // 1. Parse Message.
            $decodeStart = microtime(true);
            try {
                $message = Message::decode($buffer);
            } catch (PartialDecodingException $e) {
                $this->handleError($e);

                $response = Message::response(
                    $e->getHeader(),
                    Message::RCODE_FORMERR,
                    authoritative: false,
                );
                return $response->encode($maxResponseSize);
            } catch (Throwable $e) {
                $this->handleError($e);
                return '';
            }
            $this->duration?->record(microtime(true) - $decodeStart, ['phase' => 'decode']);

            // RFC 1035: Only OPCODE 0 (QUERY) is supported
            // Return NOTIMP for other opcodes (IQUERY=1 is obsolete, STATUS=2, others reserved)
            if ($message->header->opcode !== 0) {
                $response = Message::response(
                    $message->header,
                    Message::RCODE_NOTIMP,
                    authoritative: false,
                );
                return $response->encode($maxResponseSize);
            }

            $question = $message->questions[0] ?? null;
            if ($question === null) {
                $response = Message::response(
                    $message->header,
                    Message::RCODE_FORMERR,
                    authoritative: false,
                );
                return $response->encode($maxResponseSize);
            }

            $this->queriesTotal?->add(1, [
                'type' => $question->type,
            ]);

            // 2. Resolve query
            $resolveStart = microtime(true);
            try {
                $response = $this->resolver->resolve(new Query($message, $ip, $port, $protocol));
            } catch (Throwable $e) {
                $this->handleError($e);

                $response = Message::response(
                    $message->header,
                    Message::RCODE_SERVFAIL,
                    questions: $message->questions,
                    authoritative: false,
                );
            }
            $this->duration?->record(microtime(true) - $resolveStart, [
                'phase' => 'resolve',
                'responseCode' => $response->header->responseCode,
            ]);

            // 3. Encode response
            $encodeStart = microtime(true);
            try {
                return $response->encode($maxResponseSize);
            } catch (Throwable $e) {
                $this->handleError($e);

                $response = Message::response(
                    $message->header,
                    Message::RCODE_SERVFAIL,
                    questions: $message->questions,
                    authoritative: false,
                );
                return $response->encode($maxResponseSize);
            } finally {
                $this->duration?->record(microtime(true) - $encodeStart, [
                    'phase' => 'encode',
                    'responseCode' => $response->header->responseCode,
                ]);
            }
        } finally {
            if ($question !== null) {
                $this->responsesTotal?->add(1, [
                    'type' => $question->type,
                    'responseCode' => $response?->header->responseCode,
                ]);
            }
        }
    }

    public function start(): void
    {
        try {
            $onPacket = $this->onPacket(...);
            $this->adapter->onPacket($onPacket);
            $this->adapter->start();
        } catch (Throwable $error) {
            $this->handleError($error);
        }
    }
}
