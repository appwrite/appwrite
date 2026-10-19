/**
 * AddressModal Component
 *
 * Modal for creating or editing a billing address.
 */

import { useState, useEffect, useRef } from 'react'
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
  useSetOrganizationBillingAddress,
  useCountries,
  useLocale,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface AddressModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  address?: Models.BillingAddress
  /** When creating, if set the new address will be assigned to this organization */
  organizationId?: string
  onSuccess?: (address?: Models.BillingAddress) => void
  /** When true, dialog and overlay use z-[9999] so they appear above fullscreen wizards */
  elevatedForWizard?: boolean
}

export function AddressModal({
  open,
  onOpenChange,
  address,
  organizationId,
  onSuccess,
  elevatedForWizard = false,
}: AddressModalProps) {
  const t = useT()
  const isEditing = !!address

  const [country, setCountry] = useState('')
  const [streetAddress, setStreetAddress] = useState('')
  const [addressLine2, setAddressLine2] = useState('')
  const [city, setCity] = useState('')
  const [state, setState] = useState('')
  const [postalCode, setPostalCode] = useState('')

  const createAddressMutation = useCreateBillingAddress()
  const updateAddressMutation = useUpdateBillingAddress()
  const setOrgAddressMutation = useSetOrganizationBillingAddress()
  const { data: countriesData, isLoading: countriesLoading } = useCountries()
  const { data: localeData } = useLocale()

  const [countryPopoverOpen, setCountryPopoverOpen] = useState(false)
  const didPrefillCountryRef = useRef(false)

  const countries = countriesData?.countries || []

  const resolveLocaleCountryCode = () => {
    const raw = localeData?.countryCode?.trim()
    if (!raw || raw === '--') return ''
    const normalized = raw.toUpperCase()
    const match = countries.find((c) => c.code.toUpperCase() === normalized)
    return match?.code ?? normalized
  }

  // Reset / populate form when the modal opens or switches between create and edit
  useEffect(() => {
    if (!open) {
      didPrefillCountryRef.current = false
      setCountry('')
      setStreetAddress('')
      setAddressLine2('')
      setCity('')
      setState('')
      setPostalCode('')
      return
    }

    if (address) {
      didPrefillCountryRef.current = true
      setCountry(address.country || '')
      setStreetAddress(address.streetAddress || '')
      setAddressLine2(address.addressLine2 || '')
      setCity(address.city || '')
      setState(address.state || '')
      setPostalCode(address.postalCode || '')
      return
    }

    didPrefillCountryRef.current = false
    setStreetAddress('')
    setAddressLine2('')
    setCity('')
    setState('')
    setPostalCode('')

    const localeCountry = resolveLocaleCountryCode()
    setCountry(localeCountry)
    if (localeCountry) {
      didPrefillCountryRef.current = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- initialize once per open/address change
  }, [open, address])

  // Prefill country from locale once it (and countries) are available in create mode
  useEffect(() => {
    if (!open || address || didPrefillCountryRef.current) return
    const localeCountry = resolveLocaleCountryCode()
    if (!localeCountry) return
    setCountry(localeCountry)
    didPrefillCountryRef.current = true
    // eslint-disable-next-line react-hooks/exhaustive-deps -- prefill only until applied
  }, [open, address, localeData?.countryCode, countriesData?.countries])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!country || !streetAddress || !city || !state) {
      toast.error(t('Please fill in all required fields'))
      return
    }

    try {
      let savedAddress: Models.BillingAddress | undefined
      if (isEditing && address) {
        savedAddress = await updateAddressMutation.mutateAsync({
          billingAddressId: address.$id,
          country,
          streetAddress,
          city,
          state,
          postalCode: postalCode || undefined,
          addressLine2: addressLine2 || undefined,
        })
        toast.success(t('Billing address updated'))
      } else {
        savedAddress = await createAddressMutation.mutateAsync({
          country,
          streetAddress,
          city,
          state,
          postalCode: postalCode || undefined,
          addressLine2: addressLine2 || undefined,
        })
        if (organizationId) {
          await setOrgAddressMutation.mutateAsync({
            organizationId,
            billingAddressId: savedAddress.$id,
          })
          toast.success(t('Billing address has been added to your organization'))
        } else {
          toast.success(t('Billing address created'))
        }
      }

      onOpenChange(false)
      onSuccess?.(savedAddress)
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to save billing address'),
      )
    }
  }

  const isLoading =
    createAddressMutation.isPending ||
    updateAddressMutation.isPending ||
    setOrgAddressMutation.isPending

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn('sm:max-w-md p-0', elevatedForWizard && 'z-[9999]')}
        overlayClassName={elevatedForWizard ? 'z-[9999]' : undefined}
      >
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>
            {isEditing ? t('Update billing address') : t('Add billing address')}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {isEditing
              ? t('Update your billing address information.')
              : t('Add a new billing address to your account.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="country" className="text-[13px]">
                {t('Country')} <span className="text-red-500">*</span>
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
                          t('Select a country')
                        : t('Select a country')}
                    </span>
                    <ChevronDown className="ms-2 h-4 w-4 shrink-0 opacity-50" />
                  </Button>
                </PopoverTrigger>
                <PopoverContent
                  className="w-[--radix-popover-trigger-width] p-0"
                  align="start"
                >
                  <Command>
                    <CommandInput
                      placeholder={t('Search countries...')}
                      className="h-9"
                    />
                    <CommandList>
                      <CommandEmpty>{t('No country found.')}</CommandEmpty>
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
                                'me-2 h-4 w-4',
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
                {t('Street Address')} <span className="text-red-500">*</span>
              </Label>
              <Input
                id="street-address"
                value={streetAddress}
                onChange={(e) => setStreetAddress(e.target.value)}
                placeholder={t('123 Main St')}
                className="h-9 text-[13px]"
                disabled={isLoading}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="address-line-2" className="text-[13px]">
                {t('Address Line 2')}
              </Label>
              <Input
                id="address-line-2"
                value={addressLine2}
                onChange={(e) => setAddressLine2(e.target.value)}
                placeholder={t('Apt, suite, etc. (optional)')}
                className="h-9 text-[13px]"
                disabled={isLoading}
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="city" className="text-[13px]">
                  {t('City')} <span className="text-red-500">*</span>
                </Label>
                <Input
                  id="city"
                  value={city}
                  onChange={(e) => setCity(e.target.value)}
                  placeholder={t('City')}
                  className="h-9 text-[13px]"
                  disabled={isLoading}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="state" className="text-[13px]">
                  {t('State/Province')} <span className="text-red-500">*</span>
                </Label>
                <Input
                  id="state"
                  value={state}
                  onChange={(e) => setState(e.target.value)}
                  placeholder={t('State')}
                  className="h-9 text-[13px]"
                  disabled={isLoading}
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="postal-code" className="text-[13px]">
                {t('Postal Code')}
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
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              disabled={
                isLoading || !country || !streetAddress || !city || !state
              }
            >
              {isEditing ? t('Update') : t('Add')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
