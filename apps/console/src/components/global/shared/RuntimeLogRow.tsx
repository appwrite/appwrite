import { memo } from 'react'
import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { ChevronRight } from 'lucide-react'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { formatRawLog, logfmtFields } from '@/lib/runtime-log-line'
import type { RuntimeLogLevel, RuntimeLogLine } from '@/lib/runtime-log-line'

/** Fields shown inline after the message on a collapsed row. */
const INLINE_FIELDS = 6

/** The row already shows its own time, so these only appear when expanded. */
const TIME_KEYS = new Set(['time', 'timestamp', 'ts', '@t', '@timestamp'])

const LEVEL_LABELS: Record<RuntimeLogLevel, string> = {
  error: 'ERROR',
  warn: 'WARN',
  info: 'INFO',
  debug: 'DEBUG',
}

const LEVEL_BORDERS: Record<RuntimeLogLevel, string> = {
  error: 'border-s-red-500/80',
  warn: 'border-s-amber-500/80',
  info: 'border-s-transparent',
  debug: 'border-s-transparent',
}

const LEVEL_BADGES: Record<RuntimeLogLevel, string> = {
  error: 'bg-red-500/10 text-red-700 dark:text-red-300',
  warn: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
  info: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
  debug: 'bg-muted text-muted-foreground',
}

const LEVEL_TEXT: Record<RuntimeLogLevel, string> = {
  error: 'text-red-700 dark:text-red-300',
  warn: 'text-amber-700 dark:text-amber-300',
  info: '',
  debug: 'text-muted-foreground',
}

export interface RuntimeLogRowProps {
  log: Models.RuntimeLog
  line: RuntimeLogLine
  /** The line's own level, or one taken from its stream. */
  level: RuntimeLogLevel
  /** Message with color codes removed, as copied and downloaded. */
  plain: string
  /** Set on the project's Logs page, which names each row's resource. */
  projectId?: string
  expanded: boolean
  onToggle: (id: string) => void
  /** Time, stream and region as columns, instead of on hover and expand. */
  showMetadata: boolean
  formatTime: (timestamp: string) => string
  formatDateTime: (timestamp: string) => string
}

/**
 * One runtime log line: message first, with the level and any structured
 * fields beside it. Time, stream, region and deployment show on hover, when
 * expanded, or as columns when the viewer turns metadata on.
 */
export const RuntimeLogRow = memo(function RuntimeLogRow({
  log,
  line,
  level,
  plain,
  projectId,
  expanded,
  onToggle,
  showMetadata,
  formatTime,
  formatDateTime,
}: RuntimeLogRowProps) {
  const t = useT()
  const structured = line.format !== 'text'
  const stderr = log.stream === 'stderr'
  const shown = line.fields.filter((field) => !TIME_KEYS.has(field.key))
  const inline = shown.slice(0, INLINE_FIELDS)
  const hidden = shown.length - inline.length

  const toggle = () => {
    // Selecting text to copy shouldn't open the row
    if (window.getSelection()?.toString()) return
    onToggle(log.$id)
  }

  return (
    <div
      className={cn(
        'border-s-2 font-mono text-[12px] leading-5',
        LEVEL_BORDERS[level],
        expanded && 'bg-muted/40',
      )}
    >
      <div className="group flex min-w-0 items-start gap-2 rounded-e px-2 py-0.5 hover:bg-muted/50">
        <button
          type="button"
          onClick={toggle}
          aria-expanded={expanded}
          className="flex min-w-0 flex-1 cursor-pointer items-baseline gap-2 text-start outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
        >
          <ChevronRight
            aria-hidden
            className={cn(
              'h-3 w-3 shrink-0 self-center text-muted-foreground/60 transition-transform',
              expanded && 'rotate-90',
            )}
          />
          {showMetadata && (
            <>
              <time
                dateTime={log.timestamp}
                title={log.timestamp}
                className="shrink-0 whitespace-nowrap text-muted-foreground tabular-nums"
              >
                {formatTime(log.timestamp)}
              </time>
              <span
                className={cn(
                  'w-12 shrink-0 uppercase',
                  stderr
                    ? 'text-red-600 dark:text-red-400'
                    : 'text-muted-foreground',
                )}
              >
                {log.stream}
              </span>
              <span className="w-8 shrink-0 uppercase text-muted-foreground">
                {log.region}
              </span>
            </>
          )}
          {structured && line.level && (
            <span
              className={cn(
                'shrink-0 rounded px-1 text-[10px] font-semibold leading-4 tracking-wide',
                LEVEL_BADGES[line.level],
              )}
            >
              {LEVEL_LABELS[line.level]}
            </span>
          )}
          <span className="min-w-0 flex-1">
            {line.message !== '' && (
              <span
                className={cn(
                  'whitespace-pre-wrap break-words',
                  structured && line.level
                    ? LEVEL_TEXT[line.level]
                    : stderr || level === 'error'
                      ? LEVEL_TEXT.error
                      : level === 'warn'
                        ? LEVEL_TEXT.warn
                        : undefined,
                )}
              >
                {structured ? line.message : plain}
              </span>
            )}
            {inline.length > 0 && (
              <span
                className={cn(
                  'text-muted-foreground',
                  line.message !== '' && 'ms-2',
                  !expanded && 'break-all',
                )}
              >
                {line.message !== '' && ' '}
                {inline.map((field, i) => (
                  <span key={`${field.key}-${i}`} className="me-2">
                    <span className="text-muted-foreground/70">
                      {field.key}=
                    </span>
                    <span className="text-foreground/80">
                      {truncate(field.value)}
                    </span>{' '}
                  </span>
                ))}
                {hidden > 0 && (
                  <span className="text-muted-foreground/70">+{hidden}</span>
                )}
              </span>
            )}
          </span>
        </button>
        {projectId && <ResourceCell projectId={projectId} log={log} />}
        {!showMetadata && (
          <time
            dateTime={log.timestamp}
            title={formatDateTime(log.timestamp)}
            className={cn(
              'shrink-0 whitespace-nowrap text-[11px] text-muted-foreground tabular-nums transition-opacity',
              expanded ? 'opacity-100' : 'opacity-0 group-hover:opacity-100',
            )}
          >
            {formatTime(log.timestamp)}
          </time>
        )}
      </div>
      {expanded && (
        <div className="mb-1 ms-7 me-2 rounded border border-border bg-background px-3 py-2">
          <dl className="grid grid-cols-[minmax(80px,max-content)_1fr] gap-x-4 gap-y-0.5">
            <Detail label={t('Time')}>
              {formatDateTime(log.timestamp)}{' '}
              <span className="text-muted-foreground">{log.timestamp}</span>
            </Detail>
            {line.level && (
              <Detail label={t('Level')}>{LEVEL_LABELS[line.level]}</Detail>
            )}
            <Detail label={t('Stream')}>{log.stream}</Detail>
            <Detail label={t('Region')}>{log.region}</Detail>
            {log.deploymentId && (
              <Detail label={t('Deployment')}>{log.deploymentId}</Detail>
            )}
            {projectId && (
              <Detail label={t('Resource')}>
                {log.resourceType}/{log.resourceId}
              </Detail>
            )}
          </dl>
          <RuntimeLogEntry line={line} plain={plain} />
        </div>
      )}
    </div>
  )
})

/**
 * The entry itself under the metadata: its fields, then the whole line as it
 * was logged, so nothing is hidden behind the parsed view.
 */
function RuntimeLogEntry({
  line,
  plain,
}: {
  line: RuntimeLogLine
  plain: string
}) {
  const t = useT()
  const fields =
    line.fields.length > 0
      ? line.fields
      : line.format === 'text'
        ? (logfmtFields(plain) ?? [])
        : []

  return (
    <>
      {fields.length > 0 && (
        <>
          <div className="my-2 border-t border-border" />
          <dl className="grid grid-cols-[minmax(80px,max-content)_1fr] gap-x-4 gap-y-0.5">
            {fields.map((field, i) => (
              <Detail key={`${field.key}-${i}`} label={field.key}>
                {field.value}
              </Detail>
            ))}
          </dl>
        </>
      )}
      <div className="my-2 border-t border-border" />
      <div className="flex items-center justify-between gap-2">
        <span className="text-muted-foreground">{t('Raw')}</span>
        <CodeSnippetCopyButton content={plain} />
      </div>
      <pre className="mt-1 max-h-80 overflow-auto whitespace-pre-wrap break-all rounded bg-muted/50 px-2 py-1.5">
        {formatRawLog(plain)}
      </pre>
    </>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="truncate text-muted-foreground" title={label}>
        {label}
      </dt>
      <dd className="min-w-0 whitespace-pre-wrap break-all">{children}</dd>
    </>
  )
}

function truncate(value: string): string {
  const single = value.includes('\n') ? `${value.split('\n')[0]} …` : value
  return single.length > 120 ? `${single.slice(0, 120)}…` : single
}

const RESOURCE_LABELS: Record<string, string> = {
  functions: 'function',
  sites: 'site',
  databases: 'database',
}

/** Names the resource behind a project-wide row, linking to its own logs. */
function ResourceCell({
  projectId,
  log,
}: {
  projectId: string
  log: Models.RuntimeLog
}) {
  const label = `${RESOURCE_LABELS[log.resourceType] ?? log.resourceType}/${log.resourceId}`
  const className =
    'max-w-[200px] shrink-0 truncate text-[11px] text-muted-foreground hover:text-foreground hover:underline'
  const search = log.deploymentId ? { deploymentId: log.deploymentId } : {}
  if (log.resourceType === 'functions') {
    return (
      <Link
        to="/projects/$projectId/functions/$functionId/runtime-logs"
        params={{ projectId, functionId: log.resourceId }}
        search={search}
        title={label}
        className={className}
      >
        {label}
      </Link>
    )
  }
  if (log.resourceType === 'sites') {
    return (
      <Link
        to="/projects/$projectId/sites/$siteId/runtime-logs"
        params={{ projectId, siteId: log.resourceId }}
        search={search}
        title={label}
        className={className}
      >
        {label}
      </Link>
    )
  }
  return (
    <span
      title={label}
      className="max-w-[200px] shrink-0 truncate text-[11px] text-muted-foreground"
    >
      {label}
    </span>
  )
}
