import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { keepPreviousData, useInfiniteQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  AlertCircle,
  Download,
  Loader2,
  Pause,
  Play,
  RefreshCw,
  ScrollText,
  Search,
} from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { stripAnsiForClipboard } from '@/components/global/shared/BuildLogsView'
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
  runtimeLogsInfiniteQueryOptions,
  type RuntimeLogRange,
  type RuntimeLogResourceType,
  type RuntimeLogStream,
  type RuntimeLogsFilter,
} from '@/lib/react-query/hooks'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Live tail polls this often. The logs store has a small pool, so keep it modest. */
const TAIL_INTERVAL_MS = 5000

/** Live tail keeps at most this many new lines above the fetched pages. */
const TAIL_MAX_LINES = 1000

export interface RuntimeLogsViewProps {
  projectId: string | null | undefined
  resourceType: RuntimeLogResourceType
  resourceId: string | null | undefined
  /** Required for functions and sites. Without one the view asks for it. */
  deploymentId?: string
  /** Rendered first in the toolbar, e.g. a deployment picker. */
  toolbarStart?: ReactNode
  className?: string
}

/**
 * Runtime logs for one resource: what it wrote to stdout and stderr, newest
 * first, with stream and time filters, cursor paging and live tail.
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
  const [stream, setStream] = useState<RuntimeLogStream>('all')
  const [range, setRange] = useState<RuntimeLogRange>('1h')
  const [search, setSearch] = useState('')
  const [live, setLive] = useState(false)
  const [tail, setTail] = useState<Models.RuntimeLog[]>([])
  const [tailError, setTailError] = useState<string | null>(null)

  const filter: RuntimeLogsFilter = useMemo(
    () => ({ deploymentId, stream, range }),
    [deploymentId, stream, range],
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
    if (!live || !ready || !projectId || !resourceId) return
    const startedAt = new Date().toISOString()
    let cancelled = false
    let inFlight = false

    const tick = async () => {
      if (inFlight || document.visibilityState === 'hidden') return
      inFlight = true
      try {
        const newest = newestRef.current
        const rows = await fetchRuntimeLogsSince(
          projectId,
          resourceType,
          resourceId,
          filter,
          newest?.timestamp ?? startedAt,
        )
        if (cancelled) return
        const fresh = rows.filter((row) => !newest?.ids.has(row.$id))
        if (fresh.length > 0) {
          setTail((current) =>
            [...fresh.reverse(), ...current].slice(0, TAIL_MAX_LINES),
          )
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

  const needle = search.trim().toLowerCase()
  const visible = useMemo(
    () =>
      needle
        ? logs.filter((log) => log.message.toLowerCase().includes(needle))
        : logs,
    [logs, needle],
  )

  const formatTimestamp = useCallback(
    (timestamp: string) =>
      formatDateTime(new Date(timestamp), {
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
        (log) =>
          `${log.timestamp} ${log.region} ${log.stream} ${stripAnsiForClipboard(log.message)}`,
      )
      .join('\n')
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `runtime-logs-${resourceId}${deploymentId ? `-${deploymentId}` : ''}.log`
    link.click()
    URL.revokeObjectURL(url)
  }

  const emptyDescription =
    resourceType === 'functions'
      ? t(
          "Lines your function writes to stdout and stderr show up here. Runtime logs may not be collected in this project's region yet.",
        )
      : resourceType === 'sites'
        ? t(
            "Lines your site writes to stdout and stderr show up here. Runtime logs may not be collected in this project's region yet.",
          )
        : t(
            "Lines your database writes show up here. Runtime logs may not be collected in this project's region yet.",
          )

  let body: ReactNode
  if (!ready) {
    body = (
      <EmptyState
        icon={ScrollText}
        variant="centered"
        title={t('No deployment selected')}
        description={t('Pick a deployment to see its runtime logs.')}
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
        title={t('Could not load runtime logs')}
        description={getErrorMessage(error)}
        action={
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            {t('Try again')}
          </Button>
        }
      />
    )
  } else if (visible.length === 0) {
    body = needle ? (
      <EmptyState
        icon={Search}
        variant="centered"
        hasFilters
        title={t('No matching lines')}
        description={t(
          'Only the lines loaded so far are searched. Load older lines or widen the time range to search further back.',
        )}
      />
    ) : (
      <EmptyState
        icon={ScrollText}
        variant="centered"
        title={t('No runtime logs')}
        description={emptyDescription}
      />
    )
  } else {
    body = (
      <div role="log" aria-live={live ? 'polite' : 'off'} className="py-2">
        {visible.map((log) => (
          <div
            key={log.$id}
            className="grid grid-cols-[auto_auto_auto_1fr] items-baseline gap-x-3 rounded px-2 py-0.5 font-mono text-[12px] leading-5 hover:bg-muted/50"
          >
            <time
              dateTime={log.timestamp}
              title={log.timestamp}
              className="whitespace-nowrap text-muted-foreground tabular-nums"
            >
              {formatTimestamp(log.timestamp)}
            </time>
            <span
              className={cn(
                'w-12 uppercase',
                log.stream === 'stderr'
                  ? 'text-red-600 dark:text-red-400'
                  : 'text-muted-foreground',
              )}
            >
              {log.stream}
            </span>
            <span className="w-8 uppercase text-muted-foreground">
              {log.region}
            </span>
            <span
              className={cn(
                'min-w-0 whitespace-pre-wrap break-words',
                log.stream === 'stderr' && 'text-red-700 dark:text-red-300',
              )}
            >
              {stripAnsiForClipboard(log.message)}
            </span>
          </div>
        ))}
        {hasNextPage && (
          <div className="flex justify-center py-3">
            <Button
              variant="outline"
              size="sm"
              onClick={() => fetchNextPage()}
              disabled={isFetchingNextPage}
            >
              {isFetchingNextPage && (
                <Loader2 className="me-2 h-4 w-4 animate-spin" />
              )}
              {t('Load older')}
            </Button>
          </div>
        )}
      </div>
    )
  }

  return (
    <div className={cn('flex min-h-0 min-w-0 flex-1 flex-col', className)}>
      <div className="flex flex-wrap items-center gap-2 border-b border-border py-3">
        {toolbarStart}
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
