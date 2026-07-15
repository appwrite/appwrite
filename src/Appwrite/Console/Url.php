<?php

namespace Appwrite\Console;

use Utopia\System\System;

/**
 * Builds console deep links for both the legacy `/console/...` SPA and vibes.
 *
 * Default scheme is `legacy` so cloud (still on console-cloud) keeps working when
 * it consumes server-ce. Self-hosted can opt into vibes paths via
 * `_APP_CONSOLE_URL_SCHEME=vibes`; vibes also rewrites legacy bookmarks.
 */
class Url
{
    public const SCHEME_LEGACY = 'legacy';
    public const SCHEME_VIBES = 'vibes';

    public static function scheme(): string
    {
        $scheme = System::getEnv('_APP_CONSOLE_URL_SCHEME', '');

        if ($scheme === self::SCHEME_VIBES || $scheme === self::SCHEME_LEGACY) {
            return $scheme;
        }

        return self::SCHEME_LEGACY;
    }

    public static function isVibes(): bool
    {
        return self::scheme() === self::SCHEME_VIBES;
    }

    public static function protocol(): string
    {
        return System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
    }

    public static function absolute(string $hostname, string $path, ?string $protocol = null): string
    {
        $protocol ??= self::protocol();
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return $protocol . '://' . $hostname . $path;
    }

    public static function auth(string $suffix): string
    {
        $suffix = \ltrim($suffix, '/');

        return self::isVibes()
            ? '/auth/' . $suffix
            : '/console/auth/' . $suffix;
    }

    public static function gitAuthorizeContributor(): string
    {
        return self::isVibes()
            ? '/git/authorize-contributor'
            : '/console/git/authorize-contributor';
    }

    public static function project(string $region, string $projectId, string $suffix = ''): string
    {
        $suffix = \ltrim($suffix, '/');

        if (self::isVibes()) {
            $base = '/projects/' . $projectId;
        } else {
            $base = '/console/project-' . $region . '-' . $projectId;
        }

        return $suffix === '' ? $base : $base . '/' . $suffix;
    }

    public static function projectResource(
        string $region,
        string $projectId,
        string $collection,
        string $resourceType,
        string $resourceId,
        string $suffix = '',
    ): string {
        $suffix = \ltrim($suffix, '/');

        if (self::isVibes()) {
            $resourcePath = $collection . '/' . $resourceId;
        } else {
            $resourcePath = $collection . '/' . $resourceType . '-' . $resourceId;
        }

        if ($suffix !== '') {
            $resourcePath .= '/' . $suffix;
        }

        return self::project($region, $projectId, $resourcePath);
    }

    public static function siteDeployment(
        string $region,
        string $projectId,
        string $siteId,
        string $deploymentId,
    ): string {
        if (self::isVibes()) {
            return self::project($region, $projectId, "sites/{$siteId}/deployments/{$deploymentId}");
        }

        return self::project(
            $region,
            $projectId,
            "sites/site-{$siteId}/deployments/deployment-{$deploymentId}",
        );
    }

    public static function siteDeployments(
        string $region,
        string $projectId,
        string $siteId,
    ): string {
        if (self::isVibes()) {
            return self::project($region, $projectId, "sites/{$siteId}/deployments");
        }

        return self::project($region, $projectId, "sites/site-{$siteId}/deployments");
    }

    public static function functionDeployment(
        string $region,
        string $projectId,
        string $functionId,
        string $deploymentId,
    ): string {
        if (self::isVibes()) {
            return self::project(
                $region,
                $projectId,
                "functions/{$functionId}/deployments/{$deploymentId}",
            );
        }

        // Legacy console used a sibling /deployment-{id} segment (not /deployments/).
        return self::project(
            $region,
            $projectId,
            "functions/function-{$functionId}/deployment-{$deploymentId}",
        );
    }

    public static function webhookSettings(
        string $region,
        string $projectId,
        string $webhookId,
    ): string {
        if (self::isVibes()) {
            return self::project($region, $projectId, 'settings/webhooks');
        }

        return self::project($region, $projectId, 'settings/webhooks/' . $webhookId);
    }

    public static function gitInstallations(string $region, string $projectId): string
    {
        return self::project($region, $projectId, 'settings/git-installations');
    }
}
