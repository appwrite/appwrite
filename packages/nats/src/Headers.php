<?php

declare(strict_types=1);

namespace Utopia\NATS;

use Utopia\NATS\Exception\ProtocolException;

final class Headers implements \IteratorAggregate, \Countable
{
    /** @var array<string, list<string>> */
    private array $headers = [];
    private string $status = '';
    private string $description = '';

    public function set(string $name, string $value): self
    {
        $this->headers[$name] = [$value];
        return $this;
    }

    public function add(string $name, string $value): self
    {
        $this->headers[$name][] = $value;
        return $this;
    }

    public function get(string $name): ?string
    {
        return $this->headers[$name][0] ?? null;
    }

    /** @return list<string> */
    public function getAll(string $name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function delete(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->headers;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status, string $description = ''): self
    {
        $this->status = $status;
        $this->description = $description;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function toWire(): string
    {
        $result = 'NATS/1.0';
        if ($this->status !== '') {
            $result .= ' ' . $this->status;
            if ($this->description !== '') {
                $result .= ' ' . $this->description;
            }
        }
        $result .= "\r\n";

        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $result .= "{$name}: {$value}\r\n";
            }
        }

        return $result . "\r\n";
    }

    public static function fromWire(string $raw): self
    {
        $headers = new self();

        // explode() never returns an empty array -- on '' it returns [''] -- so
        // testing the result could not reject an empty block, and one fell through
        // to the version check below and was reported as an invalid version.
        if ($raw === '') {
            throw new ProtocolException('Empty header block');
        }

        $lines = explode("\r\n", $raw);

        // Parse status line: "NATS/1.0" or "NATS/1.0 503" or "NATS/1.0 503 No Responders"
        $statusLine = array_shift($lines);
        if (!str_starts_with($statusLine, 'NATS/1.0')) {
            throw new ProtocolException("Invalid header version: {$statusLine}");
        }

        $remainder = trim(substr($statusLine, 8));
        if ($remainder !== '') {
            $spacePos = strpos($remainder, ' ');
            if ($spacePos !== false) {
                $headers->status = substr($remainder, 0, $spacePos);
                $headers->description = substr($remainder, $spacePos + 1);
            } else {
                $headers->status = $remainder;
            }
        }

        // Parse header lines
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $name = substr($line, 0, $colonPos);
            $value = ltrim(substr($line, $colonPos + 1));
            $headers->headers[$name][] = $value;
        }

        return $headers;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->headers);
    }

    public function count(): int
    {
        return \count($this->headers);
    }
}
