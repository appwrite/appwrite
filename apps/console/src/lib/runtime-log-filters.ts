/**
 * What the runtime logs viewer shows: which resource, stream, levels and time
 * window, and the search text. Pages that own a URL keep these in their search
 * params, so a filtered view survives a reload and can be shared as a link.
 */

import { z } from 'zod'
import {
  RUNTIME_LOG_LEVELS,
  type RuntimeLogLevel,
} from '@/lib/runtime-log-line'
import type {
  RuntimeLogRange,
  RuntimeLogResourceType,
  RuntimeLogStream,
  RuntimeLogWindow,
} from '@/lib/react-query/hooks/runtime-logs'

const RESOURCE_TYPES: readonly RuntimeLogResourceType[] = [
  'functions',
  'sites',
  'databases',
]

/** Every resource (`type` null), every resource of one type, or one resource. */
export interface RuntimeLogResource {
  type: RuntimeLogResourceType | null
  id?: string
  /** Shown on the picker once a search no longer lists the picked resource. */
  name?: string
}

export interface RuntimeLogFilters {
  /** Project-wide lists only. Sent to the API. */
  resource: RuntimeLogResource
  /** Sent to the API. */
  stream: RuntimeLogStream
  /** Levels to show; empty shows every level. Filters the loaded lines. */
  levels: RuntimeLogLevel[]
  /** Look-back window, resolved against the clock on every fetch. */
  range: RuntimeLogRange
  /** A window picked on the volume chart; replaces `range` while set. */
  window?: RuntimeLogWindow
  /** Matched against the loaded lines. */
  search: string
}

export const DEFAULT_RUNTIME_LOG_FILTERS: RuntimeLogFilters = {
  resource: { type: null },
  stream: 'all',
  levels: [],
  range: '1h',
  search: '',
}

/** Search params of pages that keep the viewer's filters in their URL. */
export const runtimeLogsSearchSchema = z.object({
  /** `functions`, `functions:<functionId>`, `sites:<siteId>`, `databases`... */
  resource: z.string().optional().catch(undefined),
  stream: z.enum(['stdout', 'stderr']).optional().catch(undefined),
  /** Comma-separated levels: `error,warn`. */
  level: z.string().optional().catch(undefined),
  range: z.enum(['15m', '1h', '6h', '24h']).optional().catch(undefined),
  /** A chart window, as ISO timestamps. */
  from: z.string().optional().catch(undefined),
  to: z.string().optional().catch(undefined),
  q: z.string().optional().catch(undefined),
})

export type RuntimeLogsSearch = z.infer<typeof runtimeLogsSearchSchema>

function parseResource(value: string | undefined): RuntimeLogResource {
  if (!value) return { type: null }
  const colon = value.indexOf(':')
  const type = (colon === -1 ? value : value.slice(0, colon)) as
    | RuntimeLogResourceType
    | string
  if (!RESOURCE_TYPES.includes(type as RuntimeLogResourceType)) {
    return { type: null }
  }
  const id = colon === -1 ? '' : value.slice(colon + 1)
  return id
    ? { type: type as RuntimeLogResourceType, id }
    : { type: type as RuntimeLogResourceType }
}

/** Every level, or none, is the same as no level filter. */
export function normalizeRuntimeLogLevels(
  levels: Iterable<string>,
): RuntimeLogLevel[] {
  const picked = RUNTIME_LOG_LEVELS.filter((level) =>
    new Set(levels).has(level),
  )
  return picked.length === RUNTIME_LOG_LEVELS.length ? [] : picked
}

function parseWindow(
  from: string | undefined,
  to: string | undefined,
): RuntimeLogWindow | undefined {
  if (!from || !to) return undefined
  const start = Date.parse(from)
  const end = Date.parse(to)
  if (!Number.isFinite(start) || !Number.isFinite(end) || start >= end) {
    return undefined
  }
  return {
    from: new Date(start).toISOString(),
    to: new Date(end).toISOString(),
  }
}

export function runtimeLogFiltersFromSearch(
  search: RuntimeLogsSearch,
): RuntimeLogFilters {
  return {
    resource: parseResource(search.resource),
    stream: search.stream ?? 'all',
    levels: normalizeRuntimeLogLevels((search.level ?? '').split(',')),
    range: search.range ?? DEFAULT_RUNTIME_LOG_FILTERS.range,
    window: parseWindow(search.from, search.to),
    search: search.q ?? '',
  }
}

/**
 * The search params for `filters`. Defaults come back `undefined`, so
 * spreading the result over the current params clears them from the URL.
 */
export function runtimeLogsSearchFromFilters(
  filters: RuntimeLogFilters,
): RuntimeLogsSearch {
  const { resource } = filters
  return {
    resource: resource.type
      ? resource.id
        ? `${resource.type}:${resource.id}`
        : resource.type
      : undefined,
    stream: filters.stream === 'all' ? undefined : filters.stream,
    level: filters.levels.length > 0 ? filters.levels.join(',') : undefined,
    range:
      filters.range === DEFAULT_RUNTIME_LOG_FILTERS.range
        ? undefined
        : filters.range,
    from: filters.window?.from,
    to: filters.window?.to,
    q: filters.search.trim() || undefined,
  }
}

/** True when any filter narrows the loaded lines in the browser. */
export function filtersLoadedLines(filters: RuntimeLogFilters): boolean {
  return filters.levels.length > 0 || filters.search.trim() !== ''
}
