import { useEffect, useState, type ReactNode } from 'react'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'

type ConfirmNameDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: ReactNode
  confirmValue: string
  confirmPlaceholder: string
  confirmLabel?: string
  onConfirm: () => void
  isConfirming?: boolean
  children?: ReactNode
  contentClassName?: string
  overlayClassName?: string
}

export function ConfirmNameDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmValue,
  confirmPlaceholder,
  confirmLabel = 'Delete',
  onConfirm,
  isConfirming = false,
  children,
  contentClassName,
  overlayClassName,
}: ConfirmNameDialogProps) {
  const t = useT()
  const [confirmation, setConfirmation] = useState('')

  useEffect(() => {
    if (!open) setConfirmation('')
  }, [open])

  const canConfirm =
    !!confirmValue && confirmation === confirmValue && !isConfirming

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn('sm:max-w-md p-0', contentClassName)}
        overlayClassName={overlayClassName}
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t(title)}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {description}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-4">
          {children ? <div className="mb-4">{children}</div> : null}
          <label className="text-[13px] text-muted-foreground">
            {t('Type')}{' '}
            <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
              {confirmValue}
            </span>{' '}
            {t('to confirm')}
          </label>
          <Input
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            placeholder={t(confirmPlaceholder)}
            className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
            autoFocus
            onKeyDown={(event) => {
              if (event.key === 'Enter' && canConfirm) {
                event.preventDefault()
                onConfirm()
              }
            }}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            disabled={isConfirming}
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            className="h-9 text-[13px]"
            disabled={!canConfirm}
            onClick={onConfirm}
          >
            {t(confirmLabel)}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
