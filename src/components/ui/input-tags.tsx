import { useState, KeyboardEvent } from 'react'
import { X } from 'lucide-react'
import { Input } from './input'
import { Badge } from './badge'
import { cn } from '@/lib/utils'

interface InputTagsProps {
  value: string[]
  onChange: (value: string[]) => void
  placeholder?: string
  className?: string
  validateEmail?: boolean
}

export function InputTags({
  value,
  onChange,
  placeholder = 'Enter values and press Enter',
  className,
  validateEmail = false,
}: InputTagsProps) {
  const [inputValue, setInputValue] = useState('')
  const [error, setError] = useState<string | null>(null)

  const validateEmailFormat = (email: string): boolean => {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    return emailRegex.test(email)
  }

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && inputValue.trim()) {
      e.preventDefault()
      handleAdd(inputValue.trim())
    } else if (e.key === 'Backspace' && !inputValue && value.length > 0) {
      // Remove last tag if input is empty and backspace is pressed
      handleRemove(value[value.length - 1])
    }
  }

  const handleAdd = (tag: string) => {
    if (!tag) return

    // Validate email if required
    if (validateEmail && !validateEmailFormat(tag)) {
      setError('Please enter a valid email address')
      return
    }

    // Check for duplicates
    if (value.includes(tag)) {
      setError('This email is already added')
      return
    }

    onChange([...value, tag])
    setInputValue('')
    setError(null)
  }

  const handleRemove = (tagToRemove: string) => {
    onChange(value.filter((tag) => tag !== tagToRemove))
    setError(null)
  }

  const handleBlur = () => {
    if (inputValue.trim()) {
      handleAdd(inputValue.trim())
    }
  }

  return (
    <div className={cn('space-y-2', className)}>
      <div className="flex flex-wrap gap-2 min-h-[40px] p-2 rounded-md border border-border bg-background">
        {value.map((tag, index) => (
          <Badge
            key={index}
            variant="secondary"
            className="text-[12px] px-2 py-0.5 h-6 flex items-center gap-1.5"
          >
            {tag}
            <button
              type="button"
              onClick={() => handleRemove(tag)}
              className="ml-1 rounded-full hover:bg-muted-foreground/20 p-0.5"
            >
              <X className="h-3 w-3" />
            </button>
          </Badge>
        ))}
        <Input
          type="text"
          value={inputValue}
          onChange={(e) => {
            setInputValue(e.target.value)
            setError(null)
          }}
          onKeyDown={handleKeyDown}
          onBlur={handleBlur}
          placeholder={value.length === 0 ? placeholder : ''}
          className="flex-1 min-w-[120px] h-6 border-0 p-0 focus-visible:ring-0 focus-visible:ring-offset-0 text-[13px]"
        />
      </div>
      {error && <p className="text-[12px] text-red-500">{error}</p>}
    </div>
  )
}
