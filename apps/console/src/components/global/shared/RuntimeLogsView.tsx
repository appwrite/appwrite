import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { keepPreviousData, useInfiniteQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  AlertCircle,
  Download,
  ListCollapse,
  Loader2,
  Pause,
  Play,
  RefreshCw,
  ScrollText,
  Search,
} from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RuntimeLogRow } from '@/components/global/shared/RuntimeLogRow'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  canListRuntimeLogs,
  fetchRuntimeLogsSince,
  RUNTIME_LOGS_PAGE_LIMIT,
  runtimeLogsInfiniteQueryOptions,
  type RuntimeLogRange,
  type RuntimeLogResourceType,
  type RuntimeLogScope,
  type RuntimeLogStream,
  type RuntimeLogsFilter,
} from '@/lib/react-query/hooks'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import {
  isAtLeast,
  parseRuntimeLogLine,
  stripAnsi,
  type RuntimeLogLevel,
} from '@/lib/runtime-log-line'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Live tail polls this often. The logs store has a small pool, so keep it modest. */
const TAIL_INTERVAL_MS = 5000

/** Live tail keeps at most this many new lines above the fetched pages. */
const TAIL_MAX_LINES = 1000

/** Full pages one live tail poll may read before waiting for the next poll. */
const TAIL_MAX_PAGES_PER_TICK = 5

/** Remembers, per browser, whether rows show time, stream and region as columns. */
const METADATA_STORAGE_KEY = 'runtimeLogs:metadata'

function readShowMetadata(): boolean {
  try {
    return window.localStorage.getItem(METADATA_STORAGE_KEY) === 'true'
  } catch {
    return false
  }
}

function writeShowMetadata(value: boolean) {
  try {
    window.localStorage.setItem(METADATA_STORAGE_KEY, String(value))
  } catch {
    // Private windows and blocked storage just don't remember the choice
  }
}

export interface RuntimeLogsViewProps {
  projectId: string | null | undefined
  /** `null` lists every resource in the project, with a column naming each. */
  resourceType: RuntimeLogScope
  resourceId?: string | null
  /** Required for functions and sites. Without one the view asks for it. */
  deploymentId?: string
  /** Rendered first in the toolbar, e.g. a deployment picker. */
  toolbarStart?: ReactNode
  className?: string
}

/**
 * Runtime logs for one resource, or for the whole project: what it wrote to
 * stdout and stderr, newest first, with stream and time filters, cursor paging
 * and live tail. JSON and logfmt lines show their level and message first,
 * with their fields beside them; rows expand to show every field and the
 * line's time, stream, region and deployment.
 */
export function RuntimeLogsView({
  projectId,
  resourceType,
  resourceId,
  deploymentId,
  toolbarStart,
  className,
}: RuntimeLogsViewProps) {
  const t = useT()
  const { formatDateTime } = useLocalizedDateFormat()
  const isProject = resourceType === null
  const [kind, setKind] = useState<RuntimeLogResourceType | 'all'>('all')
  const [stream, setStream] = useState<RuntimeLogStream>('all')
  const [range, setRange] = useState<RuntimeLogRange>('1h')
  const [search, setSearch] = useState('')
  const [minLevel, setMinLevel] = useState<RuntimeLogLevel | 'all'>('all')
  const [showMetadata, setShowMetadata] = useState(false)
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set())
  const [live, setLive] = useState(false)
  const [tail, setTail] = useState<Models.RuntimeLog[]>([])
  const [tailError, setTailError] = useState<string | null>(null)

  const filter: RuntimeLogsFilter = useMemo(
    () => ({
      deploymentId,
      resourceType: isProject && kind !== 'all' ? kind : undefined,
      stream,
      range,
    }),
    [deploymentId, isProject, kind, stream, range],
  )
  const ready = canListRuntimeLogs(projectId, resourceType, resourceId, filter)

  const {
    data,
    error,
    isLoading,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
    refetch,
  } = useInfiniteQuery({
    ...runtimeLogsInfiniteQueryOptions(
      projectId,
      resourceType,
      resourceId,
      filter,
    ),
    placeholderData: keepPreviousData,
  })

  const fetched = useMemo(
    () => data?.pages.flatMap((page) => page.logs) ?? [],
    [data],
  )

  // New filters or a refetch start the tail over from the fetched pages.
  const firstPage = data?.pages[0]
  useEffect(() => {
    setTail([])
    setTailError(null)
  }, [filter, firstPage])

  const logs = useMemo(() => {
    if (tail.length === 0) return fetched
    const seen = new Set(tail.map((log) => log.$id))
    return [...tail, ...fetched.filter((log) => !seen.has(log.$id))]
  }, [tail, fetched])

  // Read by the tail loop without restarting it on every new line.
  const newestRef = useRef<{ timestamp: string; ids: Set<string> } | null>(null)
  useEffect(() => {
    const newest = logs[0]
    if (!newest) {
      newestRef.current = null
      return
    }
    const ids = new Set(
      logs
        .filter((log) => log.timestamp === newest.timestamp)
        .map((log) => log.$id),
    )
    newestRef.current = { timestamp: newest.timestamp, ids }
  }, [logs])

  useEffect(() => {
    if (!live || !ready || !projectId) return
    const startedAt = new Date().toISOString()
    let cancelled = false
    let inFlight = false

    const tick = async () => {
      if (inFlight || document.visibilityState === 'hidden') return
      inFlight = true
      try {
        let cursor = newestRef.current ?? {
          timestamp: startedAt,
          ids: new Set<string>(),
        }
        // Keep reading full pages so a busy deployment doesn't fall behind.
        for (let page = 0; page < TAIL_MAX_PAGES_PER_TICK; page++) {
          const rows = await fetchRuntimeLogsSince(
            projectId,
            resourceType,
            resourceId,
            filter,
            cursor.timestamp,
          )
          if (cancelled) return
          const seen = cursor.ids
          const fresh = rows.filter((row) => !seen.has(row.$id))
          if (fresh.length === 0) break
          setTail((current) =>
            [...[...fresh].reverse(), ...current].slice(0, TAIL_MAX_LINES),
          )
          const last = fresh[fresh.length - 1]
          const ids = new Set(
            fresh
              .filter((row) => row.timestamp === last.timestamp)
              .map((row) => row.$id),
          )
          if (last.timestamp === cursor.timestamp) {
            for (const id of seen) ids.add(id)
          }
          cursor = { timestamp: last.timestamp, ids }
          if (rows.length < RUNTIME_LOGS_PAGE_LIMIT) break
        }
        setTailError(null)
      } catch (e) {
        if (!cancelled) setTailError(getErrorMessage(e))
      } finally {
        inFlight = false
      }
    }

    void tick()
    const interval = setInterval(tick, TAIL_INTERVAL_MS)
    return () => {
      cancelled = true
      clearInterval(interval)
    }
  }, [live, ready, projectId, resourceType, resourceId, filter])

  // Read after mount, so the server render and first client render agree
  useEffect(() => {
    setShowMetadata(readShowMetadata())
  }, [])

  const toggleMetadata = () => {
    setShowMetadata((value) => {
      writeShowMetadata(!value)
      return !value
    })
  }

  const toggleExpanded = useCallback((id: string) => {
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }, [])

  // Parsed once per line, not on every render or keystroke
  const parsedCache = useRef(new Map<string, ParsedLog>())
  const parsed = useMemo(() => {
    const cache = parsedCache.current
    const next = new Map<string, ParsedLog>()
    const rows = logs.map((log) => {
      const hit = cache.get(log.$id)
      const row = hit && hit.log === log ? hit : parseLog(log)
      next.set(log.$id, row)
      return row
    })
    parsedCache.current = next
    return rows
  }, [logs])

  const needle = search.trim().toLowerCase()
  const visible = useMemo(
    () =>
      parsed.filter(
        (row) =>
          (minLevel === 'all' || isAtLeast(row.level, minLevel)) &&
          (!needle || row.plain.toLowerCase().includes(needle)),
      ),
    [parsed, needle, minLevel],
  )
  const filtering = needle !== '' || minLevel !== 'all'

  const formatTime = useCallback(
    (timestamp: string) =>
      formatDateTime(new Date(timestamp), {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        fractionalSecondDigits: 3,
        hour12: false,
      }),
    [formatDateTime],
  )

  const formatFullTimestamp = useCallback(
    (timestamp: string) =>
      formatDateTime(new Date(timestamp), {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        fractionalSecondDigits: 3,
        hour12: false,
      }),
    [formatDateTime],
  )

  const handleDownload = () => {
    const text = visible
      .map(
        ({ log, plain }) =>
          `${log.timestamp} ${log.region} ${log.stream}${isProject ? ` ${log.resourceType}/${log.resourceId}` : ''} ${plain}`,
      )
      .join('\n')
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `runtime-logs-${isProject ? projectId : resourceId}${deploymentId ? `-${deploymentId}` : ''}.log`
    link.click()
    URL.revokeObjectURL(url)
  }

  const emptyDescription = isProject
    ? t(
        "Lines your functions, sites and databases write to stdout and stderr show up here. Logs may not be collected in this project's region yet.",
      )
    : resourceType === 'functions'
      ? t(
          "Lines your function writes to stdout and stderr show up here. Logs may not be collected in this project's region yet.",
        )
      : resourceType === 'sites'
        ? t(
            "Lines your site writes to stdout and stderr show up here. Logs may not be collected in this project's region yet.",
          )
        : t(
            "Lines your database writes show up here. Logs may not be collected in this project's region yet.",
          )

  const loadOlder = hasNextPage ? (
    <Button
      variant="outline"
      size="sm"
      onClick={() => fetchNextPage()}
      disabled={isFetchingNextPage}
    >
      {isFetchingNextPage && <Loader2 className="me-2 h-4 w-4 animate-spin" />}
      {t('Load older')}
    </Button>
  ) : null

  let body: ReactNode
  if (!ready) {
    body = (
      <EmptyState
        icon={ScrollText}
        variant="centered"
        title={t('No deployment selected')}
        description={t('Pick a deployment to see its logs.')}
      />
    )
  } else if (isLoading) {
    body = (
      <div className="space-y-2 py-3">
        {Array.from({ length: 12 }).map((_, i) => (
          <Skeleton key={i} className="h-4 w-full" />
        ))}
      </div>
    )
  } else if (error) {
    body = (
      <EmptyState
        icon={AlertCircle}
        variant="centered"
        title={t('Could not load logs')}
        description={getErrorMessage(error)}
        action={
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            {t('Try again')}
          </Button>
        }
      />
    )
  } else if (visible.length === 0) {
    body = filtering ? (
      <EmptyState
        icon={Search}
        variant="centered"
        hasFilters
        title={t('No matching lines')}
        description={t(
          'Only the lines loaded so far are searched and filtered. Load older lines or widen the time range to search further back.',
        )}
        action={loadOlder}
      />
    ) : (
      <EmptyState
        icon={ScrollText}
        variant="centered"
        title={t('No logs')}
        description={emptyDescription}
      />
    )
  } else {
    body = (
      <div
        role="log"
        aria-live={live ? 'polite' : 'off'}
        className="space-y-px py-2"
      >
        {visible.map(({ log, line, level, plain }) => (
          <RuntimeLogRow
            key={log.$id}
            log={log}
            line={line}
            level={level}
            plain={plain}
            projectId={isProject && projectId ? projectId : undefined}
            expanded={expanded.has(log.$id)}
            onToggle={toggleExpanded}
            showMetadata={showMetadata}
            formatTime={formatTime}
            formatDateTime={formatFullTimestamp}
          />
        ))}
        {loadOlder && (
          <div className="flex justify-center py-3">{loadOlder}</div>
        )}
      </div>
    )
  }

  return (
    <div className={cn('flex min-h-0 min-w-0 flex-1 flex-col', className)}>
      <div className="flex flex-wrap items-center gap-2 border-b border-border py-3">
        {toolbarStart}
        {isProject && (
          <Select
            value={kind}
            onValueChange={(value) =>
              setKind(value as RuntimeLogResourceType | 'all')
            }
          >
            <SelectTrigger
              className="h-9 w-[150px] text-[13px]"
              aria-label={t('Resource')}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t('All resources')}</SelectItem>
              <SelectItem value="functions">{t('Functions')}</SelectItem>
              <SelectItem value="sites">{t('Sites')}</SelectItem>
              <SelectItem value="databases">{t('Databases')}</SelectItem>
            </SelectContent>
          </Select>
        )}
        <Select
          value={stream}
          onValueChange={(value) => setStream(value as RuntimeLogStream)}
        >
          <SelectTrigger
            className="h-9 w-[130px] text-[13px]"
            aria-label={t('Stream')}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t('All streams')}</SelectItem>
            <SelectItem value="stdout">stdout</SelectItem>
            <SelectItem value="stderr">stderr</SelectItem>
          </SelectContent>
        </Select>
        <Select
          value={minLevel}
          onValueChange={(value) =>
            setMinLevel(value as RuntimeLogLevel | 'all')
          }
        >
          <SelectTrigger
            className="h-9 w-[170px] text-[13px]"
            aria-label={t('Level')}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t('All levels')}</SelectItem>
            <SelectItem value="error">{t('Errors')}</SelectItem>
            <SelectItem value="warn">{t('Warnings and up')}</SelectItem>
            <SelectItem value="info">{t('Info and up')}</SelectItem>
          </SelectContent>
        </Select>
        <Select
          value={range}
          onValueChange={(value) => setRange(value as RuntimeLogRange)}
        >
          <SelectTrigger
            className="h-9 w-[150px] text-[13px]"
            aria-label={t('Time range')}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="15m">{t('Last 15 minutes')}</SelectItem>
            <SelectItem value="1h">{t('Last hour')}</SelectItem>
            <SelectItem value="6h">{t('Last 6 hours')}</SelectItem>
            <SelectItem value="24h">{t('Last 24 hours')}</SelectItem>
            <SelectItem value="retention">{t('All retained')}</SelectItem>
          </SelectContent>
        </Select>
        <div className="relative min-w-[180px] flex-1">
          <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder={t('Search loaded lines...')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="h-9 w-full ps-9 text-[13px]"
          />
        </div>
        <Button
          variant={live ? 'default' : 'outline'}
          size="sm"
          className="h-9"
          onClick={() => setLive((value) => !value)}
          disabled={!ready}
          aria-pressed={live}
        >
          {live ? (
            <Pause className="me-1.5 h-4 w-4" />
          ) : (
            <Play className="me-1.5 h-4 w-4" />
          )}
          {live ? t('Pause') : t('Live')}
        </Button>
        <Button
          variant={showMetadata ? 'secondary' : 'outline'}
          size="sm"
          className="h-9 w-9 p-0"
          onClick={toggleMetadata}
          aria-pressed={showMetadata}
          aria-label={t('Show time, stream and region')}
          title={t('Show time, stream and region')}
        >
          <ListCollapse className="h-4 w-4" />
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="h-9 w-9 p-0"
          onClick={() => refetch()}
          disabled={!ready || isFetching}
          aria-label={t('Refresh')}
          title={t('Refresh')}
        >
          <RefreshCw className={cn('h-4 w-4', isFetching && 'animate-spin')} />
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="h-9 w-9 p-0"
          onClick={handleDownload}
          disabled={visible.length === 0}
          aria-label={t('Download logs')}
          title={t('Download logs')}
        >
          <Download className="h-4 w-4" />
        </Button>
      </div>
      {tailError && (
        <p className="border-b border-border py-2 text-[12px] text-amber-600 dark:text-amber-400">
          {t('Live tail paused on an error and will retry:')} {tailError}
        </p>
      )}
      <div className="min-h-0 flex-1 overflow-y-auto">{body}</div>
    </div>
  )
}

interface ParsedLog {
  log: Models.RuntimeLog
  line: ReturnType<typeof parseRuntimeLogLine>
  /** The line's own level; otherwise stderr reads as an error, stdout as info. */
  level: RuntimeLogLevel
  plain: string
}

function parseLog(log: Models.RuntimeLog): ParsedLog {
  const line = parseRuntimeLogLine(log.message)
  return {
    log,
    line,
    level: line.level ?? (log.stream === 'stderr' ? 'error' : 'info'),
    plain: stripAnsi(log.message),
  }
}
