import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

const CONFIRM_ITEM_PREVIEW_LIMIT = 10

export function ConfirmDowngradeDeletes({
  open,
  onOpenChange,
  title,
  items,
  confirming,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  items: { id: string; label: string }[]
  confirming: boolean
  onConfirm: () => void
}) {
  const t = useT()
  const preview = items.slice(0, CONFIRM_ITEM_PREVIEW_LIMIT)
  const hiddenCount = items.length - preview.length

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="z-[10000] sm:max-w-md p-0"
        overlayClassName="z-[9999]"
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Only the selected items will be deleted.')}{' '}
            {t('They are removed when you submit the plan change.')}{' '}
            {t('This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <ul className="space-y-1">
            {preview.map((item) => (
              <li
                key={item.id}
                className="truncate text-[13px] leading-normal text-foreground"
                title={item.label}
              >
                {item.label}
              </li>
            ))}
          </ul>
          {hiddenCount > 0 ? (
            <p className="mt-2 text-[13px] leading-normal text-muted-foreground">
              +{hiddenCount} {t('more')}
            </p>
          ) : null}
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
            disabled={confirming || items.length === 0}
            onClick={onConfirm}
          >
            {t('Confirm selection')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
