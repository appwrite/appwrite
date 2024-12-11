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

    public function addBuild(Document $project, Document $function, string $buildStatus, string $deploymentId, array $action): void
    {
        // Unique index
        $id = $project->getId() . '_' . $function->getId();

        $this->builds[$id] = [
            'projectName' => $project->getAttribute('name'),
            'projectId' => $project->getId(),
            'functionName' => $function->getAttribute('name'),
            'functionId' => $function->getId(),
            'buildStatus' => $buildStatus,
            'deploymentId' => $deploymentId,
            'action' => $action,
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
                    'functions' => []
                ];
            }

            $projects[$build['projectId']]['functions'][$build['functionId']] = [
                'name' => $build['functionName'],
                'status' => $build['buildStatus'],
                'deploymentId' => $build['deploymentId'],
                'action' => $build['action'],
            ];
        }

        foreach ($projects as $projectId => $project) {
            $text .= "**{$project['name']}** `{$projectId}`\n\n";
            $text .= "| Function | ID | Status | Action |\n";
            $text .= "| :- | :-  | :-  | :- |\n";

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $hostname = System::getEnv('_APP_DOMAIN');

            foreach ($project['functions'] as $functionId => $function) {
                if ($function['status'] === 'waiting' || $function['status'] === 'processing' || $function['status'] === 'building') {
                    $text .= "**Your function deployment is in progress. Please check back in a few minutes for the updated status.**\n\n";
                } elseif ($function['status'] === 'ready') {
                    $text .= "**Your function has been successfully deployed.**\n\n";
                } else {
                    $text .= "**Your function deployment has failed. Please check the logs for more details and retry.**\n\n";
                }

                $text .= "Project name: **{$project['name']}** \nProject ID: `{$projectId}`\n\n";
                $text .= "| Function | ID | Status | Action |\n";
                $text .= "| :- | :-  | :-  | :- |\n";

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
        $functionUrl = $protocol . '://' . $hostname . '/console/project-' . $projectId . '/functions/function-' . $functionId;
        $text .= "Only deployments on the production branch are activated automatically. If you'd like to activate this deployment, navigate to [your deployments]($functionUrl). Learn more about Appwrite [Function deployments](https://appwrite.io/docs/functions).\n\n";

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
