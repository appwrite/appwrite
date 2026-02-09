/**
 * AddressModal Component
 *
 * Modal for creating or editing a billing address.
 */

import { useState, useEffect } from 'react'
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
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Check, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  useCreateBillingAddress,
  useUpdateBillingAddress,
  useCountries,
  useLocale,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

interface AddressModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  address?: Models.BillingAddress
  onSuccess?: () => void
}

export function AddressModal({
  open,
  onOpenChange,
  address,
  onSuccess,
}: AddressModalProps) {
  const isEditing = !!address

  const [country, setCountry] = useState('')
  const [streetAddress, setStreetAddress] = useState('')
  const [addressLine2, setAddressLine2] = useState('')
  const [city, setCity] = useState('')
  const [state, setState] = useState('')
  const [postalCode, setPostalCode] = useState('')

  const createAddressMutation = useCreateBillingAddress()
  const updateAddressMutation = useUpdateBillingAddress()
  const { data: countriesData, isLoading: countriesLoading } = useCountries()
  const { data: localeData } = useLocale()

  const [countryPopoverOpen, setCountryPopoverOpen] = useState(false)

  // Initialize form with address data or locale default
  useEffect(() => {
    if (open) {
      if (address) {
        // Edit mode - populate with existing address
        setCountry(address.country || '')
        setStreetAddress(address.streetAddress || '')
        setAddressLine2(address.addressLine2 || '')
        setCity(address.city || '')
        setState(address.state || '')
        setPostalCode(address.postalCode || '')
      } else {
        // Create mode - reset all fields
        setStreetAddress('')
        setAddressLine2('')
        setCity('')
        setState('')
        setPostalCode('')
        // Country will be set in separate effect when locale data loads
      }
    } else {
      // Reset form when modal closes
      setCountry('')
      setStreetAddress('')
      setAddressLine2('')
      setCity('')
      setState('')
      setPostalCode('')
    }
  }, [open, address])

  // Set default country from user's locale when creating new address
  useEffect(() => {
    if (open && !address && localeData?.countryCode && !country) {
      // Use countryCode (ISO 3166-1 two-character code) from locale
      setCountry(localeData.countryCode)
    }
  }, [open, address, localeData, country])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!country || !streetAddress || !city || !state) {
      toast.error('Please fill in all required fields')
      return
    }

    try {
      if (isEditing && address) {
        await updateAddressMutation.mutateAsync({
          billingAddressId: address.$id,
          country,
          streetAddress,
          city,
          state,
          postalCode: postalCode || undefined,
          addressLine2: addressLine2 || undefined,
        })
        toast.success('Billing address updated')
      } else {
        await createAddressMutation.mutateAsync({
          country,
          streetAddress,
          city,
          state,
          postalCode: postalCode || undefined,
          addressLine2: addressLine2 || undefined,
        })
        toast.success('Billing address created')
      }

      onOpenChange(false)
      onSuccess?.()
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : 'Failed to save billing address',
      )
    }
  }

  const isLoading =
    createAddressMutation.isPending || updateAddressMutation.isPending
  const countries = countriesData?.countries || []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>
            {isEditing ? 'Update billing address' : 'Add billing address'}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {isEditing
              ? 'Update your billing address information.'
              : 'Add a new billing address to your account.'}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="country" className="text-[13px]">
                Country <span className="text-red-500">*</span>
              </Label>
              <Popover
                open={countryPopoverOpen}
                onOpenChange={setCountryPopoverOpen}
              >
                <PopoverTrigger asChild>
                  <Button
                    id="country"
                    variant="outline"
                    role="combobox"
                    aria-expanded={countryPopoverOpen}
                    className="h-9 w-full justify-between text-[13px] font-normal"
                    disabled={isLoading || countriesLoading}
                  >
                    <span className="truncate">
                      {country
                        ? countries.find((c) => c.code === country)?.name ||
                          'Select a country'
                        : 'Select a country'}
                    </span>
                    <ChevronDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                  </Button>
                </PopoverTrigger>
                <PopoverContent
                  className="w-[--radix-popover-trigger-width] p-0"
                  align="start"
                >
                  <Command>
                    <CommandInput
                      placeholder="Search countries..."
                      className="h-9"
                    />
                    <CommandList>
                      <CommandEmpty>No country found.</CommandEmpty>
                      <CommandGroup>
                        {countries.map((c) => (
                          <CommandItem
                            key={c.code}
                            value={`${c.name} ${c.code}`}
                            onSelect={() => {
                              setCountry(c.code)
                              setCountryPopoverOpen(false)
                            }}
                            className="text-[13px]"
                          >
                            <Check
                              className={cn(
                                'mr-2 h-4 w-4',
                                country === c.code
                                  ? 'opacity-100'
                                  : 'opacity-0',
                              )}
                            />
                            {c.name}
                          </CommandItem>
                        ))}
                      </CommandGroup>
                    </CommandList>
                  </Command>
                </PopoverContent>
              </Popover>
            </div>

            <div className="space-y-2">
              <Label htmlFor="street-address" className="text-[13px]">
                Street Address <span className="text-red-500">*</span>
              </Label>
              <Input
                id="street-address"
                value={streetAddress}
                onChange={(e) => setStreetAddress(e.target.value)}
                placeholder="123 Main St"
                className="h-9 text-[13px]"
                disabled={isLoading}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="address-line-2" className="text-[13px]">
                Address Line 2
              </Label>
              <Input
                id="address-line-2"
                value={addressLine2}
                onChange={(e) => setAddressLine2(e.target.value)}
                placeholder="Apt, suite, etc. (optional)"
                className="h-9 text-[13px]"
                disabled={isLoading}
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="city" className="text-[13px]">
                  City <span className="text-red-500">*</span>
                </Label>
                <Input
                  id="city"
                  value={city}
                  onChange={(e) => setCity(e.target.value)}
                  placeholder="City"
                  className="h-9 text-[13px]"
                  disabled={isLoading}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="state" className="text-[13px]">
                  State/Province <span className="text-red-500">*</span>
                </Label>
                <Input
                  id="state"
                  value={state}
                  onChange={(e) => setState(e.target.value)}
                  placeholder="State"
                  className="h-9 text-[13px]"
                  disabled={isLoading}
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="postal-code" className="text-[13px]">
                Postal Code
              </Label>
              <Input
                id="postal-code"
                value={postalCode}
                onChange={(e) => setPostalCode(e.target.value)}
                placeholder="12345"
                className="h-9 text-[13px]"
                disabled={isLoading}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isLoading}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={
                isLoading || !country || !streetAddress || !city || !state
              }
            >
              {isEditing ? 'Update' : 'Add'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
