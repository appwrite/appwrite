/**
 * Domain Input Component
 *
 * Provides domain input with real-time availability validation.
 * Used across all wizard paths for configuring the site domain.
 */

import { useState, useEffect, useCallback } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { CheckCircle2, XCircle, Loader2, RotateCcw } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'

interface DomainInputProps {
  value: string
  onChange: (value: string) => void
  onValidChange: (valid: boolean) => void
  baseDomain?: string
  disabled?: boolean
  className?: string
}

type ValidationStatus = 'idle' | 'checking' | 'available' | 'unavailable' | 'error'

export function DomainInput({
  value,
  onChange,
  onValidChange,
  disabled = false,
  className,
}: DomainInputProps) {
  const { baseDomain, generateDomain } = useWizard()
  const [localValue, setLocalValue] = useState('')
  const [status, setStatus] = useState<ValidationStatus>('idle')
  const [error, setError] = useState<string | undefined>()

  // Extract subdomain from full domain
  const subdomain = value ? value.replace(`.${baseDomain}`, '') : ''

  // Sync local value with prop
  useEffect(() => {
    setLocalValue(subdomain)
  }, [subdomain])

  // Debounced validation
  useEffect(() => {
    if (!localValue.trim()) {
      setStatus('idle')
      onValidChange(false)
      return
    }

    // Validate format first
    const validPattern = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/
    if (!validPattern.test(localValue)) {
      setStatus('error')
      setError('Invalid subdomain format')
      onValidChange(false)
      return
    }

    const fullDomain = `${localValue}.${baseDomain}`

    // Set checking status
    setStatus('checking')
    setError(undefined)

    const timer = setTimeout(async () => {
      try {
        // Check domain availability via console API
        await sdk.forConsole.console.getResource({
          value: fullDomain,
          type: 'Rules',
        })
        // If resource exists, domain is unavailable
        setStatus('unavailable')
        setError('Domain is not available')
        onValidChange(false)
      } catch (err: any) {
        // 404 means domain is available
        if (err?.code === 404 || err?.response?.code === 404) {
          setStatus('available')
          setError(undefined)
          onChange(fullDomain)
          onValidChange(true)
        } else {
          setStatus('error')
          setError('Failed to check domain availability')
          onValidChange(false)
        }
      }
    }, 500)

    return () => clearTimeout(timer)
  }, [localValue, baseDomain, onChange, onValidChange])

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '')
    setLocalValue(newValue)
  }

  const handleReset = useCallback(() => {
    setLocalValue('')
    onChange('')
    setStatus('idle')
    setError(undefined)
    onValidChange(false)
  }, [onChange, onValidChange])

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
              status === 'available' && 'border-green-500 focus-visible:ring-green-500',
              status === 'unavailable' && 'border-destructive focus-visible:ring-destructive',
              status === 'error' && 'border-destructive focus-visible:ring-destructive',
            )}
          />
          <div className="absolute right-3 top-1/2 -translate-y-1/2">
            {status === 'checking' && (
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            )}
            {status === 'available' && (
              <CheckCircle2 className="h-4 w-4 text-green-500" />
            )}
            {status === 'unavailable' && (
              <XCircle className="h-4 w-4 text-destructive" />
            )}
            {status === 'error' && (
              <XCircle className="h-4 w-4 text-destructive" />
            )}
          </div>
        </div>
        <div className="flex items-center rounded-md border border-border bg-muted px-3">
          <span className="text-[13px] text-muted-foreground">.{baseDomain}</span>
        </div>
        {localValue && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={handleReset}
            className="h-9 w-9 p-0"
          >
            <RotateCcw className="h-4 w-4" />
          </Button>
        )}
      </div>
      {status === 'available' && (
        <p className="text-[12px] text-green-600 dark:text-green-400">
          Domain is available
        </p>
      )}
      {error && (
        <p className="text-[12px] text-destructive">{error}</p>
      )}
    </div>
  )
}
