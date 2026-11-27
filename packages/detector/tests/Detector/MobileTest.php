<?php

namespace Utopia\Detector\Tests\Detector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Detector\Detection\Mobile\Android;
use Utopia\Detector\Detection\Mobile as MobileDetection;
use Utopia\Detector\Detection\Mobile\Flutter;
use Utopia\Detector\Detection\Mobile\Ios;
use Utopia\Detector\Detection\Mobile\ReactNative;
use Utopia\Detector\Detector\Mobile;

class MobileTest extends TestCase
{
    private const string FLUTTER_PUBSPEC = <<<'YAML'
        name: my_app
        description: A new Flutter project.
        publish_to: 'none'
        version: 1.0.0+1

        environment:
          sdk: ^3.9.0

        dependencies:
          flutter:
            sdk: flutter
          cupertino_icons: ^1.0.8

        dev_dependencies:
          flutter_test:
            sdk: flutter
          flutter_lints: ^6.0.0

        flutter:
          uses-material-design: true
        YAML;

    private const string REACT_NATIVE_PACKAGE = <<<'JSON'
        {
          "name": "MyApp",
          "version": "0.0.1",
          "private": true,
          "scripts": {
            "android": "react-native run-android",
            "ios": "react-native run-ios"
          },
          "dependencies": {
            "react": "19.2.0",
            "react-native": "0.88.1"
          },
          "devDependencies": {
            "@react-native-community/cli": "20.0.0",
            "@react-native/metro-config": "0.88.1"
          }
        }
        JSON;

    private const string ANDROID_GROOVY = <<<'GROOVY'
        apply plugin: "com.android.application"
        apply plugin: "org.jetbrains.kotlin.android"
        apply plugin: "com.facebook.react"

        android {
            namespace "com.myapp"
        }
        GROOVY;

    private const string ANDROID_KOTLIN = <<<'KOTLIN'
        plugins {
            id("com.android.application")
            id("kotlin-android")
            id("dev.flutter.flutter-gradle-plugin")
        }

        android {
            namespace = "com.example.my_app"
            compileSdk = flutter.compileSdkVersion
        }
        KOTLIN;

    /**
     * @param array<string> $files
     * @param array<string> $manifests
     * @param array<string> $scripts
     */
    #[DataProvider('detectionDataProvider')]
    public function testDetect(string $assertion, array $files, array $manifests, array $scripts, ?string $mobile): void
    {
        $detector = $this->detector([new Flutter(), new ReactNative(), new Android(), new Ios()]);

        $this->addInputs($detector, $files, $manifests, $scripts);

        $this->assertSame($mobile, $detector->detect()?->getName(), $assertion);
    }

    /**
     * @return array<array{string, array<string>, array<string>, array<string>, string|null}>
     */
    public static function detectionDataProvider(): array
    {
        return [
            [
                'flutter app',
                ['pubspec.yaml', 'pubspec.lock', 'lib', 'android', 'ios'],
                [self::FLUTTER_PUBSPEC],
                [],
                'flutter',
            ],
            [
                'flutter app with its android module',
                ['pubspec.yaml', 'android', 'ios'],
                [self::FLUTTER_PUBSPEC],
                [self::ANDROID_KOTLIN],
                'flutter',
            ],
            [
                'flutter app with its ios project listed',
                ['pubspec.yaml', 'ios/Runner.xcodeproj', 'ios/Runner.xcworkspace', 'ios/Podfile'],
                [self::FLUTTER_PUBSPEC],
                [],
                'flutter',
            ],
            [
                'flutter pubspec in flow style',
                ['pubspec.yaml'],
                ["name: my_app\ndependencies: {flutter: {sdk: flutter}}\n"],
                [],
                'flutter',
            ],
            [
                'dart package',
                ['pubspec.yaml', 'lib', 'bin'],
                ["name: my_cli\ndependencies:\n  args: ^2.7.0\ndev_dependencies:\n  lints: ^6.0.0\n"],
                [],
                null,
            ],
            [
                'flutter only in dev dependencies',
                ['pubspec.yaml'],
                ["name: my_lints\ndev_dependencies:\n  flutter:\n    sdk: flutter\n"],
                [],
                null,
            ],
            [
                'commented out flutter dependency',
                ['pubspec.yaml'],
                ["name: my_cli\ndependencies:\n  # flutter:\n  #   sdk: flutter\n  args: ^2.7.0\n"],
                [],
                null,
            ],
            [
                'unparsable pubspec',
                ['pubspec.yaml'],
                ["dependencies: {flutter: {sdk: flutter}\n"],
                [],
                null,
            ],
            [
                'react native app',
                ['package.json', 'index.js', 'metro.config.js', 'android', 'ios'],
                [self::REACT_NATIVE_PACKAGE],
                [],
                'react-native',
            ],
            [
                'react native app with its android module',
                ['package.json', 'android', 'ios'],
                [self::REACT_NATIVE_PACKAGE],
                [self::ANDROID_GROOVY],
                'react-native',
            ],
            [
                'expo app',
                ['package.json', 'app.json'],
                ['{"dependencies":{"expo":"~55.0.0","react":"19.2.0","react-native":"0.88.1"}}'],
                [],
                'react-native',
            ],
            [
                'react native library',
                ['package.json', 'src', 'example'],
                ['{"peerDependencies":{"react-native":"*"},"devDependencies":{"react-native":"0.88.1"}}'],
                [],
                null,
            ],
            [
                'react web app',
                ['package.json', 'vite.config.ts'],
                ['{"dependencies":{"react":"19.2.0","react-dom":"19.2.0"}}'],
                [],
                null,
            ],
            [
                'android app with apply plugin',
                ['build.gradle', 'settings.gradle', 'gradlew', 'app'],
                [],
                ["apply plugin: 'com.android.application'\n\nandroid {\n    compileSdk 36\n}\n"],
                'android',
            ],
            [
                'android app with a groovy plugins block',
                ['build.gradle', 'app'],
                [],
                ["plugins {\n    id 'com.android.application'\n}\n"],
                'android',
            ],
            [
                'android app with a kotlin plugins block',
                ['build.gradle.kts', 'app'],
                [],
                [self::ANDROID_KOTLIN],
                'android',
            ],
            [
                'android root build declaring the plugin',
                ['build.gradle.kts', 'settings.gradle.kts', 'app'],
                [],
                ["plugins {\n    id(\"com.android.application\") version \"9.5.1\" apply false\n}\n"],
                'android',
            ],
            [
                'android version catalog',
                ['build.gradle.kts', 'gradle'],
                [],
                [
                    "plugins {\n    alias(libs.plugins.android.application)\n}\n",
                    "[versions]\nagp = \"9.5.1\"\n\n[plugins]\nandroid-application = { id = \"com.android.application\", version.ref = \"agp\" }\n",
                ],
                'android',
            ],
            [
                'android app with windows line endings',
                ['build.gradle'],
                [],
                ["plugins {\r\n    id 'com.android.application'\r\n}\r\n"],
                'android',
            ],
            [
                'android library',
                ['build.gradle.kts'],
                [],
                ["plugins {\n    id(\"com.android.library\")\n}\n"],
                null,
            ],
            [
                'jvm gradle project',
                ['build.gradle.kts', 'settings.gradle.kts', 'gradlew', 'src'],
                [],
                ["plugins {\n    kotlin(\"jvm\") version \"2.2.20\"\n    application\n}\n"],
                null,
            ],
            [
                'commented out android plugin',
                ['build.gradle.kts'],
                [],
                ["plugins {\n    // id(\"com.android.application\")\n    id(\"com.android.library\")\n}\n"],
                null,
            ],
            [
                'android plugin inside a block comment',
                ['build.gradle'],
                [],
                ["/*\nplugins {\n    id 'com.android.application'\n}\n*/\napply plugin: 'java'\n"],
                null,
            ],
            [
                'android plugin inside a single line block comment',
                ['build.gradle'],
                [],
                ["/* id 'com.android.application' */\napply plugin: 'java'\n"],
                null,
            ],
            [
                'android plugin id inside a longer string',
                ['build.gradle'],
                [],
                ["description = 'migrated from com.android.application'\nid 'com.android.application.custom'\n"],
                null,
            ],
            [
                'ios app',
                ['MyApp.xcodeproj', 'MyApp', 'MyAppTests'],
                [],
                [],
                'ios',
            ],
            [
                'ios app with a workspace and cocoapods',
                ['MyApp.xcworkspace', 'Podfile', 'Podfile.lock'],
                [],
                [],
                'ios',
            ],
            [
                'ios app with cocoapods only',
                ['Podfile'],
                [],
                [],
                'ios',
            ],
            [
                'ios project listed as a directory path',
                ['./MyApp.xcodeproj/'],
                [],
                [],
                'ios',
            ],
            [
                'swift package',
                ['Package.swift', 'Sources', 'Tests'],
                [],
                [],
                null,
            ],
            [
                'native android and ios projects in one repository',
                ['ios/MyApp.xcodeproj'],
                [],
                [self::ANDROID_KOTLIN],
                'android',
            ],
            [
                'web project',
                ['package.json', 'index.html', 'composer.json'],
                ['{"dependencies":{"vue":"^3.5.0"}}'],
                [],
                null,
            ],
            [
                'empty manifest',
                ['package.json'],
                [''],
                [],
                null,
            ],
            [
                'no inputs',
                [],
                [],
                [],
                null,
            ],
        ];
    }

    /**
     * @param array<MobileDetection> $options
     */
    #[DataProvider('precedenceDataProvider')]
    public function testCrossPlatformFrameworkWinsWhateverTheOptionOrder(array $options): void
    {
        $detector = $this->detector($options);

        $this->addInputs(
            $detector,
            ['pubspec.yaml', 'ios/Runner.xcodeproj', 'ios/Podfile'],
            [self::FLUTTER_PUBSPEC],
            [self::ANDROID_KOTLIN],
        );

        $this->assertSame('flutter', $detector->detect()?->getName());
    }

    /**
     * @return array<string, array{array<MobileDetection>}>
     */
    public static function precedenceDataProvider(): array
    {
        return [
            'framework first' => [[new Flutter(), new Android(), new Ios()]],
            'framework last' => [[new Android(), new Ios(), new Flutter()]],
            'framework between natives' => [[new Ios(), new Flutter(), new Android()]],
        ];
    }

    /**
     * @param array<string> $platforms
     */
    #[DataProvider('platformsDataProvider')]
    public function testPlatforms(MobileDetection $detection, array $platforms): void
    {
        $this->assertSame($platforms, $detection->getPlatforms());
    }

    /**
     * @return array<string, array{MobileDetection, array<string>}>
     */
    public static function platformsDataProvider(): array
    {
        return [
            'flutter' => [new Flutter(), [MobileDetection::PLATFORM_ANDROID, MobileDetection::PLATFORM_IOS]],
            'react native' => [new ReactNative(), [MobileDetection::PLATFORM_ANDROID, MobileDetection::PLATFORM_IOS]],
            'android' => [new Android(), [MobileDetection::PLATFORM_ANDROID]],
            'ios' => [new Ios(), [MobileDetection::PLATFORM_IOS]],
        ];
    }

    public function testDetectionDefaults(): void
    {
        $this->assertSame(['flutter'], new Flutter()->getPackages());
        $this->assertSame([], new Flutter()->getFiles());
        $this->assertSame([], new Flutter()->getFileExtensions());
        $this->assertSame([], new Flutter()->getPlugins());
        $this->assertSame(['com.android.application'], new Android()->getPlugins());
        $this->assertSame(['Podfile'], new Ios()->getFiles());
        $this->assertSame(['xcodeproj', 'xcworkspace'], new Ios()->getFileExtensions());
        $this->assertSame(['react-native'], new ReactNative()->getPackages());
        $this->assertSame('android', MobileDetection::PLATFORM_ANDROID);
        $this->assertSame('ios', MobileDetection::PLATFORM_IOS);
        $this->assertSame('gradle', Mobile::INPUT_GRADLE);
    }

    public function testCustomOptionsAreAskedThroughTheirMethods(): void
    {
        $expo = new class () extends MobileDetection {
            public function getName(): string
            {
                return 'expo';
            }

            public function getPlatforms(): array
            {
                return [self::PLATFORM_ANDROID, self::PLATFORM_IOS, 'web'];
            }

            public function getFiles(): array
            {
                return ['app.json'];
            }
        };
        $detector = $this->detector([new ReactNative(), new Android(), $expo]);

        $this->addInputs($detector, ['package.json', 'app.json'], [self::REACT_NATIVE_PACKAGE], [self::ANDROID_GROOVY]);

        $this->assertSame($expo, $detector->detect());
    }

    public function testSubclassedDetectionsKeepTheirOverrides(): void
    {
        $android = new class () extends Android {
            public function getPlugins(): array
            {
                return ['com.android.library'];
            }
        };
        $detector = $this->detector([$android]);

        $this->addInputs($detector, [], [], ["plugins {\n    id(\"com.android.library\")\n}\n"]);

        $this->assertSame($android, $detector->detect());
    }

    public function testSubclassedDetectorKeepsItsOverrides(): void
    {
        $commented = $this->overridingDetector();
        $commented->addInput("// id 'com.android.application'", Mobile::INPUT_GRADLE);

        $this->assertSame('android', $commented->detect()?->getName());

        $listed = $this->overridingDetector();
        $listed->addInput("// id 'com.android.application'", Mobile::INPUT_GRADLE);
        $listed->addInput('cupertino_icons, flutter', Mobile::INPUT_PACKAGES);

        $this->assertSame('flutter', $listed->detect()?->getName());
    }

    public function testRejectsInvalidInputType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid input type 'language'");

        new Mobile()->addInput('Kotlin', 'language');
    }

    /**
     * @param array<MobileDetection> $options
     */
    private function detector(array $options): Mobile
    {
        $detector = new Mobile();

        foreach ($options as $option) {
            $detector->addOption($option);
        }

        return $detector;
    }

    /**
     * @param array<string> $files
     * @param array<string> $manifests
     * @param array<string> $scripts
     */
    private function addInputs(Mobile $detector, array $files, array $manifests, array $scripts): void
    {
        foreach ($files as $file) {
            $detector->addInput($file, Mobile::INPUT_FILE);
        }

        foreach ($manifests as $manifest) {
            $detector->addInput($manifest, Mobile::INPUT_PACKAGES);
        }

        foreach ($scripts as $script) {
            $detector->addInput($script, Mobile::INPUT_GRADLE);
        }
    }

    /**
     * A detector whose manifests are comma separated names and whose Gradle comments count.
     */
    private function overridingDetector(): Mobile
    {
        $detector = new class () extends Mobile {
            protected function dependencies(string $manifest): array
            {
                return \array_map(trim(...), \explode(',', $manifest));
            }

            protected function statements(string $script): array
            {
                return \explode("\n", $script);
            }

            protected function declares(array $plugins, array $statements): bool
            {
                return parent::declares($plugins, $statements);
            }
        };
        $detector->addOption(new Android())->addOption(new Flutter());

        return $detector;
    }
}
