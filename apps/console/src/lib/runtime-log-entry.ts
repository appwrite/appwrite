/**
 * One runtime log row as the viewer files it: the API row, its parsed line,
 * the level it counts under, and the text search and download use. Built
 * once per row, so filtering and rendering never parse a line again.
 */

import type { Models } from '@appwrite.io/console'
import {
  parseRuntimeLogLine,
  RUNTIME_LOG_LEVELS,
  stripAnsi,
  type RuntimeLogField,
  type RuntimeLogLevel,
  type RuntimeLogLine,
} from '@/lib/runtime-log-line'

export interface RuntimeLogEntry {
  log: Models.RuntimeLog
  line: RuntimeLogLine
  /** The line's own level; otherwise stderr counts as an error, stdout as info. */
  level: RuntimeLogLevel
  /** True when the line names no level and its stream stands in for one. */
  levelFromStream: boolean
  /** The line without color codes, as copied, searched and downloaded. */
  plain: string
  /** `plain`, lowercased once for the search box. */
  searchText: string
}

export function toRuntimeLogEntry(log: Models.RuntimeLog): RuntimeLogEntry {
  const line = parseRuntimeLogLine(log.message)
  const plain = stripAnsi(log.message)
  return {
    log,
    line,
    level: line.level ?? (log.stream === 'stderr' ? 'error' : 'info'),
    levelFromStream: line.level === null,
    plain,
    searchText: plain.toLowerCase(),
  }
}

/** Lines per level, most severe first as the level filter lists them. */
export function countRuntimeLogLevels(
  entries: readonly RuntimeLogEntry[],
): Record<RuntimeLogLevel, number> {
  const counts = Object.fromEntries(
    RUNTIME_LOG_LEVELS.map((level) => [level, 0]),
  ) as Record<RuntimeLogLevel, number>
  for (const entry of entries) counts[entry.level]++
  return counts
}

/** The row already shows its time, so these only appear in the details. */
const TIME_KEYS = new Set(['time', 'timestamp', 'ts', '@t', '@timestamp'])

/** Process details every line of a runtime repeats; the details keep them. */
const QUIET_KEYS = new Set(['pid', 'hostname', 'v'])

/** Fields worth reading on the row itself, in the order written. */
export function rowRuntimeLogFields(line: RuntimeLogLine): RuntimeLogField[] {
  return line.fields.filter(
    (field) => !TIME_KEYS.has(field.key) && !QUIET_KEYS.has(field.key),
  )
}

/** The text a download writes for one line, with the resource on project-wide lists. */
export function runtimeLogDownloadLine(
  entry: RuntimeLogEntry,
  withResource: boolean,
): string {
  const { log } = entry
  const resource = withResource ? ` ${log.resourceType}/${log.resourceId}` : ''
  return `${log.timestamp} ${log.region} ${log.stream}${resource} ${entry.plain}`
}
