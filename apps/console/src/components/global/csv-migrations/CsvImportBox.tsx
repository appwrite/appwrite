/**
 * CSV import progress items (no wrapper).
 * Same card style as file uploads; on completed invalidates rows; on failed shows "View details".
 */

import { useState, useEffect, useRef, useMemo } from 'react'
import { X, Loader2, CheckCircle2, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import {
  useCsvImportMigrations,
  useProjectTable,
} from '@/lib/react-query/hooks'
import { getMigrationTableRef } from '@/lib/migrations/csv-resource'
import { useSessionMigrations } from '@/components/global/providers/SessionMigrationsContext'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'

const statusToProgress: Record<string, number> = {
  pending: 10,
  uploading: 30,
  processing: 60,
  completed: 100,
  failed: 100,
}

function MigrationItem({
  migration,
  projectId,
  onViewDetails,
  onDismiss,
}: {
  migration: Models.Migration
  projectId: string
  onViewDetails: (m: Models.Migration) => void
  onDismiss: () => void
}) {
  const t = useT()
  const parsed = getMigrationTableRef(migration)
  const { table } = useProjectTable(
    projectId,
    parsed?.databaseId ?? null,
    'tablesdb',
    parsed?.tableId ?? null,
  )
  const tableName = table?.name ?? parsed?.tableId ?? migration.resourceId

  const status = migration.status
  const progress = statusToProgress[status] ?? 50
  const isFailed = status === 'failed'
  const isCompleted = status === 'completed'
  const isPendingOrProcessing =
    status === 'pending' || status === 'processing' || status === 'uploading'

  let statusLabel: string
  switch (status) {
    case 'pending':
      statusLabel = t('Preparing CSV for import...')
      break
    case 'processing':
    case 'uploading':
      statusLabel = `${t('Importing to')} ${tableName}`
      break
    case 'completed':
      statusLabel = `${t('Import completed to')} ${tableName}`
      break
    case 'failed':
      statusLabel = `${t('Import failed to')} ${tableName}`
      break
    default:
      statusLabel = `Import (${status})`
  }

  return (
    <div className="rounded-lg border border-border bg-background p-3">
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
              {statusLabel}
            </p>
          </div>
          <ProgressBarRow value={progress} />
          {isFailed && migration.errors?.length > 0 && (
            <Button
              variant="link"
              size="sm"
              className="h-auto p-0 text-[12px] mt-0.5"
              onClick={() => onViewDetails(migration)}
            >
              {t('View details')}
            </Button>
          )}
        </div>
        <Button
          variant="ghost"
          size="sm"
          className="h-6 w-6 p-0 shrink-0"
          onClick={onDismiss}
          aria-label={t('Dismiss')}
        >
          <X className="h-3.5 w-3.5" />
        </Button>
      </div>
    </div>
  )
}

interface CsvImportBoxProps {
  projectId: string
}

export function CsvImportBox({ projectId }: CsvImportBoxProps) {
  const t = useT()
  const { sessionImportIds, dismissedImportIds, dismissImport } =
    useSessionMigrations(projectId)
  const { migrations } = useCsvImportMigrations(projectId, sessionImportIds)
  const queryClient = useQueryClient()
  const [detailsMigration, setDetailsMigration] =
    useState<Models.Migration | null>(null)
  const notifiedCompletedRef = useRef<Set<string>>(new Set())
  const dismissedSet = useMemo(
    () => new Set(dismissedImportIds),
    [dismissedImportIds],
  )
  const visible = migrations.filter((m) => !dismissedSet.has(m.$id))

  useEffect(() => {
    visible.forEach((m) => {
      if (m.status === 'completed') {
        if (!notifiedCompletedRef.current.has(m.$id)) {
          notifiedCompletedRef.current.add(m.$id)
          const parsed = getMigrationTableRef(m)
          queryClient.invalidateQueries({
            queryKey: [
              'rows',
              'project',
              projectId,
              parsed?.databaseId,
              parsed?.tableId,
            ],
          })
          queryClient.invalidateQueries({
            queryKey: ['tables', 'project', projectId, parsed?.databaseId],
          })
        }
      }
    })
  }, [visible, projectId, queryClient])

  if (visible.length === 0) return null

  return (
    <>
      <div className="w-full max-w-sm space-y-2">
        {visible.map((m: Models.Migration) => (
          <MigrationItem
            key={m.$id}
            migration={m}
            projectId={projectId}
            onViewDetails={setDetailsMigration}
            onDismiss={() => dismissImport(projectId, m.$id)}
          />
        ))}
      </div>
      {detailsMigration && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
          onClick={() => setDetailsMigration(null)}
          onKeyDown={(e) => e.key === 'Escape' && setDetailsMigration(null)}
          role="dialog"
          aria-modal="true"
        >
          <div
            className="rounded-lg border border-border bg-card p-4 max-w-md max-h-[80dvh] overflow-auto shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="text-[13px] font-semibold mb-2">
              {t('Import error details')}
            </div>
            <pre className="text-[12px] text-muted-foreground whitespace-pre-wrap break-words">
              {JSON.stringify(detailsMigration.errors ?? [], null, 2)}
            </pre>
            <Button
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={() => setDetailsMigration(null)}
            >
              {t('Close')}
            </Button>
          </div>
        </div>
      )}
    </>
  )
}
