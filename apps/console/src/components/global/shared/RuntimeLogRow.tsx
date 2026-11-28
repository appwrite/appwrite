import { memo } from 'react'
import type { ComponentType } from 'react'
import {
  Copy,
  Database,
  ExternalLink,
  FileJson,
  FileText,
  Filter,
  Globe,
  Hash,
  TextQuote,
  Zap,
} from 'lucide-react'
import { toast } from 'sonner'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { DatabaseTypeIcon } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeIcon'
import { Badge } from '@/components/ui/badge'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { useT } from '@/lib/i18n/translate'
import {
  rowRuntimeLogFields,
  type RuntimeLogEntry,
} from '@/lib/runtime-log-entry'
import {
  summarizeRuntimeLogMessage,
  type RuntimeLogLevel,
} from '@/lib/runtime-log-line'
import type { RuntimeLogSource } from '@/lib/react-query/hooks/runtime-logs'
import { cn } from '@/lib/utils'

/** Fields shown after the message on an unwrapped row. */
const ROW_FIELDS = 8

export const RUNTIME_LOG_LEVEL_LABELS: Record<RuntimeLogLevel, string> = {
  error: 'ERROR',
  warn: 'WARN',
  info: 'INFO',
  debug: 'DEBUG',
}

const LEVEL_VARIANTS = {
  error: 'error',
  warn: 'warning',
  info: 'info',
  debug: 'inactive',
} as const satisfies Record<RuntimeLogLevel, string>

/**
 * Column widths, shared by the header and every row so they line up. The list
 * is a `@container/logs`; below 640px a row stacks its message under the rest.
 */
export const RUNTIME_LOG_COLUMNS = {
  time: 'w-[92px]',
  timeWithDate: 'w-[148px]',
  level: 'w-[52px]',
  source: 'min-w-0 flex-1 @[640px]/logs:w-[176px] @[640px]/logs:flex-none',
} as const

export const RUNTIME_LOG_SOURCE_ICONS: Record<
  string,
  ComponentType<{ className?: string }>
> = {
  functions: Zap,
  sites: Globe,
  databases: Database,
}

export function RuntimeLogLevelBadge({
  level,
  className,
}: {
  level: RuntimeLogLevel
  className?: string
}) {
  return (
    <Badge
      variant={LEVEL_VARIANTS[level]}
      className={cn(
        'h-[18px] min-w-[44px] rounded px-1.5 font-mono text-[10px] font-semibold tracking-wide',
        className,
      )}
    >
      {RUNTIME_LOG_LEVEL_LABELS[level]}
    </Badge>
  )
}

/**
 * What wrote the line, by its own mark: the runtime's logo for a function,
 * the framework's for a site, the engine's for a database. Until the resource
 * loads, or when it names none, the kind's icon from the sidebar stands in.
 */
export function RuntimeLogSourceLogo({
  type,
  source,
  className,
}: {
  type: string
  source: RuntimeLogSource | undefined
  className?: string
}) {
  if (type === 'functions' && source?.runtime) {
    return (
      <RuntimeIcon runtime={source.runtime} size="sm" className={className} />
    )
  }
  if (type === 'sites' && source?.framework) {
    return (
      <FrameworkIcon
        framework={source.framework}
        size="sm"
        className={className}
      />
    )
  }
  if (type === 'databases' && source) {
    return (
      <DatabaseTypeIcon
        engine={source.engine}
        apiType={source.api}
        className={className}
      />
    )
  }
  const Icon = RUNTIME_LOG_SOURCE_ICONS[type] ?? FileText
  return <Icon className={cn('text-muted-foreground', className)} />
}

/** The source's logo on a neutral tile, as service avatars are across the Console. */
export function RuntimeLogSourceIcon({
  type,
  source,
  className,
  logoClassName = 'h-3.5 w-3.5',
}: {
  type: string
  source: RuntimeLogSource | undefined
  className?: string
  logoClassName?: string
}) {
  return (
    <span
      aria-hidden
      className={cn(
        'flex h-5 w-5 shrink-0 items-center justify-center rounded-[5px] bg-muted',
        className,
      )}
    >
      <RuntimeLogSourceLogo
        type={type}
        source={source}
        className={cn('shrink-0', logoClassName)}
      />
    </span>
  )
}

export interface RuntimeLogRowProps {
  entry: RuntimeLogEntry
  /** Project-wide lists name the resource behind each line. */
  showSource: boolean
  source: RuntimeLogSource | undefined
  /** Set while the details drawer shows this line. */
  selected: boolean
  /** Arrived while live; fades in once. */
  arrived: boolean
  wrap: boolean
  withDate: boolean
  formatTime: (timestamp: string) => string
  insetClassName?: string
  onOpen: (id: string) => void
  /** Narrows a project-wide list to this line's resource. */
  onShowSource?: (entry: RuntimeLogEntry) => void
  /** Opens the line's resource, when the Console has a page for it. */
  onOpenSource?: (entry: RuntimeLogEntry) => void
}

const OPEN_SOURCE_LABELS: Record<string, string> = {
  functions: 'Open function',
  sites: 'Open site',
  databases: 'Open database',
}

/**
 * Whether the list can narrow to the line's resource: every function and
 * site, and a database whose lines carry its own ID, as a native engine's do.
 * A TablesDB, DocumentsDB or VectorsDB logs under its compute's ID instead.
 */
export function canFilterRuntimeLogSource(
  resourceType: string,
  source: RuntimeLogSource | undefined,
): boolean {
  if (resourceType === 'functions' || resourceType === 'sites') return true
  return resourceType === 'databases' && !!source?.engine && !source.api
}

/**
 * Whether the Console has a page for the line's resource: every function and
 * site, and databases on a native PostgreSQL or MySQL engine. A TablesDB,
 * DocumentsDB or VectorsDB database logs under its compute, which has no page
 * of its own.
 */
export function canOpenRuntimeLogSource(
  resourceType: string,
  source: RuntimeLogSource | undefined,
): boolean {
  if (resourceType === 'functions' || resourceType === 'sites') return true
  return (
    resourceType === 'databases' &&
    (source?.engine === 'postgresql' || source?.engine === 'mysql') &&
    !source.api
  )
}

/**
 * One runtime log line in the list: time, level, the resource on
 * project-wide lists, then the message with its fields. The row opens the
 * line's details; right-click offers copy and filter actions.
 */
export const RuntimeLogRow = memo(function RuntimeLogRow({
  entry,
  showSource,
  source,
  selected,
  arrived,
  wrap,
  withDate,
  formatTime,
  insetClassName,
  onOpen,
  onShowSource,
  onOpenSource,
}: RuntimeLogRowProps) {
  const t = useT()
  const { log, line, level } = entry
  const fields = rowRuntimeLogFields(line)
  const shown = wrap ? fields : fields.slice(0, ROW_FIELDS)
  const hidden = fields.length - shown.length
  const openLabel =
    onOpenSource && canOpenRuntimeLogSource(log.resourceType, source)
      ? OPEN_SOURCE_LABELS[log.resourceType]
      : null
  const canFilter =
    !!onShowSource && canFilterRuntimeLogSource(log.resourceType, source)

  const toggle = () => {
    // Selecting text to copy shouldn't open the details
    if (window.getSelection()?.toString()) return
    onOpen(log.$id)
  }

  const copy = (text: string, label: string) => {
    void navigator.clipboard
      .writeText(text)
      .then(() => toast.success(label))
      .catch(() => toast.error(t('Could not copy to the clipboard')))
  }

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>
        <button
          type="button"
          onClick={toggle}
          aria-haspopup="dialog"
          data-log-id={log.$id}
          data-level={level}
          data-selected={selected || undefined}
          className={cn(
            'group flex w-full min-w-0 cursor-pointer flex-wrap gap-x-3 gap-y-0.5 border-b border-border/50 py-1.5 text-start outline-none transition-colors @[640px]/logs:flex-nowrap',
            'focus-visible:bg-muted/60 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring/50',
            wrap
              ? 'items-start @[640px]/logs:py-[5px]'
              : 'items-center @[640px]/logs:h-8 @[640px]/logs:py-0',
            selected
              ? 'bg-muted'
              : level === 'error'
                ? 'bg-red-500/[0.035] hover:bg-red-500/[0.07] dark:bg-red-500/[0.05] dark:hover:bg-red-500/[0.09]'
                : level === 'warn'
                  ? 'bg-amber-500/[0.03] hover:bg-amber-500/[0.07] dark:bg-amber-500/[0.04] dark:hover:bg-amber-500/[0.08]'
                  : 'hover:bg-muted/50',
            arrived && 'animate-in fade-in duration-700',
            insetClassName,
          )}
          // Long lists stay fast: the browser skips rows far off screen
          style={
            wrap
              ? undefined
              : { contentVisibility: 'auto', containIntrinsicSize: 'auto 32px' }
          }
        >
          <time
            dateTime={log.timestamp}
            className={cn(
              'shrink-0 whitespace-nowrap font-mono text-[12px] leading-5 text-muted-foreground tabular-nums',
              withDate
                ? RUNTIME_LOG_COLUMNS.timeWithDate
                : RUNTIME_LOG_COLUMNS.time,
            )}
          >
            {formatTime(log.timestamp)}
          </time>
          <span className={cn('flex shrink-0', RUNTIME_LOG_COLUMNS.level)}>
            <RuntimeLogLevelBadge level={level} />
          </span>
          {showSource && (
            <span
              className={cn(
                'flex items-center gap-2',
                RUNTIME_LOG_COLUMNS.source,
              )}
              title={`${log.resourceType}/${log.resourceId}`}
            >
              <RuntimeLogSourceIcon type={log.resourceType} source={source} />
              <span
                className={cn(
                  'min-w-0 truncate text-[12.5px] leading-5',
                  source
                    ? 'text-foreground'
                    : 'font-mono text-[12px] text-muted-foreground',
                )}
              >
                {source?.name ?? log.resourceId}
              </span>
            </span>
          )}
          <span
            className={cn(
              'min-w-0 flex-1 basis-full font-mono text-[12px] leading-5 @[640px]/logs:basis-auto',
              wrap
                ? 'whitespace-pre-wrap [overflow-wrap:anywhere]'
                : 'truncate',
            )}
          >
            {line.message !== '' && (
              <span className="text-foreground">
                {wrap ? line.message : summarizeRuntimeLogMessage(line.message)}
              </span>
            )}
            {shown.map((field, i) => (
              <span
                key={`${field.key}-${i}`}
                className={cn(
                  (line.message !== '' || i > 0) && 'ms-3',
                  'text-muted-foreground',
                )}
              >
                {field.key}=
                <span className="text-foreground/75">
                  {wrap ? field.value : firstLine(field.value)}
                </span>
              </span>
            ))}
            {hidden > 0 && (
              <span className="ms-3 text-muted-foreground/70">+{hidden}</span>
            )}
          </span>
        </button>
      </ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={() => onOpen(log.$id)}>
          <ContextMenuIcon icon={FileText} />
          {t('View details')}
        </ContextMenuItem>
        {(canFilter || openLabel) && <ContextMenuSeparator />}
        {canFilter && (
          <ContextMenuItem onSelect={() => onShowSource?.(entry)}>
            <ContextMenuIcon icon={Filter} />
            <span className="min-w-0 truncate">{t('Only this resource')}</span>
          </ContextMenuItem>
        )}
        {openLabel && (
          <ContextMenuItem onSelect={() => onOpenSource?.(entry)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t(openLabel)}
          </ContextMenuItem>
        )}
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent className="w-48">
            <ContextMenuItem
              onSelect={() =>
                copy(line.message || entry.plain, t('Message copied'))
              }
            >
              <ContextMenuIcon icon={TextQuote} />
              {t('Copy message')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copy(entry.plain, t('Line copied'))}
            >
              <ContextMenuIcon icon={FileText} />
              {t('Copy line')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copy(JSON.stringify(log, null, 2), t('JSON copied'))
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
            <ContextMenuItem onSelect={() => copy(log.$id, t('ID copied'))}>
              <ContextMenuIcon icon={Hash} />
              {t('Copy ID')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
      </ContextMenuContent>
    </ContextMenu>
  )
})

/** The first line of a multi-line value, marked as cut. */
function firstLine(value: string): string {
  const newline = value.indexOf('\n')
  return newline === -1 ? value : `${value.slice(0, newline)} …`
}
