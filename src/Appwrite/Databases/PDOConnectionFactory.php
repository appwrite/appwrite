<?php

namespace Appwrite\Databases;

use Utopia\Database\PDO;

class PDOConnectionFactory
{
    /**
     * Create a PDO connection with retry logic for transient connection failures.
     *
     * Retries on PDOException to handle transient errors such as ProxySQL
     * "Max connect timeout reached" (error 9001) or other temporary
     * connection pool exhaustion scenarios.
     *
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array $options
     * @param int $maxRetries Maximum number of retry attempts (0 = no retries)
     * @param int $retryDelayMs Initial delay between retries in milliseconds
     * @return PDO
     * @throws \PDOException If connection fails after all retry attempts
     */
    public static function create(
        string $dsn,
        string $username,
        string $password,
        array $options = [],
        int $maxRetries = 3,
        int $retryDelayMs = 100,
    ): PDO {
        $attempts = 0;

        while (true) {
            try {
                return new PDO($dsn, $username, $password, $options);
            } catch (\PDOException $e) {
                $attempts++;

                if ($attempts > $maxRetries) {
                    throw $e;
                }

                usleep($retryDelayMs * 1000 * $attempts);
            }
        }
    }
}
