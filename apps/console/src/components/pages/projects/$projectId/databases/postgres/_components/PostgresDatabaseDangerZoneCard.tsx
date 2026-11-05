import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Database, Trash2 } from 'lucide-react'
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
import { useDeletePostgresDatabase } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type PostgresDatabaseDangerZoneCardProps = {
  projectId: string
  database: Models.DedicatedDatabase
  canWrite: boolean
}

export function PostgresDatabaseDangerZoneCard({
  projectId,
  database,
  canWrite,
}: PostgresDatabaseDangerZoneCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const deleteMutation = useDeletePostgresDatabase(projectId)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState('')

  const handleDelete = () => {
    deleteMutation.mutate(database.$id, {
      onSuccess: () => {
        toast.success(t('Database deleted'))
        setDeleteDialogOpen(false)
        setDeleteConfirmation('')
        navigate({
          to: '/projects/$projectId/databases',
          params: { projectId },
          replace: true,
        })
      },
      onError: (error) => {
        toast.error(getErrorMessage(error, t('Failed to delete database')))
      },
    })
  }

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
          {t(
            'Permanently delete this database and all its data. This action cannot be undone.',
          )}
        </p>
        <div className="mt-4 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
            <Database className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-[14px] font-medium text-foreground">
              {database.name}
            </p>
            <p className="text-[12px] text-muted-foreground">{database.$id}</p>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogTrigger asChild>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              disabled={deleteMutation.isPending}
            >
              <Trash2 className="me-1.5 h-4 w-4" />
              {t('Delete database')}
            </Button>
          </DialogTrigger>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete database')}</DialogTitle>
              <DialogDescription className="mt-2 text-[13px]">
                {t('Are you sure you want to delete')}{' '}
                <span className="font-medium text-foreground">
                  {database.name}
                </span>
                ? {t('This action cannot be undone.')}
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-0">
              <label className="text-[13px] text-muted-foreground">
                {t('Type')}{' '}
                <span className="rounded bg-muted px-1.5 py-0.5 font-mono font-medium text-foreground">
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
            <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
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
                onClick={handleDelete}
              >
                {t('Delete database')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
