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
  const [couponCode, setCouponCode] = useState('')
  const addCreditMutation = useAddOrganizationCredit()

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    const code = couponCode.trim()
    if (!code) {
      toast.error('Please enter a promo code')
      return
    }
    try {
      await addCreditMutation.mutateAsync({
        organizationId,
        couponId: code,
      })
      const message = organizationName
        ? `Credit has been added to ${organizationName}`
        : 'Credit has been added to your organization'
      toast.success(message)
      setCouponCode('')
      onOpenChange(false)
      onSuccess?.()
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to add credit'
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
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Add credits</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Apply Appwrite credits to your organization.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-4">
            <Label htmlFor="add-credits-code" className="text-[13px]">
              Add promo code
            </Label>
            <Input
              id="add-credits-code"
              value={couponCode}
              onChange={(e) => setCouponCode(e.target.value.trimStart())}
              placeholder="Promo code"
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
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={!couponCode.trim() || addCreditMutation.isPending}
            >
              Add credits
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
