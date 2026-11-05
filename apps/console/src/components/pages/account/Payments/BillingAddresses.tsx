/**
 * AccountBillingAddresses Component
 *
 * Displays and manages billing addresses at the account level.
 * Shows all addresses with their linked organizations.
 */

import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MapPin, Link as LinkIcon, Plus, Pencil, Trash2 } from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Popover,
  PopoverContent,
  PopoverTrigger} from '@/components/ui/popover'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  useCountryLookups,
  useDeleteBillingAddress,
  fetchBillingAddresses,
  organizationsFullQueryOptions,
  billingAddressesQueryOptions,
} from '@/lib/react-query/hooks'
import { getCountryDisplayName } from '@/lib/locale/country-lookups'
import type { Models } from '@appwrite.io/console'
import { AddressModal } from './Address'
import { DeleteAddressModal } from './DeleteAddress'
import { BillingAddressContextMenu } from './BillingAddressContextMenu'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

export function AccountBillingAddresses({
  initialData,
}: {
  initialData?: {
    addresses?: Awaited<ReturnType<typeof fetchBillingAddresses>>
    organizations?: Models.Organization[]
  }
} = {}) {
  const t = useT()
  const { data: addressesData, isFetched: addressesFetched } = useQuery(
    billingAddressesQueryOptions(),
  )
  const { lookups: countryLookups } = useCountryLookups()
  useDeleteBillingAddress()

  const addresses =
    addressesData?.addresses ?? initialData?.addresses?.addresses ?? []

  const { data: organizationsData } = useQuery(organizationsFullQueryOptions())

  const organizations = organizationsData ?? initialData?.organizations ?? []
  const hasResolvedData =
    addressesFetched || initialData?.addresses !== undefined

  const [addModalOpen, setAddModalOpen] = useState(false)
  const [editModalOpen, setEditModalOpen] = useState(false)
  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [selectedAddress, setSelectedAddress] =
    useState<Models.BillingAddress | null>(null)

  // Get organizations linked to each address
  const getLinkedOrganizations = (addressId: string) => {
    return organizations.filter((org) => org.billingAddressId === addressId)
  }

  const handleAdd = () => {
    setSelectedAddress(null)
    setAddModalOpen(true)
  }

  const handleEdit = (address: Models.BillingAddress) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedAddress(address)
      setEditModalOpen(true)
    })
  }

  const handleDelete = (address: Models.BillingAddress) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedAddress(address)
      setDeleteModalOpen(true)
    })
  }

  const handleAddSuccess = () => {
    setAddModalOpen(false)
    setSelectedAddress(null)
  }

  const handleEditSuccess = () => {
    setEditModalOpen(false)
    setSelectedAddress(null)
  }

  const handleDeleteSuccess = () => {
    setDeleteModalOpen(false)
    setSelectedAddress(null)
  }

  const formatAddress = (address: Models.BillingAddress) => {
    const parts: string[] = []
    if (address.streetAddress) parts.push(address.streetAddress)
    if (address.addressLine2) parts.push(address.addressLine2)
    if (address.city) {
      let cityPart = address.city
      if (address.state) cityPart += `, ${address.state}`
      if (address.postalCode) cityPart += ` ${address.postalCode}`
      parts.push(cityPart)
    }
    if (address.country) {
      parts.push(
        getCountryDisplayName(address.country, countryLookups) ??
          address.country,
      )
    }
    return parts.join(', ') || '-'
  }

  if (!hasResolvedData) {
    return null
  }

  if (addresses.length === 0) {
    return (
      <>
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Billing addresses')}
                </h3>
                <p className="text-[13px] text-muted-foreground mt-1">
                  {t('Manage your billing addresses for invoices and payments.')}
                </p>
              </div>
            </div>
          </div>
          <div className="border-t border-border -mx-6" />
          <div className="px-6 py-8 text-center">
            <EmptyState
              icon={MapPin}
              title={t('No billing addresses')}
              description={t('Add a billing address to get started')}
              isEmpty={true}
              hasFilters={false}
              variant="default"
            >
              <div className="mt-4">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleAdd}
                >
                  <Plus className="me-1.5 h-4 w-4" />
                  {t('Add billing address')}
                </Button>
              </div>
            </EmptyState>
          </div>
        </div>

        <AddressModal
          open={addModalOpen}
          onOpenChange={setAddModalOpen}
          onSuccess={handleAddSuccess}
        />
      </>
    )
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Billing addresses')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                {t('Manage your billing addresses for invoices and payments.')}
              </p>
            </div>
            <Button size="sm" className="h-9 text-[13px]" onClick={handleAdd}>
              <Plus className="me-1.5 h-4 w-4" />
              {t('Add billing address')}
            </Button>
          </div>
        </div>
        <div className="border-t border-border -mx-6" />
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Address')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Linked To')}
              </TableHead>
              <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[60px]" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {addresses.map((address: Models.BillingAddress) => {
              const linkedOrgs = getLinkedOrganizations(address.$id)
              const isLinked = linkedOrgs.length > 0

              return (
                <BillingAddressContextMenu
                  key={address.$id}
                  address={address}
                  onUpdate={handleEdit}
                  onDelete={handleDelete}
                >
                <TableRow
                  className="hover:bg-muted/50 transition-colors"
                >
                  <TableCell className="px-6 py-3">
                    <div className="flex items-center gap-3">
                      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <MapPin className="h-4 w-4 text-muted-foreground" />
                      </div>
                      <p className="text-[13px] text-foreground">
                        {formatAddress(address)}
                      </p>
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {isLinked ? (
                      <Popover>
                        <PopoverTrigger asChild>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-[12px] text-muted-foreground hover:text-foreground -ms-2"
                          >
                            <LinkIcon className="me-1.5 h-3.5 w-3.5" />
                            {linkedOrgs.length}{' '}
                            {linkedOrgs.length > 1
                              ? t('organizations')
                              : t('organization')}
                          </Button>
                        </PopoverTrigger>
                        <PopoverContent className="w-64" align="start">
                          <div className="space-y-2">
                            <p className="text-[12px] font-medium text-foreground mb-2">
                              {t('Linked Organizations')}
                            </p>
                            {linkedOrgs.map((org) => (
                              <Link
                                key={org.$id}
                                to="/organizations/$orgId/settings/billing"
                                params={{ orgId: org.$id }}
                                className="block rounded-md px-2 py-1.5 text-[12px] text-foreground hover:bg-muted transition-colors"
                              >
                                {org.name}
                              </Link>
                            ))}
                          </div>
                        </PopoverContent>
                      </Popover>
                    ) : (
                      <Badge variant="inactive" className="text-[10px] shrink-0">
                        {t('Not linked')}
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell className="px-6 py-3 text-end">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-40">
                        <DropdownMenuItem
                          className="text-[13px]"
                          onClick={() => handleEdit(address)}
                        >
                          <MenuItemContent icon={Pencil}>
                            {t('Update')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          className="text-[13px]"
                          onClick={() => handleDelete(address)}
                        >
                          <MenuItemContent icon={Trash2}>
                            {t('Delete')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
                </BillingAddressContextMenu>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {/* Add Modal */}
      <AddressModal
        open={addModalOpen}
        onOpenChange={setAddModalOpen}
        onSuccess={handleAddSuccess}
      />

      {/* Edit Modal */}
      {selectedAddress && (
        <AddressModal
          open={editModalOpen}
          onOpenChange={setEditModalOpen}
          address={selectedAddress}
          onSuccess={handleEditSuccess}
        />
      )}

      {/* Delete Modal */}
      {selectedAddress && (
        <DeleteAddressModal
          open={deleteModalOpen}
          onOpenChange={setDeleteModalOpen}
          address={selectedAddress}
          linkedOrganizations={getLinkedOrganizations(selectedAddress.$id)}
          onSuccess={handleDeleteSuccess}
        />
      )}
    </>
  )
}
