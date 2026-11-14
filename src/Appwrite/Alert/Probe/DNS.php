<?php

namespace Appwrite\Alert\Probe;

use Appwrite\Alert\Probe;
use Utopia\Database\Document;
use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\System\System;

/**
 * Asks the monitor's resolver for the domain's records of its type. It passes
 * when the resolver answers with at least one such record and, when the
 * monitor expects an answer, one of them is it.
 */
final readonly class DNS implements Probe
{
    public const array RECORDS = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];

    public function check(Document $monitor, Egress $egress): Check
    {
        $start = \hrtime(true);
        // Without one, the resolver Appwrite checks hosts with.
        $resolver = (string) ($monitor->getAttribute('resolver') ?: \trim(\explode(',', System::getEnv('_APP_DNS_EXTERNAL', System::getEnv('_APP_DNS', '8.8.8.8')))[0]));
        [$address, $port] = self::server($resolver);

        if (!$egress->allows($address)) {
            return new Check(Result::TargetDenied, 0);
        }

        $type = (int) Record::typeNameToCode((string) $monitor->getAttribute('record'));

        try {
            $client = new Client($address, $port, (int) $monitor->getAttribute('timeout', 10), useTcp: \str_contains($address, ':'));
            $response = $client->query(Message::query(new Question((string) $monitor->getAttribute('domain'), $type)));
        } catch (\Throwable) {
            return new Check(Result::DnsFailed, (int) \round((\hrtime(true) - $start) / 1e6));
        }

        $latency = (int) \round((\hrtime(true) - $start) / 1e6);
        $code = $response->header->responseCode;
        $answers = \array_values(\array_filter($response->answers, fn (Record $record) => $record->type === $type));

        if ($code !== Message::RCODE_NOERROR || $answers === []) {
            return new Check(Result::DnsFailed, $latency, $code);
        }

        $expected = self::normalize((string) $monitor->getAttribute('answer', ''));
        if ($expected !== '' && !\in_array($expected, \array_map(fn (Record $record) => self::normalize($record->rdata), $answers), true)) {
            return new Check(Result::AnswerMismatch, $latency, $code);
        }

        return new Check(Result::Ok, $latency, $code);
    }

    /**
     * The address and port of a resolver written as `address`, `address:port` or `[IPv6]:port`.
     *
     * @return array{0: string, 1: int}
     */
    public static function server(string $resolver): array
    {
        if (\filter_var($resolver, FILTER_VALIDATE_IP) !== false) {
            return [$resolver, 53];
        }

        $separator = \strrpos($resolver, ':');
        if ($separator === false) {
            return [$resolver, 53];
        }

        return [\trim(\substr($resolver, 0, $separator), '[]'), (int) \substr($resolver, $separator + 1)];
    }

    /**
     * Names compare without their trailing dot and in any case, text without its quotes.
     */
    private static function normalize(string $value): string
    {
        return \strtolower(\rtrim(\trim(\trim($value), '"'), '.'));
    }
}
