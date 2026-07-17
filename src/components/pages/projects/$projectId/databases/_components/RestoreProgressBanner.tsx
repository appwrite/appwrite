/**
 * Inline restore progress for the database backups screen.
 * Progress comes from migrations.get (via useProjectMigration) using the
 * BackupRestoration.migrationId returned by backups.createRestoration.
 */

import { useEffect, useRef } from 'react'
import {
  Loader2,
  CheckCircle2,
  AlertCircle,
  RotateCcw,
  X,
} from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Progress } from '@/components/ui/progress'
import {
  getMigrationCounts,
  getMigrationErrorMessage,
  getMigrationProgress,
} from '@/components/pages/projects/$projectId/settings/migrations/migrationProgress'
import { useProjectMigration } from '@/lib/react-query/hooks'
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
  migrationId: string
  onDismiss: () => void
}

export function RestoreProgressBanner({
  projectId,
  databaseId,
  region,
  migrationId,
  onDismiss,
}: RestoreProgressBannerProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const completedNotifiedRef = useRef(false)
  const { migration } = useProjectMigration(projectId, region, migrationId, {
    pollWhileInProgress: true,
  })

  const status = migration?.status ?? 'pending'
  const isFailed = status === 'failed'
  const isCompleted = status === 'completed'
  const isInProgress = IN_PROGRESS_STATUSES.has(status)
  const progress = migration ? getMigrationProgress(migration) : 0
  const { succeeded, total } = migration
    ? getMigrationCounts(migration)
    : { succeeded: 0, total: 0 }
  const hasCounts = total > 0
  const errorMessage = isFailed
    ? getMigrationErrorMessage(migration?.errors as unknown[] | undefined)
    : null

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

  let title: string
  let description: string
  let badgeLabel: string
  let badgeVariant: 'info' | 'success' | 'error' | 'warning' = 'info'

  switch (status) {
    case 'pending':
      title = t('Preparing restore')
      description = t('Your database restore is queued and will start shortly.')
      badgeLabel = t('Queued')
      badgeVariant = 'warning'
      break
    case 'completed':
      title = t('Restore completed')
      description = t('Your database was restored successfully.')
      badgeLabel = t('Complete')
      badgeVariant = 'success'
      break
    case 'failed':
      title = t('Restore failed')
      description =
        errorMessage ||
        t('The database restore could not be completed. Try again or contact support.')
      badgeLabel = t('Failed')
      badgeVariant = 'error'
      break
    default:
      title = t('Restoring database')
      description = hasCounts
        ? `${t('Restoring resources')}: ${succeeded} / ${total}`
        : t('Restoring your database from the selected backup.')
      badgeLabel = t('In progress')
      badgeVariant = 'info'
  }

  const iconTone = isFailed
    ? 'bg-destructive/10 text-destructive'
    : isCompleted
      ? 'bg-green-500/10 text-green-600 dark:text-green-400'
      : 'bg-primary/10 text-primary'

  const progressTone = isFailed
    ? '[&_[data-slot=progress-indicator]]:bg-destructive bg-destructive/15'
    : isCompleted
      ? '[&_[data-slot=progress-indicator]]:bg-green-600 dark:[&_[data-slot=progress-indicator]]:bg-green-500 bg-green-500/15'
      : '[&_[data-slot=progress-indicator]]:bg-primary bg-primary/15'

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-5 py-4">
        <div className="flex items-start gap-3.5">
          <div
            className={cn(
              'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
              iconTone,
            )}
          >
            {isInProgress ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : isCompleted ? (
              <CheckCircle2 className="h-4 w-4" />
            ) : isFailed ? (
              <AlertCircle className="h-4 w-4" />
            ) : (
              <RotateCcw className="h-4 w-4" />
            )}
          </div>

          <div className="min-w-0 flex-1">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h4 className="text-[14px] font-semibold text-foreground">
                    {title}
                  </h4>
                  <Badge
                    variant={badgeVariant}
                    className="text-[10px] shrink-0"
                  >
                    {badgeLabel}
                  </Badge>
                </div>
                <p className="text-[12px] text-muted-foreground mt-1 line-clamp-2">
                  {description}
                </p>
              </div>

              {(isCompleted || isFailed) && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0 shrink-0 text-muted-foreground hover:text-foreground"
                  onClick={onDismiss}
                  aria-label={t('Dismiss')}
                >
                  <X className="h-3.5 w-3.5" />
                </Button>
              )}
            </div>

            <div className="mt-3.5 space-y-1.5">
              <div className="flex items-center justify-between gap-3">
                <span className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t('Progress')}
                </span>
                <span className="text-[12px] font-medium tabular-nums text-foreground">
                  {Math.round(progress)}%
                </span>
              </div>
              <Progress
                value={progress}
                className={cn('h-2 rounded-full', progressTone)}
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
