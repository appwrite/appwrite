<?php

namespace Utopia\Logger\Log;

use Exception;
use Utopia\Logger\Log;

class Breadcrumb
{
    /**
     * @var string (required, for example 'Log::TYPE_ERROR')
     */
    protected string $type;

    /**
     * @var string (required, for example 'auth')
     */
    protected string $category;

    /**
     * @var string (required, for example 'User is logged in')
     */
    protected string $message;

    /**
     * @var float (required, microtime as float)
     */
    protected float $timestamp;

    /**
     * Breadcrumb constructor.
     *
     * @param  string  $type
     * @param  string  $category
     * @param  string  $message
     * @param  float  $timestamp
     *
     * @throws Exception
     */
    public function __construct(string $type, string $category, string $message, float $timestamp)
    {
        $this->type = $type;
        $this->category = $category;
        $this->message = $message;
        $this->timestamp = $timestamp;

        switch ($this->getType()) {
            case Log::TYPE_DEBUG:
            case Log::TYPE_ERROR:
            case Log::TYPE_INFO:
            case Log::TYPE_WARNING:
            case Log::TYPE_VERBOSE:
                break;
            default:
                throw new Exception('Type has to be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_INFO, Log::TYPE_WARNING, Log::TYPE_VERBOSE.');
        }
    }

    /**
     * Get breadcrumb type
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get breadcrumb category
     *
     * @return string
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Get breadcrumb message
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get breadcrumb timestamp
     *
     * @return float
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}
