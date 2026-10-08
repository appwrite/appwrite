import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Database } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  deleteProjectDatabase,
  invalidateDatabaseModelAndType,
  refetchProjectDatabaseLists,
} from '@/lib/react-query/hooks'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import type { ProjectDatabaseDetail } from './types'

type DatabaseDangerZoneCardProps = {
  projectId: string
  databaseId: string
  database: ProjectDatabaseDetail
  dbKind: DatabaseRouteKind
  containersTotal: number
  canWrite: boolean
}

export function DatabaseDangerZoneCard({
  projectId,
  databaseId,
  database,
  dbKind,
  containersTotal,
  canWrite,
}: DatabaseDangerZoneCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, dbKind)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState('')

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await deleteProjectDatabase(projectId, databaseId, dbKind)
    },
    onSuccess: async () => {
      invalidateDatabaseModelAndType(projectId, databaseId)
      await refetchProjectDatabaseLists(queryClient, projectId)
      toast.success(t('Database deleted successfully'))
      setDeleteDialogOpen(false)
      setDeleteConfirmation('')
      navigate({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to delete database')))
    },
  })

  if (!canWrite) return null

  return (
    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Delete database')}
        </h3>
      </div>
      <div className="border-t border-destructive/20" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {dbLabels.deleteDatabaseContainersDescription}
        </p>
        <div className="flex items-center gap-3 mt-4">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
            <Database className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-[14px] font-medium text-foreground truncate">
              {database.name}
            </p>
            <p className="text-[12px] text-muted-foreground">
              {containersTotal}{' '}
              {containersTotal === 1
                ? dbLabels.containerSingular
                : dbLabels.containerPlural}
            </p>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogTrigger asChild>
            <Button variant="destructive" size="sm" className="h-9 text-[13px]">
              {t('Delete database')}
            </Button>
          </DialogTrigger>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete Database')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')}{' '}
                <span className="font-medium text-foreground">
                  {database.name}
                </span>{' '}
                {dbLabels.deleteDatabaseConfirmSuffix}
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-0">
              <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <Database className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div>
                    <p className="text-[13px] font-medium text-foreground">
                      {database.name}
                    </p>
                    <p className="text-[11px] text-muted-foreground">
                      {containersTotal}{' '}
                      {containersTotal === 1
                        ? dbLabels.containerSingular
                        : dbLabels.containerPlural}{' '}
                      {t('will be deleted')}
                    </p>
                  </div>
                </div>
              </div>
              <label className="text-[13px] text-muted-foreground">
                {t('Type')}{' '}
                <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                  {database.name}
                </span>{' '}
                {t('to confirm')}
              </label>
              <Input
                value={deleteConfirmation}
                onChange={(e) => setDeleteConfirmation(e.target.value)}
                placeholder={t('Enter database name')}
                className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => {
                  setDeleteDialogOpen(false)
                  setDeleteConfirmation('')
                }}
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  deleteConfirmation !== database.name ||
                  deleteMutation.isPending
                }
                onClick={() => deleteMutation.mutate()}
              >
                {t('Delete')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
