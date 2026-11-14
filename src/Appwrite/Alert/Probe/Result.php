<?php

namespace Appwrite\Alert\Probe;

/**
 * What one check of a probe monitor found. Only `ok` passes; every other
 * result opens the monitor's alert.
 */
enum Result: string
{
    case Ok = 'ok';
    case DnsFailed = 'dns_failed';
    case ConnectRefused = 'connect_refused';
    case ConnectTimeout = 'connect_timeout';
    case ConnectFailed = 'connect_failed';
    case TlsFailed = 'tls_failed';
    case TlsExpiring = 'tls_expiring';
    case StatusMismatch = 'status_mismatch';
    case AnswerMismatch = 'answer_mismatch';
    case SmtpRejected = 'smtp_rejected';
    case TargetDenied = 'target_denied';
    case Timeout = 'timeout';

    /**
     * How the result reads in a sentence, as in "is down: connection refused".
     */
    public function phrase(): string
    {
        return match ($this) {
            self::Ok => 'responding',
            self::DnsFailed => 'name did not resolve',
            self::ConnectRefused => 'connection refused',
            self::ConnectTimeout => 'connection timed out',
            self::ConnectFailed => 'connection failed',
            self::TlsFailed => 'TLS handshake failed',
            self::TlsExpiring => 'TLS certificate expiring',
            self::StatusMismatch => 'unexpected status code',
            self::AnswerMismatch => 'unexpected answer',
            self::SmtpRejected => 'server refused the session',
            self::TargetDenied => 'target is in a denied network',
            self::Timeout => 'timed out',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $result): string => $result->value, self::cases());
    }
}
