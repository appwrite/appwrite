import { useState } from 'react'
import { MapPin, Pencil, Plus, Trash2, ArrowLeftRight } from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  useOrganizationById,
  useBillingAddress,
  useBillingAddresses,
  useSetOrganizationBillingAddress,
  useDeleteOrganizationBillingAddress} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { AddressModal } from '@/components/pages/account/Payments/Address'
import { OrgBillingAddressContextMenu } from './OrgBillingAddressContextMenu'

/**
 * BillingAddressSection Component
 *
 * Displays the stored billing address with:
 * - Formatted address display (streetAddress, addressLine2, city, state, postalCode, country)
 * - Update: edit the current address (Account API)
 * - Replace: choose another address or add new, then set on org
 * - Remove: unlink billing address from org (confirm dialog)
 *
 * Props:
 * - onEditAddress?: () => void - Optional callback when "Add address" is chosen (create flow)
 * - orgId?: string - Organization ID
 */

interface BillingAddressSectionProps {
  onEditAddress?: () => void
  orgId?: string
}

export function BillingAddressSection({
  onEditAddress,
  orgId}: BillingAddressSectionProps) {
  const t = useT()
  const [editModalOpen, setEditModalOpen] = useState(false)
  const [createModalOpen, setCreateModalOpen] = useState(false)
  const [removeConfirmOpen, setRemoveConfirmOpen] = useState(false)

  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const { address, isLoading: addressLoading } = useBillingAddress(
    organization?.billingAddressId,
  )
  const { addresses: allAddresses } = useBillingAddresses()
  const setOrgAddressMutation = useSetOrganizationBillingAddress()
  const deleteOrgAddressMutation = useDeleteOrganizationBillingAddress()

  const isLoading =
    (orgLoading && !organization) || (addressLoading && !address)

  const handleLinkAddress = async (addressId: string) => {
    if (!orgId) return
    try {
      await setOrgAddressMutation.mutateAsync({
        organizationId: orgId,
        billingAddressId: addressId})
      toast.success(t('Billing address updated'))
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to update billing address'),
      )
    }
  }

  const handleRemoveAddress = async () => {
    if (!orgId) return
    closeDialogBeforeOverlayUnmount(() => setRemoveConfirmOpen(false))
    try {
      await deleteOrgAddressMutation.mutateAsync({ organizationId: orgId })
      toast.success(
        organization?.name
          ? `${t('Billing address has been removed from')} ${organization.name}`
          : t('Billing address removed'),
      )
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to remove billing address'),
      )
    }
  }

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Billing address')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading address...')}
          </p>
        </div>
      </div>
    )
  }

  if (!address) {
    const availableAddresses = allAddresses.filter(
      (addr) => addr.$id !== organization?.billingAddressId,
    )

    const handleAddOrCreate = () =>
      onEditAddress ? onEditAddress() : setCreateModalOpen(true)

    return (
      <>
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4 flex items-center justify-between gap-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Billing address')}
            </h3>
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-2 text-[13px] shrink-0"
              onClick={handleAddOrCreate}
            >
              <Plus className="h-4 w-4" />
              {t('Add billing address')}
            </Button>
          </div>
          <div className="border-t border-border px-6 py-8">
            <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
              <MapPin className="h-6 w-6 text-muted-foreground" />
            </div>
            <p className="text-[13px] text-muted-foreground text-center mb-1">
              {t('No billing address on file')}
            </p>
            <p className="text-[13px] text-muted-foreground text-center mb-4">
              {t('Add a billing address for invoices and tax documents.')}
            </p>
            {availableAddresses.length > 0 ? (
              <div className="space-y-3 max-w-md mx-auto">
                <p className="text-[12px] font-medium text-foreground">
                  {t('Use existing address')}
                </p>
                <div className="flex flex-col gap-2">
                  {availableAddresses.map((addr) => (
                    <div
                      key={addr.$id}
                      className="flex items-center justify-between rounded-lg border border-border bg-muted/20 px-4 py-3"
                    >
                      <div className="flex items-center gap-3 min-w-0">
                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-muted shrink-0">
                          <MapPin className="h-4 w-4 text-muted-foreground" />
                        </div>
                        <div className="min-w-0">
                          <p className="text-[13px] font-medium text-foreground truncate">
                            {addr.streetAddress || t('Address')}
                          </p>
                          {(addr.city || addr.country) && (
                            <p className="text-[12px] text-muted-foreground truncate">
                              {[addr.city, addr.state, addr.country]
                                .filter(Boolean)
                                .join(', ')}
                            </p>
                          )}
                        </div>
                      </div>
                      <Button
                        size="sm"
                        className="h-8 text-[12px] shrink-0"
                        onClick={() => handleLinkAddress(addr.$id)}
                        disabled={setOrgAddressMutation.isPending}
                      >
                        {t('Use this address')}
                      </Button>
                    </div>
                  ))}
                </div>
                <p className="text-[12px] font-medium text-foreground pt-1">
                  {t('Or add a new address')}
                </p>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 gap-2 text-[13px] w-full"
                  onClick={handleAddOrCreate}
                >
                  <Plus className="h-4 w-4" />
                  {t('Add new address')}
                </Button>
              </div>
            ) : (
              <div className="flex justify-center">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 gap-2 text-[13px]"
                  onClick={handleAddOrCreate}
                >
                  <Plus className="h-4 w-4" />
                  {t('Add billing address')}
                </Button>
              </div>
            )}
          </div>
        </div>

        {orgId && (
          <AddressModal
            open={createModalOpen}
            onOpenChange={setCreateModalOpen}
            organizationId={orgId}
            onSuccess={() => setCreateModalOpen(false)}
          />
        )}
      </>
    )
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Billing address')}
          </h3>
        </div>

        <OrgBillingAddressContextMenu
          address={address}
          availableAddresses={(allAddresses || []).filter(
            (addr) => addr.$id !== organization?.billingAddressId,
          )}
          onUpdate={() => setEditModalOpen(true)}
          onReplace={handleLinkAddress}
          onAddNew={() => setCreateModalOpen(true)}
          onRemove={() => setRemoveConfirmOpen(true)}
        >
        <div className="border-t border-border px-6 py-4 flex items-center justify-between gap-4 hover:bg-accent/50 transition-colors">
          <div className="flex items-start gap-4 min-w-0">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted shrink-0">
              <MapPin className="h-5 w-5 text-muted-foreground" />
            </div>
            <div className="text-[13px] text-foreground space-y-0.5 min-w-0">
              <p>{address.streetAddress}</p>
              {address.addressLine2 && <p>{address.addressLine2}</p>}
              <p>
                {address.city}
                {address.state && `, ${address.state}`} {address.postalCode}
              </p>
              <p>{address.country}</p>
            </div>
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <RowActionsMenuTrigger
                disabled={
                  setOrgAddressMutation.isPending ||
                  deleteOrgAddressMutation.isPending
                }
              />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
              <DropdownMenuItem
                className="text-[13px]"
                onSelect={() =>
                  openDialogAfterOverlayCloses(() => setEditModalOpen(true))
                }
              >
                <MenuItemContent icon={Pencil}>{t('Update')}</MenuItemContent>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuSub>
                <DropdownMenuSubTrigger className="text-[13px]">
                  <MenuItemIcon icon={ArrowLeftRight} />
                  {t('Replace')}
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent className="w-52">
                  {(() => {
                    const availableAddresses = (allAddresses || []).filter(
                      (addr) => addr.$id !== organization?.billingAddressId,
                    )
                    return (
                      <>
                        {availableAddresses.length > 0 && (
                          <>
                            <div className="px-2 py-1.5">
                              <p className="text-[11px] font-medium text-muted-foreground">
                                {t('Choose existing address')}
                              </p>
                            </div>
                            {availableAddresses.map((addr) => (
                              <DropdownMenuItem
                                key={addr.$id}
                                className="text-[13px]"
                                onClick={() => handleLinkAddress(addr.$id)}
                              >
                                <MenuItemContent icon={MapPin}>
                                  <span className="truncate">
                                    {addr.streetAddress || t('Address')}
                                    {addr.city ? `, ${addr.city}` : ''}
                                  </span>
                                </MenuItemContent>
                              </DropdownMenuItem>
                            ))}
                            <DropdownMenuSeparator />
                          </>
                        )}
                        <DropdownMenuItem
                          className="text-[13px]"
                          onSelect={() =>
                            openDialogAfterOverlayCloses(() =>
                              setCreateModalOpen(true),
                            )
                          }
                        >
                          <MenuItemContent icon={Plus}>{t('Add')}</MenuItemContent>
                        </DropdownMenuItem>
                      </>
                    )
                  })()}
                </DropdownMenuSubContent>
              </DropdownMenuSub>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="text-[13px]"
                onSelect={() =>
                  openDialogAfterOverlayCloses(() => setRemoveConfirmOpen(true))
                }
              >
                <MenuItemContent icon={Trash2}>{t('Remove')}</MenuItemContent>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
        </OrgBillingAddressContextMenu>
      </div>

      <AddressModal
        open={editModalOpen}
        onOpenChange={setEditModalOpen}
        address={address}
        onSuccess={() => setEditModalOpen(false)}
      />

      {orgId && (
        <AddressModal
          open={createModalOpen}
          onOpenChange={setCreateModalOpen}
          organizationId={orgId}
          onSuccess={() => setCreateModalOpen(false)}
        />
      )}

      <Dialog open={removeConfirmOpen} onOpenChange={setRemoveConfirmOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Remove billing address')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to remove the billing address from')}{' '}
              <strong>{organization?.name}</strong>?{' '}
              {t('The address will remain on your account; only the link to this organization will be removed.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setRemoveConfirmOpen(false)}
              disabled={deleteOrgAddressMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => handleRemoveAddress()}
              disabled={deleteOrgAddressMutation.isPending}
            >
              {t('Remove')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
