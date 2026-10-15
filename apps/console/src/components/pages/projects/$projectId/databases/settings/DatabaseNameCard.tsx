import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { DescriptionField } from '@/components/global/shared/DescriptionField'
import {
  invalidateDatabaseModel,
  refetchProjectDatabaseLists,
  updateProjectDatabase,
} from '@/lib/react-query/hooks'
import {
  normalizeDescription,
  readDescription,
} from '@/lib/appwrite/resource-descriptions'
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
  const savedDescription = readDescription(database)
  const [name, setName] = useSyncStateFromServer(database.name)
  const [description, setDescription] = useSyncStateFromServer(savedDescription)

  const updateMutation = useMutation({
    mutationFn: async (next: { name: string; description: string }) => {
      const trimmed = next.name.trim()
      if (trimmed.length < 1) {
        throw new Error(t('Name must be at least 1 character'))
      }
      if (trimmed.length > 128) {
        throw new Error(t('Name must be no longer than 128 characters'))
      }
      await updateProjectDatabase(
        projectId,
        databaseId,
        {
          name: trimmed,
          description: normalizeDescription(next.description),
          // The API writes `enabled` from a param that defaults to `true`, so
          // a name or description save has to resend the saved value or it
          // re-enables a disabled database.
          enabled: database.enabled !== false,
        },
        dbKind,
      )
    },
    onSuccess: async () => {
      invalidateDatabaseModel(projectId, databaseId)
      await queryClient.refetchQueries({
        queryKey: ['database', 'project', projectId, databaseId],
      })
      // List queries use refetchOnMount: false, so refetch (not invalidate).
      await refetchProjectDatabaseLists(queryClient, projectId)
      toast.success(t('Database updated successfully'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update database')))
    },
  })

  // Both sides normalized: the API does not trim, so a description stored
  // with surrounding whitespace would otherwise leave this card always dirty.
  const hasChanges =
    (name.trim() !== database.name ||
      normalizeDescription(description) !==
        normalizeDescription(savedDescription)) &&
    name.trim().length > 0

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Name and description')}
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
        <DescriptionField
          id="database-description"
          value={description}
          onChange={setDescription}
          placeholder={t('What does this database hold?')}
          disabled={!canWrite}
          className="mt-4 max-w-sm"
        />
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!canWrite || !hasChanges || updateMutation.isPending}
          onClick={() => updateMutation.mutate({ name, description })}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
