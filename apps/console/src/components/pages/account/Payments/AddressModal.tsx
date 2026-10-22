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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useCreateBillingAddress,
  useUpdateBillingAddress,
  useCountries,
  useLocale,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
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
  const { data: countriesData, isLoading: countriesLoading } = useCountries()
  const { data: localeData } = useLocale()

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
        // Create mode - use locale default for country
        setCountry(localeData?.country || '')
        setStreetAddress('')
        setAddressLine2('')
        setCity('')
        setState('')
        setPostalCode('')
      }
    }
  }, [open, address, localeData])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!country || !streetAddress || !city || !state) {
      toast.error(t('Please fill in all required fields'))
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
        toast.success(t('Billing address updated'))
      } else {
        await createAddressMutation.mutateAsync({
          country,
          streetAddress,
          city,
          state,
          postalCode: postalCode || undefined,
          addressLine2: addressLine2 || undefined,
        })
        toast.success(t('Billing address created'))
      }

      onOpenChange(false)
      onSuccess?.()
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to save billing address'),
      )
    }
  }

  const isLoading =
    createAddressMutation.isPending || updateAddressMutation.isPending
  const countries = countriesData?.countries || []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
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
              <Select
                value={country}
                onValueChange={setCountry}
                disabled={isLoading || countriesLoading}
              >
                <SelectTrigger id="country" className="h-9 text-[13px]">
                  <SelectValue placeholder={t('Select a country')} />
                </SelectTrigger>
                <SelectContent>
                  {countries.map((c) => (
                    <SelectItem key={c.code} value={c.code}>
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="street-address" className="text-[13px]">
                {t('Street Address')} <span className="text-red-500">*</span>
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
