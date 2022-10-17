<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;

class UsageBased extends Adapter
{
    public function getNextExecutor(?string $contaierId): array
    {
        $executors = $this->getExecutors();

        // Remove offline and unknown-status executors
        $executors = \array_filter($executors, fn($executor) => (($executor['state'] ?? [])['status']) ?? 'offline' === 'online');

        // Ideal executor is one that is already running a contianer for this function deployment
        $idealExecutors = [];

        // For whatever reason we don't know container yet. We consider all executors ideal
        if (!(isset($contaierId))) {
            $idealExecutors = \array_map(fn($executor) => $executor['id'], $executors);
        } else {
            foreach ($executors as $executor) {
                $executorId = $executor['id'] ?? '';
                $executorState = $executor['state']['health'] ?? [];
                $hostUsage = intval($executorState['hostUsage'] ?? 100);
                $containerUsage = intval(($executorState['functionsUsage'] ?? [])[$executorId . '-' . $contaierId] ?? 100);

                // If host or contianer usage above 80, executor is not ideal
                if ($hostUsage < 80 && $containerUsage < 80) {
                    $idealExecutors[] = $executorId;
                }
            }
        }

        if (\count($idealExecutors) <= 0) {
            // If no ideal, let's consider all of them ideal. Since there is no prefference.
            $idealExecutors = \array_map(fn($executor) => $executor['id'], $executors);
        }

        // Sort containers based on usage
        $sortedExecutors = [];
        $hostWeight = 0.3;
        $containerWeight = 0.7;

        foreach ($idealExecutors as $executorId) {
            $executorIndex = \array_search($executorId, \array_map(fn($executor) => $executor['id'], $executors));
            $executor = $executors[$executorIndex];

            if (!isset($executor)) {
                continue;
            }

            $executorState = $executor['state']['health'] ?? [];
            $hostUsage = intval($executorState['hostUsage'] ?? 10);
            $containerUsage = intval(($executorState['functionsUsage'] ?? [])[$executorId . '-' . $contaierId] ?? 100);

            $usageIndex = ($hostUsage * $hostWeight) + ($containerUsage * $containerWeight);

            $sortedExecutors[$executorId] = $usageIndex;
        }

        \asort($sortedExecutors);

        // Pick the least used executor
        $idealExecutorId = $idealExecutors[0] ?? null;

        // Null if no executor found
        if ($idealExecutorId === null) {
            return null;
        }

        $executorIndex = \array_search($idealExecutorId, \array_map(fn($executor) => $executor['id'], $executors));
        $executor = $executors[$executorIndex];

        // Null if can't match executor to ID
        return $executor ?? null;
    }
}
