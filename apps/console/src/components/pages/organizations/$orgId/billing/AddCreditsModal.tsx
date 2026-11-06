/**
 * AddCreditsModal Component
 *
 * Modal to add credits to an organization by redeeming a promo/coupon code.
 * Matches the old console addCreditModal flow.
 */

import { useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useAddOrganizationCredit } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

interface AddCreditsModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  organizationName?: string
  onSuccess?: () => void
}

export function AddCreditsModal({
  open,
  onOpenChange,
  organizationId,
  organizationName,
  onSuccess,
}: AddCreditsModalProps) {
  const t = useT()
  const [couponCode, setCouponCode] = useState('')
  const trimmedCode = couponCode.trim()
  const addCreditMutation = useAddOrganizationCredit()

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!trimmedCode) {
      toast.error(t('Please enter a promo code'))
      return
    }
    try {
      await addCreditMutation.mutateAsync({
        organizationId,
        couponId: trimmedCode,
      })
      const message = organizationName
        ? `${t('Credit has been added to')} ${organizationName}`
        : t('Credit has been added to your organization')
      toast.success(message)
      setCouponCode('')
      onOpenChange(false)
      onSuccess?.()
    } catch (error) {
      const message =
        error instanceof Error ? error.message : t('Failed to add credit')
      toast.error(message)
    }
  }

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setCouponCode('')
    }
    onOpenChange(newOpen)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
>
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add credits')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Apply Appwrite credits to your organization. Credits expire after a set period and do not roll over.', // pragma: allowlist secret
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form
          onSubmit={handleSubmit}
>
          <div className="px-6 pb-4 pt-4">
            <Label htmlFor="add-credits-code" className="text-[13px]">
              {t('Add promo code')}
            </Label>
            <Input
              id="add-credits-code"
              value={couponCode}
              onChange={(e) =>
                setCouponCode(e.target.value.trimStart().toUpperCase())
              }
              placeholder={t('Promo code')}
              className="mt-2 h-9 text-[13px]"
              disabled={addCreditMutation.isPending}
              autoFocus
            />
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={addCreditMutation.isPending}
>
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              disabled={!trimmedCode || addCreditMutation.isPending}
>
              {t('Add credits')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
