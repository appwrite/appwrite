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

interface DeleteRecordDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  record: Models.DnsRecord
  onDelete: () => void
  isLoading?: boolean
}

export function DeleteRecordDialog({
  open,
  onOpenChange,
  record,
  onDelete,
  isLoading = false,
}: DeleteRecordDialogProps) {
  const t = useT()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Delete DNS Record')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Are you sure you want to delete this')} {record.type}{' '}
            {t('record for')} <strong>{record.name || '@'}</strong>?{' '}
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
          <Button variant="destructive" onClick={onDelete} disabled={isLoading}>
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
