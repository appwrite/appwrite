import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, Loader2 } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import {
  tablesDatabaseMigrationPhaseLabelKey,
  isTablesDatabaseMigrationInProgress,
  canAbortTablesDatabaseMigration,
} from '@/lib/databases/tables-database-migration'
import {
  deleteTablesDatabaseMigration,
  invalidateDatabaseModel,
  refetchProjectDatabaseLists,
  tablesDatabaseMigrationsQueryKey,
} from '@/lib/react-query/hooks'
import { useDatabaseAdminOperationsAccess } from '@/components/pages/projects/$projectId/databases/_components/DatabaseOperationsLockContext'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'

type TablesDatabaseDedicatedMigrationBannerProps = {
  projectId: string
  databaseId: string
  migration: Models.DatabaseMigration
}

export function TablesDatabaseDedicatedMigrationBanner({
  projectId,
  databaseId,
  migration,
}: TablesDatabaseDedicatedMigrationBannerProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { canWrite, writeDisabled, writeTooltip } =
    useDatabaseAdminOperationsAccess()

  const phase = migration.phase
  const inProgress = isTablesDatabaseMigrationInProgress(migration)
  const failed = phase?.toLowerCase() === 'failed'
  const rolledBack = phase?.toLowerCase() === 'rolled_back'
  const showAbort =
    canAbortTablesDatabaseMigration(migration) &&
    (inProgress || failed || rolledBack || !phase)
  const labelKey = tablesDatabaseMigrationPhaseLabelKey(phase)

  const abortMutation = useMutation({
    mutationFn: () =>
      deleteTablesDatabaseMigration(projectId, databaseId, migration.$id),
    onSuccess: async () => {
      toast.success(t('Migration aborted'))
      invalidateDatabaseModel(projectId, databaseId)
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tablesDatabaseMigrationsQueryKey(projectId, databaseId),
        }),
        refetchProjectDatabaseLists(queryClient, projectId),
      ])
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to abort migration')))
    },
  })

  const lag =
    typeof migration.lagDocuments === 'number' && migration.lagDocuments > 0
      ? migration.lagDocuments
      : null

  const alertVariant = failed || rolledBack ? 'danger' : 'info'
  const Icon = failed || rolledBack ? AlertCircle : Loader2

  return (
    <HeaderAlertBar
      variant={alertVariant}
      icon={Icon}
      className={
        failed || rolledBack
          ? 'shrink-0'
          : 'shrink-0 [&_svg]:animate-spin'
      }
      action={
        showAbort && canWrite ? (
          <button
            type="button"
            className={headerAlertOutlineButtonClass(alertVariant)}
            disabled={writeDisabled || abortMutation.isPending}
            title={writeTooltip}
            onClick={() => abortMutation.mutate()}
          >
            {t('Abort migration')}
          </button>
        ) : undefined
      }
    >
      <p className="font-semibold">{t(labelKey)}</p>
      <p className="mt-1 font-normal text-[12px] opacity-90">
        {failed || rolledBack
          ? t(
              'This migration did not finish. Abort it to try upgrading again.',
            )
          : t(
              'Your database stays available while data is copied. During cutover, writes pause briefly while routing switches and replay automatically.',
            )}
      </p>
      {lag != null ? (
        <p className="mt-1 font-normal text-[12px] opacity-90 tabular-nums">
          <span className="font-medium">{lag}</span>{' '}
          {t('rows pending replication')}
        </p>
      ) : null}
      {failed && migration.lastError?.trim() ? (
        <p className="mt-1 font-normal text-[12px] opacity-90">
          {migration.lastError.trim()}
        </p>
      ) : null}
    </HeaderAlertBar>
  )
}
