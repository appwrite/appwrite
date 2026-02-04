<?php

namespace Appwrite\Transformation\Adapter;

use Appwrite\Transformation\Adapter;
use Utopia\Validator\Text;

class BranchDomain extends Adapter
{
    /**
     * Maximum length for branch prefix in domain name
     */
    public const BRANCH_PREFIX_MAX_LENGTH = 16;

    /**
     * Length of hash suffix when branch name exceeds max length
     */
    public const HASH_SUFFIX_LENGTH = 7;

    /**
     * @param array<mixed> $traits
     */
    public function isValid(array $traits): bool
    {
        return true;
    }

    /**
     * Transform branch name into a valid domain name.
     *
     * Input should be an array with:
     * - 'branch' (string): The branch name
     * - 'resourceId' (string): The resource ID (site or function)
     * - 'projectId' (string): The project ID
     * - 'sitesDomain' (string): The base sites domain
     */
    public function transform(): void
    {
        $branch = $this->input['branch'] ?? '';
        $resourceId = $this->input['resourceId'] ?? '';
        $projectId = $this->input['projectId'] ?? '';
        $sitesDomain = $this->input['sitesDomain'] ?? '';

        $branchPrefix = $this->generateBranchPrefix($branch);
        $resourceProjectHash = substr(hash('sha256', $resourceId . $projectId), 0, self::HASH_SUFFIX_LENGTH);
        $this->output = strtolower("branch-{$branchPrefix}-{$resourceProjectHash}.{$sitesDomain}");
    }

    /**
     * Generate a branch prefix for domain name from a branch name.
     * Takes up to 16 characters, sanitizes them for domain use,
     * and appends a hash suffix if the branch name is longer than 16 characters.
     *
     * @param string $branch The branch name
     * @return string The branch prefix for domain name
     */
    private function generateBranchPrefix(string $branch): string
    {
        $branchPrefix = substr($branch, 0, self::BRANCH_PREFIX_MAX_LENGTH);
        $branchPrefix = $this->sanitizeBranchName($branchPrefix);

        if (strlen($branch) > self::BRANCH_PREFIX_MAX_LENGTH) {
            $remainingChars = substr($branch, self::BRANCH_PREFIX_MAX_LENGTH);
            $branchPrefix .= '-' . substr(hash('sha256', $remainingChars), 0, self::HASH_SUFFIX_LENGTH);
        }

        return $branchPrefix;
    }

    /**
     * Sanitize a branch name for use in a domain name.
     * Replaces any characters that are not alphanumeric or hyphens with hyphens,
     * and removes leading/trailing hyphens.
     *
     * @param string $branch The branch name to sanitize
     * @return string The sanitized branch name
     */
    private function sanitizeBranchName(string $branch): string
    {
        $allowedChars = array_merge(
            Text::NUMBERS,
            Text::ALPHABET_UPPER,
            Text::ALPHABET_LOWER,
            ['-']
        );
        $allowedCharsFlip = array_flip($allowedChars);

        $sanitized = '';
        for ($i = 0; $i < \strlen($branch); $i++) {
            $char = $branch[$i];

            if (isset($allowedCharsFlip[$char])) {
                $sanitized .= $char;
            } else {
                // Prevents two -- or more in row
                if (strlen($sanitized) > 0 && $sanitized[strlen($sanitized) - 1] !== '-') {
                    $sanitized .= '-';
                }
            }
        }

        return trim($sanitized, '-');
    }
}
