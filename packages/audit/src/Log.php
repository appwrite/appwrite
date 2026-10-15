<?php

namespace Utopia\Audit;

use ArrayObject;

/**
 * Audit Log
 *
 * Represents a single audit log entry with structured data.
 * Extends ArrayObject to provide array-like access while maintaining type safety.
 *
 * @extends ArrayObject<string, mixed>
 */
class Log extends ArrayObject
{
    /**
     * Construct a new audit log object.
     *
     * @param array<string, mixed> $input
     */
    public function __construct(array $input = [])
    {
        parent::__construct($input);
    }

    /**
     * Get the log ID.
     */
    public function getId(): string
    {
        $id = $this->getAttribute('$id', '');
        return \is_string($id) ? $id : '';
    }

    /**
     * Get the user ID associated with this log entry.
     */
    public function getUserId(): ?string
    {
        $userId = $this->getAttribute('userId');
        return \is_string($userId) ? $userId : null;
    }

    /**
     * Get the actor ID associated with this log entry.
     */
    public function getActorId(): ?string
    {
        $actorId = $this->getAttribute('actorId');
        return \is_string($actorId) ? $actorId : null;
    }

    /**
     * Get the actor type associated with this log entry.
     */
    public function getActorType(): ?string
    {
        $actorType = $this->getAttribute('actorType');
        return \is_string($actorType) ? $actorType : null;
    }

    /**
     * Get the actor internal ID associated with this log entry.
     */
    public function getActorInternalId(): ?string
    {
        $actorInternalId = $this->getAttribute('actorInternalId');
        return \is_string($actorInternalId) ? $actorInternalId : null;
    }

    /**
     * Get the event name.
     */
    public function getEvent(): string
    {
        $event = $this->getAttribute('event', '');
        return \is_string($event) ? $event : '';
    }

    /**
     * Get the resource identifier.
     */
    public function getResource(): string
    {
        $resource = $this->getAttribute('resource', '');
        return \is_string($resource) ? $resource : '';
    }

    /**
     * Get the SDK name associated with this log entry.
     *
     * Optional column: returns null when the SDK was never recorded, mirroring
     * the other nullable actor columns (getActorId/getActorInternalId).
     */
    public function getSdk(): ?string
    {
        $sdk = $this->getAttribute('sdk');
        return \is_string($sdk) ? $sdk : null;
    }

    /**
     * Get the SDK version associated with this log entry.
     *
     * Optional column: returns null when the SDK version was never recorded.
     */
    public function getSdkVersion(): ?string
    {
        $sdkVersion = $this->getAttribute('sdkVersion');
        return \is_string($sdkVersion) ? $sdkVersion : null;
    }

    /**
     * Get the parsed user-agent OS short code (e.g. `IOS`, `WIN`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getOsCode(): ?string
    {
        $value = $this->getAttribute('osCode');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent OS name (e.g. `iOS`, `Windows`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getOsName(): ?string
    {
        $value = $this->getAttribute('osName');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent OS version (e.g. `17.4`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getOsVersion(): ?string
    {
        $value = $this->getAttribute('osVersion');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent client type (e.g. `browser`, `library`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getClientType(): ?string
    {
        $value = $this->getAttribute('clientType');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent client short code (e.g. `MF`, `CH`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getClientCode(): ?string
    {
        $value = $this->getAttribute('clientCode');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent client name (e.g. `Mobile Safari`, `Chrome`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getClientName(): ?string
    {
        $value = $this->getAttribute('clientName');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent client version (e.g. `17.4`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getClientVersion(): ?string
    {
        $value = $this->getAttribute('clientVersion');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent client engine (e.g. `WebKit`, `Blink`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getClientEngine(): ?string
    {
        $value = $this->getAttribute('clientEngine');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent client engine version (e.g. `605.1.15`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getClientEngineVersion(): ?string
    {
        $value = $this->getAttribute('clientEngineVersion');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent device type (e.g. `smartphone`, `desktop`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getDeviceName(): ?string
    {
        $value = $this->getAttribute('deviceName');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent device brand (e.g. `Apple`, `Samsung`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getDeviceBrand(): ?string
    {
        $value = $this->getAttribute('deviceBrand');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the parsed user-agent device model (e.g. `iPhone`).
     *
     * ClickHouse-only optional column: returns null when it was never recorded.
     */
    public function getDeviceModel(): ?string
    {
        $value = $this->getAttribute('deviceModel');
        return \is_string($value) ? $value : null;
    }

    /**
     * Get the user agent string.
     */
    public function getUserAgent(): string
    {
        $userAgent = $this->getAttribute('userAgent', '');
        return \is_string($userAgent) ? $userAgent : '';
    }

    /**
     * Get the IP address.
     */
    public function getIp(): string
    {
        $ip = $this->getAttribute('ip', '');
        return \is_string($ip) ? $ip : '';
    }

    /**
     * Get the timestamp.
     */
    public function getTime(): string
    {
        $time = $this->getAttribute('time', '');
        return \is_string($time) ? $time : '';
    }

    /**
     * Get the additional data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $data = $this->getAttribute('data', []);
        return \is_array($data) ? $data : [];
    }

    /**
     * Get the tenant ID (for multi-tenant setups).
     */
    public function getTenant(): ?int
    {
        $tenant = $this->getAttribute('tenant');

        if ($tenant === null) {
            return null;
        }

        if (\is_int($tenant)) {
            return $tenant;
        }

        if (is_numeric($tenant)) {
            return (int) $tenant;
        }

        return null;
    }

    /**
     * Get an attribute by key.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->offsetExists($key) ? $this->offsetGet($key) : $default;
    }

    /**
     * Set an attribute.
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->offsetSet($key, $value);
        return $this;
    }

    /**
     * Remove an attribute.
     */
    public function removeAttribute(string $key): self
    {
        if ($this->offsetExists($key)) {
            $this->offsetUnset($key);
        }
        return $this;
    }

    /**
     * Check if an attribute exists.
     */
    public function isSet(string $key): bool
    {
        return $this->offsetExists($key);
    }

    /**
     * Get all attributes as an array.
     *
     * @return array<string, mixed>
     */
    public function getArrayCopy(): array
    {
        return parent::getArrayCopy();
    }
}
