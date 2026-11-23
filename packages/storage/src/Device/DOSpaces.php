<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Client\ClientInterface;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Storage\Acl;
use Utopia\Storage\DeviceType;

class DOSpaces extends S3
{
    /**
     * Regions constants
     */
    public const SGP1 = 'sgp1';

    public const NYC3 = 'nyc3';

    public const FRA1 = 'fra1';

    public const SFO2 = 'sfo2';

    public const SFO3 = 'sfo3';

    public const AMS3 = 'AMS3';

    /**
     * DOSpaces Constructor
     */
    public function __construct(
        string $root,
        string $accessKey,
        #[\SensitiveParameter]
        string $secretKey,
        string $bucket,
        string $region = self::NYC3,
        ?Acl $acl = Acl::Private,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        $host = $bucket . '.' . $region . '.digitaloceanspaces.com';
        parent::__construct($root, $accessKey, $secretKey, $host, $region, $acl, $client, $bucket);
    }

    #[\Override]
    public function getType(): DeviceType
    {
        return DeviceType::DoSpaces;
    }
}
