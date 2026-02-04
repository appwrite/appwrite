<?php

namespace Appwrite\Vcs;

class Domain
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
     * Sanitize a branch name for use in a domain name.
     * Replaces any characters that are not alphanumeric or hyphens with hyphens,
     * and removes leading/trailing hyphens.
     *
     * @param string $branch The branch name to sanitize
     * @return string The sanitized branch name
     */
    public static function sanitizeBranchName(string $branch): string
    {
        // Replace any sequence of invalid characters with a single hyphen
        $sanitized = preg_replace('/[^a-zA-Z0-9-]+/', '-', $branch);
        // Remove leading and trailing hyphens
        return trim($sanitized, '-');
    }

    /**
     * Generate a branch prefix for domain name from a branch name.
     * Takes up to 16 characters, sanitizes them for domain use,
     * and appends a hash suffix if the branch name is longer than 16 characters.
     *
     * @param string $branch The branch name
     * @return string The branch prefix for domain name
     */
    public static function generateBranchPrefix(string $branch): string
    {
        $branchPrefix = substr($branch, 0, self::BRANCH_PREFIX_MAX_LENGTH);
        $branchPrefix = self::sanitizeBranchName($branchPrefix);

        if (strlen($branch) > self::BRANCH_PREFIX_MAX_LENGTH) {
            $remainingChars = substr($branch, self::BRANCH_PREFIX_MAX_LENGTH);
            $branchPrefix .= '-' . substr(hash('sha256', $remainingChars), 0, self::HASH_SUFFIX_LENGTH);
        }

        return $branchPrefix;
    }

    /**
     * Generate a full branch preview domain name.
     *
     * @param string $branch The branch name
     * @param string $resourceId The resource ID (site or function)
     * @param string $projectId The project ID
     * @param string $sitesDomain The base sites domain
     * @return string The full domain name
     */
    public static function generateBranchDomain(string $branch, string $resourceId, string $projectId, string $sitesDomain): string
    {
        $branchPrefix = self::generateBranchPrefix($branch);
        $resourceProjectHash = substr(hash('sha256', $resourceId . $projectId), 0, self::HASH_SUFFIX_LENGTH);

        return "branch-{$branchPrefix}-{$resourceProjectHash}.{$sitesDomain}";
    }
}
