<?php

namespace Appwrite\Exception;

class Certificate extends \Exception
{
    private $incrementAttempts = false;

    // Only set incrementAttempts to TRUE if exception occured AFTER certbot command execution
    public function __construct(string $message, bool $incrementAttempts = false)
    {
        $this->incrementAttempts = $incrementAttempts;

        parent::__construct($message);
    }

    /**
     * Get value if attempts should be incremented.
     * 
     * @return boolean
     */ 
    public function getIncrementAttempts(): bool
    {
        return $this->incrementAttempts;
    }

    /**
     * Set if attempts should be incremented
     * 
     * @param boolean $value
     * 
     * @return void
     */
    public function setIncrementAttempts(bool $value): void
    {
        $this->incrementAttempts = $value;
    }
}