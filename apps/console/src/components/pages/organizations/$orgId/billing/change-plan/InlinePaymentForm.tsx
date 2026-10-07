import { Button } from '@/components/ui/button'
import { X } from 'lucide-react'
import { PaymentMethodForm } from '../PaymentMethodForm'
import { useT } from '@/lib/i18n/translate'

interface InlinePaymentFormProps {
  organizationId?: string
  onSuccess?: () => void
  onCancel?: () => void
}

export function InlinePaymentForm({
  organizationId,
  onSuccess,
  onCancel,
}: InlinePaymentFormProps) {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-card/50 p-4 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Add payment method')} <span className="text-destructive">*</span>
        </h3>
        {onCancel && (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 w-7 p-0"
            onClick={onCancel}
            aria-label={t('Close')}
          >
            <X className="h-4 w-4" />
          </Button>
        )}
      </div>

      <PaymentMethodForm
        open
        organizationId={organizationId}
        onSuccess={onSuccess}
        onCancel={onCancel}
        variant="inline"
      />
    </div>
  )
}
