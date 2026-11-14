<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Alert\Probe\DNS;
use Appwrite\Alert\Probe\Result;
use Appwrite\Alert\Probe\SMTP;
use Appwrite\Alert\Severity;
use Appwrite\Alert\Status;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;
use Utopia\Database\Document;

class Monitor extends Model
{
    public function __construct()
    {
        $alerts = Config::getParam('alerts', []);
        $types = \array_keys($alerts['types'] ?? []);
        $resources = \array_keys($alerts['resources'] ?? []);

        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Monitor ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Monitor creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Monitor update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Monitor name.',
                'default' => '',
                'example' => 'Bandwidth spike',
            ])
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('rules', [
                'type' => self::TYPE_STRING,
                'description' => 'Rules that must all hold for the monitor to fire, as query strings. A usage monitor has one comparing a metric with a threshold, optionally scoped with `equal(\'resourceType\')` and `equal(\'resourceId\')`. Empty for probe monitors.',
                'default' => [],
                'example' => ['{"method":"greaterThan","attribute":"network.outbound","values":[5000000]}'],
                'array' => true,
            ])
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'What the monitor checks: `usage` compares a usage metric with a threshold; `http`, `dns`, `tcp` and `smtp` probe a target on their own interval.',
                'default' => $types[0] ?? '',
                'example' => 'usage',
                'enum' => $types,
                'enumSDKName' => 'MonitorType',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_ENUM,
                'description' => 'Type of the resource the monitor watches, from its rules: the project, or one database, bucket, function or site.',
                'default' => $resources[0] ?? '',
                'example' => 'database',
                'enum' => $resources,
                'enumSDKName' => 'MonitorResourceType',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the resource the monitor watches, from its rules. The project ID for a project-wide monitor.',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('window', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Seconds a counter metric is summed over, and a growth metric is measured across. Gauges compare their latest value. Usage is read a short lag behind the evaluation (`_APP_ALERTS_USAGE_LAG`, 120 seconds by default) so events still being written are counted by the next evaluation.',
                'default' => 3600,
                'example' => 3600,
            ])
            ->addRule('interval', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Probe monitors: seconds between two checks. 0 for usage monitors, which are evaluated on the server\'s interval.',
                'default' => 0,
                'example' => 60,
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Probe monitors: seconds a check may take before it fails.',
                'default' => 0,
                'example' => 10,
            ])
            ->addRule('url', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP monitors: URL requested.',
                'default' => '',
                'example' => 'https://example.com/health',
            ])
            ->addRule('method', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP monitors: method the URL is requested with.',
                'default' => '',
                'example' => 'GET',
            ])
            ->addRule('headers', [
                'type' => self::TYPE_JSON,
                'description' => 'HTTP monitors: headers sent, as an object of header names and values.',
                'default' => new \stdClass(),
                'required' => false,
                'example' => ['Accept' => 'application/json'],
            ])
            ->addRule('body', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP monitors: request body sent.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('statusCodes', [
                'type' => self::TYPE_INTEGER,
                'description' => 'HTTP monitors: status codes the final response may have. Empty accepts any 2xx status.',
                'default' => [],
                'example' => [200],
                'array' => true,
            ])
            ->addRule('certificateDays', [
                'type' => self::TYPE_INTEGER,
                'description' => 'HTTP monitors: days before an HTTPS URL\'s certificate expires from which the check fails. 0 never warns before it expires.',
                'default' => 0,
                'example' => 14,
            ])
            ->addRule('domain', [
                'type' => self::TYPE_STRING,
                'description' => 'DNS monitors: domain queried.',
                'default' => '',
                'example' => 'example.com',
            ])
            ->addRule('record', [
                'type' => self::TYPE_STRING,
                'description' => 'DNS monitors: record type queried: ' . \implode(', ', DNS::RECORDS) . '.',
                'default' => '',
                'example' => 'A',
            ])
            ->addRule('resolver', [
                'type' => self::TYPE_STRING,
                'description' => 'DNS monitors: resolver queried. Empty for the server\'s DNS resolver.',
                'default' => '',
                'example' => '1.1.1.1',
            ])
            ->addRule('answer', [
                'type' => self::TYPE_STRING,
                'description' => 'DNS monitors: record value one of the answers must have. Empty accepts any answer.',
                'default' => '',
                'example' => '93.184.215.14',
            ])
            ->addRule('host', [
                'type' => self::TYPE_STRING,
                'description' => 'TCP and SMTP monitors: hostname or IP address connected to.',
                'default' => '',
                'example' => 'mail.example.com',
            ])
            ->addRule('port', [
                'type' => self::TYPE_INTEGER,
                'description' => 'TCP and SMTP monitors: port connected to.',
                'default' => 0,
                'example' => 587,
            ])
            ->addRule('tlsMode', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP monitors: `' . SMTP::TLS_NONE . '`, `' . SMTP::TLS_STARTTLS . '` or `' . SMTP::TLS_IMPLICIT . '`.',
                'default' => '',
                'example' => SMTP::TLS_STARTTLS,
            ])
            ->addRule('result', [
                'type' => self::TYPE_ENUM,
                'description' => 'Probe monitors: what the last check found. `ok` passes; anything else opened the monitor\'s alert. Null before the first check.',
                'default' => null,
                'required' => false,
                'example' => Result::Ok->value,
                'enum' => Result::values(),
                'enumSDKName' => 'MonitorResult',
            ])
            ->addRule('latency', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Probe monitors: milliseconds the last check took.',
                'default' => null,
                'required' => false,
                'example' => 182,
            ])
            ->addRule('code', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Probe monitors: last status code an HTTP target answered with, reply code an SMTP server sent, or response code a DNS resolver returned.',
                'default' => null,
                'required' => false,
                'example' => 200,
            ])
            ->addRule('certificateExpiresAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Probe monitors: expiry date of the TLS certificate the last check saw, in ISO 8601 format. Null without TLS.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('cooldown', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum seconds between two team notifications of this monitor firing. Events fire on every change regardless.',
                'default' => 3600,
                'example' => 3600,
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the monitor is evaluated.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('notify', [
                'type' => self::TYPE_STRING,
                'description' => 'Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Empty when the alert events are the only delivery.',
                'default' => ['owner'],
                'example' => ['owner'],
                'array' => true,
            ])
            ->addRule('severity', [
                'type' => self::TYPE_ENUM,
                'description' => 'How urgent the monitor\'s alerts are: `info`, `warning`, `error` or `critical`. Critical alerts may be delivered with higher urgency by the notification channels that support it.',
                'default' => Severity::Warning->value,
                'example' => Severity::Warning->value,
                'enum' => Severity::values(),
                'enumSDKName' => 'MonitorSeverity',
            ])
            ->addRule('critical', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Deprecated: use `severity`. Whether the monitor\'s severity is `critical`.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the monitor is firing, as of its last evaluation.',
                'default' => Status::Ok->value,
                'example' => Status::Ok->value,
                'enum' => Status::values(),
                'enumSDKName' => 'MonitorStatus',
            ])
            ->addRule('value', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Metric value at the last evaluation. Null before the first evaluation, and when the last evaluation (`evaluatedAt`) found no usage recorded to read: without data a monitor is not compared, so it keeps its status and open alert.',
                'default' => null,
                'required' => false,
                'example' => 1250000,
            ])
            ->addRule('evaluatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Last evaluation date in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('alertId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the alert the monitor is firing for. Empty while it is not firing.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('triggeredAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the monitor last announced an alert, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'Monitor';
    }

    public function getType(): string
    {
        return Response::MODEL_MONITOR;
    }

    /**
     * `critical` predates `severity` and stays for clients that read it. Headers are stored as a JSON object.
     */
    public function filter(Document $document): Document
    {
        $document->setAttribute('critical', $document->getAttribute('severity') === Severity::Critical->value);
        $document->setAttribute('headers', (object) (\json_decode((string) $document->getAttribute('headers', ''), true) ?: []));
        if ($document->getAttribute('result') === '') {
            $document->setAttribute('result', null);
        }

        return $document;
    }
}
