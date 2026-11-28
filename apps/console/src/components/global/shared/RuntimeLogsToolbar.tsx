import type { ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import { Download, Filter, Search, WrapText, X } from 'lucide-react'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import type { RuntimeLogStream } from '@/lib/react-query/hooks/runtime-logs'
import { normalizeRuntimeLogLevels } from '@/lib/runtime-log-filters'
import type { RuntimeLogLevel } from '@/lib/runtime-log-line'
import { RUNTIME_LOG_DATE_RANGE_PRESET_GROUPS } from '@/lib/usage/usage-date-range-presets'
import { cn } from '@/lib/utils'

/** Most severe first, as the menu and chips list them. */
export const RUNTIME_LOG_LEVEL_OPTIONS: {
  value: RuntimeLogLevel
  label: string
}[] = [
  { value: 'error', label: 'Error' },
  { value: 'warn', label: 'Warning' },
  { value: 'info', label: 'Info' },
  { value: 'debug', label: 'Debug' },
]

/** Outline controls in the Console's toolbars: quiet until hovered. */
const TOOL_BUTTON =
  'h-9 border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground'

export interface RuntimeLogsToolbarProps {
  /** Pickers that scope the list (deployment, resource), first in the row. */
  start?: ReactNode
  search: string
  onSearchChange: (value: string) => void
  levels: readonly RuntimeLogLevel[]
  /** Loaded lines per level, shown beside each level. */
  levelCounts: Record<RuntimeLogLevel, number>
  onLevelsChange: (levels: RuntimeLogLevel[]) => void
  stream: RuntimeLogStream
  onStreamChange: (stream: RuntimeLogStream) => void
  /** The time range as the shared picker shows it. */
  dateRange: DateRange
  /** The rolling preset in use, or `null` for a picked window. */
  presetId: string | null
  onDateRangeChange: (range: DateRange | undefined) => void
  live: boolean
  onLiveChange: (live: boolean) => void
  wrap: boolean
  onWrapChange: (wrap: boolean) => void
  ready: boolean
  refreshing: boolean
  onRefresh: () => void
  canDownload: boolean
  onDownload: () => void
  insetClassName?: string
}

/**
 * The runtime logs toolbar, laid out like every Console list: search first,
 * then filters and the shared date range picker, with live, wrap, refresh and
 * download on the end. Labels fold into icons when the view is narrow.
 */
export function RuntimeLogsToolbar({
  start,
  search,
  onSearchChange,
  levels,
  levelCounts,
  onLevelsChange,
  stream,
  onStreamChange,
  dateRange,
  presetId,
  onDateRangeChange,
  live,
  onLiveChange,
  wrap,
  onWrapChange,
  ready,
  refreshing,
  onRefresh,
  canDownload,
  onDownload,
  insetClassName,
}: RuntimeLogsToolbarProps) {
  const t = useT()
  const active = (levels.length > 0 ? 1 : 0) + (stream === 'all' ? 0 : 1)
  const shown = (level: RuntimeLogLevel) =>
    levels.length === 0 || levels.includes(level)

  const toggleLevel = (level: RuntimeLogLevel) => {
    const next = new Set(
      levels.length === 0
        ? RUNTIME_LOG_LEVEL_OPTIONS.map((o) => o.value)
        : levels,
    )
    if (next.has(level)) next.delete(level)
    else next.add(level)
    onLevelsChange(normalizeRuntimeLogLevels(next))
  }

  return (
    <TooltipProvider delayDuration={0}>
      <div
        className={cn(
          '@container/runtime-logs flex min-w-0 flex-wrap items-center gap-2 border-b border-border py-3',
          insetClassName,
        )}
      >
        {start}
        <div className="relative min-w-[160px] flex-1 @[720px]/runtime-logs:max-w-xs">
          <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input
            type="search"
            placeholder={t('Search logs...')}
            aria-label={t('Search logs')}
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            className="h-9 w-full min-w-0 rounded-md border border-border bg-accent/50 ps-9 pe-3 text-[13px] text-foreground outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&::-webkit-search-cancel-button]:hidden"
          />
        </div>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className={cn(
                TOOL_BUTTON,
                'shrink-0 gap-1.5 px-2.5 @[560px]/runtime-logs:px-3',
                active > 0 && 'text-foreground',
              )}
              aria-label={t('Filters')}
            >
              <Filter className="h-3.5 w-3.5 shrink-0" />
              <span className="hidden @[560px]/runtime-logs:inline">
                {t('Filters')}
              </span>
              {active > 0 && (
                <span className="flex h-4 min-w-4 items-center justify-center rounded-full bg-foreground px-1 text-[10px] font-semibold text-background tabular-nums">
                  {active}
                </span>
              )}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-60">
            <DropdownMenuLabel className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Level')}
            </DropdownMenuLabel>
            <DropdownMenuCheckboxItem
              checked={levels.length === 0}
              onCheckedChange={() => onLevelsChange([])}
              onSelect={(event) => event.preventDefault()}
              className="text-[13px]"
            >
              {t('All levels')}
            </DropdownMenuCheckboxItem>
            {RUNTIME_LOG_LEVEL_OPTIONS.map((option) => (
              <DropdownMenuCheckboxItem
                key={option.value}
                checked={shown(option.value)}
                onCheckedChange={() => toggleLevel(option.value)}
                onSelect={(event) => event.preventDefault()}
                className="text-[13px]"
              >
                <span className="flex-1">{t(option.label)}</span>
                <span className="text-[12px] text-muted-foreground tabular-nums">
                  {levelCounts[option.value].toLocaleString()}
                </span>
              </DropdownMenuCheckboxItem>
            ))}
            <DropdownMenuSeparator />
            <DropdownMenuLabel className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Stream')}
            </DropdownMenuLabel>
            <DropdownMenuRadioGroup
              value={stream}
              onValueChange={(value) =>
                onStreamChange(value as RuntimeLogStream)
              }
            >
              <DropdownMenuRadioItem
                value="all"
                onSelect={(event) => event.preventDefault()}
                className="text-[13px]"
              >
                {t('All streams')}
              </DropdownMenuRadioItem>
              <DropdownMenuRadioItem
                value="stdout"
                onSelect={(event) => event.preventDefault()}
                className="font-mono text-[12px]"
              >
                stdout
              </DropdownMenuRadioItem>
              <DropdownMenuRadioItem
                value="stderr"
                onSelect={(event) => event.preventDefault()}
                className="font-mono text-[12px]"
              >
                stderr
              </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
            {active > 0 && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onSelect={() => {
                    onLevelsChange([])
                    onStreamChange('all')
                  }}
                  className="text-[13px] text-muted-foreground"
                >
                  {t('Clear filters')}
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>

        <DateRangePicker
          dateRange={dateRange}
          onDateRangeChange={onDateRangeChange}
          presetId={presetId}
          presetGroups={RUNTIME_LOG_DATE_RANGE_PRESET_GROUPS}
          popoverContentAlign="start"
          className="h-9 min-w-[160px] shrink-0 text-[13px]"
        />

        <div className="ms-auto flex shrink-0 items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className={cn(
              TOOL_BUTTON,
              'gap-2 px-3',
              live &&
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 hover:bg-emerald-500/15 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300',
            )}
            onClick={() => onLiveChange(!live)}
            disabled={!ready}
            aria-pressed={live}
          >
            <span className="relative flex h-2 w-2 shrink-0" aria-hidden>
              {live && (
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60 motion-reduce:animate-none" />
              )}
              <span
                className={cn(
                  'relative inline-flex h-2 w-2 rounded-full',
                  live ? 'bg-emerald-500' : 'bg-muted-foreground/40',
                )}
              />
            </span>
            {t('Live')}
          </Button>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className={cn(
                  TOOL_BUTTON,
                  'w-9 p-0',
                  wrap && 'bg-accent text-foreground',
                )}
                onClick={() => onWrapChange(!wrap)}
                aria-pressed={wrap}
                aria-label={t('Wrap lines')}
              >
                <WrapText className="h-4 w-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{t('Wrap lines')}</TooltipContent>
          </Tooltip>
          <RefreshButton
            onClick={onRefresh}
            isRefreshing={refreshing}
            disabled={!ready}
          />
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className={cn(TOOL_BUTTON, 'w-9 p-0 disabled:opacity-50')}
                onClick={onDownload}
                disabled={!canDownload}
                aria-label={t('Download logs')}
              >
                <Download className="h-4 w-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{t('Download logs')}</TooltipContent>
          </Tooltip>
        </div>
      </div>
    </TooltipProvider>
  )
}

/**
 * The active level and stream filters as removable chips under the toolbar,
 * like the Console's other filtered lists, so a narrowed list never passes
 * for a quiet one.
 */
export function RuntimeLogFilterChips({
  levels,
  onLevelsChange,
  stream,
  onStreamChange,
  insetClassName,
}: {
  levels: readonly RuntimeLogLevel[]
  onLevelsChange: (levels: RuntimeLogLevel[]) => void
  stream: RuntimeLogStream
  onStreamChange: (stream: RuntimeLogStream) => void
  insetClassName?: string
}) {
  const t = useT()
  if (levels.length === 0 && stream === 'all') return null
  const levelLabel = RUNTIME_LOG_LEVEL_OPTIONS.filter((option) =>
    levels.includes(option.value),
  )
    .map((option) => t(option.label))
    .join(', ')

  return (
    <div
      className={cn(
        'flex flex-wrap items-center gap-2 border-b border-border py-2.5',
        insetClassName,
      )}
    >
      <span className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        {t('Filtered by')}
      </span>
      {levels.length > 0 && (
        <Chip
          field={t('Level')}
          operator={levels.length > 1 ? t('is any of') : t('is')}
          value={levelLabel}
          onRemove={() => onLevelsChange([])}
        />
      )}
      {stream !== 'all' && (
        <Chip
          field={t('Stream')}
          operator={t('is')}
          value={stream}
          mono
          onRemove={() => onStreamChange('all')}
        />
      )}
      {levels.length > 0 && stream !== 'all' && (
        <Button
          variant="ghost"
          size="sm"
          className="h-7 px-2 text-[12px] text-muted-foreground"
          onClick={() => {
            onLevelsChange([])
            onStreamChange('all')
          }}
        >
          {t('Clear all')}
        </Button>
      )}
    </div>
  )
}

function Chip({
  field,
  operator,
  value,
  mono,
  onRemove,
}: {
  field: string
  operator: string
  value: string
  mono?: boolean
  onRemove: () => void
}) {
  const t = useT()
  return (
    <span className="inline-flex h-7 max-w-[28rem] items-stretch overflow-hidden rounded-md border border-border bg-card text-[12px]">
      <span className="flex shrink-0 items-center px-2 text-muted-foreground">
        {field}
      </span>
      <span className="flex shrink-0 items-center border-s border-border px-2 text-foreground/80">
        {operator}
      </span>
      <span
        className={cn(
          'flex min-w-0 items-center border-s border-border px-2 font-medium text-foreground',
          mono && 'font-mono',
        )}
        title={value}
      >
        <span className="truncate">{value}</span>
      </span>
      <button
        type="button"
        onClick={onRemove}
        className="flex w-7 shrink-0 cursor-pointer items-center justify-center border-s border-border text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        aria-label={t('Remove filter')}
      >
        <X className="h-3 w-3" />
      </button>
    </span>
  )
}
