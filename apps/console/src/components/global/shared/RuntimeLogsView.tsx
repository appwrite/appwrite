import {
  useCallback,
  useDeferredValue,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import type { ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { AlertCircle, Clock, Loader2, ScrollText, Search } from 'lucide-react'
import { DisplayTimeZoneBadge } from '@/components/global/shared/DisplayTimeZoneBadge'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RuntimeLogDrawer } from '@/components/global/shared/RuntimeLogDrawer'
import { RuntimeLogResourcePicker } from '@/components/global/shared/RuntimeLogResourcePicker'
import {
  RUNTIME_LOG_COLUMNS,
  RuntimeLogRow,
} from '@/components/global/shared/RuntimeLogRow'
import {
  RuntimeLogFilterChips,
  RuntimeLogsToolbar,
} from '@/components/global/shared/RuntimeLogsToolbar'
import { RuntimeLogsVolumeChart } from '@/components/global/shared/RuntimeLogsVolumeChart'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  RUNTIME_LOGS_TAIL_INTERVAL_MS,
  useRuntimeLogsFeed,
} from '@/hooks/use-runtime-logs-feed'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import { useT } from '@/lib/i18n/translate'
import {
  RUNTIME_LOG_RANGES,
  runtimeLogSourceKey,
  runtimeLogSourcesQueryOptions,
  runtimeLogsUsageQueryOptions,
  type RuntimeLogRange,
  type RuntimeLogScope,
  type RuntimeLogsFilter,
} from '@/lib/react-query/hooks/runtime-logs'
import {
  countRuntimeLogLevels,
  runtimeLogDownloadLine,
  type RuntimeLogEntry,
} from '@/lib/runtime-log-entry'
import {
  DEFAULT_RUNTIME_LOG_FILTERS,
  type RuntimeLogFilters,
} from '@/lib/runtime-log-filters'
import { useDisplayTimeZone } from '@/lib/timezones'
import { findMatchingUsageDateRangePreset } from '@/lib/usage/usage-date-range-presets'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'

/** Remembers, per browser, whether long lines wrap. */
const WRAP_STORAGE_KEY = 'runtimeLogs:wrap'

/**
 * Level and search filter the loaded lines, so while they match fewer than
 * this many, older pages load on their own...
 */
const FILL_MATCHES = 40

/** ...up to this many pages in all; past that, Load older reads on. */
const FILL_MAX_PAGES = 5

function readWrap(): boolean {
  try {
    return window.localStorage.getItem(WRAP_STORAGE_KEY) === 'true'
  } catch {
    return false
  }
}

function writeWrap(value: boolean) {
  try {
    window.localStorage.setItem(WRAP_STORAGE_KEY, String(value))
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
  /**
   * The filters, for pages that keep them in their URL. Without them the
   * view keeps its own, starting from the defaults.
   */
  filters?: RuntimeLogFilters
  onFiltersChange?: (filters: RuntimeLogFilters) => void
  /**
   * Side padding for the toolbar and the lines. The view itself has none, so
   * the toolbar's bottom border runs edge to edge like other filter bars.
   */
  insetClassName?: string
  className?: string
}

/**
 * Runtime logs for one resource, or for the whole project: what it wrote to
 * stdout and stderr, newest first, as a table of time, level, resource and
 * message. Stream, resource and time go to the API; level and search narrow
 * the loaded lines. A line opens its details in a drawer.
 */
export function RuntimeLogsView({
  projectId,
  resourceType,
  resourceId,
  deploymentId,
  toolbarStart,
  filters: controlledFilters,
  onFiltersChange,
  insetClassName = 'px-4 sm:px-6',
  className,
}: RuntimeLogsViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const { intlLocale } = useLocalizedDateFormat()
  const { timeZone } = useDisplayTimeZone()
  const isProject = resourceType === null

  const [ownFilters, setOwnFilters] = useState(DEFAULT_RUNTIME_LOG_FILTERS)
  const filters = controlledFilters ?? ownFilters
  const filtersRef = useRef(filters)
  filtersRef.current = filters
  const updateFilters = useCallback(
    (patch: Partial<RuntimeLogFilters>) => {
      const next = { ...filtersRef.current, ...patch }
      filtersRef.current = next
      if (onFiltersChange) onFiltersChange(next)
      else setOwnFilters(next)
    },
    [onFiltersChange],
  )

  // The box filters as you type; the URL follows once typing pauses
  const [searchDraft, setSearchDraft] = useState(filters.search)
  const pushedSearch = useRef(filters.search)
  useEffect(() => {
    if (filters.search !== pushedSearch.current) {
      pushedSearch.current = filters.search
      setSearchDraft(filters.search)
    }
  }, [filters.search])
  const debouncedSearch = useDebouncedValue(searchDraft, 300)
  useEffect(() => {
    if (debouncedSearch === filtersRef.current.search) return
    pushedSearch.current = debouncedSearch
    updateFilters({ search: debouncedSearch })
  }, [debouncedSearch, updateFilters])

  const [live, setLive] = useState(false)
  const [wrap, setWrap] = useState(false)
  const [selectedId, setSelectedId] = useState<string | null>(null)

  // Read after mount, so the server render and first client render agree
  useEffect(() => {
    setWrap(readWrap())
  }, [])

  const apiFilter: RuntimeLogsFilter = useMemo(
    () => ({
      deploymentId,
      resourceType: isProject
        ? (filters.resource.type ?? undefined)
        : undefined,
      resourceId: isProject ? filters.resource.id : undefined,
      stream: filters.stream,
      range: filters.range,
      window: filters.window,
    }),
    [
      deploymentId,
      isProject,
      filters.resource.type,
      filters.resource.id,
      filters.stream,
      filters.range,
      filters.window,
    ],
  )

  const feed = useRuntimeLogsFeed({
    projectId,
    resourceType,
    resourceId,
    filter: apiFilter,
    live,
  })
  const { ready, entries } = feed

  // Live tail follows the clock, so it polls the chart too
  const usage = useQuery({
    ...runtimeLogsUsageQueryOptions(
      projectId,
      resourceType,
      resourceId,
      apiFilter,
    ),
    placeholderData: keepPreviousData,
    refetchInterval: live ? RUNTIME_LOGS_TAIL_INTERVAL_MS * 6 : false,
  })

  // Names for the resources on a project-wide list
  const sourceIds = useMemo(() => {
    const functions = new Set<string>()
    const sites = new Set<string>()
    let databases = false
    if (isProject) {
      for (const { log } of entries) {
        if (log.resourceType === 'functions') functions.add(log.resourceId)
        else if (log.resourceType === 'sites') sites.add(log.resourceId)
        else if (log.resourceType === 'databases') databases = true
      }
    }
    return {
      functions: [...functions].sort(),
      sites: [...sites].sort(),
      databases,
    }
  }, [entries, isProject])
  const sourcesQuery = useQuery({
    ...runtimeLogSourcesQueryOptions(
      projectId,
      sourceIds.functions,
      sourceIds.sites,
      sourceIds.databases,
    ),
    placeholderData: keepPreviousData,
  })
  const sources = sourcesQuery.data

  // Level and search narrow the loaded lines
  const needle = useDeferredValue(searchDraft).trim().toLowerCase()
  const searched = useMemo(
    () =>
      needle
        ? entries.filter((entry) => entry.searchText.includes(needle))
        : entries,
    [entries, needle],
  )
  const levelCounts = useMemo(() => countRuntimeLogLevels(searched), [searched])
  const visible = useMemo(
    () =>
      filters.levels.length === 0
        ? searched
        : searched.filter((entry) => filters.levels.includes(entry.level)),
    [searched, filters.levels],
  )
  const filtering = needle !== '' || filters.levels.length > 0

  // A narrow filter reads a few older pages before asking for more
  const { hasNextPage, isFetching, fetchNextPage, pages } = feed
  useEffect(() => {
    if (!filtering || !hasNextPage || isFetching) return
    if (visible.length >= FILL_MATCHES || pages >= FILL_MAX_PAGES) return
    void fetchNextPage()
  }, [filtering, hasNextPage, isFetching, fetchNextPage, pages, visible.length])

  // Ranges longer than a day show the date beside the time
  const withDate =
    filters.window !== undefined
      ? Date.parse(filters.window.to) - Date.parse(filters.window.from) >
        12 * 60 * 60_000
      : filters.range === '24h'

  const formatters = useMemo(() => {
    const clock: Intl.DateTimeFormatOptions = {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      fractionalSecondDigits: 3,
      hour12: false,
      timeZone,
    }
    return {
      time: new Intl.DateTimeFormat(intlLocale, clock),
      dateTime: new Intl.DateTimeFormat(intlLocale, {
        ...clock,
        month: 'short',
        day: 'numeric',
      }),
      full: new Intl.DateTimeFormat(intlLocale, {
        ...clock,
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZoneName: 'short',
      }),
    }
  }, [intlLocale, timeZone])

  const formatTime = useCallback(
    (timestamp: string) =>
      (withDate ? formatters.dateTime : formatters.time).format(
        new Date(timestamp),
      ),
    [formatters, withDate],
  )
  const formatTimestamp = useCallback(
    (timestamp: string) => formatters.full.format(new Date(timestamp)),
    [formatters],
  )

  // The shared picker shows a rolling range as the window it covers now, and
  // a window picked on its calendar or dragged on the chart as it is
  const pickerRange = useMemo<DateRange>(() => {
    if (filters.window) {
      return {
        from: new Date(filters.window.from),
        to: new Date(filters.window.to),
      }
    }
    const now = Date.now()
    return {
      from: new Date(now - RUNTIME_LOG_RANGES[filters.range]),
      to: new Date(now),
    }
  }, [filters.window, filters.range])

  const handleDateRangeChange = (picked: DateRange | undefined) => {
    if (!picked?.from || !picked.to) return
    // A rolling preset keeps following the clock; anything else is a window
    const preset = findMatchingUsageDateRangePreset(picked)
    if (preset && preset.value in RUNTIME_LOG_RANGES) {
      updateFilters({
        range: preset.value as RuntimeLogRange,
        window: undefined,
      })
      return
    }
    setLive(false)
    updateFilters({
      window: { from: picked.from.toISOString(), to: picked.to.toISOString() },
    })
  }

  // The drawer steps through the list as filtered
  const selectedIndex = selectedId
    ? visible.findIndex((entry) => entry.log.$id === selectedId)
    : -1
  const selected =
    selectedIndex >= 0
      ? visible[selectedIndex]
      : selectedId
        ? (entries.find((entry) => entry.log.$id === selectedId) ?? null)
        : null
  const newer = selectedIndex > 0 ? visible[selectedIndex - 1] : null
  const older =
    selectedIndex >= 0 && selectedIndex < visible.length - 1
      ? visible[selectedIndex + 1]
      : null

  const listRef = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!selectedId) return
    listRef.current
      ?.querySelector(`[data-log-id="${CSS.escape(selectedId)}"]`)
      ?.scrollIntoView({ block: 'nearest' })
  }, [selectedId])

  // New live lines land above; keep a reader's place unless they're at the top
  const scrollAnchor = useRef<{ first: string | undefined; height: number }>({
    first: undefined,
    height: 0,
  })
  useLayoutEffect(() => {
    const list = listRef.current
    const first = visible[0]?.log.$id
    if (!list) return
    const previous = scrollAnchor.current
    if (
      live &&
      previous.first !== undefined &&
      first !== previous.first &&
      list.scrollTop > 0
    ) {
      list.scrollTop += list.scrollHeight - previous.height
    }
    scrollAnchor.current = { first, height: list.scrollHeight }
  }, [visible, live])

  // Each time the drawer opens from closed it mounts afresh. Radix portals
  // its overlay and panel apart, so reopening one still animating out would
  // stack the new overlay over the old panel.
  const [drawerSession, setDrawerSession] = useState(0)
  const selectedRef = useRef(selectedId)
  selectedRef.current = selectedId
  const openEntry = useCallback((id: string) => {
    if (selectedRef.current === null) setDrawerSession((session) => session + 1)
    setSelectedId(id)
  }, [])

  const showSource = useCallback(
    (entry: RuntimeLogEntry) => {
      const { log } = entry
      setSelectedId(null)
      updateFilters({
        resource: {
          type: log.resourceType as RuntimeLogFilters['resource']['type'],
          id: log.resourceId,
          name: sources?.get(
            runtimeLogSourceKey(log.resourceType, log.resourceId),
          )?.name,
        },
      })
    },
    [sources, updateFilters],
  )

  const openSource = useCallback(
    (entry: RuntimeLogEntry) => {
      if (!projectId) return
      const { log } = entry
      const search = log.deploymentId ? { deploymentId: log.deploymentId } : {}
      if (log.resourceType === 'functions') {
        void navigate({
          to: '/projects/$projectId/functions/$functionId/runtime-logs',
          params: { projectId, functionId: log.resourceId },
          search,
        })
      } else if (log.resourceType === 'sites') {
        void navigate({
          to: '/projects/$projectId/sites/$siteId/runtime-logs',
          params: { projectId, siteId: log.resourceId },
          search,
        })
      } else if (log.resourceType === 'databases') {
        const source = sources?.get(
          runtimeLogSourceKey(log.resourceType, log.resourceId),
        )
        if (source?.engine === 'mysql') {
          void navigate({
            to: '/projects/$projectId/databases/mysql/$databaseId',
            params: { projectId, databaseId: log.resourceId },
          })
        } else if (source?.engine === 'postgresql') {
          void navigate({
            to: '/projects/$projectId/databases/postgres/$databaseId',
            params: { projectId, databaseId: log.resourceId },
          })
        }
      }
    },
    [navigate, projectId, sources],
  )

  const handleDownload = () => {
    const text = visible
      .map((entry) => runtimeLogDownloadLine(entry, isProject))
      .join('\n')
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `runtime-logs-${isProject ? projectId : resourceId}${deploymentId ? `-${deploymentId}` : ''}.log`
    link.click()
    URL.revokeObjectURL(url)
  }

  const clearLineFilters = () => {
    setSearchDraft('')
    updateFilters({ levels: [], search: '' })
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

  const loadOlder = feed.hasNextPage ? (
    <Button
      variant="outline"
      size="sm"
      className="h-7 gap-1.5 px-2.5 text-[12px]"
      onClick={() => feed.fetchNextPage()}
      disabled={feed.isFetchingNextPage}
    >
      {feed.isFetchingNextPage && (
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
      )}
      {t('Load older')}
    </Button>
  ) : null

  const timeColumn = withDate
    ? RUNTIME_LOG_COLUMNS.timeWithDate
    : RUNTIME_LOG_COLUMNS.time

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
  } else if (feed.isLoading) {
    body = (
      <div aria-hidden>
        {Array.from({ length: 14 }).map((_, i) => (
          <div
            key={i}
            className={cn(
              'flex h-8 items-center gap-3 overflow-hidden border-b border-border/50',
              insetClassName,
            )}
          >
            <Skeleton className={cn('h-3.5 shrink-0', timeColumn)} />
            <Skeleton className="h-[18px] w-[44px] shrink-0 rounded" />
            {isProject && (
              <Skeleton className={cn('h-3.5', RUNTIME_LOG_COLUMNS.source)} />
            )}
            <Skeleton
              className="h-3.5 min-w-0 flex-1"
              style={{ maxWidth: `${45 + ((i * 37) % 50)}%` }}
            />
          </div>
        ))}
      </div>
    )
  } else if (isHistoryLimit(feed.error)) {
    body = (
      <EmptyState
        icon={Clock}
        variant="centered"
        title={t('Older than your plan keeps logs')}
        description={t(
          'Your plan keeps logs for a limited time. Pick a more recent range, or upgrade your plan for longer retention.',
        )}
        action={
          <Button
            variant="outline"
            size="sm"
            onClick={() => updateFilters({ range: '24h', window: undefined })}
          >
            {t('Show the last 24 hours')}
          </Button>
        }
      />
    )
  } else if (feed.error) {
    body = (
      <EmptyState
        icon={AlertCircle}
        variant="centered"
        title={t('Could not load logs')}
        description={getErrorMessage(feed.error)}
        action={
          <Button variant="outline" size="sm" onClick={() => feed.refetch()}>
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
        action={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={clearLineFilters}>
              {t('Clear filters')}
            </Button>
            {feed.hasNextPage && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => feed.fetchNextPage()}
                disabled={feed.isFetchingNextPage}
              >
                {t('Load older')}
              </Button>
            )}
          </div>
        }
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
      <div role="log" aria-live={live ? 'polite' : 'off'}>
        {visible.map((entry) => (
          <RuntimeLogRow
            key={entry.log.$id}
            entry={entry}
            showSource={isProject}
            source={
              isProject
                ? sources?.get(
                    runtimeLogSourceKey(
                      entry.log.resourceType,
                      entry.log.resourceId,
                    ),
                  )
                : undefined
            }
            selected={entry.log.$id === selectedId}
            arrived={feed.arrived.has(entry.log.$id)}
            wrap={wrap}
            withDate={withDate}
            formatTime={formatTime}
            insetClassName={insetClassName}
            onOpen={openEntry}
            onShowSource={isProject ? showSource : undefined}
            onOpenSource={isProject ? openSource : undefined}
          />
        ))}
      </div>
    )
  }

  const loaded = entries.length
  const status =
    !ready || feed.isLoading || feed.error
      ? null
      : filtering
        ? t('{shown} of {loaded} loaded lines')
            .replace('{shown}', visible.length.toLocaleString())
            .replace('{loaded}', loaded.toLocaleString())
        : loaded === 1
          ? t('1 line loaded')
          : t('{count} lines loaded').replace(
              '{count}',
              loaded.toLocaleString(),
            )

  return (
    <div className={cn('flex min-h-0 min-w-0 flex-1 flex-col', className)}>
      <RuntimeLogsToolbar
        start={
          <>
            {toolbarStart}
            {isProject && (
              <RuntimeLogResourcePicker
                projectId={projectId}
                value={filters.resource}
                onValueChange={(resource) => updateFilters({ resource })}
              />
            )}
          </>
        }
        search={searchDraft}
        onSearchChange={setSearchDraft}
        levels={filters.levels}
        levelCounts={levelCounts}
        onLevelsChange={(levels) => updateFilters({ levels })}
        stream={filters.stream}
        onStreamChange={(stream) => updateFilters({ stream })}
        dateRange={pickerRange}
        presetId={filters.window ? null : filters.range}
        onDateRangeChange={handleDateRangeChange}
        live={live}
        onLiveChange={(next) => {
          // Live follows new lines, so it leaves a window picked on the chart
          if (next && filters.window) updateFilters({ window: undefined })
          setLive(next)
        }}
        wrap={wrap}
        onWrapChange={(next) => {
          writeWrap(next)
          setWrap(next)
        }}
        ready={ready}
        refreshing={feed.isFetching && !feed.isFetchingNextPage}
        onRefresh={() => {
          void feed.refetch()
          void usage.refetch()
        }}
        canDownload={visible.length > 0}
        onDownload={handleDownload}
        insetClassName={insetClassName}
      />
      <RuntimeLogFilterChips
        levels={filters.levels}
        onLevelsChange={(levels) => updateFilters({ levels })}
        stream={filters.stream}
        onStreamChange={(stream) => updateFilters({ stream })}
        insetClassName={insetClassName}
      />
      {feed.tailError && (
        <p
          className={cn(
            'border-b border-border bg-amber-500/5 py-2 text-[12px] text-amber-700 dark:text-amber-400',
            insetClassName,
          )}
        >
          {t('Live tail paused on an error and will retry:')} {feed.tailError}
        </p>
      )}
      {ready && !usage.error && (
        <RuntimeLogsVolumeChart
          points={usage.data?.points}
          interval={usage.data?.interval}
          total={usage.data?.total}
          loading={usage.isLoading}
          zoomed={!!filters.window}
          onZoom={(picked) => {
            setLive(false)
            updateFilters({
              window: {
                from: picked.from!.toISOString(),
                to: picked.to!.toISOString(),
              },
            })
          }}
          onResetZoom={() => updateFilters({ window: undefined })}
          insetClassName={insetClassName}
        />
      )}
      <div
        ref={listRef}
        className="@container/logs relative min-h-0 flex-1 overflow-y-auto overflow-x-hidden"
      >
        {ready && !feed.error && (feed.isLoading || visible.length > 0) && (
          <div
            className={cn(
              'sticky top-0 z-10 hidden h-9 items-center gap-3 bg-background text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] @[640px]/logs:flex',
              insetClassName,
            )}
            aria-hidden
          >
            <span
              className={cn('flex shrink-0 items-center gap-1.5', timeColumn)}
            >
              {t('Time')}
              <DisplayTimeZoneBadge />
            </span>
            <span className={cn('shrink-0', RUNTIME_LOG_COLUMNS.level)}>
              {t('Level')}
            </span>
            {isProject && (
              <span className={RUNTIME_LOG_COLUMNS.source}>
                {t('Resource')}
              </span>
            )}
            <span className="min-w-0 flex-1">{t('Message')}</span>
          </div>
        )}
        {body}
      </div>
      {status && (
        <div
          className={cn(
            'flex h-11 shrink-0 items-center justify-between gap-3 border-t border-border text-[12px] text-muted-foreground',
            insetClassName,
          )}
        >
          <span className="min-w-0 truncate tabular-nums">{status}</span>
          {loadOlder ??
            (loaded > 0 && (
              <span className="shrink-0">
                {t('Every line in this time range is loaded')}
              </span>
            ))}
        </div>
      )}
      <RuntimeLogDrawer
        key={drawerSession}
        entry={selected}
        onOpenChange={(open) => {
          if (!open) setSelectedId(null)
        }}
        showSource={isProject}
        source={
          selected && isProject
            ? sources?.get(
                runtimeLogSourceKey(
                  selected.log.resourceType,
                  selected.log.resourceId,
                ),
              )
            : undefined
        }
        formatTimestamp={formatTimestamp}
        onNewer={newer ? () => setSelectedId(newer.log.$id) : undefined}
        onOlder={older ? () => setSelectedId(older.log.$id) : undefined}
        onShowSource={isProject ? showSource : undefined}
        onOpenSource={isProject ? openSource : undefined}
      />
    </div>
  )
}

/** The API's answer to a range older than the plan's log retention. */
function isHistoryLimit(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    (error as { type?: unknown }).type === 'limit_history_exceeded'
  )
}
