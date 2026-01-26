/**
 * Domain Input Component
 *
 * Provides domain input with format validation and availability check.
 * Used across all wizard paths for configuring the site domain.
 */

import { useState, useEffect, useRef } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { CheckCircle2, XCircle, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { sdk } from '@/lib/appwrite/sdk'
import { ConsoleResourceType } from '@appwrite.io/console'
import { useWizard } from './WizardContext'

interface DomainInputProps {
  value: string
  onChange: (value: string) => void
  onValidChange: (valid: boolean) => void
  baseDomain?: string
  disabled?: boolean
  className?: string
}

type ValidationStatus = 'idle' | 'checking' | 'available' | 'taken' | 'invalid'

/**
 * Generate a random 4-character suffix using lowercase letters and numbers
 */
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
  disabled = false,
  className,
}: DomainInputProps) {
  const { baseDomain } = useWizard()
  const [localValue, setLocalValue] = useState('')
  const [status, setStatus] = useState<ValidationStatus>('idle')
  const [error, setError] = useState<string | undefined>()
  const checkTimeoutRef = useRef<NodeJS.Timeout | null>(null)
  const hasTriedSuffixRef = useRef<string | null>(null) // Track the base value we've tried suffix for
  const isUserEditedRef = useRef(false) // Track if user has manually edited the input

  // Extract subdomain from full domain
  const subdomain = value ? value.replace(`.${baseDomain}`, '') : ''

  // Sync local value with prop
  useEffect(() => {
    setLocalValue(subdomain)
  }, [subdomain])

  // Validate and check availability
  useEffect(() => {
    // Clear any pending check
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

    // Validate minimum length
    if (localValue.length < 3) {
      setStatus('invalid')
      setError('Subdomain must be at least 3 characters')
      onValidChange(false)
      return
    }

    // Validate maximum length
    if (localValue.length > 63) {
      setStatus('invalid')
      setError('Subdomain must be less than 64 characters')
      onValidChange(false)
      return
    }

    // Validate format: must start and end with alphanumeric, can contain hyphens in the middle
    const validPattern = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/
    if (!validPattern.test(localValue)) {
      setStatus('invalid')
      if (localValue.startsWith('-') || localValue.endsWith('-')) {
        setError('Subdomain cannot start or end with a hyphen')
      } else {
        setError('Subdomain can only contain lowercase letters, numbers, and hyphens')
      }
      onValidChange(false)
      return
    }

    // Format is valid, now check availability
    const fullDomain = `${localValue}.${baseDomain}`
    setStatus('checking')
    setError(undefined)
    onValidChange(false)

    // Debounce the API check
    checkTimeoutRef.current = setTimeout(async () => {
      try {
        // getResource checks if a resource is available
        // Success (200/204) means the domain is available
        await sdk.forConsole.console.getResource({
          value: fullDomain,
          type: ConsoleResourceType.Rules,
        })
        // Domain is available
        setStatus('available')
        setError(undefined)
        onChange(fullDomain)
        onValidChange(true)
      } catch (err: unknown) {
        const error = err as { code?: number; response?: { code?: number } }
        const errorCode = error?.code || error?.response?.code
        
        // 409 Conflict means the domain is already taken
        if (errorCode === 409) {
          // Only auto-suggest suffix on initial auto-generated value, not user edits
          if (!isUserEditedRef.current && hasTriedSuffixRef.current !== localValue) {
            // Try with a random suffix
            hasTriedSuffixRef.current = localValue
            const newValue = `${localValue}-${generateRandomSuffix()}`
            setLocalValue(newValue)
            // The useEffect will re-trigger the check
          } else {
            // User edited or already tried suffix, show error
            setStatus('taken')
            setError('This domain is already in use')
            onValidChange(false)
          }
        } else {
          // Other errors - assume available and let server validate on creation
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
    // Only allow lowercase letters, numbers, and hyphens
    const newValue = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '')
    // Mark as user-edited and reset suffix tracking
    isUserEditedRef.current = true
    hasTriedSuffixRef.current = null
    setLocalValue(newValue)
  }

  return (
    <div className={cn('space-y-2', className)}>
      <Label htmlFor="domain" className="text-[13px] font-medium">
        Domain
      </Label>
      <div className="flex gap-2">
        <div className="relative flex-1">
          <Input
            id="domain"
            value={localValue}
            onChange={handleInputChange}
            placeholder="my-site"
            disabled={disabled}
            className={cn(
              'h-9 pr-10 text-[13px]',
              status === 'available' && 'border-green-500/50',
              (status === 'invalid' || status === 'taken') && 'border-destructive/50',
            )}
          />
          <div className="absolute right-3 top-1/2 -translate-y-1/2">
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
          <span className="text-[13px] text-muted-foreground">.{baseDomain}</span>
        </div>
      </div>
      <div className="h-[18px]">
        {status === 'available' && (
          <p className="text-[12px] text-green-600 dark:text-green-400">
            Domain is available
          </p>
        )}
        {error && (
          <p className="text-[12px] text-destructive">{error}</p>
        )}
      </div>
    </div>
  )
}
