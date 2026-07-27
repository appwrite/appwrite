import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { useT } from '@/lib/i18n/translate'
import type { CreateAnalyticsPropertyInput } from '@/lib/react-query/hooks'

interface CreatePropertyProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (data: CreateAnalyticsPropertyInput) => void
  isLoading?: boolean
}

/** Browser IANA timezone, used as the property's daily boundary by default. */
function resolveBrowserTimezone(): string | undefined {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined
  } catch {
    return undefined
  }
}

export function CreateProperty({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreatePropertyProps) {
  const t = useT()
  const [propertyId, setPropertyId] = useState<string | undefined>(undefined)
  const [name, setName] = useState('')
  const [domain, setDomain] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const resetForm = () => {
    setPropertyId(undefined)
    setName('')
    setDomain('')
    setErrors({})
  }

  useEffect(() => {
    if (!open) {
      resetForm()
    }
  }, [open])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
      if (!newOpen) {
        resetForm()
      }
    }
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors.name = t('Name is required')
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!validate()) {
      return
    }

    onCreate({
      propertyId,
      name: name.trim(),
      domain: domain.trim() || undefined,
      timezone: resolveBrowserTimezone(),
    })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create property')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Track a website or application. Daily boundaries use your current timezone.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="property-name">
                {t('Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="property-name"
                type="text"
                placeholder={t('Enter property name')}
                value={name}
                onChange={(e) => {
                  setName(e.target.value)
                  if (errors.name) {
                    setErrors((prev) => ({ ...prev, name: '' }))
                  }
                }}
                disabled={isLoading}
                className={errors.name ? 'border-destructive' : ''}
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="property-domain">{t('Domain')}</Label>
              <Input
                id="property-domain"
                type="text"
                placeholder={t('example.com')}
                value={domain}
                onChange={(e) => setDomain(e.target.value)}
                disabled={isLoading}
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Optional for native apps.')}
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="property-id">{t('Property ID')}</Label>
              <IdInput
                id="property-id"
                value={propertyId}
                onChange={setPropertyId}
                maxLength={36}
                disabled={isLoading}
                placeholder={t('Leave blank to auto-generate')}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || !name.trim()}>
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
