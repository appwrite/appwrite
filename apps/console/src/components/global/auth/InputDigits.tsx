import * as React from 'react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

export interface InputDigitsProps {
  /**
   * The current value (6-digit code)
   */
  value: string
  /**
   * Callback when value changes
   */
  onChange: (value: string) => void
  /**
   * Number of digits (default: 6)
   */
  length?: number
  /**
   * Whether the component is disabled
   */
  disabled?: boolean
  /**
   * Additional className
   */
  className?: string
  /**
   * Input ID for label association
   */
  id?: string
  /**
   * Auto-focus the input
   */
  autoFocus?: boolean
  /**
   * Placeholder text
   */
  placeholder?: string
}

/**
 * Input component for entering 6-digit verification codes
 * Automatically formats and validates numeric input
 */
export function InputDigits({
  value,
  onChange,
  length = 6,
  disabled = false,
  className,
  id,
  autoFocus = false,
  placeholder,
}: InputDigitsProps) {
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Only allow numeric input, limit to specified length
    const numericValue = e.target.value.replace(/\D/g, '').slice(0, length)
    onChange(numericValue)
  }

  return (
    <Input
      id={id}
      type="text"
      inputMode="numeric"
      pattern="[0-9]*"
      value={value}
      onChange={handleChange}
      disabled={disabled}
      autoFocus={autoFocus}
      placeholder={placeholder || '000000'}
      maxLength={length}
      className={cn(
        'text-center text-2xl tracking-widest font-mono',
        className,
      )}
    />
  )
}
