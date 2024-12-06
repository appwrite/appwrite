<?php

namespace Appwrite\Vcs;

use Utopia\Database\Document;
use Utopia\System\System;

// TODO this class should be moved to a more appropriate place in the architecture

class Comment
{
    protected array $tips = [
        'Appwrite has a Discord community with over 16 000 members. [Come join us!](https://appwrite.io/discord)',
        'You can use [Avatars API](https://appwrite.io/docs/client/avatars?sdk=web-default#avatarsGetQR) to generate QR code for any text or URLs',
        '[Cursor pagination](https://appwrite.io/docs/pagination#cursor-pagination) performs better than offset pagination when loading further pages',
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

    public function addBuild(Document $project, Document $resource, string $resourceType, string $buildStatus, string $deploymentId, array $action, string $previewUrl, string $previewQrCode): void
    {
        // Unique index
        $id = $project->getId() . '_' . $resource->getId();

        $this->builds[$id] = [
            'projectName' => $project->getAttribute('name'),
            'projectId' => $project->getId(),
            'resourceName' => $resource->getAttribute('name'),
            'resourceId' => $resource->getId(),
            'resourceType' => $resourceType,
            'buildStatus' => $buildStatus,
            'deploymentId' => $deploymentId,
            'action' => $action,
            'previewQrCode' => $previewQrCode,
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
                    'status' => $build['buildStatus'],
                    'deploymentId' => $build['deploymentId'],
                    'action' => $build['action'],
                    'previewUrl' => $build['previewUrl'],
                    'previewQrCode' => $build['previewQrCode']
                ];
            } elseif ($build['resourceType'] === 'function') {
                $projects[$build['projectId']]['function'][$build['resourceId']] = [
                    'name' => $build['resourceName'],
                    'status' => $build['buildStatus'],
                    'deploymentId' => $build['deploymentId'],
                    'action' => $build['action'],
                ];
            }
        }

        foreach ($projects as $projectId => $project) {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $hostname = System::getEnv('_APP_DOMAIN');

            $text .= "Project name: **{$project['name']}** \nProject ID: `{$projectId}`\n\n";

            if (\count($project['site']) > 0) {

                $text .= "| Site | ID | Status | Previews | Action |\n";
                $text .= "| :- | :-  | :-  | :-  | :- |\n";

                foreach ($project['site'] as $siteId => $site) {
                    $generateImage = function (string $status) use ($protocol, $hostname) {
                        $extention = $status === 'building' ? 'gif' : 'png';
                        $imagesUrl = $protocol . '://' . $hostname . '/console/images/vcs/';
                        $imageUrl = '<picture><source media="(prefers-color-scheme: dark)" srcset="' . $imagesUrl . 'status-' . $status . '-dark.' . $extention . '"><img alt="' . $status . '" height="25" align="center" src="' . $imagesUrl . 'status-' . $status . '-light.' . $extention . '"></picture>';

                        return $imageUrl;
                    };

                    $status = match ($site['status']) {
                        'waiting' => $generateImage('waiting') . ' Waiting to build',
                        'processing' => $generateImage('processing') . ' Processing',
                        'building' => $generateImage('building') . ' Building',
                        'ready' => $generateImage('ready') . ' Ready',
                        'failed' => $generateImage('failed') . ' Failed',
                    };

                    if ($site['action']['type'] === 'logs') {
                        $action = '[View Logs](' . $protocol . '://' . $hostname . '/console/project-' . $projectId . '/sites/site-' . $siteId . '/deployment-' . $site['deploymentId'] . ')';
                    } else {
                        $action = '[Authorize](' . $site['action']['url'] . ')';
                    }

                    $previews = '[Preview URL](' . $site['previewUrl'] . ') [QR Code](' . $site['previewQrCode'] . ')';

                    $text .= "| {$site['name']} | `{$siteId}` | {$status} | {$previews} | {$action} |\n";
                }

                $text .= "\n\n";
            }

            if (\count($project['function']) > 0) {

                $text .= "| Function | ID | Status | Action |\n";
                $text .= "| :- | :-  | :-  | :- |\n";

                foreach ($project['function'] as $functionId => $function) {
                    $generateImage = function (string $status) use ($protocol, $hostname) {
                        $extention = $status === 'building' ? 'gif' : 'png';
                        $imagesUrl = $protocol . '://' . $hostname . '/images/vcs/';
                        $imageUrl = '<picture><source media="(prefers-color-scheme: dark)" srcset="' . $imagesUrl . 'status-' . $status . '-dark.' . $extention . '"><img alt="' . $status . '" height="25" align="center" src="' . $imagesUrl . 'status-' . $status . '-light.' . $extention . '"></picture>';
                        return $imageUrl;
                    };

                    $status = match ($function['status']) {
                        'waiting' => $generateImage('waiting') . ' Waiting to build',
                        'processing' => $generateImage('processing') . ' Processing',
                        'building' => $generateImage('building') . ' Building',
                        'ready' => $generateImage('ready') . ' Ready',
                        'failed' => $generateImage('failed') . ' Failed',
                    };

                    if ($function['action']['type'] === 'logs') {
                        $action = '[View Logs](' . $protocol . '://' . $hostname . '/console/project-' . $projectId . '/functions/function-' . $functionId . '/deployment-' . $function['deploymentId'] . ')';
                    } else {
                        $action = '[Authorize](' . $function['action']['url'] . ')';
                    }

                    $text .= "| {$function['name']} | `{$functionId}` | {$status} | {$action} |\n";
                }

                $text .= "\n\n";
            }

        }

        $text .= "Only deployments on the production branch are activated automatically. Learn more about Appwrite [Functions](https://appwrite.io/docs/functions) and [Sites](https://appwrite.io/docs/sites).\n\n";

        $tip = $this->tips[array_rand($this->tips)];
        $text .= "> **💡 Did you know?** \n " . $tip . "\n\n";

        return $text;
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
