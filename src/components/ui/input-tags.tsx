import { useState, type ClipboardEvent, type KeyboardEvent } from 'react'
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

  const splitTokens = (raw: string): string[] => {
    if (!raw.trim()) return []
    if (validateEmail) {
      return raw
        .split(/[,\s]+/)
        .map((part) => part.trim())
        .filter(Boolean)
    }
    return [raw.trim()]
  }

  const handleAddMany = (raw: string) => {
    const tokens = splitTokens(raw)
    if (tokens.length === 0) return

    const next = [...value]
    let added = false

    for (const tag of tokens) {
      if (validateEmail && !validateEmailFormat(tag)) {
        setError('Please enter a valid email address')
        return
      }
      if (next.includes(tag)) continue
      next.push(tag)
      added = true
    }

    if (!added && tokens.length > 0) {
      setError('This email is already added')
      return
    }

    if (added) {
      onChange(next)
      setInputValue('')
      setError(null)
    }
  }

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    const isCommitKey =
      e.key === 'Enter' ||
      (validateEmail && (e.key === ' ' || e.key === ','))

    if (isCommitKey && inputValue.trim()) {
      e.preventDefault()
      handleAddMany(inputValue)
    } else if (e.key === 'Backspace' && !inputValue && value.length > 0) {
      handleRemove(value[value.length - 1])
    }
  }

  const handlePaste = (e: ClipboardEvent<HTMLInputElement>) => {
    if (!validateEmail) return
    const text = e.clipboardData.getData('text')
    if (!/[,\s]/.test(text)) return
    e.preventDefault()
    const combined = inputValue ? `${inputValue}${text}` : text
    handleAddMany(combined)
  }

  const handleRemove = (tagToRemove: string) => {
    onChange(value.filter((tag) => tag !== tagToRemove))
    setError(null)
  }

  const handleBlur = () => {
    if (inputValue.trim()) {
      handleAddMany(inputValue)
    }
  }

  const handleInputChange = (next: string) => {
    setError(null)
    if (validateEmail && next.includes(',')) {
      const parts = next.split(',')
      const pending = parts.pop() ?? ''
      if (parts.some((part) => part.trim())) {
        handleAddMany(parts.join(','))
      }
      setInputValue(pending)
      return
    }
    setInputValue(next)
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
          onChange={(e) => handleInputChange(e.target.value)}
          onKeyDown={handleKeyDown}
          onPaste={handlePaste}
          onBlur={handleBlur}
          placeholder={value.length === 0 ? placeholder : ''}
          className="flex-1 min-w-[120px] h-6 border-0 p-0 focus-visible:ring-0 focus-visible:ring-offset-0 text-[13px]"
        />
      </div>
      {error && <p className="text-[12px] text-red-500">{error}</p>}
    </div>
  )
}
