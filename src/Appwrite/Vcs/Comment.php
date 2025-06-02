<?php

namespace Appwrite\Vcs;

use Utopia\Database\Document;
use Utopia\System\System;

// TODO this class should be moved to a more appropriate place in the architecture

class Comment
{
    // TODO: Add more tips
    protected array $tips = [
        'Appwrite has a Discord community with over 16 000 members.',
        'You can use Avatars API to generate QR code for any text or URLs.',
        'Cursor pagination performs better than offset pagination when loading further pages.',
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
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $hostname = System::getEnv('_APP_DOMAIN');

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
                    $extension = $site['status'] === 'building' ? 'gif' : 'png';

                    $imageStatus = in_array($site['status'], ['processing', 'building']) ? 'building' : $site['status'];

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
        $text .= "\n<br>\n\n> [!NOTE]\n> $tip\n\n";

        return $text;
    }

    public function generatImage(string $pathLight, string $pathDark, string $alt, int $width): string
    {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_DOMAIN');

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
