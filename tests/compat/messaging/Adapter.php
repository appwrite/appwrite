<?php

declare(strict_types=1);

namespace Tests\Compat\Messaging;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Messaging\Adapter as MessagingAdapter;
use Utopia\Messaging\Adapter\Email\Mime;
use Utopia\Messaging\Adapter\Email\Mock as MockEmail;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Adapter\Email\SMTP\NoHostAnswered;
use Utopia\Messaging\Adapter\SMS\Bird;
use Utopia\Messaging\Adapter\SMS\Bird\Category;
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Adapter\SMS\Msg91\MetadataParameter as Msg91Parameter;
use Utopia\Messaging\Adapter\SMS\WhatsApp\App;
use Utopia\Messaging\Adapter\SMS\WhatsApp\MetadataParameter as WhatsAppParameter;
use Utopia\Messaging\Adapter\SMS\WhatsApp\OtpType;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Helpers\JWT;
use Utopia\Messaging\Message;
use Utopia\Messaging\Messages\Discord;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Priority;
use Utopia\Messaging\Response;
use Utopia\Messaging\Tests\Support\ScriptedSmtpServer;
use Utopia\SMTP\Address;

require_once __DIR__ . '/Doubles.php';
require_once __DIR__ . '/../../../packages/messaging/tests/Support/ScriptedSmtpServer.php';

/**
 * Maps tests/compat/messaging/spec.json operations onto utopia-php/messaging.
 * Glue only: no logic.
 *
 * No provider is ever reached: an adapter's requests are answered by a
 * stub (`"transport": "stub"`, as the library's tests override
 * `request()`), or sent to the `messaging-http` fixture server (`"fixture"`,
 * or `"custom"` through a `$clientFactory`); see Doubles.php. SMTP goes to
 * a scripted SMTP server or the `messaging-smtp` Mailpit. Input the library
 * refuses with its InvalidArgumentException is reported as a value,
 * `{"$invalid": {type, value, message}}`, so its type and value are compared.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        $email = static fn (): Email => new Email(
            to: [['email' => 'john@example.test', 'name' => 'John Doe'], 'jane@example.test'],
            subject: 'Your order has shipped',
            content: '<p>Hello <b>John</b>, your order is on its way.</p>',
            fromName: 'Acme, Inc.',
            fromEmail: 'orders@example.test',
            cc: [['email' => 'support@example.test', 'name' => 'Support']],
            html: true,
            headers: ['List-Unsubscribe' => '<https://example.test/u?token=abc>'],
        );

        return [
            'email' => ['Build and validate an HTML email to two recipients with a CC and a header', fn (Session $s) => fn () => $email()],
            'calling_code' => ['Find the calling code of a phone number', fn (Session $s) => fn () => CallingCode::fromPhoneNumber('+44 (20) 7946-0958')],
            'mime' => ['Render an email as a MIME message', function (Session $s) use ($email) {
                $message = $email();

                return fn () => (string) Mime::message($message, $message->getTo(), $message->getCC() ?? []);
            }],
            'ses_sign' => ['Sign an SES request with AWS Signature Version 4', function (Session $s) {
                $ses = new RoutedSES('compat-access-key', 'compat-secret-key', 'us-east-1');

                return fn () => $ses->callSign('POST', '/v2/email/outbound-bulk-emails', '{"FromEmailAddress":"a@example.test"}', ['content-type' => 'application/json', 'host' => 'email.us-east-1.amazonaws.com', 'x-amz-date' => '20260101T000000Z'], '20260101T000000Z');
            }],
            'jwt_hs256' => ['Encode and sign an HS256 JWT', fn (Session $s) => fn () => JWT::encode(['iss' => 'team', 'iat' => 1700000000], 'secret', 'HS256', 'kid')],
            'send_twilio' => ['Send an SMS through Twilio on a stub answering at once', function (Session $s) {
                $router = new Router('stub');
                $twilio = (new RoutedTwilio('AC123', 'token', '+15550000000'))->route($router);
                $sms = new SMS(['+15551234567'], 'Your code is 123456');

                return function () use ($twilio, $sms, $router) {
                    $router->captured = [];

                    return $twilio->send($sms);
                };
            }],
            'send_sendgrid' => ['Send an email to two recipients through Sendgrid on a stub answering at once', function (Session $s) use ($email) {
                $router = new Router('stub');
                $sendgrid = (new RoutedSendgrid('key'))->route($router);
                $message = $email();

                return function () use ($sendgrid, $message, $router) {
                    $router->queue([['statusCode' => 202, 'response' => null]]);
                    $router->captured = [];

                    return $sendgrid->send($message);
                };
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'email.new' => fn (array $a, Session $s) => self::invalid(fn () => self::emailOut(self::email($a))),
            'sms.new' => fn (array $a, Session $s) => self::smsOut(self::sms($a)),
            'push.new' => fn (array $a, Session $s) => self::invalid(fn () => self::pushOut(self::push($a))),
            'discord.new' => fn (array $a, Session $s) => self::discordOut(self::discord($a)),
            'attachment.new' => fn (array $a, Session $s) => self::attachmentOut(self::attachment($a)),
            'response.run' => function (array $a, Session $s) {
                $response = new Response($a['type']);
                foreach ($a['steps'] ?? [] as $step) {
                    match (array_key_first($step)) {
                        'set_delivered_to' => $response->setDeliveredTo($step['set_delivered_to']),
                        'increment' => $response->incrementDeliveredTo(),
                        'set_type' => $response->setType($step['set_type']),
                        'add_result' => $response->addResult(...$step['add_result']),
                        default => throw new Fault('unknown response step'),
                    };
                }

                return [
                    'deliveredTo' => $response->getDeliveredTo(),
                    'type' => $response->getType(),
                    'details' => $response->getDetails(),
                    'array' => $response->toArray(),
                ];
            },
            'exception.new' => function (array $a, Session $s) {
                $e = new InvalidArgumentException($a['type'], $a['message'], $a['value'] ?? null);

                return ['class' => $e::class, 'type' => $e->getType(), 'value' => $e->getValue(), 'message' => $e->getMessage(), 'code' => $e->getCode()];
            },
            'no_host_answered.new' => function (array $a, Session $s) {
                $e = new NoHostAnswered($a['message'], $a['permanent']);

                return ['class' => $e::class, 'message' => $e->getMessage(), 'permanent' => $e->permanent, 'connection' => $e instanceof \Utopia\SMTP\Exception\ConnectionException];
            },
            'enums' => fn (array $a, Session $s) => [
                'priority' => array_map(static fn (Priority $p) => [$p->name, $p->value], Priority::cases()),
                'category' => array_map(static fn (Category $c) => [$c->name, $c->value], Category::cases()),
                'otp_type' => array_map(static fn (OtpType $o) => [$o->name, $o->value, $o->requiresApps()], OtpType::cases()),
                'msg91' => array_map(static fn (Msg91Parameter $p) => [$p->name, $p->value], Msg91Parameter::cases()),
                'whatsapp' => array_map(static fn (WhatsAppParameter $p) => [$p->name, $p->value], WhatsAppParameter::cases()),
                'reasons' => [
                    InvalidArgumentException::MESSAGE_TYPE, InvalidArgumentException::MESSAGE_EMPTY,
                    InvalidArgumentException::TOO_MANY_RECIPIENTS, InvalidArgumentException::RECIPIENT_EMPTY,
                    InvalidArgumentException::RECIPIENT_MALFORMED, InvalidArgumentException::RECIPIENT_DOMAIN_INVALID,
                    InvalidArgumentException::PROVIDER_REJECTED, InvalidArgumentException::NAME_MALFORMED,
                    InvalidArgumentException::SENDER_MALFORMED, InvalidArgumentException::HEADER_MALFORMED,
                ],
                'whatsapp_constants' => [
                    \Utopia\Messaging\Adapter\SMS\WhatsApp::DEFAULT_LANGUAGE, \Utopia\Messaging\Adapter\SMS\WhatsApp::DEFAULT_VERSION,
                    \Utopia\Messaging\Adapter\SMS\WhatsApp::CODE_MAX_LENGTH, \Utopia\Messaging\Adapter\SMS\WhatsApp::EXPIRATION_MAX_MINUTES,
                    \Utopia\Messaging\Adapter\SMS\WhatsApp::TIME_TO_LIVE_MIN_SECONDS, \Utopia\Messaging\Adapter\SMS\WhatsApp::TIME_TO_LIVE_MAX_SECONDS,
                    \Utopia\Messaging\Adapter\SMS\WhatsApp::TIME_TO_LIVE_DAY, \Utopia\Messaging\Adapter\SMS\WhatsApp::CALLBACK_DATA_MAX_LENGTH,
                    \Utopia\Messaging\Adapter\SMS\WhatsApp::TEMPLATE_NAME_MAX_LENGTH, \Utopia\Messaging\Adapter\SMS\WhatsApp::APPS_MAX,
                    App::PACKAGE_NAME_MAX_LENGTH, App::SIGNATURE_HASH_LENGTH,
                ],
            ],
            'calling_code.from_phone_number' => fn (array $a, Session $s) => CallingCode::fromPhoneNumber($a['number']),
            'calling_code.constants' => fn (array $a, Session $s) => (new \ReflectionClass(CallingCode::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC),
            'jwt.encode' => function (array $a, Session $s) {
                $key = \is_array($a['key']) ? (string) file_get_contents(Normal::KEYS . '/' . $a['key']['fixture'] . '.pem') : $a['key'];
                $token = JWT::encode($a['payload'], $key, $a['algorithm'], $a['key_id'] ?? null);
                [$header, $payload, $signature] = explode('.', $token);
                $raw = (string) base64_decode(strtr($signature, '-_', '+/'));
                $randomized = str_starts_with($a['algorithm'], 'ES');

                return [
                    'header' => $header,
                    'payload' => $payload,
                    'signature' => $randomized ? ['length' => \strlen($raw)] : $signature,
                    'verified' => Normal::verify($header . '.' . $payload, $raw, (string) ($a['verify'] ?? $a['algorithm'])),
                ];
            },
            'bird.host_for' => fn (array $a, Session $s) => Bird::hostFor($a['api_key']),
            'whatsapp.app' => fn (array $a, Session $s) => (new App($a['package_name'], $a['signature_hash']))->toArray(),
            'mime.message' => fn (array $a, Session $s) => self::invalid(function () use ($a) {
                $email = self::email($a['email']);

                return Normal::mime((string) Mime::message(
                    $email,
                    isset($a['to']) ? self::recipients($a['to']) : $email->getTo(),
                    isset($a['cc']) ? self::recipients($a['cc']) : [],
                    isset($a['bcc']) ? self::recipients($a['bcc']) : [],
                    $a['headers'] ?? [],
                ));
            }),
            'mime.addresses' => fn (array $a, Session $s) => array_map(
                static fn (Address $address) => ['email' => $address->email, 'name' => $address->name, 'string' => (string) $address],
                Mime::addresses(self::recipients($a['recipients'])),
            ),
            'mime.attachments' => fn (array $a, Session $s) => self::invalid(fn () => array_map(
                static fn (\Utopia\SMTP\Attachment $attachment) => [
                    'name' => $attachment->name,
                    'type' => $attachment->type,
                    'content' => $attachment->content,
                    'path' => $attachment->path,
                ],
                Mime::attachments(self::email($a['email'])),
            )),
            'mime.size' => fn (array $a, Session $s) => self::invalid(fn () => Mime::size(self::email($a['email']))),
            'ses.sign' => fn (array $a, Session $s) => (new RoutedSES($a['access_key'], $a['secret_key'], $a['region']))
                ->withService($a['service'] ?? 'ses')
                ->callSign($a['method'], $a['path'], $a['payload'], $a['signed_headers'], $a['amz_date']),
            'smtp.hosts' => function (array $a, Session $s) {
                $smtp = new SMTP(host: $a['host'], port: $a['port'] ?? 25, smtpSecure: $a['secure'] ?? '', smtpAutoTLS: $a['auto_tls'] ?? false);
                $hosts = (new \ReflectionMethod(SMTP::class, 'hosts'))->invoke($smtp);

                return array_map(static fn (array $h) => [$h[0], $h[1], $h[2]->name], (array) $hosts);
            },
            'telemetry.new' => fn (array $a, Session $s) => $s->handle(new RecordingTelemetry()),
            'telemetry.records' => fn (array $a, Session $s) => $s->get($a['telemetry'])->records,
            'scripted.messages' => fn (array $a, Session $s) => array_map(self::smsOut(...), $s->get($a['adapter'])['adapter']->messages),
            'smtp_server.new' => fn (array $a, Session $s) => $s->handle(new ScriptedSmtpServer($a['replies'])),
            'smtp_server.commands' => fn (array $a, Session $s) => array_map(
                static fn (string $c) => str_starts_with($c, 'EHLO ') || str_starts_with($c, 'HELO ') ? substr($c, 0, 5) . '<host>' : $c,
                $s->get($a['server'])->commands(),
            ),
            'adapter.new' => function (array $a, Session $s) {
                $transport = $a['transport'] ?? 'stub';
                $router = \in_array($transport, ['stub', 'fixture', 'custom', 'default'], true)
                    ? new Router($transport === 'stub' ? 'stub' : 'fixture', $transport === 'stub' ? '' : $s->service('messaging-http'), $s->ns)
                    : null;
                $ports = [];
                try {
                    $adapter = self::adapter($a, $s, $transport === 'custom' ? $router : null, $ports);
                } catch (InvalidArgumentException $e) {
                    return self::invalidOut($e);
                }
                // `default` keeps the adapter's own client: its router only queues and takes.
                if (\in_array($transport, ['stub', 'fixture'], true) && $router instanceof Router && method_exists($adapter, 'route')) {
                    $adapter->route($router);
                } elseif (!\in_array($transport, ['custom', 'default'], true)) {
                    $router = null;
                }
                if (isset($a['telemetry'])) {
                    $adapter->setTelemetry($s->get($a['telemetry']));
                }

                return $s->handle(['adapter' => $adapter, 'router' => $router, 'ports' => $ports]);
            },
            'adapter.info' => function (array $a, Session $s) {
                $adapter = $s->get($a['adapter'])['adapter'];

                return [
                    'name' => $adapter->getName(),
                    'type' => $adapter->getType(),
                    'messageType' => $adapter->getMessageType(),
                    'maxMessagesPerRequest' => $adapter->getMaxMessagesPerRequest(),
                ];
            },
            'adapter.send' => fn (array $a, Session $s) => self::exchange($a, $s, fn (MessagingAdapter $adapter) => $adapter->send(self::message($a['message']))),
            'adapter.once' => function (array $a, Session $s) {
                $handle = ($this->operations()['adapter.new'])(['adapter' => $a['adapter'], 'args' => self::parameters($a['adapter'], $a['args'] ?? []), 'transport' => 'stub'], $s);
                if (!isset($handle['$handle'])) {
                    return $handle;
                }

                return self::exchange(['adapter' => $handle] + $a, $s, fn (MessagingAdapter $adapter) => $adapter->send(self::message($a['message'])));
            },
            'adapter.call' => fn (array $a, Session $s) => self::exchange($a, $s, fn (MessagingAdapter $adapter) => self::call($adapter, $a['method'], $a['args'] ?? [], $s)),
            'mailpit.message' => function (array $a, Session $s) {
                $api = $s->service('messaging-mailpit');
                $found = json_decode((string) file_get_contents($api . '/api/v1/search?query=' . rawurlencode('subject:"' . $a['subject'] . '"')), true);
                $messages = $found['messages'] ?? [];
                if (\count($messages) !== 1) {
                    throw new Fault('expected exactly one message with subject ' . $a['subject'] . ', got ' . \count($messages));
                }
                $id = $messages[0]['ID'];
                $m = json_decode((string) file_get_contents("{$api}/api/v1/message/{$id}"), true);
                $headers = json_decode((string) file_get_contents("{$api}/api/v1/message/{$id}/headers"), true);
                $people = static fn (?array $list) => array_map(static fn (array $p) => [$p['Name'], $p['Address']], $list ?? []);

                return [
                    'subject' => $m['Subject'],
                    'from' => $people([$m['From']]),
                    'to' => $people($m['To']),
                    'cc' => $people($m['Cc']),
                    'bcc' => $people($m['Bcc']),
                    'reply_to' => $people($m['ReplyTo']),
                    'text' => trim((string) $m['Text']),
                    'html' => $m['HTML'],
                    'attachments' => array_map(static fn (array $f) => [$f['FileName'], $f['ContentType'], $f['Size']], $m['Attachments'] ?? []),
                    'headers' => array_intersect_key($headers, array_flip(['List-Unsubscribe', 'List-Unsubscribe-Post', 'X-Mailer', 'X-Tag'])),
                ];
            },
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, string> $ports
     */
    private static function adapter(array $a, Session $s, ?Router $custom, array &$ports): object
    {
        $args = $a['args'] ?? [];
        if ($custom instanceof Router) {
            $args['clientFactory'] = static fn () => new FixtureClient($custom);
        }
        if (isset($args['serviceAccount'])) {
            $account = [];
            foreach ($args['serviceAccount'] as $key => $value) {
                $account[$key] = \is_array($value) && isset($value['fixture']) ? (string) file_get_contents(Normal::KEYS . '/' . $value['fixture'] . '.pem') : $value;
            }
            unset($args['serviceAccount']);
            $args['serviceAccountJSON'] = (string) json_encode($account);
        }
        if (isset($args['category'])) {
            $args['category'] = Category::from($args['category']);
        }
        if (isset($args['host']) && \is_string($args['host'])) {
            $args['host'] = self::host($args['host'], $a['servers'] ?? [], $s, $ports);
        }

        return match ($a['adapter']) {
            'mailgun' => new RoutedMailgun(...$args),
            'sendgrid' => new RoutedSendgrid(...$args),
            'resend' => new RoutedResend(...$args),
            'ses' => new RoutedSES(...$args),
            'smtp' => new SMTP(...$args),
            'mock_email' => new MockEmail(...$args),
            'bird' => new RoutedBird(...$args),
            'clickatell' => new RoutedClickatell(...$args),
            'fast2sms' => new RoutedFast2SMS(...$args),
            'infobip' => new RoutedInfobip(...$args),
            'inforu' => new RoutedInforu(...$args),
            'mock_sms' => new RoutedMockSMS(...$args),
            'msg91' => new RoutedMsg91(...$args),
            'plivo' => new RoutedPlivo(...$args),
            'seven' => new RoutedSeven(...$args),
            'sinch' => new RoutedSinch(...$args),
            'telesign' => new RoutedTelesign(...$args),
            'telnyx' => new RoutedTelnyx(...$args),
            'textmagic' => new RoutedTextMagic(...$args),
            'twilio' => new RoutedTwilio(...$args),
            'vonage' => new RoutedVonage(...$args),
            'whatsapp' => new RoutedWhatsApp(...$args),
            'apns' => new RoutedAPNS(...array_merge($args, isset($args['authKey']) && \is_array($args['authKey']) ? ['authKey' => (string) file_get_contents(Normal::KEYS . '/' . $args['authKey']['fixture'] . '.pem')] : [])),
            'fcm' => new RoutedFCM(...$args),
            'discord' => new RoutedDiscord(...$args),
            'geosms' => new GEOSMS($s->get($args['defaultAdapter'])['adapter']),
            'scripted' => new ScriptedSMS($args['name'] ?? 'Test', $args['max'] ?? 100, $args['response'] ?? null, $args['error'] ?? null),
            default => throw new Fault('unknown adapter ' . json_encode($a['adapter'])),
        };
    }

    /**
     * The arguments the adapter's constructor takes, of a superset shared by
     * the adapters a fuzz profile picks from.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function parameters(string $adapter, array $args): array
    {
        $class = [
            'mailgun' => RoutedMailgun::class, 'sendgrid' => RoutedSendgrid::class, 'resend' => RoutedResend::class, 'ses' => RoutedSES::class,
            'bird' => RoutedBird::class, 'clickatell' => RoutedClickatell::class, 'fast2sms' => RoutedFast2SMS::class, 'infobip' => RoutedInfobip::class,
            'inforu' => RoutedInforu::class, 'mock_sms' => RoutedMockSMS::class, 'msg91' => RoutedMsg91::class, 'plivo' => RoutedPlivo::class,
            'seven' => RoutedSeven::class, 'sinch' => RoutedSinch::class, 'telesign' => RoutedTelesign::class, 'telnyx' => RoutedTelnyx::class,
            'textmagic' => RoutedTextMagic::class, 'twilio' => RoutedTwilio::class, 'vonage' => RoutedVonage::class, 'whatsapp' => RoutedWhatsApp::class,
            'apns' => RoutedAPNS::class, 'fcm' => RoutedFCM::class, 'discord' => RoutedDiscord::class,
        ][$adapter] ?? throw new Fault('unknown adapter ' . $adapter);
        $names = array_map(static fn (\ReflectionParameter $p) => $p->getName(), (new \ReflectionMethod($class, '__construct'))->getParameters());
        if (\in_array('serviceAccountJSON', $names, true)) {
            $names[] = 'serviceAccount';
        }

        return array_intersect_key($args, array_flip($names));
    }

    /**
     * A host string with `{port<n>}` replaced by the n-th scripted SMTP
     * server's port and `{closed}` by a port nobody listens on. `$ports`
     * maps each `127.0.0.1:<port>` back to its placeholder, which results
     * report instead of the random port.
     *
     * @param list<mixed> $servers
     * @param array<string, string> $ports
     */
    private static function host(string $host, array $servers, Session $s, array &$ports): string
    {
        foreach ($servers as $i => $server) {
            $port = (string) $s->get($server)->port;
            $host = str_replace('{port' . $i . '}', $port, $host);
            $ports['127.0.0.1:' . $port] = '127.0.0.1:{port' . $i . '}';
        }
        if (str_contains($host, '{closed}')) {
            $port = (string) ScriptedSmtpServer::closedPort();
            $host = str_replace('{closed}', $port, $host);
            $ports['127.0.0.1:' . $port] = '127.0.0.1:{closed}';
        }
        if (str_contains($host, '{mailpit}')) {
            $host = str_replace('{mailpit}', $s->service('messaging-smtp'), $host);
        }

        return $host;
    }

    /**
     * Runs a send or another call, and reports its outcome with the requests
     * it made.
     *
     * @param array<string, mixed> $a
     * @param callable(MessagingAdapter): mixed $run
     * @return array<string, mixed>
     */
    private static function exchange(array $a, Session $s, callable $run): array
    {
        $entry = $s->get($a['adapter']);
        /** @var Router|null $router */
        $router = $entry['router'];
        $router?->queue($a['replies'] ?? []);
        try {
            $out = ['result' => $run($entry['adapter'])];
        } catch (InvalidArgumentException $e) {
            $out = self::invalidOut($e);
        } catch (Fault $fault) {
            throw $fault;
        } catch (\Throwable $e) {
            $out = ['error' => ['class' => $e::class, 'message' => $e->getMessage()]];
        }
        if ($router instanceof Router) {
            $out['requests'] = $router->take((bool) ($a['sort'] ?? false));
        }
        if (($entry['ports'] ?? []) !== []) {
            $out = json_decode(strtr((string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $entry['ports']), true);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function call(MessagingAdapter $adapter, string $method, array $args, Session $s): mixed
    {
        return match ($method) {
            'set_telemetry' => $adapter->setTelemetry($s->get($args['telemetry'])),
            'set_local' => $adapter->setLocal($args['code'], $s->get($args['adapter'])['adapter']) === $adapter,
            'get_endpoint' => $adapter->getEndpoint(),
            'set_endpoint' => $adapter->setEndpoint(self::endpoint($args['url'], $s)) === $adapter,
            'disconnect' => $adapter->disconnect(),
            'upsert_template' => $adapter->upsertTemplate(...array_merge(
                $args,
                isset($args['otpType']) ? ['otpType' => OtpType::from($args['otpType'])] : [],
                isset($args['apps']) ? ['apps' => array_map(static fn (array $app) => new App($app['package_name'], $app['signature_hash']), $args['apps'])] : [],
            )),
            'get_template' => $adapter->getTemplate(...$args),
            'delete_template' => $adapter->deleteTemplate(...$args),
            default => throw new Fault('unknown method ' . json_encode($method)),
        };
    }

    /** `{fixture}` in a URL is this runtime's fixture server with the namespace. */
    private static function endpoint(string $url, Session $s): string
    {
        return str_replace('{fixture}', $s->service('messaging-http') . '/' . $s->ns, $url);
    }

    /**
     * @param array<string, mixed> $m
     */
    private static function message(array $m): Message
    {
        return match ($m['kind']) {
            'email' => self::email($m),
            'sms' => self::sms($m),
            'push' => self::push($m),
            'discord' => self::discord($m),
            default => throw new Fault('unknown message kind'),
        };
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function email(array $a): Email
    {
        $email = new Email(
            to: $a['to'] ?? [],
            subject: $a['subject'] ?? 'Subject',
            content: $a['content'] ?? 'Body',
            fromName: $a['from_name'] ?? 'Sender',
            fromEmail: $a['from_email'] ?? 'sender@example.test',
            replyToName: $a['reply_to_name'] ?? null,
            replyToEmail: $a['reply_to_email'] ?? null,
            cc: $a['cc'] ?? null,
            bcc: $a['bcc'] ?? null,
            attachments: isset($a['attachments']) ? array_map(self::attachment(...), $a['attachments']) : null,
            html: $a['html'] ?? false,
            headers: $a['headers'] ?? [],
        );
        if (\array_key_exists('origin', $a)) {
            $email->setOrigin($a['origin']);
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function attachment(array $a): Attachment
    {
        $content = $a['content'] ?? null;
        if (\is_array($content)) {
            $content = str_repeat((string) $content['repeat'], (int) $content['times']);
        }

        return new Attachment($a['name'], $a['path'] ?? '', $a['type'], $content);
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function sms(array $a): SMS
    {
        $sms = new SMS(
            to: $a['to'],
            content: $a['content'] ?? '',
            from: $a['from'] ?? null,
            attachments: $a['attachments'] ?? null,
            metadata: $a['metadata'] ?? null,
        );
        if (\array_key_exists('set_metadata', $a)) {
            $sms->setMetadata($a['set_metadata']);
        }
        if (\array_key_exists('origin', $a)) {
            $sms->setOrigin($a['origin']);
        }

        return $sms;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function push(array $a): Push
    {
        $push = new Push(
            to: $a['to'],
            title: $a['title'] ?? null,
            body: $a['body'] ?? null,
            data: $a['data'] ?? null,
            action: $a['action'] ?? null,
            sound: $a['sound'] ?? null,
            image: $a['image'] ?? null,
            icon: $a['icon'] ?? null,
            color: $a['color'] ?? null,
            tag: $a['tag'] ?? null,
            badge: $a['badge'] ?? null,
            contentAvailable: $a['content_available'] ?? null,
            critical: $a['critical'] ?? null,
            priority: isset($a['priority']) ? \constant(Priority::class . '::' . $a['priority']) : null,
            channelId: $a['channel_id'] ?? null,
        );
        if (\array_key_exists('origin', $a)) {
            $push->setOrigin($a['origin']);
        }

        return $push;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function discord(array $a): Discord
    {
        $discord = new Discord(
            content: $a['content'],
            username: $a['username'] ?? null,
            avatarUrl: $a['avatar_url'] ?? null,
            tts: $a['tts'] ?? null,
            embeds: $a['embeds'] ?? null,
            allowedMentions: $a['allowed_mentions'] ?? null,
            components: $a['components'] ?? null,
            attachments: $a['attachments'] ?? null,
            flags: $a['flags'] ?? null,
            threadName: $a['thread_name'] ?? null,
            wait: $a['wait'] ?? null,
            threadId: $a['thread_id'] ?? null,
        );
        if (\array_key_exists('origin', $a)) {
            $discord->setOrigin($a['origin']);
        }

        return $discord;
    }

    /**
     * @param list<mixed> $list
     * @return list<array<string, string>>
     */
    private static function recipients(array $list): array
    {
        return array_map(static fn (mixed $r) => \is_string($r) ? ['email' => $r] : $r, $list);
    }

    /**
     * @param array<array<string, string>>|null $list
     * @return list<array<string, string>>|null
     */
    private static function mailboxes(?array $list): ?array
    {
        return $list === null ? null : array_values(array_map(
            static fn (array $r) => ['email' => $r['email']] + (isset($r['name']) ? ['name' => $r['name']] : []),
            $list,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function emailOut(Email $email): array
    {
        return [
            'to' => self::mailboxes($email->getTo()),
            'subject' => $email->getSubject(),
            'content' => $email->getContent(),
            'fromName' => $email->getFromName(),
            'fromEmail' => $email->getFromEmail(),
            'replyToName' => $email->getReplyToName(),
            'replyToEmail' => $email->getReplyToEmail(),
            'cc' => self::mailboxes($email->getCC()),
            'bcc' => self::mailboxes($email->getBCC()),
            'attachments' => $email->getAttachments() === null ? null : array_map(self::attachmentOut(...), $email->getAttachments()),
            'html' => $email->isHtml(),
            'headers' => $email->getHeaders(),
            'origin' => $email->getOrigin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attachmentOut(Attachment $attachment): array
    {
        return [
            'name' => $attachment->getName(),
            'path' => $attachment->getPath(),
            'type' => $attachment->getType(),
            'content' => $attachment->getContent(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function smsOut(SMS $sms): array
    {
        return [
            'to' => $sms->getTo(),
            'content' => $sms->getContent(),
            'from' => $sms->getFrom(),
            'attachments' => $sms->getAttachments(),
            'metadata' => $sms->getMetadata(),
            'origin' => $sms->getOrigin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pushOut(Push $push): array
    {
        return [
            'to' => $push->getTo(),
            'from' => $push->getFrom(),
            'title' => $push->getTitle(),
            'body' => $push->getBody(),
            'data' => $push->getData(),
            'action' => $push->getAction(),
            'sound' => $push->getSound(),
            'image' => $push->getImage(),
            'icon' => $push->getIcon(),
            'color' => $push->getColor(),
            'tag' => $push->getTag(),
            'badge' => $push->getBadge(),
            'contentAvailable' => $push->getContentAvailable(),
            'critical' => $push->getCritical(),
            'priority' => $push->getPriority()?->name,
            'channelId' => $push->getChannelId(),
            'origin' => $push->getOrigin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function discordOut(Discord $discord): array
    {
        return [
            'content' => $discord->getContent(),
            'username' => $discord->getUsername(),
            'avatarUrl' => $discord->getAvatarUrl(),
            'tts' => $discord->getTts(),
            'embeds' => $discord->getEmbeds(),
            'allowedMentions' => $discord->getAllowedMentions(),
            'components' => $discord->getComponents(),
            'attachments' => $discord->getAttachments(),
            'flags' => $discord->getFlags(),
            'threadName' => $discord->getThreadName(),
            'wait' => $discord->getWait(),
            'threadId' => $discord->getThreadId(),
            'origin' => $discord->getOrigin(),
        ];
    }

    /**
     * @param callable(): mixed $run
     */
    private static function invalid(callable $run): mixed
    {
        try {
            return $run();
        } catch (InvalidArgumentException $e) {
            return self::invalidOut($e);
        }
    }

    /**
     * @return array{'$invalid': array{type: string, value: string|null, message: string}}
     */
    private static function invalidOut(InvalidArgumentException $e): array
    {
        return ['$invalid' => ['type' => $e->getType(), 'value' => $e->getValue(), 'message' => $e->getMessage()]];
    }
}
