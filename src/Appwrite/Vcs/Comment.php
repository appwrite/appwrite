<?php

namespace Appwrite\Vcs;

use Utopia\Database\Document;

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

        $text .= "> **Your function has automatically been deployed.** Learn more about Appwrite Function Deployments in our [documentation](https://appwrite.io/docs/functions).\n\n";

        foreach ($projects as $projectId => $project) {
            $text .= "**{$project['name']}** `{$projectId}`\n\n";
            $text .= "| Function | ID | Status | Build Logs |\n";
            $text .= "| :- | :-  | :-  | :- |\n";

            foreach ($project['functions'] as $functionId => $function) {
                $status = match ($function['status']) {
                    'waiting' => '⌛ Waiting to build',
                    'processing' => '🤔 Processing',
                    'building' => '🛠️ Building',
                    'ready' => '✅ Ready',
                    'failed' => '❌ Failed',
                };

                $logs = $function['status'] === 'ready' ? "[View output](#)" : '_Build must be ready first_';

                $text .= "| {$function['name']} | `{$functionId}` | {$status} | {$logs} |\n";
            }

            $text .= "\n";
        }

        $text .= "> **💡 Did you know?** \n Appwrite has a discord community with XX members. [Come join us!](https://appwrite.io/discord).\n\n";

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
