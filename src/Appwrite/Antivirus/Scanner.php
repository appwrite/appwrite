<?php

namespace Appwrite\Antivirus;

use Utopia\Storage\Device;

class Scanner
{
    public function __construct(
        private readonly Client $client,
        private readonly int $contentLimit = APP_LIMIT_ANTIVIRUS,
    ) {
    }

    /**
     * Hash lookup first (any size, no file transfer). Content scan only when
     * the file is at or under the content limit — Defender buffers the body,
     * and remote devices have to download it.
     *
     * Device hashes that are not a whole-file MD5/SHA-1/SHA-256 (S3 multipart
     * ETags) skip the lookup and fall through to a content scan when size allows.
     *
     * @throws Exception
     */
    public function scan(Device $device, string $path, int $fileSize, string $hash): Result
    {
        $result = null;

        if (self::isDigest($hash)) {
            $result = $this->client->scanHash($hash, $fileSize);
            if ($result->isInfected()) {
                return $result;
            }
        }

        if ($fileSize > $this->contentLimit) {
            return $result ?? new Result(Result::CLEAN);
        }

        return $this->client->scan($device->read($path));
    }

    public static function isDigest(string $hash): bool
    {
        $length = \strlen($hash);

        return ($length === 32 || $length === 40 || $length === 64) && \ctype_xdigit($hash);
    }
}
