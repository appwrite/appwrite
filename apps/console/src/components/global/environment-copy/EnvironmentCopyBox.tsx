/**
 * Progress of an environment's copy from its project: the Appwrite to Appwrite
 * migration created with the environment. Same card as CSV imports. Picks a copy
 * still under way back up after a reload, and refreshes the environment's data
 * once the copy completes.
 */

import { useEffect, useMemo, useRef } from 'react'
import { Link } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { AlertCircle, CheckCircle2, Loader2, X } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import {
  IN_PROGRESS_COPY_STATUSES,
  useSessionMigrations,
} from '@/components/global/providers/SessionMigrationsContext'
import {
  useEnvironmentCopyMigrations,
  useLatestEnvironmentCopyMigration,
  useProject,
} from '@/lib/react-query/hooks'
import { migrationProgress } from '@/lib/project-environments'
import { useT } from '@/lib/i18n/translate'

function CopyItem({
  migration,
  projectId,
  sourceName,
  onDismiss,
}: {
  migration: Models.Migration
  projectId: string
  sourceName: string
  onDismiss: () => void
}) {
  const t = useT()
  const status = migration.status
  const isFailed = status === 'failed'
  const isCompleted = status === 'completed'
  const isInProgress = IN_PROGRESS_COPY_STATUSES.includes(status)

  // The counters say how far the copy got once the worker has reported; before
  // that the bar shows it has started
  const counted = migrationProgress(migration.statusCounters)
  const progress = isCompleted || isFailed ? 100 : Math.max(counted, 5)

  // Whole sentences, with the project named on its own line: a name inside a
  // sentence reads wrong in languages that put it elsewhere
  const statusLabel = isCompleted
    ? t('Copied from the project')
    : isFailed
      ? t('Could not copy from the project')
      : t('Copying from the project')

  return (
    <div className="rounded-lg border border-border bg-background p-3">
      <div className="flex items-start gap-3">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 mb-1">
            {isInProgress ? (
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
          <p className="text-[12px] text-muted-foreground truncate mb-1">
            {sourceName}
          </p>
          <ProgressBarRow value={progress} />
          {isFailed && (
            <Button
              asChild
              variant="link"
              size="sm"
              className="h-auto p-0 text-[12px] mt-0.5"
            >
              <Link
                to="/projects/$projectId/settings/migrations"
                params={{ projectId }}
              >
                {t('View details')}
              </Link>
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

interface EnvironmentCopyBoxProps {
  projectId: string
}

export function EnvironmentCopyBox({ projectId }: EnvironmentCopyBoxProps) {
  const queryClient = useQueryClient()
  const { projectData } = useProject(projectId)
  const parentId = projectData?.parentId || undefined
  const { project: parent } = useProject(parentId)
  const { sessionCopyIds, dismissedCopyIds, addCopyId, dismissCopy } =
    useSessionMigrations(projectId)

  // Only an environment has a copy to show. A reload mid-copy lost the session
  // IDs, so the latest copy is looked up once and tracked again while under way.
  const latest = useLatestEnvironmentCopyMigration(
    projectId,
    !!parentId && sessionCopyIds.length === 0,
  )

  useEffect(() => {
    if (latest && IN_PROGRESS_COPY_STATUSES.includes(latest.status)) {
      addCopyId(projectId, latest.$id)
    }
  }, [latest, projectId, addCopyId])

  const { migrations } = useEnvironmentCopyMigrations(projectId, sessionCopyIds)
  const dismissedSet = useMemo(
    () => new Set(dismissedCopyIds),
    [dismissedCopyIds],
  )
  const visible = migrations.filter((m) => !dismissedSet.has(m.$id))

  // What the copy brought is in every list of the environment: refresh them all
  // when a copy seen under way completes, not for one already complete on load
  const previousStatusRef = useRef<Map<string, string>>(new Map())

  useEffect(() => {
    for (const migration of visible) {
      const previous = previousStatusRef.current.get(migration.$id)
      previousStatusRef.current.set(migration.$id, migration.status)
      if (
        migration.status === 'completed' &&
        previous !== undefined &&
        previous !== 'completed'
      ) {
        queryClient.invalidateQueries({
          predicate: (query) =>
            query.queryKey[1] === 'project' && query.queryKey[2] === projectId,
        })
      }
    }
  }, [visible, projectId, queryClient])

  if (!parentId || visible.length === 0) return null

  const sourceName = parent?.name || parentId

  return (
    <div className="w-full max-w-sm space-y-2">
      {visible.map((migration) => (
        <CopyItem
          key={migration.$id}
          migration={migration}
          projectId={projectId}
          sourceName={sourceName}
          onDismiss={() => dismissCopy(projectId, migration.$id)}
        />
      ))}
    </div>
  )
}
