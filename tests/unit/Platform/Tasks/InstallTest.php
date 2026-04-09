<?php

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\Install;
use PHPUnit\Framework\TestCase;

class InstallTest extends TestCase
{
    public function testResolveComposeAppwriteImageUsesLocalImageForDevBuilds(): void
    {
        $this->assertSame('appwrite-dev', $this->resolveComposeAppwriteImageForTest(false, 'appwrite', 'appwrite', 'dev', true));
    }

    public function testShouldNotReuseExistingConfigForDevTaggedCompose(): void
    {
        $this->assertFalse($this->shouldReuseExistingConfigForTest(false, false, true, 'appwrite/appwrite:dev'));
    }

    public function testShouldReuseExistingConfigForLocalAppwriteImageReference(): void
    {
        $this->assertTrue($this->shouldReuseExistingConfigForTest(false, false, true, 'appwrite-dev'));
    }

    private function resolveComposeAppwriteImageForTest(bool $isLocalInstall, string $organization, string $image, string $version, bool $useLocalAppwriteImage): string
    {
        $install = new class ($useLocalAppwriteImage) extends Install {
            public function __construct(private bool $useLocalAppwriteImage)
            {
            }

            public function resolveComposeAppwriteImageForTest(bool $isLocalInstall, string $organization, string $image, string $version): string
            {
                return $this->resolveComposeAppwriteImage($isLocalInstall, $organization, $image, $version);
            }

            protected function shouldUseLocalAppwriteImage(): bool
            {
                return $this->useLocalAppwriteImage;
            }
        };

        return $install->resolveComposeAppwriteImageForTest($isLocalInstall, $organization, $image, $version);
    }

    private function shouldReuseExistingConfigForTest(bool $isLocalInstall, bool $isUpgrade, bool $configFilesExist, ?string $existingImage = null): bool
    {
        $install = new class () extends Install {
            public function shouldReuseExistingConfigForTest(bool $isLocalInstall, bool $isUpgrade, bool $configFilesExist, ?string $existingImage = null): bool
            {
                return $this->shouldReuseExistingConfig($isLocalInstall, $isUpgrade, $configFilesExist, $existingImage);
            }
        };

        return $install->shouldReuseExistingConfigForTest($isLocalInstall, $isUpgrade, $configFilesExist, $existingImage);
    }
}
