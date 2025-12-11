<?php

use Utopia\System\System;

/**
 * Platform configuration
 */
return [
    'domain' => System::getEnv('_APP_DOMAIN', 'localhost'),
    'consoleDomain' => System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', 'localhost')),
    'platformName' => APP_EMAIL_PLATFORM_NAME,
    'logoUrl' => APP_EMAIL_LOGO_URL,
    'accentColor' => APP_EMAIL_ACCENT_COLOR,
    'footerImageUrl' => APP_EMAIL_FOOTER_IMAGE_URL,
    'twitterUrl' => APP_SOCIAL_TWITTER,
    'discordUrl' => APP_SOCIAL_DISCORD,
    'githubUrl' => APP_SOCIAL_GITHUB,
    'termsUrl' => APP_EMAIL_TERMS_URL,
    'privacyUrl' => APP_EMAIL_PRIVACY_URL,
    'websiteUrl' => 'https://' . APP_DOMAIN,
];
