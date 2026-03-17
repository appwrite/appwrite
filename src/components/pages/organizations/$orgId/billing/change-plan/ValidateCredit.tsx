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
import { Alert, AlertDescription } from '@/components/ui/alert'
import { AlertCircle, Ticket } from 'lucide-react'
import { useCouponAccount } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

interface ValidateCreditModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCouponApply: (coupon: Models.Coupon) => void
  /** When true, dialog and overlay use z-[9999] so they appear above fullscreen wizards */
  elevatedForWizard?: boolean
}

export function ValidateCreditModal({
  open,
  onOpenChange,
  onCouponApply,
  elevatedForWizard = false,
}: ValidateCreditModalProps) {
  const [couponCode, setCouponCode] = useState('')
  const { coupon, isLoading, error, refetch } = useCouponAccount(
    couponCode.trim() || null,
  )

  const handleApply = () => {
    if (!couponCode.trim()) {
      toast.error('Please enter a coupon code')
      return
    }

    refetch().then(() => {
      if (coupon) {
        onCouponApply(coupon)
        setCouponCode('')
        onOpenChange(false)
        toast.success('Coupon applied successfully')
      } else if (error) {
        // Error handling is done by the hook
        const errorMessage =
          error instanceof Error ? error.message : 'Invalid coupon code'
        if (errorMessage.includes('not_found')) {
          toast.error('Coupon not found')
        } else if (errorMessage.includes('already_used')) {
          toast.error('This coupon has already been used')
        } else if (errorMessage.includes('not_eligible')) {
          toast.error('This coupon is not eligible for your plan')
        } else if (errorMessage.includes('unsupported')) {
          toast.error('Credits are not supported on this plan')
        } else {
          toast.error(errorMessage)
        }
      }
    })
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
        className={cn('sm:max-w-md p-0', elevatedForWizard && 'z-[9999]')}
        overlayClassName={elevatedForWizard ? 'z-[9999]' : undefined}
      >
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Add Credits</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Enter a coupon code to apply credits to your account.
          </DialogDescription>
        </DialogHeader>

        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div>
              <Label htmlFor="coupon-code" className="text-[13px] font-medium">
                Coupon Code
              </Label>
              <Input
                id="coupon-code"
                value={couponCode}
                onChange={(e) => setCouponCode(e.target.value.toUpperCase())}
                placeholder="Enter coupon code"
                className="mt-2 h-9 text-[13px]"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    handleApply()
                  }
                }}
              />
            </div>

            {error && (
              <Alert variant="destructive">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription className="text-[13px] mt-2">
                  {(() => {
                    const errorMessage =
                      error instanceof Error
                        ? error.message
                        : 'Invalid coupon code'
                    if (errorMessage.includes('not_found')) {
                      return 'Coupon not found. Please check the code and try again.'
                    }
                    if (errorMessage.includes('already_used')) {
                      return 'This coupon has already been used.'
                    }
                    if (errorMessage.includes('not_eligible')) {
                      return 'This coupon is not eligible for your selected plan.'
                    }
                    if (errorMessage.includes('unsupported')) {
                      return 'Credits are not supported on this plan.'
                    }
                    return errorMessage
                  })()}
                </AlertDescription>
              </Alert>
            )}

            {coupon && (
              <Alert>
                <Ticket className="h-4 w-4" />
                <AlertDescription className="text-[13px] mt-2">
                  Coupon "{coupon.code}" is valid and will be applied.
                </AlertDescription>
              </Alert>
            )}
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            Cancel
          </Button>
          <Button
            onClick={handleApply}
            disabled={!couponCode.trim() || isLoading}
          >
            Apply Coupon
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
