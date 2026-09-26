<?php

namespace Utopia\Http\Tests;

use Utopia\Http\Adapter\FPM\Request as UtopiaFPMRequest;

class UtopiaFPMRequestTest extends UtopiaFPMRequest
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $params;

    /**
     * Get Param
     *
     * Get param by current method name
     *
     * @param  mixed  $default
     */
    #[\Override]
    public function getParam(string $key, $default = null): mixed
    {
        if ($this::_hasParams() && \in_array($key, $this::_getParams())) {
            return $this::_getParams()[$key];
        }

        return parent::getParam($key, $default);
    }

    /**
     * Get Params
     *
     * Get all params of current method
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function getParams(): array
    {
        $paramsArray = [];

        if ($this::_hasParams()) {
            $paramsArray = $this::_getParams();
        }

        return array_merge($paramsArray, parent::getParams());
    }

    /**
     * Function to set a response filter
     *
     * @param  array<string, mixed>|null  $params
     */
    public static function _setParams(?array $params): void
    {
        self::$params = $params;
    }

    /**
     * Return the currently set filter
     *
     * @return array<string, mixed>|null
     */
    public static function _getParams(): ?array
    {
        return self::$params;
    }

    /**
     * Check if a filter has been set
     */
    public static function _hasParams(): bool
    {
        return self::$params != null;
    }
}
