/**
 * Right-side drawer for migration details.
 * Uses BaseDrawer; shows status counters and parsed errors (not just logs).
 * Receives migration from list so realtime updates flow through.
 */

import { useMemo, useState, useEffect } from 'react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  AlertCircle,
  CheckCircle2,
  Loader2,
  Clock,
  XCircle,
  AlertTriangle,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { parseStatusCounters, type StatusCounter } from './migrationProgress'
import { useT } from '@/lib/i18n/translate'

interface MigrationDetailsDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  migration: Models.Migration
}

function getEntityLabel(key: string, total: number): string {
  const singular = key.charAt(0).toUpperCase() + key.slice(1).toLowerCase()
  return total === 1 ? singular : `${singular}s`
}

/** Parsed error with optional resource context */
interface ParsedError {
  /** Human-friendly one-line description */
  friendlyMessage: string
  code?: number
  resourceName?: string
  resourceGroup?: string
  resourceId?: string
  raw?: unknown
}

/** Map HTTP/API error codes to human-readable descriptions */
const ERROR_CODE_MESSAGES: Record<number, string> = {
  400: 'Bad request',
  401: 'Unauthorized',
  403: 'Forbidden',
  404: 'Not found',
  409: 'Conflict',
  413: 'Payload too large',
  422: 'Validation failed',
  429: 'Too many requests',
  500: 'Server error',
  502: 'Bad gateway',
  503: 'Service unavailable',
}

function getMessageForCode(code: number): string {
  return ERROR_CODE_MESSAGES[code] ?? `Error (${code})`
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase()
}

function formatResourceContext(
  resourceName?: string,
  resourceGroup?: string,
  resourceId?: string,
): string {
  const parts: string[] = []
  if (resourceName) parts.push(capitalize(resourceName))
  if (resourceGroup) parts.push(`in ${capitalize(resourceGroup)}`)
  if (resourceId && resourceId.length > 8) {
    parts.push(`(${resourceId.slice(0, 8)}…)`)
  }
  return parts.join(' ')
}

function parseMigrationErrors(errors: unknown[] | undefined): ParsedError[] {
  if (!errors?.length) return []
  return errors.map((error) => {
    try {
      const parsed =
        typeof error === 'string'
          ? JSON.parse(error)
          : (error as Record<string, unknown>)
      if (parsed && typeof parsed === 'object') {
        const code = typeof parsed.code === 'number' ? parsed.code : undefined
        const message =
          typeof parsed.message === 'string' && parsed.message.trim()
            ? parsed.message.trim()
            : code != null
              ? getMessageForCode(code)
              : typeof parsed.error === 'string'
                ? parsed.error
                : 'Unknown error'
        const resourceName =
          typeof parsed.resourceName === 'string'
            ? parsed.resourceName
            : undefined
        const resourceGroup =
          typeof parsed.resourceGroup === 'string'
            ? parsed.resourceGroup
            : undefined
        const resourceId =
          typeof parsed.resourceId === 'string' ? parsed.resourceId : undefined

        const context = formatResourceContext(
          resourceName,
          resourceGroup,
          resourceId,
        )
        const friendlyMessage =
          context.length > 0 ? `${context}: ${message}` : message

        return {
          friendlyMessage,
          code,
          resourceName,
          resourceGroup,
          resourceId,
          raw: parsed,
        }
      }
    } catch {
      // fallback: use as string
    }
    return {
      friendlyMessage: typeof error === 'string' ? error : String(error),
      raw: error,
    }
  })
}

export function MigrationDetailsDrawer({
  open,
  onOpenChange,
  migration,
}: MigrationDetailsDrawerProps) {
  const t = useT()
  const statusCountersMap = useMemo(
    () => parseStatusCounters(migration),
    [migration],
  )

  const parsedErrors = useMemo(
    () => parseMigrationErrors(migration.errors as unknown[] | undefined),
    [migration.errors],
  )

  const countSummary = useMemo(() => {
    let succeeded = 0
    let failed = 0
    let skipped = 0
    let warning = 0
    for (const counter of Object.values(statusCountersMap)) {
      const c = (counter || {}) as StatusCounter
      succeeded += c.success ?? 0
      failed += c.error ?? 0
      skipped += c.skip ?? 0
      warning += c.warning ?? 0
    }
    return { succeeded, failed, skipped, warning }
  }, [statusCountersMap])

  const entityEntries = useMemo(() => {
    return Object.entries(statusCountersMap).map(([entityKey, counter]) => {
      const c = (counter || {}) as StatusCounter
      const pending = c.pending ?? 0
      const success = c.success ?? 0
      const error = c.error ?? 0
      const skip = c.skip ?? 0
      const processing = c.processing ?? 0
      const warning = c.warning ?? 0
      const total = pending + success + error + skip + processing + warning

      let icon = Clock
      let tone: 'error' | 'warning' | 'processing' | 'success' | 'waiting' =
        'waiting'
      if (error > 0 || warning > 0) {
        icon = error > 0 ? XCircle : AlertTriangle
        tone = error > 0 ? 'error' : 'warning'
      } else if (pending > 0 || processing > 0) {
        icon = Loader2
        tone = 'processing'
      } else if (success > 0) {
        icon = CheckCircle2
        tone = 'success'
      }
      return {
        key: entityKey,
        label: getEntityLabel(entityKey, total),
        total,
        succeeded: success,
        icon,
        tone,
      }
    })
  }, [statusCountersMap])

  const hasStatusErrors =
    entityEntries.some(
      (e) =>
        (statusCountersMap[e.key] as StatusCounter)?.error &&
        (statusCountersMap[e.key] as StatusCounter).error! > 0,
    ) || parsedErrors.length > 0

  const title =
    migration.status === 'failed'
      ? t('Resolve migration issues')
      : t('Migration details')

  const MAX_ERRORS_IN_DETAILS = 3
  const displayedErrors = parsedErrors.slice(0, MAX_ERRORS_IN_DETAILS)
  const hasMoreErrors = parsedErrors.length > MAX_ERRORS_IN_DETAILS

  const [activeTab, setActiveTab] = useState<'details' | 'logs'>('details')

  useEffect(() => {
    if (open) setActiveTab('details')
  }, [open, migration.$id])

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={title}
      maxWidth="sm:max-w-xl"
      side="right"
    >
      <>
        <div className="border-t border-border shrink-0" />

        <div className="flex flex-1 flex-col min-h-0">
          <div className="flex-1 overflow-y-auto">
            <div className="px-6 py-6">
              <Tabs
                value={activeTab}
                onValueChange={(v) => setActiveTab(v as 'details' | 'logs')}
                className="w-full"
              >
                <TabsList className="w-full grid grid-cols-2 h-9">
                  <TabsTrigger value="details" className="text-[13px]">
                    {t('Details')}
                  </TabsTrigger>
                  <TabsTrigger value="logs" className="text-[13px]">
                    {t('Logs')}
                  </TabsTrigger>
                </TabsList>

                <TabsContent
                  value="details"
                  className="mt-6 flex flex-1 flex-col gap-6 min-h-0"
                >
                  {/* Overview card */}
                  <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                    <div className="px-6 py-3">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        {t('Overview')}
                      </h3>
                    </div>
                    <div className="border-t border-border" />
                    <div className="px-6 py-3 grid grid-cols-2 gap-x-4 gap-y-3">
                      <div className="min-w-0">
                        <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                          {t('Created')}
                        </p>
                        <p className="mt-0.5 text-[13px] text-foreground">
                          <DateTooltip
                            date={migration.$createdAt}
                            showFormattedDate
                          />
                        </p>
                      </div>
                      <div className="min-w-0">
                        <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                          {t('Source')}
                        </p>
                        <p className="mt-0.5 text-[13px] text-muted-foreground">
                          {migration.source}
                        </p>
                      </div>
                    </div>
                    {migration.status === 'failed' &&
                      (countSummary.succeeded > 0 ||
                        countSummary.failed > 0 ||
                        countSummary.skipped > 0 ||
                        countSummary.warning > 0) && (
                        <>
                          <div className="border-t border-border" />
                          <div className="px-6 py-3">
                            <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                              {t('Summary')}
                            </p>
                            <p className="mt-0.5 text-[13px] text-muted-foreground">
                              {countSummary.succeeded > 0 && (
                                <span>
                                  {countSummary.succeeded}{' '}
                                  {countSummary.succeeded === 1
                                    ? t('item')
                                    : t('items')}{' '}
                                  {t('succeeded')}
                                  {(countSummary.failed > 0 ||
                                    countSummary.skipped > 0 ||
                                    countSummary.warning > 0) &&
                                    ', '}
                                </span>
                              )}
                              {countSummary.failed > 0 && (
                                <span>
                                  {countSummary.failed}{' '}
                                  {countSummary.failed === 1 ? t('item') : t('items')}{' '}
                                  {t('failed')}
                                  {(countSummary.skipped > 0 ||
                                    countSummary.warning > 0) &&
                                    ', '}
                                </span>
                              )}
                              {countSummary.skipped > 0 && (
                                <span>
                                  {countSummary.skipped} {t('skipped')}
                                  {countSummary.warning > 0 && ', '}
                                </span>
                              )}
                              {countSummary.warning > 0 && (
                                <span>{countSummary.warning} {t('warning')}</span>
                              )}
                            </p>
                          </div>
                        </>
                      )}
                  </div>

                  {/* Status card */}
                  <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                    <div className="px-6 py-3">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        {t('Status')}
                      </h3>
                    </div>
                    <div className="border-t border-border" />
                    <div className="px-6 py-3">
                      {entityEntries.length > 0 ? (
                        <div className="grid gap-2 sm:grid-cols-2">
                          {entityEntries.map(
                            ({
                              key,
                              label,
                              total,
                              succeeded,
                              icon: Icon,
                              tone,
                            }) => (
                              <div
                                key={key}
                                className="flex items-center gap-2 rounded-lg border border-border bg-background p-3"
                              >
                                <div
                                  className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                                    tone === 'error'
                                      ? 'bg-red-500/10'
                                      : tone === 'warning'
                                        ? 'bg-amber-500/10'
                                        : tone === 'processing'
                                          ? 'bg-blue-500/10'
                                          : tone === 'success'
                                            ? 'bg-green-500/10'
                                            : 'bg-muted'
                                  }`}
                                >
                                  <Icon
                                    className={`h-4 w-4 ${
                                      tone === 'error'
                                        ? 'text-red-600 dark:text-red-400'
                                        : tone === 'warning'
                                          ? 'text-amber-600 dark:text-amber-400'
                                          : tone === 'processing'
                                            ? 'text-blue-600 dark:text-blue-400 animate-spin'
                                            : tone === 'success'
                                              ? 'text-green-600 dark:text-green-400'
                                              : 'text-muted-foreground'
                                    }`}
                                  />
                                </div>
                                <div className="min-w-0 flex-1">
                                  <p className="truncate text-[13px] font-medium">
                                    {label}
                                  </p>
                                  <p className="text-[12px] text-muted-foreground">
                                    {succeeded} / {total}{' '}
                                    {total === 1 ? t('item') : t('items')}
                                  </p>
                                </div>
                                <Badge
                                  variant="info"
                                  className="shrink-0 text-[10px]"
                                >
                                  {succeeded} / {total}
                                </Badge>
                              </div>
                            ),
                          )}
                        </div>
                      ) : (
                        <p className="text-[13px] text-muted-foreground">
                          {t('No status data yet')}
                        </p>
                      )}
                    </div>
                  </div>

                  {hasStatusErrors && parsedErrors.length === 0 && (
                    <Alert className="border-red-500/30 bg-red-500/5">
                      <AlertCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
                      <AlertTitle className="text-red-600 dark:text-red-400">
                        {t('Migration errors')}
                      </AlertTitle>
                      <AlertDescription className="text-foreground">
                        {t(
                          'Some entities failed to migrate. Check status counts above.',
                        )}
                      </AlertDescription>
                    </Alert>
                  )}

                  {parsedErrors.length > 0 && (
                    <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-red-500/30 bg-red-500/5 px-4 py-3 text-foreground">
                      <div className="flex items-start gap-3 shrink-0">
                        <AlertCircle className="h-4 w-4 translate-y-0.5 shrink-0 text-red-600 dark:text-red-400" />
                        <p className="font-medium tracking-tight text-sm text-red-600 dark:text-red-400">
                          {parsedErrors.length}{' '}
                          {parsedErrors.length === 1 ? t('error') : t('errors')}{' '}
                          {t('recorded')}
                        </p>
                      </div>
                      <ul
                        className="mt-2 list-disc list-outside ps-5 space-y-1.5 overflow-y-auto overflow-x-hidden pe-2 text-[13px] text-foreground min-h-0 flex-1 overscroll-contain"
                        onWheel={(e) => {
                          const el = e.currentTarget
                          const { scrollTop, scrollHeight, clientHeight } = el
                          const atTop = scrollTop <= 0
                          const atBottom =
                            scrollTop + clientHeight >= scrollHeight
                          const scrollingDown = e.deltaY > 0
                          const scrollingUp = e.deltaY < 0
                          if (
                            (atTop && scrollingUp) ||
                            (atBottom && scrollingDown)
                          ) {
                            return
                          }
                          e.stopPropagation()
                        }}
                      >
                        {displayedErrors.map((err, i) => (
                          <li key={i}>{err.friendlyMessage}</li>
                        ))}
                      </ul>
                      {hasMoreErrors && (
                        <div className="mt-2 shrink-0 border-t border-red-500/20 pt-2">
                          <p className="text-[12px] text-muted-foreground">
                            {t('Showing first')} {MAX_ERRORS_IN_DETAILS}{' '}
                            {t('of')} {parsedErrors.length} {t('errors.')}
                          </p>
                          <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-[12px] text-foreground underline underline-offset-2 hover:no-underline focus-visible:ring-2 focus-visible:ring-ring"
                            onClick={() => setActiveTab('logs')}
                          >
                            {t('View full list in Logs')}
                          </Button>
                        </div>
                      )}
                    </div>
                  )}
                </TabsContent>

                <TabsContent value="logs" className="mt-6">
                  <CodeBlock
                    code={JSON.stringify(migration, null, 2)}
                    language="json"
                    showCopy={true}
                    fixedHeight="400px"
                  />
                </TabsContent>
              </Tabs>
            </div>
          </div>
        </div>
      </>
    </BaseDrawer>
  )
}
