<?php

use Appwrite\Distribution\Framework;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provider;
use Utopia\System\System;

return [
    'images' => [
        Platform::Android->value => System::getEnv('_APP_DISTRIBUTION_IMAGE_ANDROID', 'appwrite/distribution-android:1.0.0'),
        'tools' => System::getEnv('_APP_DISTRIBUTION_IMAGE_TOOLS', 'appwrite/distribution-tools:1.0.0'),
    ],
    'frameworks' => [
        Framework::Flutter->value => [
            Platform::Android->value => [
                'install' => 'flutter pub get',
                'build' => 'flutter build appbundle --release',
                'output' => 'build/app/outputs/bundle/release/*.aab',
            ],
            Platform::Ios->value => [
                'install' => 'flutter pub get && flutter precache --ios',
                'build' => 'flutter build ios --release --no-codesign',
                'output' => '',
            ],
        ],
        Framework::ReactNative->value => [
            Platform::Android->value => [
                'install' => 'npm ci',
                'build' => 'cd android && ./gradlew --no-daemon bundleRelease',
                'output' => 'android/app/build/outputs/bundle/release/*.aab',
            ],
            Platform::Ios->value => [
                'install' => 'npm ci && cd ios && pod install',
                'build' => '',
                'output' => '',
            ],
        ],
        Framework::Android->value => [
            Platform::Android->value => [
                'install' => '',
                'build' => './gradlew --no-daemon bundleRelease',
                'output' => 'app/build/outputs/bundle/release/*.aab',
            ],
        ],
        Framework::Ios->value => [
            Platform::Ios->value => [
                'install' => '',
                'build' => '',
                'output' => '',
            ],
        ],
    ],
    'specifications' => [
        'd-4vcpu-8gb' => ['cpus' => 4, 'memory' => 8192],
        'd-8vcpu-16gb' => ['cpus' => 8, 'memory' => 16384],
    ],
    'tracks' => [
        Provider::Apple->value => ['testflight', 'testflight-external', 'production'],
        Provider::Google->value => ['internal', 'alpha', 'beta', 'production'],
    ],
];
