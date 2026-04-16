<?php

namespace Appwrite\Vcs;

use Utopia\Database\Document;
use Utopia\System\System;

// TODO this class should be moved to a more appropriate place in the architecture

class Comment
{
    public function __construct(
        private array $platform
    ) {
    }

    // TODO: Add more tips
    protected array $tips = [
        'Appwrite has crossed the 50K GitHub stars milestone with hundreds of active contributors',
        'Our Discord community has grown to 24K developers, and counting',
        'Sites auto-generate unique domains with the pattern https://randomstring.appwrite.network',
        'Every Git commit and branch gets its own deployment URL automatically',
        'Custom domains work with both CNAME for subdomains and NS records for apex domains',
        'HTTPS and SSL certificates are handled automatically for all your Sites',
        'Functions can run for up to 15 minutes before timing out',
        'Schedule functions to run as often as every minute with cron expressions',
        'Environment variables can be scoped per function or shared across your project',
        'Function scopes give you fine-grained control over API permissions',
        'Sites support three domain rule types: Active deployment, Git branch, and Redirect',
        'Preview deployments create instant URLs for every branch and commit',
        'Trigger functions via HTTP, SDKs, events, webhooks, or scheduled cron jobs',
        'Each function runs in its own isolated container with custom environment variables',
        'Build commands execute in runtime containers during deployment',
        'Dynamic API keys are generated automatically for each function execution',
        'JWT tokens let functions act on behalf of users while preserving their permissions',
        'Storage files get ClamAV malware scanning and encryption by default',
        'Roll back Sites deployments instantly by switching between versions',
        'Git integration provides automatic deployments with optional PR comments',
        'Silent mode disables those chatty PR comments if you prefer peace and quiet',
        'Environment variable changes require redeployment to take effect',
        'SSR frameworks are fully supported with configurable build runtimes',
        'Global CDN and DDoS protection come free with every Sites deployment',
        'Deploy functions via zip upload or connect directly to your Git repo',
        'Realtime gives you live updates for users, storage, functions, and databases',
        'GraphQL API works alongside REST and WebSocket protocols',
        'Messaging handles push notifications, emails, and SMS through one unified API',
        'Teams feature lets you group users with membership management and role permissions',
        'MCP server integration brings LLM superpowers to Claude Desktop and Cursor IDE',
    ];

    protected string $statePrefix = '[appwrite]: #';

    /**
     * @var mixed[] $builds
     */
    protected array $builds = [];

    public function isEmpty(): bool
    {
        return \count($this->builds) === 0;
    }

    public function addBuild(Document $project, Document $resource, string $resourceType, string $buildStatus, string $deploymentId, array $action, string $previewUrl): void
    {
        // Unique index
        $id = $project->getId() . '_' . $resource->getId();

        $this->builds[$id] = [
            'projectName' => $project->getAttribute('name'),
            'projectId' => $project->getId(),
            'region' => $project->getAttribute('region', 'default'),
            'resourceName' => $resource->getAttribute('name'),
            'resourceId' => $resource->getId(),
            'resourceType' => $resourceType,
            'buildStatus' => $buildStatus,
            'deploymentId' => $deploymentId,
            'action' => $action,
            'previewUrl' => $previewUrl,
        ];
    }

    public function generateComment(): string
    {
        $json = \json_encode($this->builds);

        $text = $this->statePrefix . \base64_encode($json) . "\n\n";

        $projects = [];

        foreach ($this->builds as $id => $build) {
            if (!\array_key_exists($build['projectId'], $projects)) {
                $projects[$build['projectId']] = [
                    'name' => $build['projectName'],
                    'function' => [],
                    'site' => []
                ];
            }

            if ($build['resourceType'] === 'site') {
                $projects[$build['projectId']]['site'][$build['resourceId']] = [
                    'name' => $build['resourceName'],
                    'region' => $build['region'],
                    'status' => $build['buildStatus'],
                    'deploymentId' => $build['deploymentId'],
                    'action' => $build['action'],
                    'previewUrl' => $build['previewUrl'],
                ];
            } elseif ($build['resourceType'] === 'function') {
                $projects[$build['projectId']]['function'][$build['resourceId']] = [
                    'name' => $build['resourceName'],
                    'region' => $build['region'],
                    'status' => $build['buildStatus'],
                    'deploymentId' => $build['deploymentId'],
                    'action' => $build['action'],
                ];
            }
        }

        $i = 0;
        foreach ($projects as $projectId => $project) {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $hostname = $this->platform['consoleHostname'] ?? '';

            $text .= "## {$project['name']}\n\n";
            $text .= "Project ID: `{$projectId}`\n\n";

            $isOpen = $i === 0;

            if (\count($project['site']) > 0) {
                $text .= "<details" . ($isOpen ? ' open' : '') . ">\n";
                $text .= "<summary>Sites (" . \count($project['site']) . ")</summary>\n\n";
                $text .= "<br>\n\n";

                $text .= "| Site | Status | Logs | Preview | QR\n";
                $text .= "| :- | :-  | :-  | :-  | :- |\n";

                foreach ($project['site'] as $siteId => $site) {
                    $imageStatus = in_array($site['status'], ['processing', 'building']) ? 'building' : $site['status'];

                    $extension = $site['status'] === 'building' ? 'gif' : 'png';

                    $pathLight = '/images/vcs/status-' . $imageStatus . '-light.' . $extension;
                    $pathDark = '/images/vcs/status-' . $imageStatus . '-dark.' . $extension;

                    $status = match ($site['status']) {
                        'waiting' => $this->generatImage($pathLight, $pathDark, 'Queued', 85) . ' _Queued_',
                        'processing' => $this->generatImage($pathLight, $pathDark, 'Processing', 85) . ' _Processing_',
                        'building' => $this->generatImage($pathLight, $pathDark, 'Building', 85) . ' _Building_',
                        'ready' => $this->generatImage($pathLight, $pathDark, 'Ready', 85) . ' _Ready_',
                        'failed' => $this->generatImage($pathLight, $pathDark, 'Failed', 85) . ' _Failed_',
                    };

                    if ($site['action']['type'] === 'logs') {
                        $action = '[View Logs](' . $protocol . '://' . $hostname . '/console/project-' . $site['region'] . '-' . $projectId . '/sites/site-' . $siteId . '/deployments/deployment-' . $site['deploymentId'] . ')';
                    } else {
                        $action = '[Authorize](' . $site['action']['url'] . ')';
                    }

                    $qrImagePathLight = '/images/vcs/qr-light.svg';
                    $qrImagePathDark = '/images/vcs/qr-dark.svg';

                    $consoleUrl = $protocol . '://' . $hostname . '/v1/avatars/qr?text=' . \urlencode($site['previewUrl']);
                    $qr = '[' . $this->generatImage($qrImagePathLight, $qrImagePathDark, 'QR Code', 28) . '](' . $consoleUrl . ')';

                    $preview = '[Preview URL](' . $site['previewUrl'] . ')';

                    $text .= "| &nbsp;**{$site['name']}**<br>`$siteId`";
                    $text .= "| {$status}";
                    $text .= "| {$action}";
                    $text .= "| {$preview}";
                    $text .= "| {$qr}";
                    $text .= "|\n";
                }

                $text .= "\n</details>\n\n";
            }

            if (\count($project['function']) > 0) {
                $text .= "<details" . ($isOpen ? ' open' : '') . ">\n";
                $text .= "<summary>Functions (" . \count($project['function']) . ")</summary>\n\n";
                $text .= "<br>\n\n";
                $text .= "| Function | ID | Status | Logs |\n";
                $text .= "| :- | :-  | :-  | :- |\n";

                foreach ($project['function'] as $functionId => $function) {
                    $imageStatus = in_array($function['status'], ['processing', 'building']) ? 'building' : $function['status'];
                    $extension = $imageStatus === 'building' ? 'gif' : 'png';

                    $pathLight = '/images/vcs/status-' . $imageStatus . '-light.' . $extension;
                    $pathDark = '/images/vcs/status-' . $imageStatus . '-dark.' . $extension;

                    $status = match ($function['status']) {
                        'waiting' => $this->generatImage($pathLight, $pathDark, 'Queued', 85) . ' _Queued_',
                        'processing' => $this->generatImage($pathLight, $pathDark, 'Processing', 85) . ' _Processing_',
                        'building' => $this->generatImage($pathLight, $pathDark, 'Building', 85) . ' _Building_',
                        'ready' => $this->generatImage($pathLight, $pathDark, 'Ready', 85) . ' _Ready_',
                        'failed' => $this->generatImage($pathLight, $pathDark, 'Failed', 85) . ' _Failed_',
                    };

                    if ($function['action']['type'] === 'logs') {
                        $action = '[View Logs](' . $protocol . '://' . $hostname . '/console/project-' . $function['region'] . '-' . $projectId . '/functions/function-' . $functionId . '/deployment-' . $function['deploymentId'] . ')';
                    } else {
                        $action = '[Authorize](' . $function['action']['url'] . ')';
                    }

                    $text .= "| &nbsp;**{$function['name']}**";
                    $text .= "| `{$functionId}`";
                    $text .= "| {$status}";
                    $text .= "| {$action}";
                    $text .= "|\n";
                }

                $text .= "</details>\n\n";
            }

            $text .= "</details>\n\n";

            $isLast = $i === \count($projects) - 1;

            if (\count($projects) > 1 && $isLast) {
                $text .= "---\n\n";
            }

            $i++;
        }

        $tip = $this->tips[array_rand($this->tips)];
        $text .= "\n<br>\n\n> [!TIP]\n> $tip\n\n";

        return $text;
    }

    public function generatImage(string $pathLight, string $pathDark, string $alt, int $width): string
    {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $this->platform['consoleHostname'] ?? '';

        $imageLight = $protocol . '://' . $hostname . $pathLight;
        $imageDark = $protocol . '://' . $hostname . $pathDark;

        $imageUrl = '<picture><source media="(prefers-color-scheme: dark)" srcset="' . $imageDark . '"><img alt="' . $alt . '" width="' . $width . '" align="center" src="' . $imageLight . '"></picture>';

        return $imageUrl;
    }

    public function parseComment(string $comment): self
    {
        $state = \explode("\n", $comment)[0] ?? '';
        $state = substr($state, strlen($this->statePrefix));

        $json = \base64_decode($state);

        $builds = \json_decode($json, true);
        $this->builds = $builds;

        return $this;
    }
}
