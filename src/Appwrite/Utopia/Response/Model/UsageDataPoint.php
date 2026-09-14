<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model as ResponseModel;

class UsageDataPoint extends ResponseModel
{
    public function __construct()
    {
        $this
            ->addRule('time', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Bucket start timestamp in ISO 8601. Omitted for flat dimension aggregates.',
                'required' => false,
                'default' => null,
                'example' => '2026-04-09T12:00:00.000+00:00',
            ])
            ->addRule('value', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Aggregated value for the point.',
                'default' => 0,
                'example' => 5000,
            ]);

        foreach ([
            'path' => '/v1/storage/files',
            'method' => 'POST',
            'status' => '201',
            'service' => 'storage',
            'country' => 'us',
            'region' => 'default',
            'hostname' => 'app.example.com',
            'ip' => '192.0.2.44',
            'osName' => 'iOS',
            'clientType' => 'browser',
            'clientName' => 'Chrome',
            'sdk' => 'web',
            'sdkVersion' => '14.0.0',
            'deviceName' => 'smartphone',
            'resourceId' => 'abc123',
            'resourceType' => 'bucket',
            'ordinal' => '0',
        ] as $name => $example) {
            $this->addRule($name, [
                'type' => self::TYPE_STRING,
                'description' => "Value when broken down by `{$name}`.",
                'required' => false,
                'default' => null,
                'example' => $example,
            ]);
        }
    }

    public function getName(): string
    {
        return Response::MODEL_USAGE_DATA_POINT;
    }

    public function getType(): string
    {
        return Response::MODEL_USAGE_DATA_POINT;
    }
}
