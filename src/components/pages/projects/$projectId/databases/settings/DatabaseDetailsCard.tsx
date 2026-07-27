import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  invalidateDatabaseModel,
  updateProjectDatabase,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import type { ProjectDatabaseDetail } from './types'

type DatabaseDetailsCardProps = {
  projectId: string
  databaseId: string
  dbKind: DatabaseRouteKind
  database: ProjectDatabaseDetail
  canWrite: boolean
}

export function DatabaseDetailsCard({
  projectId,
  databaseId,
  dbKind,
  database,
  canWrite,
}: DatabaseDetailsCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const originalEnabled = database.enabled !== false
  const [enabled, setEnabled] = useState(originalEnabled)

  useEffect(() => {
    setEnabled(database.enabled !== false)
  }, [database.enabled])

  const updateMutation = useMutation({
    mutationFn: async (nextEnabled: boolean) => {
      await updateProjectDatabase(
        projectId,
        databaseId,
        {
          name: database.name,
          enabled: nextEnabled,
        },
        dbKind,
      )
    },
    onSuccess: (_data, nextEnabled) => {
      toast.success(
        nextEnabled
          ? t('Database has been enabled')
          : t('Database has been disabled'),
      )
      invalidateDatabaseModel(projectId, databaseId)
      queryClient.invalidateQueries({
        queryKey: ['database', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
      setEnabled(originalEnabled)
    },
  })

  const hasChanges = enabled !== originalEnabled

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {database.name}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Switch
              id="database-enabled"
              checked={enabled}
              onCheckedChange={setEnabled}
              disabled={!canWrite || updateMutation.isPending}
            />
            <Label
              htmlFor="database-enabled"
              className="text-[13px] text-foreground"
            >
              {enabled ? t('Enabled') : t('Disabled')}
            </Label>
          </div>
        </div>
        <div className="mt-4 space-y-1">
          <p className="text-[13px] text-muted-foreground">
            {t('Database ID')}:{' '}
            <span className="ms-1.5">
              <CopyableId id={database.$id} size="sm" />
            </span>
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Created:')}{' '}
            <DateTooltip
              date={database.createdAt}
              showFormattedDate
              className="text-foreground"
            />
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Last updated:')}{' '}
            <DateTooltip
              date={database.updatedAt || database.createdAt}
              showFormattedDate
              className="text-foreground"
            />
          </p>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!canWrite || !hasChanges || updateMutation.isPending}
          onClick={() => updateMutation.mutate(enabled)}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
