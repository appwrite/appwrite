import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { ScopeEditor, getAllAvailableScopes } from '@/components/global/shared/ScopeEditor'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

interface ApiKeyDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (data: {
    name: string
    scopes?: string[]
    expire?: string
  }) => void
  isLoading?: boolean
  apiKey?: Models.Key | null
}

export function ApiKeyDrawer({
  open,
  onOpenChange,
  onSubmit,
  isLoading = false,
  apiKey,
}: ApiKeyDrawerProps) {
  const [name, setName] = useState('')
  const [expire, setExpire] = useState('')
  const [scopes, setScopes] = useState<string[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [expiryOption, setExpiryOption] = useState<string>('never')

  const isEditing = !!apiKey

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

  // Reset form when dialog closes or when apiKey changes
  useEffect(() => {
    if (!open) {
      setName('')
      setExpire('')
      setScopes([])
      setErrors({})
      setExpiryOption('never')
    } else if (apiKey) {
      // Update mode: use existing scopes
      setName(apiKey.name || '')
      const existingExpire = apiKey.expire || ''
      setExpire(existingExpire)
      setScopes(apiKey.scopes || [])
      setErrors({})
      setExpiryOption(getExpiryOptionFromDate(existingExpire))
    } else {
      // Create mode: select all scopes by default
      setName('')
      setExpire('')
      setScopes(getAllAvailableScopes())
      setErrors({})
      setExpiryOption('never')
    }
  }, [open, apiKey])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
    }
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors.name = 'Name is required'
    }

    if (expire) {
      const expireDate = new Date(expire)
      if (isNaN(expireDate.getTime())) {
        newErrors.expire = 'Invalid date format'
      } else if (expireDate < new Date()) {
        newErrors.expire = 'Expiration date must be in the future'
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

  // Format date for input (YYYY-MM-DDTHH:mm)
  const formatDateForInput = (dateString?: string) => {
    if (!dateString) return ''
    try {
      const date = new Date(dateString)
      if (isNaN(date.getTime())) return ''
      // Format as YYYY-MM-DDTHH:mm
      const year = date.getFullYear()
      const month = String(date.getMonth() + 1).padStart(2, '0')
      const day = String(date.getDate()).padStart(2, '0')
      const hours = String(date.getHours()).padStart(2, '0')
      const minutes = String(date.getMinutes()).padStart(2, '0')
      return `${year}-${month}-${day}T${hours}:${minutes}`
    } catch {
      return ''
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEditing ? 'Update API key' : 'Create API key'}
      maxWidth="sm:max-w-lg"
    >
      <>
        <div className="border-t border-border shrink-0" />

        <form onSubmit={handleSubmit} className="flex flex-1 flex-col min-h-0">
          <div className="flex-1 overflow-y-auto">
            <div className="px-6 py-6">
              <div className="space-y-5">
                <div className="space-y-2">
                  <Label htmlFor="name">
                    Name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="name"
                    type="text"
                    placeholder="Enter API key name"
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
                  <Label>Expiration date</Label>
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
                              isLoading && 'cursor-not-allowed opacity-50'
                            )}
                          >
                            {option.label}
                          </Label>
                        </div>
                      )
                    })}
                  </RadioGroup>
                  {expiryOption === 'custom' && (
                    <div className="pt-2">
                      <Input
                        id="expire"
                        type="datetime-local"
                        value={formatDateForInput(expire)}
                        onChange={(e) => {
                          const value = e.target.value
                          if (value) {
                            // Convert from datetime-local format to ISO string
                            const date = new Date(value)
                            setExpire(date.toISOString())
                          } else {
                            setExpire('')
                          }
                          if (errors.expire) {
                            setErrors((prev) => ({ ...prev, expire: '' }))
                          }
                        }}
                        disabled={isLoading}
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
                  <Label>Scopes</Label>
                  <ScopeEditor
                    value={scopes}
                    onChange={setScopes}
                    disabled={isLoading}
                  />
                  <p className="text-[12px] text-muted-foreground">
                    Select the scopes this API key will have access to. <a href="https://appwrite.io/docs/advanced/platform/api-keys" target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">Learn more about API key scopes</a>.
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            <Button type="submit" disabled={isLoading}>
              {isEditing ? 'Update API key' : 'Create API key'}
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              Cancel
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}

