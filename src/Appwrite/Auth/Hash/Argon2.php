<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * Argon2 accepted options:
 * int threads
 * int time_cost
 * int memory_cost
 *
 * Reference: https://www.php.net/manual/en/function.password-hash.php#example-983
*/
class Argon2 extends Hash
{
    /**
     * @param string $password Input password to hash
     *
     * @return string hash
     */
    public function hash(string $password): string
    {
        $options = $this->normalizeOptions($this->getOptions());
        return \password_hash($password, PASSWORD_ARGON2ID, $options);
    }

    /**
     * @param string $password Input password to validate
     * @param string $hash Hash to verify password against
     *
     * @return boolean true if password matches hash
     */
    public function verify(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }

    /**
     * Get default options for specific hashing algo
     *
     * @return array options named array
     */
    public function getDefaultOptions(): array
    {
        return ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
    }

    /**
     * Normalize options to convert between camelCase and snake_case formats
     * This ensures backward compatibility with existing hash options
     *
     * @param array $options
     * @return array normalized options
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];
        
        // Handle both camelCase (API format) and snake_case (internal format)
        $normalized['memory_cost'] = $options['memory_cost'] ?? $options['memoryCost'] ?? $this->getDefaultOptions()['memory_cost'];
        $normalized['time_cost'] = $options['time_cost'] ?? $options['timeCost'] ?? $this->getDefaultOptions()['time_cost'];
        $normalized['threads'] = $options['threads'] ?? $this->getDefaultOptions()['threads'];
        
        return $normalized;
    }
}
