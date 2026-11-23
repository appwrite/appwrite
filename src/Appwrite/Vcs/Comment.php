<?php

namespace Appwrite\Vcs;

use Utopia\Database\Document;
use Utopia\System\System;

// TODO this class should be moved to a more appropriate place in the architecture

class Comment
{
    public function __construct(
        private array $platform,
        private bool $withImages = true,
    ) {
    }

    protected string $statePrefix = '[appwrite]: #';

    /**
     * @var mixed[] $builds
     */
    protected array $builds = [];

    public function isEmpty(): bool
    {
        return \count($this->builds) === 0;
    }

    public function addBuild(Document $project, Document $resource, string $resourceType, string $buildStatus, string $deploymentId, array $action, string $previewUrl, string $commitHash): void
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
            'commitHash' => $commitHash,
        ];
    }

    public function generateComment(): string
    {
        $json = \json_encode([
            'builds' => $this->builds,
        ]);

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

            if ($build['resourceType'] === 'site' || $build['resourceType'] === 'function') {
                $projects[$build['projectId']][$build['resourceType']][$build['resourceId']] = [
                    'name' => $build['resourceName'],
                    'region' => $build['region'],
                    'status' => $build['buildStatus'],
                    'deploymentId' => $build['deploymentId'],
                    'action' => $build['action'],
                    'previewUrl' => $build['previewUrl'] ?? '',
                    // A comment posted before commits were named has none until its next build
                    'commitHash' => $build['commitHash'] ?? '',
                ];
            }
        }

        // The heading says what the comment is, as the code review comment's does
        $text .= "## Deployments\n\n";

        $i = 0;
        foreach ($projects as $projectId => $project) {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $hostname = $this->platform['consoleHostname'] ?? '';

            $text .= "Project **{$project['name']}** · `{$projectId}`\n\n";

            $isOpen = $i === 0;

            foreach (['site' => 'Sites', 'function' => 'Functions'] as $type => $title) {
                $resources = $project[$type];

                if (\count($resources) === 0) {
                    continue;
                }

                $isSite = $type === 'site';
                $collection = $isSite ? 'sites' : 'functions';

                // Both tables read the same way: what it is, which commit, its status, then the links
                $columns = $isSite
                    ? ['Site', 'Commit', 'Status', 'Preview', 'QR', 'Details']
                    : ['Function', 'Commit', 'Status', 'Details'];

                $text .= "<details" . ($isOpen ? ' open' : '') . ">\n";
                $text .= "<summary>{$title} (" . \count($resources) . ")</summary>\n\n";
                $text .= "<br>\n\n";
                $text .= '| ' . \implode(' | ', $columns) . " |\n";
                $text .= '| ' . \implode(' | ', \array_fill(0, \count($columns), ':-')) . " |\n";

                foreach ($resources as $resourceId => $resource) {
                    $cells = [
                        "**{$resource['name']}**",
                        $resource['commitHash'] === '' ? '' : '`' . \substr($resource['commitHash'], 0, 7) . '`',
                        $this->generateStatus($resource['status']),
                    ];

                    if ($isSite) {
                        $qrImagePathLight = '/images/vcs/qr-light.svg';
                        $qrImagePathDark = '/images/vcs/qr-dark.svg';

                        $qrUrl = $protocol . '://' . $hostname . '/v1/avatars/qr?text=' . \urlencode($resource['previewUrl']);

                        $cells[] = '[Preview URL](' . $resource['previewUrl'] . ')';
                        $cells[] = $this->withImages
                            ? '[' . $this->generatImage($qrImagePathLight, $qrImagePathDark, 'QR Code', 28) . '](' . $qrUrl . ')'
                            : '[QR Code](' . $qrUrl . ')';
                    }

                    if ($resource['action']['type'] === 'logs') {
                        $logsUrl = ($this->platform['consoleUrl'] ?? '') . "/projects/{$projectId}/{$collection}/{$resourceId}/deployments/{$resource['deploymentId']}";
                        $cells[] = $resource['deploymentId'] === '' ? '' : "[View logs]({$logsUrl})";
                    } else {
                        $cells[] = '[Authorize](' . $resource['action']['url'] . ')';
                    }

                    $text .= '| ' . \implode(' | ', $cells) . " |\n";
                }

                $text .= "\n</details>\n\n";
            }

            $isLast = $i === \count($projects) - 1;

            if (\count($projects) > 1 && $isLast) {
                $text .= "---\n\n";
            }

            $i++;
        }

        $text .= "\n<br>\n\n> [!TIP]\n> {$this->generateTip()}\n\n";

        return $text;
    }

    /**
     * The status cell: the state image, then its label. A state with no image
     * shows the label alone.
     */
    private function generateStatus(string $status): string
    {
        $label = match ($status) {
            'waiting' => 'Queued',
            'processing' => 'Processing',
            'building' => 'Building',
            'ready' => 'Ready',
            'failed' => 'Failed',
            'canceled' => 'Canceled',
            default => '',
        };

        if ($label === '') {
            return '';
        }

        // Nothing is drawn for a canceled build yet
        if ($status === 'canceled') {
            return "_{$label}_";
        }

        $imageStatus = \in_array($status, ['processing', 'building']) ? 'building' : $status;

        $extension = $imageStatus === 'building' ? 'gif' : 'png';

        $pathLight = '/images/vcs/status-' . $imageStatus . '-light.' . $extension;
        $pathDark = '/images/vcs/status-' . $imageStatus . '-dark.' . $extension;

        return $this->generatImage($pathLight, $pathDark, $label, 85) . " _{$label}_";
    }

    /**
     * How to act on the comment, as the code review comment's tip says how to
     * re-run a review: what a push does, and where the comment is switched off.
     */
    private function generateTip(): string
    {
        $settings = 'under **Git automation** in the settings of each site or function';

        // One resource has one settings page to link
        if (\count($this->builds) === 1) {
            $build = \array_values($this->builds)[0];
            $collection = $build['resourceType'] === 'site' ? 'sites' : 'functions';
            $url = ($this->platform['consoleUrl'] ?? '') . "/projects/{$build['projectId']}/{$collection}/{$build['resourceId']}/settings/git";
            $settings = "in [Git automation]({$url})";
        }

        return "Push a commit to redeploy, or switch these comments off {$settings}.";
    }

    public function generatImage(string $pathLight, string $pathDark, string $alt, int $width): string
    {
        // Providers without comment image support get the textual cells only.
        if (!$this->withImages) {
            return '';
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $this->platform['consoleHostname'] ?? '';

        $imageLight = $protocol . '://' . $hostname . $pathLight;
        $imageDark = $protocol . '://' . $hostname . $pathDark;

        // absmiddle is vertical-align: middle; "center" puts the image's middle on the text baseline, so the label beside it rode high.
        $imageUrl = '<picture><source media="(prefers-color-scheme: dark)" srcset="' . $imageDark . '"><img alt="' . $alt . '" width="' . $width . '" align="absmiddle" src="' . $imageLight . '"></picture>';

        return $imageUrl;
    }

    public function parseComment(string $comment): self
    {
        $state = \explode("\n", $comment)[0];
        $state = substr($state, strlen($this->statePrefix));

        $json = \base64_decode($state);

        $data = \json_decode($json, true);

        if (\is_array($data) && \array_key_exists('builds', $data)) {
            $this->builds = \is_array($data['builds']) ? $data['builds'] : [];
        } else {
            // Backward compatibility with old state format (builds array only)
            $this->builds = \is_array($data) ? $data : [];
        }

        return $this;
    }
}
