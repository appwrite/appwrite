<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Client\ClientInterface;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Storage\Acl;
use Utopia\Storage\DeviceType;

class AWS extends S3
{
    /**
     * AWS Regions constants
     */
    public const US_EAST_1 = 'us-east-1';

    public const US_EAST_2 = 'us-east-2';

    public const US_WEST_1 = 'us-west-1';

    public const US_WEST_2 = 'us-west-2';

    public const AF_SOUTH_1 = 'af-south-1';

    public const AP_EAST_1 = 'ap-east-1';

    public const AP_SOUTH_1 = 'ap-south-1';

    public const AP_NORTHEAST_3 = 'ap-northeast-3';

    public const AP_NORTHEAST_2 = 'ap-northeast-2';

    public const AP_NORTHEAST_1 = 'ap-northeast-1';

    public const AP_SOUTHEAST_1 = 'ap-southeast-1';

    public const AP_SOUTHEAST_2 = 'ap-southeast-2';

    public const CA_CENTRAL_1 = 'ca-central-1';

    public const EU_CENTRAL_1 = 'eu-central-1';

    public const EU_WEST_1 = 'eu-west-1';

    public const EU_SOUTH_1 = 'eu-south-1';

    public const EU_WEST_2 = 'eu-west-2';

    public const EU_WEST_3 = 'eu-west-3';

    public const EU_NORTH_1 = 'eu-north-1';

    public const SA_EAST_1 = 'eu-north-1';

    public const CN_NORTH_1 = 'cn-north-1';

    public const CN_NORTH_4 = 'cn-north-4';

    public const CN_NORTHWEST_1 = 'cn-northwest-1';

    public const ME_SOUTH_1 = 'me-south-1';

    public const US_GOV_EAST_1 = 'us-gov-east-1';

    public const US_GOV_WEST_1 = 'us-gov-west-1';

    /**
     * AWS Constructor
     */
    public function __construct(
        string $root,
        string $accessKey,
        #[\SensitiveParameter]
        string $secretKey,
        string $bucket,
        string $region = self::US_EAST_1,
        ?Acl $acl = Acl::Private,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        $host = match ($region) {
            self::CN_NORTH_1, self::CN_NORTH_4, self::CN_NORTHWEST_1 => $bucket . '.s3.' . $region . '.amazonaws.cn',
            default => $bucket . '.s3.' . $region . '.amazonaws.com'
        };
        parent::__construct($root, $accessKey, $secretKey, $host, $region, $acl, $client, $bucket);
    }

    #[\Override]
    public function getType(): DeviceType
    {
        return DeviceType::AwsS3;
    }
}
