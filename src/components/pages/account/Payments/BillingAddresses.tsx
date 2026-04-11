/**
 * AccountBillingAddresses Component
 *
 * Displays and manages billing addresses at the account level.
 * Shows all addresses with their linked organizations.
 */

import { useMemo, useState } from 'react'
import { MapPin, MoreHorizontal, Link as LinkIcon, Plus } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  useBillingAddresses,
  useCountries,
  useDeleteBillingAddress,
} from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { AddressModal } from './Address'
import { DeleteAddressModal } from './DeleteAddress'

export function AccountBillingAddresses() {
  const { addresses: allAddresses, isLoading: addressesLoading } =
    useBillingAddresses()
  const { data: countriesData } = useCountries()
  useDeleteBillingAddress()

  // Fetch organizations with full data (including billingAddressId)
  const { data: organizationsData } = useQuery({
    queryKey: ['organizations', 'console', 'full'],
    queryFn: async () => {
      const response = await sdk.forConsole.organizations.list({
        queries: [Query.equal('platform', 'appwrite')],
      })
      return response.teams || []
    },
    staleTime: 30 * 1000,
  })

  const organizations = organizationsData || []

  const [addModalOpen, setAddModalOpen] = useState(false)
  const [editModalOpen, setEditModalOpen] = useState(false)
  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [selectedAddress, setSelectedAddress] =
    useState<Models.BillingAddress | null>(null)

  // Get organizations linked to each address
  const getLinkedOrganizations = (addressId: string) => {
    return organizations.filter((org) => org.billingAddressId === addressId)
  }

  // Country lookup map
  const countryMap = useMemo(() => {
    if (!countriesData?.countries) return new Map()
    return new Map(
      countriesData.countries.map((country) => [country.code, country.name]),
    )
  }, [countriesData])

  const handleAdd = () => {
    setSelectedAddress(null)
    setAddModalOpen(true)
  }

  const handleEdit = (address: Models.BillingAddress) => {
    setSelectedAddress(address)
    setEditModalOpen(true)
  }

  const handleDelete = (address: Models.BillingAddress) => {
    setSelectedAddress(address)
    setDeleteModalOpen(true)
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
      const countryName = countryMap.get(address.country) || address.country
      parts.push(countryName)
    }
    return parts.join(', ') || '-'
  }

  if (addressesLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                Billing Addresses
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                Manage your billing addresses for invoices and payments.
              </p>
            </div>
          </div>
        </div>
        <div className="border-t border-border -mx-6" />
        <div className="px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            Loading addresses...
          </p>
        </div>
      </div>
    )
  }

  if (allAddresses.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                Billing Addresses
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                Manage your billing addresses for invoices and payments.
              </p>
            </div>
          </div>
        </div>
        <div className="border-t border-border -mx-6" />
        <div className="px-6 py-8 text-center">
          <EmptyState
            icon={MapPin}
            title="No billing addresses"
            description="Add a billing address to get started"
            isEmpty={true}
            hasFilters={false}
            variant="default"
          >
            <div className="mt-4">
              <Button size="sm" className="h-9 text-[13px]" onClick={handleAdd}>
                <Plus className="mr-1.5 h-4 w-4" />
                Add billing address
              </Button>
            </div>
          </EmptyState>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                Billing Addresses
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                Manage your billing addresses for invoices and payments.
              </p>
            </div>
            <Button size="sm" className="h-9 text-[13px]" onClick={handleAdd}>
              <Plus className="mr-1.5 h-4 w-4" />
              Add billing address
            </Button>
          </div>
        </div>
        <div className="border-t border-border -mx-6" />
        <div className="px-6 py-4">
          <div className="rounded-lg border border-border overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Address
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Linked To
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[60px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {allAddresses.map((address: Models.BillingAddress) => {
                  const linkedOrgs = getLinkedOrganizations(address.$id)
                  const isLinked = linkedOrgs.length > 0

                  return (
                    <TableRow
                      key={address.$id}
                      className="hover:bg-muted/50 transition-colors"
                    >
                      <TableCell className="px-4 py-3">
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
                                className="h-7 text-[12px] text-muted-foreground hover:text-foreground -ml-2"
                              >
                                <LinkIcon className="mr-1.5 h-3.5 w-3.5" />
                                {linkedOrgs.length} organization
                                {linkedOrgs.length > 1 ? 's' : ''}
                              </Button>
                            </PopoverTrigger>
                            <PopoverContent className="w-64" align="start">
                              <div className="space-y-2">
                                <p className="text-[12px] font-medium text-foreground mb-2">
                                  Linked Organizations
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
                          <span className="text-[12px] text-muted-foreground">
                            Not linked
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
                            >
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-40">
                            <DropdownMenuItem
                              className="text-[13px]"
                              onClick={() => handleEdit(address)}
                            >
                              Update
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              className="text-[13px] text-red-600 dark:text-red-400 focus:text-red-600 dark:focus:text-red-400"
                              onClick={() => handleDelete(address)}
                            >
                              Delete
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        </div>
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
