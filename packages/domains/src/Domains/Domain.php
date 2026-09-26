<?php

namespace Utopia\Domains;

use Exception;

class Domain
{
    /**
     * @var array<string, array{suffix: string, type: string, comments: string[]}>
     */
    protected static $list = [];

    /**
     * Domain
     */
    protected string $domain;

    /**
     * TLD
     *
     * @var string
     */
    protected $TLD = '';

    /**
     * Suffix
     *
     * @var string
     */
    protected $suffix = '';

    /**
     * Name
     *
     * @var string
     */
    protected $name = '';

    /**
     * Sub Domain
     *
     * @var string
     */
    protected $sub = '';

    /**
     * PSL rule matching suffix
     *
     * @var string
     */
    protected $rule = '';

    /**
     * Domain Parts
     *
     * @var string[]
     */
    protected array $parts;

    /**
     * Domain constructor.
     */
    public function __construct(string $domain)
    {
        if ((str_starts_with($domain, 'http://')) || (str_starts_with($domain, 'https://'))) {
            throw new Exception("'{$domain}' must be a valid domain or hostname");
        }

        $this->domain = mb_strtolower($domain);
        $this->parts = explode('.', $this->domain);

        if (empty(self::$list)) {
            self::$list = include __DIR__ . '/../../data/data.php';
        }
    }

    /**
     * Return domain
     */
    public function get(): string
    {
        return $this->domain;
    }

    /**
     * Return apex domain
     */
    public function getApex(): string
    {
        return $this->getName() . '.' . $this->getSuffix();
    }

    /**
     * Return top level domain
     */
    public function getTLD(): string
    {
        if ($this->TLD) {
            return $this->TLD;
        }

        if ($this->parts === []) {
            return '';
        }

        $this->TLD = end($this->parts);

        return $this->TLD;
    }

    /**
     * Returns domain public suffix
     */
    public function getSuffix(): string
    {
        if ($this->suffix) {
            return $this->suffix;
        }
        $counter = \count($this->parts);

        for ($i = 0; $i < $counter; $i++) {
            $joined = implode('.', \array_slice($this->parts, $i));
            $next = implode('.', \array_slice($this->parts, $i + 1));
            $exception = '!' . $joined;
            $wildcard = '*.' . $next;

            if (\array_key_exists($exception, self::$list)) {
                $this->suffix = $next;
                $this->rule = $exception;

                return $next;
            }

            if (\array_key_exists($joined, self::$list)) {
                $this->suffix = $joined;
                $this->rule = $joined;

                return $joined;
            }

            if (\array_key_exists($wildcard, self::$list)) {
                $this->suffix = $joined;
                $this->rule = $wildcard;

                return $joined;
            }
        }

        return '';
    }

    public function getRule(): string
    {
        if (! $this->rule) {
            $this->getSuffix();
        }
        return $this->rule;
    }

    /**
     * Returns registerable domain name
     */
    public function getRegisterable(): string
    {
        if (! $this->isKnown()) {
            return '';
        }

        return $this->getName() . '.' . $this->getSuffix();
    }

    /**
     * Returns domain name
     */
    public function getName(): string
    {
        if ($this->name) {
            return $this->name;
        }

        $suffix = $this->getSuffix();
        $suffix = ($suffix === '' || $suffix === '0') ? '.' . $this->getTLD() : '.' . $suffix;

        $name = explode('.', mb_substr($this->domain, 0, mb_strlen($suffix) * -1));

        $this->name = end($name);

        return $this->name;
    }

    /**
     * Returns sub-domain name
     */
    public function getSub(): string
    {
        $name = $this->getName();
        $name = ($name === '' || $name === '0') ? '' : '.' . $name;

        $suffix = $this->getSuffix();
        $suffix = ($suffix === '' || $suffix === '0') ? '.' . $this->getTLD() : '.' . $suffix;

        $domain = $name . $suffix;

        $sub = explode('.', mb_substr($this->domain, 0, mb_strlen($domain) * -1));

        $this->sub = implode('.', $sub);

        return $this->sub;
    }

    /**
     * Returns true if the public suffix is found;
     */
    public function isKnown(): bool
    {
        return \array_key_exists($this->getRule(), self::$list);
    }

    /**
     * Returns true if the public suffix is found using ICANN domains section
     */
    public function isICANN(): bool
    {
        return isset(self::$list[$this->getRule()]) && self::$list[$this->getRule()]['type'] === 'ICANN';
    }

    /**
     * Returns true if the public suffix is found using PRIVATE domains section
     */
    public function isPrivate(): bool
    {
        return isset(self::$list[$this->getRule()]) && self::$list[$this->getRule()]['type'] === 'PRIVATE';
    }

    /**
     * Returns true if the public suffix is reserved for testing purpose
     */
    public function isTest(): bool
    {
        return \in_array($this->getTLD(), ['test', 'localhost']);
    }
}
