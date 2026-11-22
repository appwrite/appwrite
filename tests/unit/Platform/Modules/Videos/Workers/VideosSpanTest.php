<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Videos\Workers;

use Appwrite\Platform\Modules\Videos\Workers\Videos;
use PHPUnit\Framework\TestCase;

final class VideosSpanTest extends TestCase
{
    private function scrub(string $text): string
    {
        $worker = (new \ReflectionClass(Videos::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(Videos::class, 'withoutSignatures'))->invoke($worker, $text);
    }

    public function testCommandLineLosesTheEphemeralKeyHeader(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJwcm9qZWN0SWQiOiJwMSJ9.c2lnbmF0dXJl';
        $command = "ffprobe -headers X-Appwrite-Project: p1\r\nX-Appwrite-Key: {$jwt}\r\n -i http://appwrite/v1/storage/buckets/b/files/f/download";

        $scrubbed = $this->scrub($command);

        $this->assertStringNotContainsString($jwt, $scrubbed);
        $this->assertStringContainsString('X-Appwrite-Key: [redacted]', $scrubbed);
        $this->assertStringContainsString('X-Appwrite-Project: p1', $scrubbed);
        $this->assertStringContainsString('http://appwrite/v1/storage/buckets/b/files/f/download', $scrubbed);
    }

    public function testOutputLosesABareJwt(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzY29wZXMiOlsiZmlsZXMucmVhZCJdfQ.c2ln';

        $this->assertSame('request with [redacted] failed', $this->scrub("request with {$jwt} failed"));
    }

    public function testPresignedUrlStillLosesItsSignature(): void
    {
        $this->assertSame(
            'ffmpeg -i https://s3.example.com/b/k',
            $this->scrub('ffmpeg -i https://s3.example.com/b/k?X-Amz-Signature=abc&X-Amz-Credential=def'),
        );
    }
}
