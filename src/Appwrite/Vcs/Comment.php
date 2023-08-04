<?php

namespace Appwrite\Vcs;

use Utopia\Database\Document;
use Utopia\App;

class Comment
{
    protected string $statePrefix = '[appwrite]: #';

    /**
     * @var mixed[] $builds
     */
    protected array $builds = [];

    public function isEmpty(): bool
    {
        return \count($this->builds) === 0;
    }

    public function addBuild(Document $project, Document $function, string $buildStatus, string $deploymentId): void
    {
        // Unique index
        $id = $project->getId() . '_' . $function->getId();

        $this->builds[$id] = [
            'projectName' => $project->getAttribute('name'),
            'projectId' => $project->getId(),
            'functionName' => $function->getAttribute('name'),
            'functionId' => $function->getId(),
            'buildStatus' => $buildStatus,
            'deploymentId' => $deploymentId
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
                'deploymentId' => $build['deploymentId']
            ];
        }

        //TODO: Update link to documentation
        $text .= "**Your function has automatically been deployed.** Learn more about Appwrite Function Deployments in our [documentation](https://appwrite.io/docs/functions).\n\n";

        foreach ($projects as $projectId => $project) {
            $text .= "**{$project['name']}** `{$projectId}`\n\n";
            $text .= "| Function | ID | Status | Build Logs |\n";
            $text .= "| :- | :-  | :-  | :- |\n";

            $protocol = App::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $hostname = App::getEnv('_APP_DOMAIN');

            foreach ($project['functions'] as $functionId => $function) {
                $status = match ($function['status']) {
                    'waiting' => '<img src="' . $protocol . '://' . $hostname . '/state-waiting.png" alt="Waiting" height="25" align="center"> Waiting to build',
                    'processing' => '<img src="' . $protocol . '://' . $hostname . '/animation-building.gif" alt="Processing" height="29" align="center"> Processing',
                    'building' => '<img src="' . $protocol . '://' . $hostname . '/animation-building.gif" alt="Building" height="29" align="center"> Building',
                    'ready' => '<img src="' . $protocol . '://' . $hostname . '/state-success.png" alt="Ready" height="25" align="center"> Ready',
                    'failed' => '<img src="' . $protocol . '://' . $hostname . '/state-failed.png" alt="Failed" height="25" align="center"> Failed',
                };
                //TODO: Update names of images

                $logs = '[View output](' . $protocol . '://' . $hostname . '/console/project-' . $projectId . '/functions/function-' . $functionId . '/deployment-' . $function['deploymentId'] . ')';

                $text .= "| {$function['name']} | `{$functionId}` | {$status} | {$logs} |\n";
            }

            $text .= "\n\n";
        }
        //TODO: Update did you know section
        $text .= "> **💡 Did you know?** \n Appwrite has a discord community with XX members. [Come join us!](https://appwrite.io/discord)\n\n";

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
