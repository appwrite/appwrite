<?php

namespace Appwrite\Vcs;

use Utopia\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * Only GitHub implements check runs, so a false return means fall back to the
 * commit status.
 */
class CheckRuns
{
    public const string CONCLUSION_NEUTRAL = 'neutral';
    public const string CONCLUSION_ACTION_REQUIRED = 'action_required';

    protected const int NAME_LIMIT = 255;

    /**
     * Owners that refused a run, so a fan-out pays one rejected call, not one each.
     *
     * @var array<string, true>
     */
    protected array $refused = [];

    public function supports(Git $vcs): bool
    {
        return $vcs instanceof GitHub;
    }

    public function conclude(
        Git $vcs,
        string $owner,
        string $repositoryName,
        string $commitHash,
        string $name,
        string $conclusion,
        string $title,
        string $summary,
        string $detailsUrl = '',
    ): bool {
        if (empty($commitHash) || !$this->supports($vcs) || empty($owner) || empty($repositoryName) || isset($this->refused[$owner])) {
            return false;
        }

        try {
            $vcs->createCheckRun(
                owner: $owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: \mb_strimwidth($name, 0, self::NAME_LIMIT, '...'),
                status: 'completed',
                conclusion: $conclusion,
                title: $title,
                summary: $summary,
                detailsUrl: $detailsUrl,
            );

            return true;
        } catch (\Throwable $error) {
            if ($error->getCode() === 403) {
                $this->refused[$owner] = true;
            }

            Console::warning("Failed to create check run on {$owner}/{$repositoryName}: " . $error->getMessage());

            return false;
        }
    }
}
