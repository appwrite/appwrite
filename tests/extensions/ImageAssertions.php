<?php

namespace Appwrite\Tests;

use PHPUnit\Framework\Assert;

final class ImageAssertions
{
    public static function assertSamePixels(string $expectedImagePath, string $actualImageBlob): void
    {
        $expected = new \Imagick($expectedImagePath);
        $actual = new \Imagick();
        $actual->readImageBlob($actualImageBlob);

        foreach ([$expected, $actual] as $image) {
            // Normalize to PNG and strip metadata to avoid nondeterministic chunks
            $image->setImageFormat('PNG');
            $image->stripImage();
            // Exclude time and profile chunks that vary between builds
            $image->setOption('png:exclude-chunks', 'date,time,iCCP,sRGB,gAMA,cHRM');
        }

        Assert::assertSame($expected->getImageSignature(), $actual->getImageSignature());
    }
}