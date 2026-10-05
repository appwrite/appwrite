<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Client\ClientInterface;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Storage\Acl;
use Utopia\Storage\DeviceType;

class Wasabi extends S3
{
    /**
     * Regions constants
     */
    public const US_WEST_1 = 'us-west-1';

    public const AP_NORTHEAST_1 = 'ap-northeast-1';

    public const AP_NORTHEAST_2 = 'ap-northeast-2';

    public const EU_CENTRAL_1 = 'eu-central-1';

    public const EU_CENTRAL_2 = 'eu-central-2';

    public const EU_WEST_1 = 'eu-west-1';

    public const EU_WEST_2 = 'eu-west-2';

    public const US_CENTRAL_1 = 'us-central-1';

    public const US_EAST_1 = 'us-east-1';

    public const US_EAST_2 = 'us-east-2';

    /**
     * Wasabi Constructor
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
        $host = $bucket . '.' . 's3' . '.' . $region . '.' . 'wasabisys' . '.' . 'com';
        parent::__construct($root, $accessKey, $secretKey, $host, $region, $acl, $client, $bucket);
    }

    #[\Override]
    public function getType(): DeviceType
    {
        return DeviceType::Wasabi;
    }
}
