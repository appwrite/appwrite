<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;

class UsageBased extends Adapter
{
    public function getNextExecutor(?string $contaierId): array
    {
        // Adapter configuration
        $idealMaxUsage = 80; // Means 80%
        $hostWeight = 0.3;
        $containerWeight = 0.7; //Weights should add up to 1. For maintanance reasons

        $executors = $this->getExecutors();

        // Remove offline and unknown-status executors
        $executors = \array_filter($executors, fn($executor) => (($executor['state'] ?? [])['status']) ?? 'offline' === 'online');

        // Ideal executor is one that is already running a contianer for this function deployment
        $idealExecutors = [];

        // For whatever reason we don't know container yet. We consider all executors ideal
        if (!(isset($contaierId))) {
            $idealExecutors = \array_map(fn($executor) => $executor['hostname'], $executors);
        } else {
            foreach ($executors as $executor) {
                $executorId = $executor['hostname'] ?? '';
                $executorState = $executor['state']['health'] ?? [];
                $hostUsage = intval($executorState['hostUsage'] ?? 100);
                $containerUsage = intval(($executorState['functionsUsage'] ?? [])[$executorId . '-' . $contaierId] ?? 100); // Forcing 100 to mark that starting runtime is the least ideal.

                // If host or contianer usage above idealMaxUsage, executor is not ideal
                if ($hostUsage < $idealMaxUsage && $containerUsage < $idealMaxUsage) {
                    $idealExecutors[] = $executorId;
                }
            }
        }

        if (\count($idealExecutors) <= 0) {
            // If no ideal, let's consider all of them ideal. Since there is no prefference.
            $idealExecutors = \array_map(fn($executor) => $executor['hostname'], $executors);
        }

        // Sort containers based on usage
        $sortedExecutors = [];

        foreach ($idealExecutors as $executorId) {
            $executorIndex = \array_search($executorId, \array_map(fn($executor) => $executor['hostname'], $executors));
            $executor = $executors[$executorIndex];

            if (!isset($executor)) {
                continue;
            }

            $executorState = $executor['state']['health'] ?? [];
            $hostUsage = intval($executorState['hostUsage'] ?? 10);
            $containerUsage = intval(($executorState['functionsUsage'] ?? [])[$executorId . '-' . $contaierId] ?? 0);

            $usageIndex = ($hostUsage * $hostWeight) + ($containerUsage * $containerWeight);

            $sortedExecutors[$executorId] = $usageIndex;
        }

        \asort($sortedExecutors);

        // Pick the least used executor
        $idealExecutorId = \array_keys($sortedExecutors)[0] ?? null;

        // Null if no executor found
        if ($idealExecutorId === null) {
            return null;
        }

        $executorIndex = \array_search($idealExecutorId, \array_map(fn($executor) => $executor['hostname'], $executors));
        $executor = $executors[$executorIndex];

        // Null if can't match executor to ID
        return $executor ?? null;
    }
}
