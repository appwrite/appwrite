/**
 * Inline restore progress for the database backups screen.
 * Header row stays fixed (status → destination → Open CTA).
 * Per-resource breakdown sits below.
 */

import { useEffect, useRef } from 'react'
import { Link } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Loader2,
  CheckCircle2,
  AlertCircle,
  ArrowRight,
  Database,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  getMigrationErrorMessage,
  getMigrationResourceBreakdown,
  sortRestoreResourceKeys,
} from '@/components/pages/projects/$projectId/settings/migrations/migrationProgress'
import {
  fetchRestoredDatabaseIdByName,
  getRestoredDatabaseTarget,
  useProjectMigration,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const IN_PROGRESS_STATUSES = new Set([
  'pending',
  'processing',
  'uploading',
  'downloading',
])

interface RestoreProgressBannerProps {
  projectId: string
  databaseId: string
  region?: string
  restoration: Models.BackupRestoration
}

export function RestoreProgressBanner({
  projectId,
  databaseId,
  region,
  restoration,
}: RestoreProgressBannerProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const completedNotifiedRef = useRef(false)
  const migrationId = restoration.migrationId
  const restoredTarget = getRestoredDatabaseTarget(restoration)

  const { migration } = useProjectMigration(projectId, region, migrationId, {
    pollWhileInProgress: true,
  })

  const status = migration?.status ?? restoration.status ?? 'pending'
  const isFailed = status === 'failed'
  const isCompleted = status === 'completed'
  const isInProgress = IN_PROGRESS_STATUSES.has(status)

  // API lists index before column; always display restore execution order.
  const resourceKeys = sortRestoreResourceKeys(
    (migration?.resources?.length
      ? migration.resources
      : restoration.resources) || [],
  )
  const errorMessage = isFailed
    ? getMigrationErrorMessage(migration?.errors as unknown[] | undefined)
    : null

  const breakdown = getMigrationResourceBreakdown(migration, resourceKeys)

  const needsNameLookup =
    isCompleted &&
    !!restoredTarget?.isNew &&
    !restoredTarget.databaseId &&
    !!restoredTarget.name

  const { data: lookedUpDatabaseId } = useQuery({
    queryKey: [
      'restorations',
      'project',
      projectId,
      'lookup-database',
      restoredTarget?.dbKind,
      restoredTarget?.name,
    ],
    queryFn: () =>
      fetchRestoredDatabaseIdByName(
        projectId,
        restoredTarget!.dbKind,
        restoredTarget!.name!,
      ),
    enabled: needsNameLookup,
    staleTime: 30 * 1000,
  })

  const openDatabaseId = restoredTarget?.isNew
    ? restoredTarget.databaseId || lookedUpDatabaseId || ''
    : restoredTarget?.databaseId || databaseId

  const canOpenDatabase =
    isCompleted && !!restoredTarget && !!openDatabaseId

  useEffect(() => {
    if (!migration || completedNotifiedRef.current) return
    if (status !== 'completed' && status !== 'failed') return

    completedNotifiedRef.current = true
    void queryClient.invalidateQueries({
      queryKey: [
        'backup-archives',
        'project',
        projectId,
        'database',
        databaseId,
      ],
    })
    void queryClient.invalidateQueries({
      queryKey: [
        'restorations',
        'project',
        projectId,
        'database',
        databaseId,
      ],
    })
    void queryClient.invalidateQueries({
      queryKey: ['databases', 'project', projectId],
    })
  }, [migration, status, projectId, databaseId, queryClient])

  let label: string
  switch (status) {
    case 'pending':
      label = t('Preparing restore')
      break
    case 'completed':
      label = t('Restore completed')
      break
    case 'failed':
      label = errorMessage || t('Restore failed')
      break
    default:
      label = t('Restoring database')
  }

  const openLabel = restoredTarget?.isNew
    ? t('Open new database')
    : t('Open database')

  const destinationLabel =
    restoredTarget?.name ||
    (restoredTarget?.isNew ? openDatabaseId : null) ||
    restoredTarget?.databaseId ||
    t('Database')

  return (
    <div className="rounded-lg border border-border px-3 py-2.5 space-y-2.5">
      <div className="flex items-center gap-3">
        <div className="flex min-w-0 flex-1 items-center gap-2">
          {isInProgress ? (
            <Loader2 className="h-4 w-4 animate-spin text-primary shrink-0" />
          ) : isCompleted ? (
            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400 shrink-0" />
          ) : (
            <AlertCircle className="h-4 w-4 text-destructive shrink-0" />
          )}
          <p
            className={cn(
              'text-[13px] font-medium truncate',
              isFailed && 'text-destructive',
            )}
            title={label}
          >
            {label}
          </p>
          <ArrowRight className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
          <span
            className="inline-flex max-w-[160px] items-center gap-1 truncate rounded-md bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground shrink-0"
            title={destinationLabel}
          >
            <Database className="h-3 w-3 shrink-0" />
            {destinationLabel}
          </span>
        </div>

        <div className="w-[148px] shrink-0 flex justify-end">
          {canOpenDatabase ? (
            <Button
              asChild
              size="sm"
              className="h-7 gap-1.5 text-[12px] px-2.5"
            >
              <Link
                to="/projects/$projectId/databases/$dbKind/$databaseId/"
                params={{
                  projectId,
                  dbKind: restoredTarget.dbKind,
                  databaseId: openDatabaseId,
                }}
              >
                {openLabel}
                <ArrowRight className="h-3.5 w-3.5" />
              </Link>
            </Button>
          ) : (
            <Button
              size="sm"
              className="h-7 gap-1.5 text-[12px] px-2.5"
              disabled
            >
              {openLabel}
              <ArrowRight className="h-3.5 w-3.5" />
            </Button>
          )}
        </div>
      </div>

      {breakdown.length > 0 ? (
        <div className="grid grid-cols-2 gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-3 md:grid-cols-5">
          {breakdown.map((item) => {
            const pct =
              item.total > 0
                ? Math.round((item.succeeded / item.total) * 100)
                : item.tone === 'success'
                  ? 100
                  : 0
            return (
              <div
                key={item.key}
                className="flex flex-col gap-1.5 bg-card/80 px-2.5 py-2"
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[11px] text-muted-foreground truncate">
                    {item.label}
                  </span>
                  {item.tone === 'processing' ? (
                    <Loader2 className="h-3 w-3 animate-spin text-primary shrink-0" />
                  ) : item.tone === 'error' ? (
                    <span className="h-1.5 w-1.5 rounded-full bg-destructive shrink-0" />
                  ) : item.tone === 'success' ? (
                    <span className="h-1.5 w-1.5 rounded-full bg-green-600 dark:bg-green-500 shrink-0" />
                  ) : (
                    <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/30 shrink-0" />
                  )}
                </div>
                <div className="flex items-baseline justify-between gap-2">
                  <span className="text-[13px] font-semibold tabular-nums text-foreground">
                    {item.total > 0 ? item.succeeded : 0}
                    <span className="font-normal text-muted-foreground">
                      /{item.total}
                    </span>
                  </span>
                  <span className="text-[10px] tabular-nums text-muted-foreground">
                    {pct}%
                  </span>
                </div>
                <div className="h-0.5 w-full overflow-hidden rounded-full bg-muted">
                  <div
                    className={cn(
                      'h-full rounded-full transition-all',
                      item.tone === 'error'
                        ? 'bg-destructive'
                        : item.tone === 'success'
                          ? 'bg-green-600 dark:bg-green-500'
                          : item.tone === 'processing'
                            ? 'bg-primary'
                            : 'bg-muted-foreground/25',
                    )}
                    style={{ width: `${pct}%` }}
                  />
                </div>
              </div>
            )
          })}
        </div>
      ) : null}
    </div>
  )
}
