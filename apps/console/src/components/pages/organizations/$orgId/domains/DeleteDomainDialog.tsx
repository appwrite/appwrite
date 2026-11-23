import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface DeleteDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  domain: Models.Domain
  onDelete: (domainId: string) => void
  isLoading?: boolean
}

export function DeleteDomainDialog({
  open,
  onOpenChange,
  domain,
  onDelete,
  isLoading = false,
}: DeleteDomainDialogProps) {
  const t = useT()
  const handleDelete = () => {
    onDelete(domain.$id)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Delete Domain')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Are you sure you want to delete')}{' '}
            <strong>{domain.domain}</strong>?{' '}
            {t('This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            onClick={handleDelete}
            disabled={isLoading}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
