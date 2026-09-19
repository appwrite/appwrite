<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Client\ClientInterface;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Storage\Acl;
use Utopia\Storage\DeviceType;

class Linode extends S3
{
    /**
     * Regions constants
     */
    public const EU_CENTRAL_1 = 'eu-central-1';

    public const US_SOUTHEAST_1 = 'us-southeast-1';

    public const US_EAST_1 = 'us-east-1';

    public const AP_SOUTH_1 = 'ap-south-1';

    /**
     * Object Storage Constructor
     */
    public function __construct(
        string $root,
        string $accessKey,
        #[\SensitiveParameter]
        string $secretKey,
        string $bucket,
        string $region = self::EU_CENTRAL_1,
        ?Acl $acl = Acl::Private,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        $host = $bucket . '.' . $region . '.' . 'linodeobjects.com';
        parent::__construct($root, $accessKey, $secretKey, $host, $region, $acl, $client, $bucket);
    }

    #[\Override]
    public function getType(): DeviceType
    {
        return DeviceType::Linode;
    }
}
