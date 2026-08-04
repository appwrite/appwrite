import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  invalidateDatabaseModel,
  updateProjectDatabase,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import type { ProjectDatabaseDetail } from './types'

type DatabaseNameCardProps = {
  projectId: string
  databaseId: string
  dbKind: DatabaseRouteKind
  database: ProjectDatabaseDetail
  canWrite: boolean
}

export function DatabaseNameCard({
  projectId,
  databaseId,
  dbKind,
  database,
  canWrite,
}: DatabaseNameCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [name, setName] = useState(database.name)

  useEffect(() => {
    setName(database.name)
  }, [database.name])

  const updateMutation = useMutation({
    mutationFn: async (nextName: string) => {
      const trimmed = nextName.trim()
      if (trimmed.length < 1) {
        throw new Error(t('Name must be at least 1 character'))
      }
      if (trimmed.length > 128) {
        throw new Error(t('Name must be no longer than 128 characters'))
      }
      await updateProjectDatabase(
        projectId,
        databaseId,
        { name: trimmed },
        dbKind,
      )
    },
    onSuccess: () => {
      invalidateDatabaseModel(projectId, databaseId)
      queryClient.invalidateQueries({
        queryKey: ['database', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
      toast.success(t('Database name updated successfully'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update database name')))
    },
  })

  const hasChanges = name.trim() !== database.name && name.trim().length > 0

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Name')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            "Update your database's display name. This will be visible to all organization members.",
          )}
        </p>
        <Input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t('Database name')}
          disabled={!canWrite}
          className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
        />
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!canWrite || !hasChanges || updateMutation.isPending}
          onClick={() => updateMutation.mutate(name)}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
