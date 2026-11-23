import { useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { AlertCircle } from 'lucide-react'
import { fetchCouponAccount } from '@/lib/react-query/hooks'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

interface ValidateCreditModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCouponApply: (coupon: Models.Coupon) => void
  /** When true, dialog and overlay use z-[9999] so they appear above fullscreen wizards */
  elevatedForWizard?: boolean
}

function getCouponErrorMessage(error: unknown): string {
  const message =
    error instanceof AppwriteException
      ? error.message
      : error instanceof Error
        ? error.message
        : 'Invalid coupon code'

  if (message.includes('not_found')) {
    return 'Coupon not found. Please check the code and try again.'
  }
  if (message.includes('already_used')) {
    return 'This coupon has already been used.'
  }
  if (message.includes('not_eligible')) {
    return 'This coupon is not eligible for your selected plan.'
  }
  if (message.includes('unsupported')) {
    return 'Credits are not supported on this plan.'
  }
  return message
}

export function ValidateCreditModal({
  open,
  onOpenChange,
  onCouponApply,
  elevatedForWizard = false,
}: ValidateCreditModalProps) {
  const t = useT()
  const [couponCode, setCouponCode] = useState('')
  const [isApplying, setIsApplying] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)

  const trimmedCode = couponCode.trim()

  const handleApply = async () => {
    if (!trimmedCode) {
      toast.error(t('Please enter a coupon code'))
      return
    }

    setIsApplying(true)
    setSubmitError(null)

    try {
      const resolvedCoupon = await fetchCouponAccount(trimmedCode)
      if (!resolvedCoupon) {
        const message = t('Coupon not found. Please check the code and try again.')
        setSubmitError(message)
        toast.error(message)
        return
      }

      onCouponApply(resolvedCoupon)
      setCouponCode('')
      setSubmitError(null)
      onOpenChange(false)
      toast.success(t('Coupon applied successfully'))
    } catch (error) {
      const message = t(getCouponErrorMessage(error))
      setSubmitError(message)
      toast.error(message)
    } finally {
      setIsApplying(false)
    }
  }

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setCouponCode('')
      setSubmitError(null)
    }
    onOpenChange(newOpen)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className={cn('sm:max-w-md p-0', elevatedForWizard && 'z-[9999]')}
        overlayClassName={elevatedForWizard ? 'z-[9999]' : undefined}
      >
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Apply coupon')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Enter a coupon code to update your estimated total. Applied credits expire after a set period and do not roll over.')}
          </DialogDescription>
        </DialogHeader>

        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-4">
          <div className="space-y-4">
            <div>
              <Label htmlFor="coupon-code" className="text-[13px] font-medium">
                {t('Coupon code')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="coupon-code"
                value={couponCode}
                onChange={(e) => {
                  setCouponCode(e.target.value.toUpperCase())
                  setSubmitError(null)
                }}
                placeholder={t('Enter coupon code')}
                className="mt-2 h-9 text-[13px]"
                disabled={isApplying}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    void handleApply()
                  }
                }}
              />
            </div>

            {submitError ? (
              <WarningAlert icon={AlertCircle}>
                {submitError}
              </WarningAlert>
            ) : null}
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isApplying}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={() => void handleApply()}
            disabled={!trimmedCode || isApplying}
            {...analyticsAttrs('upgrade-apply-coupon-confirm')}
          >
            {t('Apply coupon')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
