import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import {
  Check,
  ChevronDown,
  ChevronUp,
  ExternalLink,
  FileJson,
  Filter,
} from 'lucide-react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  canFilterRuntimeLogSource,
  canOpenRuntimeLogSource,
  RuntimeLogLevelBadge,
  RuntimeLogSourceIcon,
} from '@/components/global/shared/RuntimeLogRow'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import { useRegions } from '@/lib/react-query/hooks/regions'
import type { RuntimeLogSource } from '@/lib/react-query/hooks/runtime-logs'
import type { RuntimeLogEntry } from '@/lib/runtime-log-entry'
import { formatRawLog, logfmtFields } from '@/lib/runtime-log-line'
import { cn } from '@/lib/utils'

const LEVEL_NAMES = {
  error: 'Error',
  warn: 'Warning',
  info: 'Info',
  debug: 'Debug',
} as const

const SOURCE_KINDS: Record<string, string> = {
  functions: 'Function',
  sites: 'Site',
  databases: 'Database',
}

export interface RuntimeLogDrawerProps {
  entry: RuntimeLogEntry | null
  onOpenChange: (open: boolean) => void
  /** Project-wide lists name the resource; a resource's own tab doesn't. */
  showSource: boolean
  source: RuntimeLogSource | undefined
  formatTimestamp: (timestamp: string) => string
  /** The line above (newer), or below (older), in the list as filtered. */
  onNewer?: () => void
  onOlder?: () => void
  onShowSource?: (entry: RuntimeLogEntry) => void
  onOpenSource?: (entry: RuntimeLogEntry) => void
}

/**
 * Everything about one runtime log line: its level, time and resource, the
 * message, every field, where it ran, and the line exactly as written.
 * Arrow keys step to the newer or older line.
 */
export function RuntimeLogDrawer({
  entry,
  onOpenChange,
  showSource,
  source,
  formatTimestamp,
  onNewer,
  onOlder,
  onShowSource,
  onOpenSource,
}: RuntimeLogDrawerProps) {
  const t = useT()
  const open = entry !== null
  const [copiedJson, setCopiedJson] = useState(false)

  // Up and down step through the list while the drawer is open
  useEffect(() => {
    if (!open) return
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null
      if (target?.closest('input, textarea, [contenteditable="true"]')) return
      if (event.key === 'ArrowUp' || event.key === 'k') {
        if (!onNewer) return
        event.preventDefault()
        onNewer()
      } else if (event.key === 'ArrowDown' || event.key === 'j') {
        if (!onOlder) return
        event.preventDefault()
        onOlder()
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [open, onNewer, onOlder])

  useEffect(() => setCopiedJson(false), [entry])

  const copyJson = () => {
    if (!entry) return
    void navigator.clipboard
      .writeText(JSON.stringify(entry.log, null, 2))
      .then(() => setCopiedJson(true))
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Log line')}
      description={
        entry ? `${t('Details for log line')} ${entry.log.$id}` : undefined
      }
      maxWidth="sm:max-w-[640px]"
      contentClassName="overflow-hidden"
      disableAutoFocus
      // A row opens its own line here; pressing one, even while the drawer
      // closes, must not dismiss the line it is about to show
      onPointerDownOutside={(event) => {
        const target = event.target
        if (target instanceof Element && target.closest('[data-log-id]')) {
          event.preventDefault()
        }
      }}
      headerActions={
        <TooltipProvider delayDuration={0}>
          <Button
            variant="outline"
            size="sm"
            className="h-8 shrink-0 gap-1.5 text-[13px]"
            onClick={copyJson}
            disabled={!entry}
          >
            {copiedJson ? (
              <Check className="h-4 w-4 text-emerald-500" />
            ) : (
              <FileJson className="h-4 w-4" />
            )}
            {t('Copy as JSON')}
          </Button>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={onNewer}
                disabled={!onNewer}
                aria-label={t('Newer line')}
              >
                <ChevronUp className="h-4 w-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{t('Newer line')}</TooltipContent>
          </Tooltip>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={onOlder}
                disabled={!onOlder}
                aria-label={t('Older line')}
              >
                <ChevronDown className="h-4 w-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{t('Older line')}</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      }
    >
      <div className="border-t border-border shrink-0" />
      {entry && (
        <div className="min-h-0 flex-1 overflow-y-auto">
          <RuntimeLogDetails
            entry={entry}
            showSource={showSource}
            source={source}
            formatTimestamp={formatTimestamp}
            onShowSource={onShowSource}
            onOpenSource={onOpenSource}
          />
        </div>
      )}
    </BaseDrawer>
  )
}

function RuntimeLogDetails({
  entry,
  showSource,
  source,
  formatTimestamp,
  onShowSource,
  onOpenSource,
}: {
  entry: RuntimeLogEntry
  showSource: boolean
  source: RuntimeLogSource | undefined
  formatTimestamp: (timestamp: string) => string
  onShowSource?: (entry: RuntimeLogEntry) => void
  onOpenSource?: (entry: RuntimeLogEntry) => void
}) {
  const t = useT()
  const { log, line, level } = entry
  const { regions } = useRegions()
  const region = regions.find((r) => r.$id === log.region)
  const fields =
    line.fields.length > 0
      ? line.fields
      : line.format === 'text'
        ? (logfmtFields(entry.plain) ?? [])
        : []
  const message = line.message || entry.plain
  const kind = SOURCE_KINDS[log.resourceType]

  return (
    <div className="space-y-5 px-6 py-6">
      <div className="space-y-3">
        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
          <RuntimeLogLevelBadge level={level} />
          <span className="font-mono text-[13px] text-foreground tabular-nums">
            {formatTimestamp(log.timestamp)}
          </span>
          <span className="text-muted-foreground" aria-hidden>
            ·
          </span>
          <DateTooltip
            date={log.timestamp}
            className="text-[13px] text-muted-foreground"
          />
        </div>
        {showSource && (
          <div className="flex min-w-0 items-center gap-2.5 rounded-lg border border-border bg-muted/20 px-3 py-2.5">
            <RuntimeLogSourceIcon
              type={log.resourceType}
              source={source}
              className="h-8 w-8 rounded-md"
              logoClassName="h-[18px] w-[18px]"
            />
            <div className="min-w-0 flex-1">
              <p className="truncate text-[13px] font-medium text-foreground">
                {source?.name ?? log.resourceId}
              </p>
              <p className="truncate text-[12px] text-muted-foreground">
                {kind ? t(kind) : log.resourceType}
                <span aria-hidden> · </span>
                <span className="font-mono">{log.resourceId}</span>
              </p>
            </div>
            {onShowSource &&
              canFilterRuntimeLogSource(log.resourceType, source) && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 shrink-0 gap-1.5 text-[12px]"
                  onClick={() => onShowSource(entry)}
                >
                  <Filter className="h-3.5 w-3.5" />
                  {t('Only this resource')}
                </Button>
              )}
            {onOpenSource &&
              canOpenRuntimeLogSource(log.resourceType, source) && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 shrink-0 p-0"
                  onClick={() => onOpenSource(entry)}
                  aria-label={t('Open')}
                  title={t('Open')}
                >
                  <ExternalLink className="h-4 w-4" />
                </Button>
              )}
          </div>
        )}
      </div>

      <Section
        title={t('Message')}
        action={<CodeSnippetCopyButton content={message} />}
      >
        <pre className="max-h-72 overflow-auto whitespace-pre-wrap px-4 py-3 font-mono text-[12.5px] leading-relaxed text-foreground [overflow-wrap:anywhere]">
          {message}
        </pre>
      </Section>

      {fields.length > 0 && (
        <Section title={t('Fields')} count={fields.length}>
          <dl className="divide-y divide-border">
            {fields.map((field, i) => (
              <div
                key={`${field.key}-${i}`}
                className="grid grid-cols-[minmax(96px,32%)_1fr] gap-4 px-4 py-2"
              >
                <dt
                  className="truncate font-mono text-[12px] text-muted-foreground"
                  title={field.key}
                >
                  {field.key}
                </dt>
                <dd className="min-w-0 whitespace-pre-wrap font-mono text-[12px] text-foreground [overflow-wrap:anywhere]">
                  {field.value}
                </dd>
              </div>
            ))}
          </dl>
        </Section>
      )}

      <Section title={t('Details')}>
        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 px-4 py-4 sm:grid-cols-2">
          <Detail label={t('Level')}>
            {t(LEVEL_NAMES[level])}
            {entry.levelFromStream && (
              <p className="mt-0.5 text-[12px] text-muted-foreground">
                {log.stream === 'stderr'
                  ? t('The line names no level, so stderr counts as an error.')
                  : t('The line names no level, so stdout counts as info.')}
              </p>
            )}
          </Detail>
          <Detail label={t('Stream')}>
            <span className="font-mono text-[12px]">{log.stream}</span>
          </Detail>
          <Detail label={t('Region')}>
            {region?.name ? (
              <>
                {region.name}{' '}
                <span className="text-muted-foreground uppercase">
                  {log.region}
                </span>
              </>
            ) : (
              <span className="uppercase">{log.region || '-'}</span>
            )}
          </Detail>
          {log.deploymentId && (
            <Detail label={t('Deployment')}>
              <CopyableId id={log.deploymentId} size="sm" maxWidth={200} />
            </Detail>
          )}
          <Detail label={t('Log ID')}>
            <CopyableId id={log.$id} size="sm" maxWidth={200} />
          </Detail>
          <Detail label={t('Timestamp')}>
            <span className="font-mono text-[12px] [overflow-wrap:anywhere]">
              {log.timestamp}
            </span>
          </Detail>
        </dl>
      </Section>

      <Section
        title={t('Raw')}
        action={<CodeSnippetCopyButton content={entry.plain} />}
      >
        <pre className="max-h-80 overflow-auto whitespace-pre-wrap bg-muted/30 px-4 py-3 font-mono text-[12px] leading-relaxed text-foreground [overflow-wrap:anywhere]">
          {formatRawLog(entry.plain)}
        </pre>
      </Section>
    </div>
  )
}

function Section({
  title,
  count,
  action,
  children,
}: {
  title: string
  count?: number
  action?: ReactNode
  children: ReactNode
}) {
  return (
    <section className="overflow-hidden rounded-xl border border-border bg-card/40">
      <div className="flex min-h-10 items-center justify-between gap-3 border-b border-border bg-muted/20 px-4 py-1.5">
        <h3 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {title}
          {count !== undefined && (
            <span className="ms-1.5 font-medium normal-case tracking-normal tabular-nums">
              {count}
            </span>
          )}
        </h3>
        {action}
      </div>
      {children}
    </section>
  )
}

function Detail({
  label,
  children,
  className,
}: {
  label: string
  children: ReactNode
  className?: string
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <dt className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
        {label}
      </dt>
      <dd className="mt-1 min-w-0 text-[13px] text-foreground">{children}</dd>
    </div>
  )
}
