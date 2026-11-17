<?php

namespace Appwrite\Alert;

use Appwrite\Alert\Probe\Check;
use Appwrite\Alert\Probe\DNS;
use Appwrite\Alert\Probe\Result;
use Appwrite\Alert\Probe\SMTP;
use Utopia\Database\Document;
use Utopia\DSN\DSN;
use Utopia\System\System;

/**
 * The wire format of the synthetics-operator prober (`internal/results` in
 * loks0n/synthetics-operator), which checks probe monitors' targets instead of
 * the alerts worker when `_APP_CONNECTIONS_PROBER` names the Redis it shares
 * with it. The worker pushes each job onto the `synthetics.probes.jobs` list
 * and `probe-results` pops what the prober pushes onto `synthetics.probes.results`.
 *
 * A `ProbeJob` carries the monitor's target with no assertions: the prober
 * reports what it saw, and the expectations the PHP probes check (status codes,
 * certificate days, DNS answer) are checked here on its `ProbeResult`. The
 * job's namespace is the project ID, its name the monitor ID, and its
 * generation a hash of the target, so a result for a target the monitor no
 * longer has is dropped.
 */
final readonly class Prober
{
    public const string JOBS = 'synthetics.probes.jobs';
    public const string RESULTS = 'synthetics.probes.results';

    /**
     * The prober's kind for each monitor type it checks.
     */
    public const array KINDS = [
        MONITOR_TYPE_HTTP => 'HTTPProbe',
        MONITOR_TYPE_DNS => 'DNSProbe',
        MONITOR_TYPE_TCP => 'TCPProbe',
        MONITOR_TYPE_SMTP => 'SMTPProbe',
    ];

    /**
     * The prober's result classes, as the PHP probes' results. `config_error` and
     * `assertion_failed` are missing on purpose: jobs carry no assertions, and a
     * target the API accepted is not one the prober refuses, so either is a
     * mismatch between the two that no check of the target explains.
     */
    public const array OUTCOMES = [
        'ok' => Result::Ok,
        'dns_failed' => Result::DnsFailed,
        'connect_refused' => Result::ConnectRefused,
        'connect_timeout' => Result::ConnectTimeout,
        'connect_failed' => Result::ConnectFailed,
        'tls_failed' => Result::TlsFailed,
        'recv_timeout' => Result::Timeout,
        'smtp_rejected' => Result::SmtpRejected,
        'target_denied' => Result::TargetDenied,
    ];

    /**
     * A connection to the Redis named by `_APP_CONNECTIONS_PROBER`
     * (`redis://[user:password@]host:port`), which the prober pops jobs from.
     */
    public static function connect(string $dsn): \Redis
    {
        $dsn = new DSN($dsn);
        $redis = new \Redis();
        $redis->connect($dsn->getHost(), (int) ($dsn->getPort() ?: 6379));
        $password = (string) $dsn->getPassword();
        if ($password !== '') {
            $user = (string) $dsn->getUser();
            $redis->auth($user === '' ? $password : [$user, $password]);
        }

        return $redis;
    }

    /**
     * The `ProbeJob` that checks the monitor's target once, scheduled now.
     *
     * @return array<string, mixed>
     */
    public static function job(Document $monitor, \DateTimeImmutable $scheduledAt): array
    {
        $type = (string) $monitor->getAttribute('type');
        $payload = self::payload($monitor);

        return [
            'spec' => [
                'kind' => self::KINDS[$type],
                'namespace' => (string) $monitor->getAttribute('projectId'),
                'name' => $monitor->getId(),
                'generation' => self::generation($payload),
                'intervalMs' => (int) $monitor->getAttribute('interval') * 1000,
                $type . 'Probe' => $payload,
            ],
            'scheduledAt' => $scheduledAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /**
     * The check a `ProbeResult` reports for the monitor, or null when it is not
     * one to record: for another kind or target than the monitor has now, older
     * than the check already recorded, or with a result there is no name for here.
     *
     * A result is older than the recorded check when it was scheduled at least a
     * timeout before that check was recorded. Each check is recorded within its
     * timeout of being scheduled and the next is scheduled an interval later, no
     * shorter than the timeout, so only a result overtaken by a newer one is.
     *
     * @param array<string, mixed> $result
     */
    public static function check(Document $monitor, array $result): ?Check
    {
        $type = (string) $monitor->getAttribute('type');
        if (($result['kind'] ?? null) !== (self::KINDS[$type] ?? '') || ($result['generation'] ?? null) !== self::generation(self::payload($monitor))) {
            return null;
        }

        $outcome = self::OUTCOMES[(string) ($result['result'] ?? '')] ?? null;
        $timestamp = $result['timestamp'] ?? '';
        if ($outcome === null || !\is_string($timestamp) || $timestamp === '') {
            return null;
        }
        try {
            $scheduledAt = new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return null;
        }

        $evaluatedAt = $monitor->getAttribute('evaluatedAt');
        if ($evaluatedAt !== null && $scheduledAt->getTimestamp() <= (new \DateTimeImmutable($evaluatedAt))->getTimestamp() - (int) $monitor->getAttribute('timeout')) {
            return null;
        }

        $latency = (int) ($result['durationMs'] ?? 0);
        $expiry = (int) ($result['tlsCertExpiryUnix'] ?? 0);
        $certificateExpiresAt = $expiry > 0 ? new \DateTimeImmutable('@' . $expiry) : null;
        $code = (int) ($result[$type === MONITOR_TYPE_SMTP ? 'smtpReplyCode' : 'httpStatusCode'] ?? 0) ?: null;

        if ($outcome === Result::Ok) {
            $outcome = match ($type) {
                MONITOR_TYPE_HTTP => self::http($monitor, (int) $code, $certificateExpiresAt),
                MONITOR_TYPE_DNS => self::dns($monitor, (int) ($result['dnsAnswerCount'] ?? 0), (string) ($result['dnsFirstAnswerValue'] ?? '')),
                default => Result::Ok,
            };
        }

        // The prober reports no DNS response code, but answers to compare come only with NOERROR.
        if ($type === MONITOR_TYPE_DNS) {
            $code = \in_array($outcome, [Result::Ok, Result::AnswerMismatch], true) ? 0 : null;
        }

        return new Check($outcome, $latency, $code, $certificateExpiresAt);
    }

    /**
     * Passes on a status the monitor expects (any 2xx by default) from a
     * certificate further away than its `certificateDays`, as the HTTP probe.
     */
    private static function http(Document $monitor, int $code, ?\DateTimeImmutable $certificateExpiresAt): Result
    {
        $expected = \array_map('intval', $monitor->getAttribute('statusCodes', []) ?: []);
        if ($expected === [] ? ($code < 200 || $code > 299) : !\in_array($code, $expected, true)) {
            return Result::StatusMismatch;
        }

        $days = (int) $monitor->getAttribute('certificateDays', 0);
        if ($days > 0 && $certificateExpiresAt !== null && $certificateExpiresAt->getTimestamp() - \time() < $days * 86400) {
            return Result::TlsExpiring;
        }

        return Result::Ok;
    }

    /**
     * Passes on any answer, or on the expected one. The prober reports only the
     * first answer's value, so that is the one compared, where the DNS probe
     * accepts any answer of the record type.
     */
    private static function dns(Document $monitor, int $answers, string $first): Result
    {
        if ($answers === 0) {
            return Result::DnsFailed;
        }

        $normalize = fn (string $value): string => \strtolower(\rtrim(\trim(\trim($value), '"'), '.'));
        $expected = $normalize((string) $monitor->getAttribute('answer', ''));
        if ($expected !== '' && $normalize($first) !== $expected) {
            return Result::AnswerMismatch;
        }

        return Result::Ok;
    }

    /**
     * The target the prober checks: the type's `*ProbeSpecPayload`.
     *
     * @return array<string, mixed>
     */
    private static function payload(Document $monitor): array
    {
        $timeout = (int) $monitor->getAttribute('timeout') * 1000;
        $port = (int) $monitor->getAttribute('port');

        return match ((string) $monitor->getAttribute('type')) {
            MONITOR_TYPE_HTTP => \array_filter([
                'timeoutMs' => $timeout,
                'url' => (string) $monitor->getAttribute('url'),
                'method' => (string) $monitor->getAttribute('method') ?: 'GET',
                // A JSON object of header names and values; left out when empty, which Go reads as no map.
                'headers' => \json_decode((string) $monitor->getAttribute('headers', ''), true) ?: null,
                'body' => (string) $monitor->getAttribute('body', ''),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            MONITOR_TYPE_DNS => [
                'timeoutMs' => $timeout,
                'name' => (string) $monitor->getAttribute('domain'),
                'type' => (string) $monitor->getAttribute('record') ?: 'A',
                'resolver' => self::resolver((string) $monitor->getAttribute('resolver')),
            ],
            MONITOR_TYPE_TCP => [
                'timeoutMs' => $timeout,
                'host' => (string) $monitor->getAttribute('host'),
                'port' => $port,
            ],
            MONITOR_TYPE_SMTP => [
                'timeoutMs' => $timeout,
                'host' => (string) $monitor->getAttribute('host'),
                'port' => $port,
                'tlsMode' => (string) $monitor->getAttribute('tlsMode') ?: SMTP::mode($port),
            ],
        };
    }

    /**
     * The monitor's resolver as the `host:port` the prober dials. Without one,
     * the resolver the DNS probe falls back to.
     */
    private static function resolver(string $resolver): string
    {
        [$address, $port] = DNS::server($resolver ?: \trim(\explode(',', System::getEnv('_APP_DNS_EXTERNAL', System::getEnv('_APP_DNS', '8.8.8.8')))[0]));

        return (\str_contains($address, ':') ? '[' . $address . ']' : $address) . ':' . $port;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function generation(array $payload): int
    {
        return \crc32((string) \json_encode($payload));
    }
}
