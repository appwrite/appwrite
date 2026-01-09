import { MapPin, Pencil, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { billingAddress } from '@/lib/utils/mock-data'

/**
 * BillingAddressSection Component
 *
 * Displays the stored billing address with:
 * - Formatted address display
 * - Update/add address actions
 *
 * Props:
 * - onEditAddress?: () => void - Callback to update address
 *
 * Edge cases:
 * - No address: Shows add address prompt
 */

interface BillingAddressSectionProps {
  onEditAddress?: () => void
}

export function BillingAddressSection({
  onEditAddress,
}: BillingAddressSectionProps) {
  const hasAddress = billingAddress && billingAddress.addressLine1

  if (!hasAddress) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Billing Address
          </h3>
        </div>
        <div className="border-t border-border px-6 py-8 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <MapPin className="h-6 w-6 text-muted-foreground" />
          </div>
          <p className="text-[13px] text-muted-foreground mb-4">
            No billing address on file
          </p>
          <Button
            size="sm"
            className="h-9 gap-2 text-[13px]"
            onClick={onEditAddress}
          >
            <Plus className="h-4 w-4" />
            Add billing address
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Billing Address
        </h3>
      </div>

      {/* Address Display */}
      <div className="border-t border-border px-6 py-4">
        <div className="flex items-start gap-4">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted shrink-0">
            <MapPin className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="text-[13px] text-foreground space-y-0.5">
            <p className="font-medium">{billingAddress.name}</p>
            {billingAddress.company && (
              <p className="text-muted-foreground">{billingAddress.company}</p>
            )}
            <p>{billingAddress.addressLine1}</p>
            {billingAddress.addressLine2 && (
              <p>{billingAddress.addressLine2}</p>
            )}
            <p>
              {billingAddress.city}
              {billingAddress.state && `, ${billingAddress.state}`}{' '}
              {billingAddress.postalCode}
            </p>
            <p>{billingAddress.country}</p>
          </div>
        </div>
      </div>

      {/* Footer */}
      <div className="border-t border-border px-6 py-4 bg-muted/30">
        <Button
          variant="outline"
          size="sm"
          className="h-9 gap-2 text-[13px]"
          onClick={onEditAddress}
        >
          <Pencil className="h-4 w-4" />
          Update address
        </Button>
      </div>
    </div>
  )
}
