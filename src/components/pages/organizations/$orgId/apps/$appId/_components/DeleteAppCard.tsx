import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Package, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { useDeleteOrganizationApp } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type DeleteAppCardProps = {
  orgId: string
  app: Models.App
}

export function DeleteAppCard({ orgId, app }: DeleteAppCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const deleteMutation = useDeleteOrganizationApp(orgId)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const handleDelete = async () => {
    try {
      await deleteMutation.mutateAsync(app.$id)
      toast.success(t('App deleted'))
      setDeleteDialogOpen(false)
      navigate({ to: '/organizations/$orgId/settings/oauth-apps', params: { orgId } })
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete app')))
    }
  }

  return (
    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{t('Delete app')}</h3>
      </div>
      <div className="border-t border-destructive/20" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            'Permanently delete this app and revoke all associated tokens. This action cannot be undone.',
          )}
        </p>
        <div className="flex items-center gap-3 mt-4">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
            <Package className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-[14px] font-medium text-foreground truncate">
              {app.name || t('Unnamed app')}
            </p>
            <p className="text-[12px] text-muted-foreground">{app.$id}</p>
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
              {t('Delete app')}
            </Button>
          </DialogTrigger>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete app')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')}{' '}
                <span className="font-medium text-foreground">
                  {app.name || t('this app')}
                </span>{' '}
                {t(
                  'and revoke all associated tokens? This action cannot be undone.',
                )}
              </DialogDescription>
            </DialogHeader>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={deleteMutation.isPending}
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                onClick={handleDelete}
                disabled={deleteMutation.isPending}
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
