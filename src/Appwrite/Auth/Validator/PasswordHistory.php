<?php

namespace Appwrite\Auth\Validator;

use Utopia\Auth\Proofs\Password as ProofsPassword;

/**
 * Password.
 *
 * Validates user password string
 */
class PasswordHistory extends Password
{
    protected array $history;
    protected string $algo;
    protected array $algoOptions;

    public function __construct(array $history, string $algo, array $algoOptions = [])
    {
        parent::__construct();

        $this->history = $history;
        $this->algo = $algo;
        $this->algoOptions = $algoOptions;
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Password shouldn\'t be in the history.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        $proofForPassword = ProofsPassword::createHash($this->algo, $this->algoOptions);

        foreach ($this->history as $hash) {
            if (!empty($hash) && $proofForPassword->verify($value, $hash)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
