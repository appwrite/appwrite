import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { PaymentMethodForm } from './PaymentMethodForm'
import { useT } from '@/lib/i18n/translate'

interface PaymentModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId?: string
  isBackup?: boolean
  onSuccess?: () => void
  /** When true, dialog and overlay use z-[9999] so they appear above fullscreen wizards */
  elevatedForWizard?: boolean
}

export function PaymentModal({
  open,
  onOpenChange,
  organizationId,
  isBackup = false,
  onSuccess,
  elevatedForWizard = false,
}: PaymentModalProps) {
  const t = useT()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn('sm:max-w-md p-0', elevatedForWizard && 'z-[9999]')}
        overlayClassName={elevatedForWizard ? 'z-[9999]' : undefined}
      >
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add payment method')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Enter your card details to add a new payment method.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="pt-4">
          <PaymentMethodForm
            open={open}
            organizationId={organizationId}
            isBackup={isBackup}
            onSuccess={() => {
              onOpenChange(false)
              onSuccess?.()
            }}
            onCancel={() => onOpenChange(false)}
            variant="dialog"
          />
        </div>
      </DialogContent>
    </Dialog>
  )
}
