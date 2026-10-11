/**
 * ReplaceAddressModal Component
 *
 * Modal to replace the organization's billing address: choose an existing
 * address from the account or add a new one, then set it on the organization.
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import {
  useBillingAddresses,
  useCreateBillingAddress,
  useSetOrganizationBillingAddress,
  useCountries,
  useLocale,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface ReplaceAddressModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  currentAddressId: string | undefined
  onSuccess?: () => void
}

const NEW_ADDRESS_VALUE = '$new'

export function ReplaceAddressModal({
  open,
  onOpenChange,
  organizationId,
  currentAddressId,
  onSuccess,
}: ReplaceAddressModalProps) {
  const t = useT()
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [country, setCountry] = useState('')
  const [streetAddress, setStreetAddress] = useState('')
  const [addressLine2, setAddressLine2] = useState('')
  const [city, setCity] = useState('')
  const [state, setState] = useState('')
  const [postalCode, setPostalCode] = useState('')

  const { addresses, isLoading: addressesLoading } = useBillingAddresses()
  const createAddressMutation = useCreateBillingAddress()
  const setOrgAddressMutation = useSetOrganizationBillingAddress()
  const { data: countriesData } = useCountries()
  const { data: localeData } = useLocale()

  useEffect(() => {
    if (open && addresses?.length) {
      const firstOther = addresses.find((a) => a.$id !== currentAddressId)
      setSelectedId(firstOther?.$id ?? null)
    } else if (open && !addresses?.length) {
      setSelectedId(NEW_ADDRESS_VALUE)
    }
  }, [open, addresses, currentAddressId])

  useEffect(() => {
    if (
      open &&
      selectedId === NEW_ADDRESS_VALUE &&
      localeData?.countryCode &&
      localeData.countryCode !== '--'
    ) {
      setCountry(localeData.countryCode.toUpperCase())
    }
  }, [open, selectedId, localeData?.countryCode])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!organizationId) return

    try {
      if (selectedId === NEW_ADDRESS_VALUE) {
        if (!country || !streetAddress || !city || !state) {
          toast.error(t('Please fill in all required fields'))
          return
        }
        const newAddress = await createAddressMutation.mutateAsync({
          country,
          streetAddress,
          city,
          state,
          postalCode: postalCode || undefined,
          addressLine2: addressLine2 || undefined,
        })
        await setOrgAddressMutation.mutateAsync({
          organizationId,
          billingAddressId: newAddress.$id,
        })
        toast.success(
          t('Billing address has been created and set for your organization'),
        )
      } else if (selectedId && selectedId !== currentAddressId) {
        await setOrgAddressMutation.mutateAsync({
          organizationId,
          billingAddressId: selectedId,
        })
        toast.success(t('Your billing address has been updated'))
      }

      onOpenChange(false)
      onSuccess?.()
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to update billing address'),
      )
    }
  }

  const isLoading =
    createAddressMutation.isPending || setOrgAddressMutation.isPending
  const countries = countriesData?.countries || []
  const isNewAddress = selectedId === NEW_ADDRESS_VALUE
  const canSubmit =
    selectedId === currentAddressId
      ? false
      : isNewAddress
        ? !!(country && streetAddress && city && state)
        : !!selectedId

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Replace billing address')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Replace the existing billing address for your organization.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-4 space-y-4">
            {addressesLoading ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
              </div>
            ) : addresses?.length ? (
              <>
                <RadioGroup
                  value={selectedId ?? ''}
                  onValueChange={(v) => setSelectedId(v || null)}
                  className="space-y-2"
                >
                  {addresses.map((addr) => (
                    <label
                      key={addr.$id}
                      className={cn(
                        'flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-colors',
                        addr.$id === currentAddressId
                          ? 'border-border bg-muted/50 cursor-not-allowed opacity-70'
                          : 'border-border hover:bg-muted/30',
                      )}
                    >
                      <RadioGroupItem
                        value={addr.$id}
                        disabled={addr.$id === currentAddressId}
                        className="mt-0.5"
                      />
                      <div className="text-[13px] flex-1">
                        <p className="font-medium">{addr.streetAddress}</p>
                        {addr.addressLine2 && (
                          <p className="text-muted-foreground">
                            {addr.addressLine2}
                          </p>
                        )}
                        <p className="text-muted-foreground">
                          {addr.city}
                          {addr.state && `, ${addr.state}`} {addr.postalCode}
                        </p>
                        <p className="text-muted-foreground">{addr.country}</p>
                        {addr.$id === currentAddressId && (
                          <span className="text-[12px] text-muted-foreground mt-1 inline-block">
                            {t('Current')}
                          </span>
                        )}
                      </div>
                    </label>
                  ))}

                  <label className="flex items-start gap-3 rounded-lg border border-border p-3 cursor-pointer hover:bg-muted/30 transition-colors">
                    <RadioGroupItem
                      value={NEW_ADDRESS_VALUE}
                      className="mt-0.5"
                    />
                    <span className="text-[13px] font-medium">
                      {t('Add a new billing address')}
                    </span>
                  </label>
                </RadioGroup>
              </>
            ) : (
              <p className="text-[13px] text-muted-foreground py-2">
                {t('There are no billing addresses on your account. Add one below.')}
              </p>
            )}

            {(!addresses?.length || isNewAddress) && (
              <div className="space-y-4 pt-2 border-t border-border">
                <div className="space-y-2">
                  <Label htmlFor="replace-country" className="text-[13px]">
                    {t('Country')} <span className="text-red-500">*</span>
                  </Label>
                  <Select
                    value={country}
                    onValueChange={setCountry}
                    disabled={isLoading}
                  >
                    <SelectTrigger
                      id="replace-country"
                      className="h-9 text-[13px]"
                    >
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
                  <Label htmlFor="replace-street" className="text-[13px]">
                    {t('Street address')} <span className="text-red-500">*</span>
                  </Label>
                  <Input
                    id="replace-street"
                    value={streetAddress}
                    onChange={(e) => setStreetAddress(e.target.value)}
                    placeholder={t('Enter street address')}
                    className="h-9 text-[13px]"
                    disabled={isLoading}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="replace-line2" className="text-[13px]">
                    {t('Address line 2')}
                  </Label>
                  <Input
                    id="replace-line2"
                    value={addressLine2}
                    onChange={(e) => setAddressLine2(e.target.value)}
                    placeholder={t('Unit, floor, etc.')}
                    className="h-9 text-[13px]"
                    disabled={isLoading}
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="replace-city" className="text-[13px]">
                      {t('City')} <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="replace-city"
                      value={city}
                      onChange={(e) => setCity(e.target.value)}
                      placeholder={t('City')}
                      className="h-9 text-[13px]"
                      disabled={isLoading}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="replace-state" className="text-[13px]">
                      {t('State')} <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="replace-state"
                      value={state}
                      onChange={(e) => setState(e.target.value)}
                      placeholder={t('State')}
                      className="h-9 text-[13px]"
                      disabled={isLoading}
                    />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="replace-postal" className="text-[13px]">
                    {t('Postal code')}
                  </Label>
                  <Input
                    id="replace-postal"
                    value={postalCode}
                    onChange={(e) => setPostalCode(e.target.value)}
                    placeholder={t('Postal code')}
                    className="h-9 text-[13px]"
                    disabled={isLoading}
                  />
                </div>
              </div>
            )}
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
            <Button type="submit" disabled={isLoading || !canSubmit}>
              {t('Save')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
