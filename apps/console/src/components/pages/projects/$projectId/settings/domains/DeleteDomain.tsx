import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useDeleteDomain } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface DeleteDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  onDeleteSuccess: () => void
}

export function DeleteDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  onDeleteSuccess,
}: DeleteDomainDialogProps) {
  const t = useT()
  const deleteDomainMutation = useDeleteDomain(projectId, region)

  const handleDelete = async () => {
    try {
      await deleteDomainMutation.mutateAsync(rule.$id)
      onDeleteSuccess()
    } catch (error: unknown) {
      toast.error(error.message || t('Failed to delete domain'))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Delete domain')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Are you sure you want to delete this domain? This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
            disabled={deleteDomainMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleDelete}
            disabled={deleteDomainMutation.isPending}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
