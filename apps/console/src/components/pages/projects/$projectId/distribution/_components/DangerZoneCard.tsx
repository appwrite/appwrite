import { useState } from 'react'
import { Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import { useDeleteDistributionApp } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

interface DangerZoneCardProps {
  projectId: string
  app: DistributionApp
  onDelete: () => Promise<unknown>
}

export function DangerZoneCard({
  projectId,
  app,
  onDelete,
}: DangerZoneCardProps) {
  const t = useT()
  const [dialogOpen, setDialogOpen] = useState(false)
  const deleteApp = useDeleteDistributionApp(projectId, async () => {
    toast.success(t('App deleted'))
    setDialogOpen(false)
    await onDelete()
  })

  const handleDelete = () => {
    deleteApp.mutate(app.$id, {
      onError: (error) => {
        toast.error(getErrorMessage(error, t('Failed to delete app')))
      },
    })
  }

  return (
    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Delete app')}
        </h3>
      </div>
      <div className="border-t border-destructive/20" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            'Permanently delete this app with its targets, variables and builds. Apps already published to the stores stay there. This action cannot be undone.',
          )}
        </p>
        <div className="flex items-center gap-3 mt-4">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
            <Trash2 className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-[14px] font-medium text-foreground truncate">
              {app.name}
            </p>
            <p className="text-[12px] text-muted-foreground">{app.$id}</p>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
        <Button
          variant="destructive"
          size="sm"
          className="h-9 text-[13px]"
          disabled={deleteApp.isPending}
          onClick={() => setDialogOpen(true)}
        >
          <Trash2 className="me-1.5 h-4 w-4" />
          {t('Delete app')}
        </Button>
        <ConfirmNameDialog
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          title={t('Delete app')}
          description={t(
            'Its targets, variables, builds and repository link are removed. This action cannot be undone.',
          )}
          confirmValue={app.name.trim() || app.$id}
          confirmPlaceholder={t('Enter app name')}
          onConfirm={handleDelete}
          isConfirming={deleteApp.isPending}
        />
      </div>
    </div>
  )
}
