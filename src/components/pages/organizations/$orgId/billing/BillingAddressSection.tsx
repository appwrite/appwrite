import { MapPin, Pencil, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useOrganizationById, useBillingAddress, useBillingAddresses, useUpdateOrganizationBillingAddress } from '@/lib/react-query/hooks'
import { toast } from 'sonner'

/**
 * BillingAddressSection Component
 *
 * Displays the stored billing address with:
 * - Formatted address display
 * - Update/add address actions
 *
 * Props:
 * - onEditAddress?: () => void - Callback to update address
 * - orgId?: string - Organization ID
 *
 * Edge cases:
 * - No address: Shows add address prompt
 */

interface BillingAddressSectionProps {
  onEditAddress?: () => void
  orgId?: string
}

export function BillingAddressSection({
  onEditAddress,
  orgId,
}: BillingAddressSectionProps) {
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const { address, isLoading: addressLoading } = useBillingAddress(organization?.billingAddressId)
  const { addresses: allAddresses } = useBillingAddresses()
  const updateAddressMutation = useUpdateOrganizationBillingAddress()

  const isLoading = orgLoading || addressLoading

  const handleLinkAddress = async (addressId: string) => {
    if (!orgId) return
    
    try {
      await updateAddressMutation.mutateAsync({
        organizationId: orgId,
        billingAddressId: addressId,
      })
      toast.success('Billing address updated')
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Failed to update billing address')
    }
  }

  const handleRemoveAddress = async () => {
    if (!orgId) return
    
    try {
      await updateAddressMutation.mutateAsync({
        organizationId: orgId,
        billingAddressId: undefined,
      })
      toast.success('Billing address removed')
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Failed to remove billing address')
    }
  }

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Billing Address
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">Loading address...</p>
        </div>
      </div>
    )
  }

  if (!address) {
    // Show available addresses from account if any
    const availableAddresses = allAddresses.filter(
      (addr) => addr.$id !== organization?.billingAddressId,
    )

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
          {availableAddresses.length > 0 ? (
            <div className="space-y-2">
              {availableAddresses.map((addr) => (
                <Button
                  key={addr.$id}
                  variant="outline"
                  size="sm"
                  className="h-9 gap-2 text-[13px] w-full"
                  onClick={() => handleLinkAddress(addr.$id)}
                >
                  Use {addr.name || 'Address'}
                </Button>
              ))}
              <Button
                size="sm"
                className="h-9 gap-2 text-[13px] w-full"
                onClick={onEditAddress}
              >
                <Plus className="h-4 w-4" />
                Create new address
              </Button>
            </div>
          ) : (
            <Button
              size="sm"
              className="h-9 gap-2 text-[13px]"
              onClick={onEditAddress}
            >
              <Plus className="h-4 w-4" />
              Add billing address
            </Button>
          )}
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
            {address.name && (
              <p className="font-medium">{address.name}</p>
            )}
            {address.company && (
              <p className="text-muted-foreground">{address.company}</p>
            )}
            <p>{address.addressLine1}</p>
            {address.addressLine2 && (
              <p>{address.addressLine2}</p>
            )}
            <p>
              {address.city}
              {address.state && `, ${address.state}`}{' '}
              {address.postalCode}
            </p>
            <p>{address.country}</p>
          </div>
        </div>
      </div>

      {/* Footer */}
      <div className="border-t border-border px-6 py-4 bg-muted/30">
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px]"
            onClick={onEditAddress}
          >
            <Pencil className="h-4 w-4" />
            Update address
          </Button>
          {allAddresses.length > 1 && (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleRemoveAddress}
              disabled={updateAddressMutation.isPending}
            >
              Remove
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
