<?php

namespace Tests\Compat;

/**
 * Per-driver state: the namespace isolating this run's resources, the service
 * endpoints, and objects created by earlier operations (handles).
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $handles = [];

    private int $next = 0;

    /**
     * @param string $ns Prefix every key, table, file or queue an operation creates must carry.
     * @param array<string, string> $services Service endpoints for this runtime.
     */
    public function __construct(
        public readonly string $ns = 'compat',
        public readonly array $services = [],
    ) {
    }

    /**
     * Stores an object and returns its handle.
     *
     * @return array{'$handle': string}
     */
    public function handle(mixed $object): array
    {
        $id = 'h' . ++$this->next;
        $this->handles[$id] = $object;

        return ['$handle' => $id];
    }

    public function get(mixed $handle): mixed
    {
        $id = \is_array($handle) ? ($handle['$handle'] ?? null) : null;
        if (!\is_string($id) || !\array_key_exists($id, $this->handles)) {
            throw new Fault('expected a handle, got ' . json_encode($handle));
        }

        return $this->handles[$id];
    }

    public function service(string $name): string
    {
        return $this->services[$name] ?? throw new Fault("service `{$name}` is not configured for this run");
    }
}
