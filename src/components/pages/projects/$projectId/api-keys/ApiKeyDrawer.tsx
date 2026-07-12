import { useState, useEffect } from 'react'
import { Copy, Check, Eye, EyeOff } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { ScopeEditor } from '@/components/global/shared/ScopeEditor'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

interface ApiKeyDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (data: { name: string; scopes?: string[]; expire?: string }) => void
  isLoading?: boolean
  apiKey?: Models.Key | null
  /** When set (e.g. after create), show the new secret with copy; drawer stays open until user dismisses */
  createdKeySecret?: string | null
  onCopy?: (text: string, field: string) => void
  copiedField?: string | null
  /** Pre-filled name when opening the create drawer */
  initialName?: string
  /** Pre-selected scopes when opening the create drawer */
  initialScopes?: string[]
}

function maskKey(key: string) {
  if (key.length <= 15) return '•'.repeat(12)
  return key.slice(0, 7) + '•'.repeat(24) + key.slice(-4)
}

export function ApiKeyDrawer({
  open,
  onOpenChange,
  onSubmit,
  isLoading = false,
  apiKey,
  createdKeySecret,
  onCopy,
  copiedField,
  initialName,
  initialScopes,
}: ApiKeyDrawerProps) {
  const t = useT()
  const [name, setName] = useState('')
  const [expire, setExpire] = useState('')
  const [scopes, setScopes] = useState<string[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [expiryOption, setExpiryOption] = useState<string>('never')
  const [keyRevealed, setKeyRevealed] = useState(false)

  const isEditing = !!apiKey
  const showCreatedKey = !!createdKeySecret
  const secretToShow =
    createdKeySecret ??
    (apiKey?.secret && apiKey.secret.trim() ? apiKey.secret : null)
  const canCopyKey = !!secretToShow
  const copyFieldId = createdKeySecret
    ? 'apiKeyDrawer-created'
    : `apiKeyDrawer-${apiKey?.$id ?? 'edit'}`
  const isCopied = copiedField === copyFieldId

  // Predefined expiry options
  const expiryOptions = [
    { value: 'never', label: 'Never' },
    { value: '1week', label: '1 week' },
    { value: '1month', label: '1 month' },
    { value: '6months', label: '6 months' },
    { value: '1year', label: '1 year' },
    { value: 'custom', label: 'Custom date' },
  ]

  // Calculate expiry date from option
  const getExpiryDateFromOption = (option: string): string => {
    if (option === 'never' || option === 'custom') return ''

    const now = new Date()
    switch (option) {
      case '1week':
        now.setDate(now.getDate() + 7)
        break
      case '1month':
        now.setMonth(now.getMonth() + 1)
        break
      case '6months':
        now.setMonth(now.getMonth() + 6)
        break
      case '1year':
        now.setFullYear(now.getFullYear() + 1)
        break
    }
    return now.toISOString()
  }

  // Determine which option matches the current expire value
  const getExpiryOptionFromDate = (dateString?: string): string => {
    if (!dateString) return 'never'

    const expireDate = new Date(dateString)
    if (isNaN(expireDate.getTime())) return 'never'

    const now = new Date()
    const diffMs = expireDate.getTime() - now.getTime()
    const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24))

    // Check if it matches a predefined option (with some tolerance)
    if (diffDays >= 6 && diffDays <= 8) return '1week'
    if (diffDays >= 28 && diffDays <= 31) return '1month'
    if (diffDays >= 178 && diffDays <= 186) return '6months'
    if (diffDays >= 365 && diffDays <= 366) return '1year'

    return 'custom'
  }

  const handleCopyKey = () => {
    if (!secretToShow) return
    navigator.clipboard.writeText(secretToShow)
    if (onCopy) {
      onCopy(secretToShow, copyFieldId)
    } else {
      toast.success(t('Copied to clipboard'))
    }
  }

  // Reset form when dialog closes or when apiKey changes
  useEffect(() => {
    if (!open) {
      setName('')
      setExpire('')
      setScopes([])
      setErrors({})
      setExpiryOption('never')
      setKeyRevealed(false)
    } else if (apiKey) {
      // Update mode: use existing scopes
      setName(apiKey.name || '')
      const existingExpire = apiKey.expire || ''
      setExpire(existingExpire)
      setScopes(apiKey.scopes || [])
      setErrors({})
      setExpiryOption(getExpiryOptionFromDate(existingExpire))
    } else {
      // Create mode: optional pre-filled name and scopes
      setName(initialName ?? '')
      setExpire('')
      setScopes(initialScopes ?? [])
      setErrors({})
      setExpiryOption('never')
    }
  }, [open, apiKey, initialName, initialScopes])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
    }
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors.name = t('Name is required')
    }

    if (expire) {
      const expireDate = new Date(expire)
      if (isNaN(expireDate.getTime())) {
        newErrors.expire = t('Invalid date format')
      } else if (expireDate < new Date()) {
        newErrors.expire = t('Expiration date must be in the future')
      }
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!validate()) {
      return
    }

    onSubmit({
      name: name.trim(),
      scopes: scopes.length > 0 ? scopes : undefined,
      expire: expire.trim() || undefined,
    })
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={
        showCreatedKey
          ? t('API key created')
          : isEditing
            ? t('Update API key')
            : t('Create API key')
      }
      maxWidth="sm:max-w-lg"
    >
      <>
        <div className="border-t border-border shrink-0" />

        <form onSubmit={handleSubmit} className="flex flex-1 flex-col min-h-0">
          <div className="flex-1 overflow-y-auto">
            <div className="px-6 py-6">
              {/* Newly created key */}
              {showCreatedKey && secretToShow && (
                <div className="mb-6 rounded-xl border border-primary/30 bg-primary/5 p-4">
                  <p className="text-[13px] font-medium text-foreground mb-1">
                    {t('Your new API key')}
                  </p>
                  <p className="text-[12px] text-muted-foreground mb-3">
                    {t(
                      'Copy and store it securely. You can view the full key anytime from the API keys list.',
                    )}
                  </p>
                  <div className="flex gap-2">
                    <textarea
                      readOnly
                      value={secretToShow}
                      rows={3}
                      className="flex-1 rounded-md border border-border bg-muted px-3 py-2 font-mono text-[12px] text-foreground focus:outline-none focus:ring-2 focus:ring-ring select-all"
                      onClick={(e) =>
                        (e.target as HTMLTextAreaElement).select()
                      }
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 shrink-0"
                      onClick={handleCopyKey}
                    >
                      {isCopied ? (
                        <Check className="h-4 w-4 text-emerald-500" />
                      ) : (
                        <Copy className="h-4 w-4" />
                      )}
                    </Button>
                  </div>
                </div>
              )}

              {!showCreatedKey && (
                <div className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="name">
                      {t('Name')} <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="name"
                      type="text"
                      placeholder={t('Enter API key name')}
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
                      <p className="text-[12px] text-destructive">
                        {errors.name}
                      </p>
                    )}
                  </div>

                  {isEditing && apiKey && (
                    <div className="space-y-1">
                      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                        {t('Last used')}
                      </p>
                      <p className="text-[13px] text-foreground">
                        {apiKey.accessedAt?.trim() ? (
                          <DateTooltip date={apiKey.accessedAt} />
                        ) : (
                          t('Never')
                        )}
                      </p>
                    </div>
                  )}

                  {/* View/copy key in edit mode when secret is available */}
                  {isEditing && (
                    <div className="space-y-2">
                      <Label className="text-[12px] font-medium">
                        {t('API key')}
                      </Label>
                      {canCopyKey ? (
                        <div className="flex gap-2">
                          <Input
                            readOnly
                            type={keyRevealed ? 'text' : 'password'}
                            value={
                              keyRevealed
                                ? secretToShow
                                : maskKey(secretToShow!)
                            }
                            className="font-mono text-[12px]"
                          />
                          <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="h-9 w-9 shrink-0"
                            onClick={() => setKeyRevealed((v) => !v)}
                            title={keyRevealed ? t('Hide key') : t('Show key')}
                            aria-label={
                              keyRevealed ? t('Hide key') : t('Show key')
                            }
                          >
                            {keyRevealed ? (
                              <EyeOff className="h-4 w-4" />
                            ) : (
                              <Eye className="h-4 w-4" />
                            )}
                          </Button>
                          <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="h-9 w-9 shrink-0"
                            onClick={handleCopyKey}
                            title={t('Copy key')}
                            aria-label={t('Copy key')}
                          >
                            {isCopied ? (
                              <Check className="h-4 w-4 text-emerald-500" />
                            ) : (
                              <Copy className="h-4 w-4" />
                            )}
                          </Button>
                        </div>
                      ) : (
                        <p className="text-[12px] text-muted-foreground">
                          {t(
                            "The full key value isn't shown here. Find this key in the API keys list to view and copy it.",
                          )}
                        </p>
                      )}
                    </div>
                  )}

                  <div className="space-y-2">
                    <Label>{t('Expiration date')}</Label>
                    <RadioGroup
                      value={expiryOption}
                      defaultValue="never"
                      onValueChange={(value) => {
                        setExpiryOption(value)
                        if (value === 'never') {
                          setExpire('')
                        } else if (value === 'custom') {
                          // Keep existing expire value if it exists, otherwise leave empty
                          if (!expire) {
                            setExpire('')
                          }
                        } else {
                          setExpire(getExpiryDateFromOption(value))
                        }
                        if (errors.expire) {
                          setErrors((prev) => ({ ...prev, expire: '' }))
                        }
                      }}
                      disabled={isLoading}
                      className="grid grid-cols-2 gap-3"
                    >
                      {expiryOptions.map((option) => {
                        const isSelected = expiryOption === option.value
                        return (
                          <div key={option.value}>
                            <RadioGroupItem
                              value={option.value}
                              id={`expire-${option.value}`}
                              className="peer sr-only"
                            />
                            <Label
                              htmlFor={`expire-${option.value}`}
                              className={cn(
                                'flex cursor-pointer items-center justify-center rounded-lg border border-border bg-card px-4 py-3 text-sm font-medium transition-all',
                                'hover:border-primary/50 hover:bg-accent/50',
                                isSelected && 'border-primary bg-accent',
                                isLoading && 'cursor-not-allowed opacity-50',
                              )}
                            >
                              {t(option.label)}
                            </Label>
                          </div>
                        )
                      })}
                    </RadioGroup>
                    {expiryOption === 'custom' && (
                      <div className="pt-2">
                        <DateTimePicker
                          id="expire"
                          value={expire || null}
                          onChange={(value) => {
                            setExpire(value ?? '')
                            if (errors.expire) {
                              setErrors((prev) => ({ ...prev, expire: '' }))
                            }
                          }}
                          disabled={isLoading}
                          clearable
                          className={errors.expire ? 'border-destructive' : ''}
                        />
                        {errors.expire && (
                          <p className="text-[12px] text-destructive mt-1">
                            {errors.expire}
                          </p>
                        )}
                      </div>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label>{t('Scopes')}</Label>
                    <ScopeEditor
                      value={scopes}
                      onChange={setScopes}
                      disabled={isLoading}
                    />
                    <p className="text-[12px] text-muted-foreground">
                      {t('Select the scopes this API key will have access to.')}{' '}
                      <DocsRouteLink className="link-neutral" href="/docs/advanced/platform/api-keys">
                        {t('Learn more about API key scopes')}
                      </DocsRouteLink>
                      .
                    </p>
                  </div>
                </div>
              )}
            </div>
          </div>

          <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            {showCreatedKey ? (
              <Button type="button" onClick={() => handleOpenChange(false)}>
                {t('Done')}
              </Button>
            ) : (
              <>
                <Button type="submit" disabled={isLoading}>
                  {isEditing ? t('Update API key') : t('Create API key')}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => handleOpenChange(false)}
                  disabled={isLoading}
                >
                  {t('Cancel')}
                </Button>
              </>
            )}
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
