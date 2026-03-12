/**
 * Shared helpers for migration progress from statusCounters.
 * Used by the migrations table (progress column) and details drawer.
 */

import type { Models } from '@appwrite.io/console'

export interface StatusCounter {
  pending?: number
  success?: number
  error?: number
  skip?: number
  processing?: number
  warning?: number
}

export function parseStatusCounters(
  m: Models.Migration,
): Record<string, StatusCounter> {
  if (!m.statusCounters) return {}
  try {
    const raw =
      typeof m.statusCounters === 'string'
        ? JSON.parse(m.statusCounters as string)
        : m.statusCounters
    return typeof raw === 'object' && raw !== null ? raw : {}
  } catch {
    return {}
  }
}

/** Succeeded and total item counts from statusCounters for "X / Y" display. */
export function getMigrationCounts(m: Models.Migration): {
  succeeded: number
  total: number
} {
  const map = parseStatusCounters(m)
  let succeeded = 0
  let total = 0
  for (const counter of Object.values(map)) {
    const c = (counter || {}) as StatusCounter
    const pending = c.pending ?? 0
    const success = c.success ?? 0
    const error = c.error ?? 0
    const skip = c.skip ?? 0
    const processing = c.processing ?? 0
    const warning = c.warning ?? 0
    succeeded += success
    total +=
      success + error + skip + warning + Math.max(0, pending) + processing
  }
  return { succeeded, total }
}

/** Progress % from statusCounters: done = success+error+skip+warning, total = done + pending+processing. */
export function getMigrationProgress(m: Models.Migration): number {
  if (m.status === 'failed' || m.status === 'completed') return 100
  const map = parseStatusCounters(m)
  let totalDone = 0
  let totalInProgress = 0
  for (const counter of Object.values(map)) {
    const c = (counter || {}) as StatusCounter
    const pending = c.pending ?? 0
    const success = c.success ?? 0
    const error = c.error ?? 0
    const skip = c.skip ?? 0
    const processing = c.processing ?? 0
    const warning = c.warning ?? 0
    totalDone += success + error + skip + warning
    totalInProgress += pending + processing
  }
  const total = totalDone + totalInProgress
  if (total === 0) return 0
  const pct = (totalDone / total) * 100
  return Math.round(Math.min(100, Math.max(0, pct)))
}
