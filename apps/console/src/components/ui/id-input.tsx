import * as React from 'react'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { Info } from 'lucide-react'

export interface IdInputProps {
  /**
   * The current ID value. If undefined or empty, will use auto-generated ID.
   */
  value?: string
  /**
   * Callback when ID changes. Receives the new ID value or undefined for auto-generated.
   */
  onChange?: (id: string | undefined) => void
  /**
   * Maximum length for the ID
   * @default 36
   */
  maxLength?: number
  /**
   * Whether the component is disabled
   * @default false
   */
  disabled?: boolean
  /**
   * Placeholder text
   * @default "Leave blank to auto-generate"
   */
  placeholder?: string
  /**
   * Additional className for the input
   */
  className?: string
  /**
   * Input ID for label association
   */
  id?: string
  /**
   * ID validation rules. Dedicated native databases only allow letters and numbers.
   * @default 'default'
   */
  idFormat?: 'default' | 'dedicated'
}

/**
 * Validates Appwrite ID format: alphanumeric, non-leading hyphen, underscore, period
 */
function validateDefaultId(id: string): boolean {
  if (!id || id.length === 0) return true // Empty is valid (will use auto-generated)
  if (id.length > 36) return false
  // Must start with alphanumeric or underscore, not hyphen or period
  if (!/^[a-zA-Z0-9_]/.test(id)) return false
  // Can contain alphanumeric, hyphen (not leading), underscore, period
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(id)
}

function validateDedicatedId(id: string): boolean {
  if (!id || id.length === 0) return true
  if (id.length > 36) return false
  return /^[A-Za-z0-9]+$/.test(id)
}

export function IdInput({
  value,
  onChange,
  maxLength = 36,
  disabled = false,
  placeholder = 'Leave blank to auto-generate',
  className,
  id,
  idFormat = 'default',
}: IdInputProps) {
  const validateId =
    idFormat === 'dedicated' ? validateDedicatedId : validateDefaultId
  const idHelpText =
    idFormat === 'dedicated'
      ? 'Allowed characters: letters and numbers only'
      : 'Allowed characters: alphanumeric, non-leading hyphen, underscore, period'
  const [isOpen, setIsOpen] = React.useState(false)
  const [inputValue, setInputValue] = React.useState(value || '')
  const [error, setError] = React.useState<string | null>(null)

  // Sync input value with prop value
  React.useEffect(() => {
    setInputValue(value || '')
  }, [value])

  // Initialize input value when opened
  React.useEffect(() => {
    if (isOpen) {
      setInputValue(value || '')
      setError(null)
    }
  }, [isOpen, value])

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value
    setInputValue(newValue)

    // Validate as user types
    if (newValue.length > maxLength) {
      setError(`ID must be ${maxLength} characters or less`)
    } else if (newValue && !validateId(newValue)) {
      setError('Invalid ID format')
    } else {
      setError(null)
    }

    // Call onChange immediately with trimmed value or undefined
    const trimmedValue = newValue.trim()
    onChange?.(trimmedValue || undefined)
  }

  const handleToggle = () => {
    if (!disabled) {
      setIsOpen(!isOpen)
      if (!isOpen) {
        // Reset when opening
        setInputValue(value || '')
        setError(null)
      }
    }
  }

  // Display value: show custom ID if set, otherwise show "Auto-generated"
  const displayValue = value || 'Auto-generated'

  return (
    <div className="space-y-2">
      <Badge
        variant="outline"
        className={cn(
          'cursor-pointer transition-colors hover:bg-accent',
          disabled && 'cursor-not-allowed opacity-50',
          className,
        )}
        onClick={handleToggle}
      >
        {displayValue}
      </Badge>

      {isOpen && (
        <div className="rounded-lg border border-border bg-card p-4 space-y-4">
          <div className="space-y-2">
            <div className="relative">
              <Input
                id={id}
                type="text"
                placeholder={placeholder}
                value={inputValue}
                onChange={handleInputChange}
                maxLength={maxLength}
                disabled={disabled}
                className={cn(
                  'pe-16',
                  error && 'border-destructive focus-visible:ring-destructive',
                )}
                autoFocus
              />
              <div className="absolute end-3 top-1/2 -translate-y-1/2 pointer-events-none">
                <span
                  className={cn(
                    'text-[11px] text-muted-foreground',
                    inputValue.length > maxLength && 'text-destructive',
                  )}
                >
                  {inputValue.length}/{maxLength}
                </span>
              </div>
            </div>
            {error && <p className="text-[12px] text-destructive">{error}</p>}
          </div>

          <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-3">
            <Info className="h-4 w-4 shrink-0 text-muted-foreground mt-0.5" />
            <p className="text-[12px] text-muted-foreground">
              {idHelpText}
            </p>
          </div>
        </div>
      )}
    </div>
  )
}
