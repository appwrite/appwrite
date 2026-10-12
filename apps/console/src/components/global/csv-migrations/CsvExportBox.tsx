/**
 * CSV export progress items (no wrapper).
 * Renders the same card style as file uploads; shows Download link when export completes.
 * Row count is throttled to avoid lag when the number updates frequently.
 */

import { useMemo, useRef, useState, useEffect, memo } from 'react'
import { X, Download, Loader2, CheckCircle2, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { useCsvExportMigrations } from '@/lib/react-query/hooks'
import { useSessionMigrations } from '@/components/global/providers/SessionMigrationsContext'
import type { Models } from '@appwrite.io/console'

const statusToLabel = (status: string): string => {
  switch (status) {
    case 'pending':
      return 'Preparing export...'
    case 'processing':
      return 'Exporting...'
    case 'completed':
      return 'Export completed'
    case 'failed':
      return 'Export failed'
    default:
      return `Export (${status})`
  }
}

const ExportStatusHeader = memo(function ExportStatusHeader({
  status,
}: {
  status: string
}) {
  const t = useT()
  const isPendingOrProcessing = status === 'pending' || status === 'processing'
  const isCompleted = status === 'completed'
  const isFailed = status === 'failed'
  const label = t(statusToLabel(status))
  return (
    <div className="flex items-center gap-2 mb-1">
      {isPendingOrProcessing ? (
        <Loader2 className="h-4 w-4 animate-spin-smooth text-primary shrink-0" />
      ) : isCompleted ? (
        <CheckCircle2 className="h-4 w-4 text-green-600 shrink-0" />
      ) : isFailed ? (
        <AlertCircle className="h-4 w-4 text-destructive shrink-0" />
      ) : (
        <Loader2 className="h-4 w-4 animate-spin-smooth text-muted-foreground shrink-0" />
      )}
      <p className="text-[13px] font-medium text-foreground truncate">
        {label}
      </p>
    </div>
  )
})

type StatusCounters = {
  row?: {
    success?: number
    processing?: number
    error?: number
    pending?: number
    skip?: number
    warning?: number
  }
}

function getExportedRowCount(m: Models.Migration): number | null {
  const counters = m.statusCounters as StatusCounters | undefined
  const row = counters?.row
  if (!row) return null
  const success = row.success ?? 0
  const processing = row.processing ?? 0
  return success + processing
}

interface CsvExportBoxProps {
  projectId: string
}

export function CsvExportBox({ projectId }: CsvExportBoxProps) {
  const t = useT()
  const { sessionExportIds, dismissedExportIds, dismissExport } =
    useSessionMigrations(projectId)
  const { migrations } = useCsvExportMigrations(projectId, sessionExportIds)
  const dismissedSet = useMemo(
    () => new Set(dismissedExportIds),
    [dismissedExportIds],
  )
  const visible = migrations.filter((m) => !dismissedSet.has(m.$id))

  const latestCountsRef = useRef<Record<string, number>>({})
  const [displayedCounts, setDisplayedCounts] = useState<
    Record<string, number>
  >({})
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)

  visible.forEach((m) => {
    const count = getExportedRowCount(m)
    if (count !== null) latestCountsRef.current[m.$id] = count
  })

  useEffect(() => {
    const throttleMs = 400
    const sync = () => {
      setDisplayedCounts((prev) => {
        const next = { ...prev }
        let changed = false
        for (const [id, count] of Object.entries(latestCountsRef.current)) {
          if (prev[id] !== count) {
            next[id] = count
            changed = true
          }
        }
        return changed ? next : prev
      })
    }
    sync()
    intervalRef.current = setInterval(sync, throttleMs)
    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current)
    }
  }, [visible.length])

  if (visible.length === 0) return null

  return (
    <div className="w-full max-w-sm space-y-2">
      {visible.map((m: Models.Migration) => {
        const isCompleted = m.status === 'completed'
        const isInProgress = m.status === 'pending' || m.status === 'processing'
        const url = (m.options as { downloadUrl?: string })?.downloadUrl
        const liveCount = getExportedRowCount(m)
        const rowCount =
          isCompleted && liveCount !== null
            ? liveCount
            : (displayedCounts[m.$id] ?? liveCount)
        const statusLine =
          rowCount !== null && rowCount > 0
            ? `${rowCount.toLocaleString()} ${t('rows exported')}`
            : m.status === 'pending'
              ? t('Preparing export...')
              : m.status === 'processing'
                ? t('Exporting...')
                : null
        return (
          <div
            key={m.$id}
            className="rounded-lg border border-border bg-background p-3"
          >
            <div className="flex items-start gap-3">
              <div className="flex-1 min-w-0">
                <ExportStatusHeader status={m.status} />
                {statusLine ? (
                  <p className="text-[11px] text-muted-foreground mb-2">
                    {statusLine}
                  </p>
                ) : (
                  <div className="mb-2 min-h-[14px]" />
                )}
                <div className="min-h-[28px] flex items-center">
                  {isCompleted && url ? (
                    <Button
                      variant="link"
                      size="sm"
                      className="h-auto p-0 text-[12px]"
                      onClick={() => window.open(url, '_blank')}
                    >
                      <Download className="h-3 w-3 me-1" />
                      {t('Download')}
                    </Button>
                  ) : isInProgress ? (
                    <Button
                      variant="link"
                      size="sm"
                      className="h-auto p-0 text-[12px]"
                      disabled
                    >
                      <Download className="h-3 w-3 me-1" />
                      {t('Download')}
                    </Button>
                  ) : null}
                </div>
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-6 w-6 p-0 shrink-0"
                onClick={() => dismissExport(projectId, m.$id)}
                aria-label={t('Dismiss')}
              >
                <X className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        )
      })}
    </div>
  )
}
