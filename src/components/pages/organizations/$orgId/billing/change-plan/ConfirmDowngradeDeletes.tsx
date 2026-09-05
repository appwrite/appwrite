import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

export function ConfirmDowngradeDeletes({
  open,
  onOpenChange,
  title,
  count,
  confirming,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  count: number
  confirming: boolean
  onConfirm: () => void
}) {
  const t = useT()

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Only the selected items will be deleted.')}{' '}
            {t('We will check the plan limits again after that.')}{' '}
            {t('This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-foreground">
            {t('Selected:')} {count}
          </p>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={confirming}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            disabled={confirming || count === 0}
            onClick={onConfirm}
          >
            {t('Delete selected')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
