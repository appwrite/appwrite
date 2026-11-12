/**
 * Explains why the primary payment method cannot be removed on paid plans
 * when no backup method is on file.
 */

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Plus } from '@/lib/icons'
import { PaymentMethodBrandAvatar } from '@/components/global/shared/PaymentMethodBrandAvatar'
import { formatPaymentMethodSummary } from './utils'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface CannotRemovePrimaryPaymentMethodModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationName?: string
  availableMethods?: Models.PaymentMethod[]
  onReplacePrimary?: (paymentMethodId: string) => void
  onAddNew?: () => void
}

export function CannotRemovePrimaryPaymentMethodModal({
  open,
  onOpenChange,
  organizationName,
  availableMethods = [],
  onReplacePrimary,
  onAddNew,
}: CannotRemovePrimaryPaymentMethodModalProps) {
  const t = useT()
  const orgLabel = organizationName?.trim() || t('your organization')
  const hasExistingCards = availableMethods.length > 0

  const handleReplacePrimary = (paymentMethodId: string) => {
    onOpenChange(false)
    onReplacePrimary?.(paymentMethodId)
  }

  const handleAddNew = () => {
    onOpenChange(false)
    onAddNew?.()
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Payment method required')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2 space-y-3">
            <p>
              {orgLabel}{' '}
              {t('is on a paid plan with recurring billing. Your primary payment method must remain on file while subscription charges are active.')}
            </p>
            <p>
              {t('To remove this card, replace it with another payment method on your account first. Once a new primary card is set, you can remove this one.')}
            </p>
          </DialogDescription>
        </DialogHeader>

        {(hasExistingCards || onAddNew) && (
          <>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-3">
              {hasExistingCards && (
                <div className="space-y-2">
                  <p className="text-[13px] font-medium text-foreground">
                    {t('Replace with an existing card')}
                  </p>
                  <div className="flex flex-col gap-2">
                    {availableMethods.map((method) => (
                      <div
                        key={method.$id}
                        className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 px-4 py-3"
                      >
                        <div className="flex min-w-0 items-center gap-3">
                          <PaymentMethodBrandAvatar brand={method.brand} />
                          <span className="truncate text-[13px] font-medium text-foreground">
                            {formatPaymentMethodSummary(method)}
                          </span>
                        </div>
                        <Button
                          type="button"
                          size="sm"
                          className="h-8 shrink-0 text-[12px]"
                          onClick={() => handleReplacePrimary(method.$id)}
                        >
                          {t('Replace')}
                        </Button>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {hasExistingCards && onAddNew && (
                <p className="text-[12px] text-muted-foreground">{t('Or')}</p>
              )}

              {onAddNew && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 w-full gap-2 text-[13px]"
                  onClick={handleAddNew}
                >
                  <Plus className="h-4 w-4" />
                  {t('Add new card')}
                </Button>
              )}
            </div>
          </>
        )}

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t('Close')}
          </Button>
          {!hasExistingCards && onAddNew && (
            <Button type="button" onClick={handleAddNew}>
              {t('Add payment method')}
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
