<?php

declare(strict_types=1);

namespace Utopia\Storage;

enum DeviceType: string
{
    case Local = 'local';

    case S3 = 's3';

    case AwsS3 = 'awss3';

    case DoSpaces = 'dospaces';

    case Wasabi = 'wasabi';

    case Backblaze = 'backblaze';

    case Linode = 'linode';
}
