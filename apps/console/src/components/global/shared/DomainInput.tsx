/**
 * Domain Input Component
 *
 * Domain input with format validation and availability check.
 * Reused by sites and functions create wizards.
 */

import { useState, useEffect, useRef } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { CheckCircle2, XCircle, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { sdk } from '@/lib/appwrite/sdk'
import { ConsoleResourceType } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

export interface DomainInputProps {
  value: string
  onChange: (value: string) => void
  onValidChange: (valid: boolean) => void
  /** Base domain (e.g. appwrite.network); subdomain is validated and appended. */
  baseDomain: string
  /** Placeholder for subdomain (e.g. "my-site" for sites, "my-function" for functions) */
  placeholder?: string
  disabled?: boolean
  className?: string
}

type ValidationStatus = 'idle' | 'checking' | 'available' | 'taken' | 'invalid'

function generateRandomSuffix(): string {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789'
  let suffix = ''
  for (let i = 0; i < 4; i++) {
    suffix += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  return suffix
}

export function DomainInput({
  value,
  onChange,
  onValidChange,
  baseDomain,
  placeholder = 'my-site',
  disabled = false,
  className,
}: DomainInputProps) {
  const t = useT()
  const [localValue, setLocalValue] = useState('')
  const [status, setStatus] = useState<ValidationStatus>('idle')
  const [error, setError] = useState<string | undefined>()
  const checkTimeoutRef = useRef<NodeJS.Timeout | null>(null)
  const hasTriedSuffixRef = useRef<string | null>(null)
  const isUserEditedRef = useRef(false)

  const subdomain = value ? value.replace(`.${baseDomain}`, '') : ''

  useEffect(() => {
    setLocalValue(subdomain)
  }, [subdomain])

  useEffect(() => {
    if (checkTimeoutRef.current) {
      clearTimeout(checkTimeoutRef.current)
      checkTimeoutRef.current = null
    }

    if (!localValue.trim()) {
      setStatus('idle')
      setError(undefined)
      onValidChange(false)
      return
    }

    if (localValue.length < 3) {
      setStatus('invalid')
      setError('Subdomain must be at least 3 characters')
      onValidChange(false)
      return
    }

    if (localValue.length > 63) {
      setStatus('invalid')
      setError('Subdomain must be less than 64 characters')
      onValidChange(false)
      return
    }

    const validPattern = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/
    if (!validPattern.test(localValue)) {
      setStatus('invalid')
      if (localValue.startsWith('-') || localValue.endsWith('-')) {
        setError('Subdomain cannot start or end with a hyphen')
      } else {
        setError(
          'Subdomain can only contain lowercase letters, numbers, and hyphens',
        )
      }
      onValidChange(false)
      return
    }

    const fullDomain = `${localValue}.${baseDomain}`
    setStatus('checking')
    setError(undefined)
    onValidChange(false)

    checkTimeoutRef.current = setTimeout(async () => {
      try {
        await sdk.forConsole.console.getResource({
          value: fullDomain,
          type: ConsoleResourceType.Rules,
        })
        setStatus('available')
        setError(undefined)
        onChange(fullDomain)
        onValidChange(true)
      } catch (err: unknown) {
        const error = err as { code?: number; response?: { code?: number } }
        const errorCode = error?.code || error?.response?.code

        if (errorCode === 409) {
          if (
            !isUserEditedRef.current &&
            hasTriedSuffixRef.current !== localValue
          ) {
            hasTriedSuffixRef.current = localValue
            const newValue = `${localValue}-${generateRandomSuffix()}`
            setLocalValue(newValue)
          } else {
            setStatus('taken')
            setError('This domain is already in use')
            onValidChange(false)
          }
        } else {
          setStatus('available')
          setError(undefined)
          onChange(fullDomain)
          onValidChange(true)
        }
      }
    }, 500)

    return () => {
      if (checkTimeoutRef.current) {
        clearTimeout(checkTimeoutRef.current)
      }
    }
  }, [localValue, baseDomain, onChange, onValidChange])

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '')
    isUserEditedRef.current = true
    hasTriedSuffixRef.current = null
    setLocalValue(newValue)
  }

  return (
    <div className={cn('space-y-2', className)}>
      <Label htmlFor="domain" className="text-[13px] font-medium">
        {t('Domain')}
      </Label>
      <div className="flex gap-2">
        <div className="relative flex-1">
          <Input
            id="domain"
            value={localValue}
            onChange={handleInputChange}
            placeholder={placeholder}
            disabled={disabled}
            className={cn(
              'h-9 pe-10 text-[13px]',
              status === 'available' && 'border-green-500/50',
              (status === 'invalid' || status === 'taken') &&
                'border-destructive/50',
            )}
          />
          <div className="absolute end-3 top-1/2 -translate-y-1/2">
            {status === 'checking' && (
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            )}
            {status === 'available' && (
              <CheckCircle2 className="h-4 w-4 text-green-500" />
            )}
            {(status === 'invalid' || status === 'taken') && (
              <XCircle className="h-4 w-4 text-destructive" />
            )}
          </div>
        </div>
        <div className="flex items-center rounded-md border border-border bg-muted px-3">
          <span className="text-[13px] text-muted-foreground">
            .{baseDomain}
          </span>
        </div>
      </div>
      <div className="h-[18px]">
        {status === 'available' && (
          <p className="text-[12px] text-green-600 dark:text-green-400">
            {t('Domain is available')}
          </p>
        )}
        {error && <p className="text-[12px] text-destructive">{t(error)}</p>}
      </div>
    </div>
  )
}
