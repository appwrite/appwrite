<?php

namespace Tests\Compat;

/**
 * A library adapter with benchmarks of its hot paths, the same names and
 * work as `BENCHES` in crates/tools/compat/src/libs/<lib>.rs.
 */
interface Benchmarks
{
    /**
     * Benchmark name => [description, setup]. The setup builds what the
     * benchmark needs and returns the closure to time, so only the library
     * call is measured.
     *
     * @return array<string, array{0: string, 1: callable(Session): (callable(): mixed)}>
     */
    public function benchmarks(): array;
}
