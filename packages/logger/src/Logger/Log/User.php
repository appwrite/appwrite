<?php

namespace Utopia\Logger\Log;

class User
{
    /**
     * @var string|null (optional, for example 'abcd1234')
     */
    protected ?string $userId;

    /**
     * @var string|null (optional, for example 'matej@appwrite.io')
     */
    protected ?string $userEmail;

    /**
     * @var string|null (optional, for example 'Matej Bačo')
     */
    protected ?string $userName;

    /**
     * User constructor.
     *
     * @param  string|null  $userId
     * @param  string|null  $userEmail
     * @param  string|null  $userName
     */
    public function __construct(string $userId = null, string $userEmail = null, string $userName = null)
    {
        $this->userId = $userId;
        $this->userEmail = $userEmail;
        $this->userName = $userName;
    }

    /**
     * Get user's identifier
     *
     * @return string|null
     */
    public function getId(): ?string
    {
        return $this->userId;
    }

    /**
     * Get user's email
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->userEmail;
    }

    /**
     * Get user's name
     *
     * @return string|null
     */
    public function getUsername(): ?string
    {
        return $this->userName;
    }
}
