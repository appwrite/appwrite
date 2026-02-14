/**
 * CSV export progress items (no wrapper).
 * Renders the same card style as file uploads; shows Download link when export completes.
 */

import { useMemo } from 'react'
import { X, Download, Loader2, CheckCircle2, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { useCsvExportMigrations } from '@/lib/react-query/hooks'
import { useSessionMigrations } from '@/components/global/providers/SessionMigrationsContext'
import type { Models } from '@appwrite.io/console'

const statusToProgress: Record<string, number> = {
  pending: 10,
  processing: 60,
  completed: 100,
  failed: 100,
}

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

interface CsvExportBoxProps {
  projectId: string
}

export function CsvExportBox({ projectId }: CsvExportBoxProps) {
  const {
    sessionExportIds,
    dismissedExportIds,
    dismissExport,
  } = useSessionMigrations(projectId)
  const { migrations } = useCsvExportMigrations(projectId, sessionExportIds)
  const dismissedSet = useMemo(
    () => new Set(dismissedExportIds),
    [dismissedExportIds],
  )
  const visible = migrations.filter((m) => !dismissedSet.has(m.$id))

  if (visible.length === 0) return null

  return (
    <div className="w-full max-w-sm space-y-2">
      {visible.map((m: Models.Migration) => {
        const progress = statusToProgress[m.status] ?? 50
        const isFailed = m.status === 'failed'
        const isCompleted = m.status === 'completed'
        const isPendingOrProcessing =
          m.status === 'pending' || m.status === 'processing'
        const url = (m.options as { downloadUrl?: string })?.downloadUrl
        const label = statusToLabel(m.status)
        return (
          <div
            key={m.$id}
            className="rounded-lg border border-border bg-background p-3"
          >
            <div className="flex items-start gap-3">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  {isPendingOrProcessing ? (
                    <Loader2 className="h-4 w-4 animate-spin text-primary shrink-0" />
                  ) : isCompleted ? (
                    <CheckCircle2 className="h-4 w-4 text-green-600 shrink-0" />
                  ) : isFailed ? (
                    <AlertCircle className="h-4 w-4 text-destructive shrink-0" />
                  ) : (
                    <Loader2 className="h-4 w-4 animate-spin text-muted-foreground shrink-0" />
                  )}
                  <p className="text-[13px] font-medium text-foreground truncate">
                    {label}
                  </p>
                </div>
                <ProgressBarRow value={progress} />
                <div className="min-h-[28px] flex items-center">
                  {isCompleted && url ? (
                    <Button
                      variant="link"
                      size="sm"
                      className="h-auto p-0 text-[12px]"
                      onClick={() => window.open(url, '_blank')}
                    >
                      <Download className="h-3 w-3 mr-1" />
                      Download
                    </Button>
                  ) : null}
                </div>
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-6 w-6 p-0 shrink-0"
                onClick={() => dismissExport(projectId, m.$id)}
                aria-label="Dismiss"
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
