import { useEffect, useState, type ClipboardEvent, type KeyboardEvent } from 'react'
import { X } from 'lucide-react'
import { Input } from './input'
import { Badge } from './badge'
import { cn } from '@/lib/utils'

interface InputTagsProps {
  id?: string
  value: string[]
  onChange: (value: string[]) => void
  placeholder?: string
  className?: string
  validateEmail?: boolean
  /** When true, comma also commits tags and pasted comma-separated values are split. */
  splitOnComma?: boolean
  disabled?: boolean
  /** Tags shown inside the field that cannot be removed. */
  lockedTags?: string[]
  /**
   * Fills the text field when `id` changes (e.g. example chip clicked).
   * Does not add a tag until the user presses Enter or comma.
   */
  prefillRequest?: { id: number; value: string } | null
  onPrefillConsumed?: () => void
}

export function InputTags({
  id,
  value,
  onChange,
  placeholder = 'Enter values and press Enter',
  className,
  validateEmail = false,
  splitOnComma = false,
  disabled = false,
  lockedTags = [],
  prefillRequest,
  onPrefillConsumed,
}: InputTagsProps) {
  const [inputValue, setInputValue] = useState('')
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    if (!prefillRequest?.value) return
    const text = prefillRequest.value
    setInputValue(text)
    setError(null)
    const focusWithCursorAtEnd = () => {
      const el = id ? document.getElementById(id) : null
      if (!el || !(el instanceof HTMLInputElement)) return
      el.focus()
      const end = el.value.length
      el.setSelectionRange(end, end)
    }
    requestAnimationFrame(() => requestAnimationFrame(focusWithCursorAtEnd))
    onPrefillConsumed?.()
  }, [prefillRequest?.id, prefillRequest?.value, onPrefillConsumed, id])

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
    if (splitOnComma) {
      return raw
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean)
    }
    return [raw.trim()]
  }

  const handleAddMany = (raw: string) => {
    if (disabled) return
    const tokens = splitTokens(raw)
    if (tokens.length === 0) return

    const next = [...value]
    let added = false

    for (const tag of tokens) {
      if (validateEmail && !validateEmailFormat(tag)) {
        setError('Please enter a valid email address')
        return
      }
      if (lockedTags.includes(tag) || next.includes(tag)) continue
      next.push(tag)
      added = true
    }

    if (!added && tokens.length > 0) {
      setError(
        validateEmail ? 'This email is already added' : 'This value is already added',
      )
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
      (validateEmail && (e.key === ' ' || e.key === ',')) ||
      (splitOnComma && e.key === ',')

    if (isCommitKey && inputValue.trim()) {
      e.preventDefault()
      handleAddMany(inputValue)
    } else if (e.key === 'Backspace' && !inputValue && value.length > 0) {
      handleRemove(value[value.length - 1])
    }
  }

  const handlePaste = (e: ClipboardEvent<HTMLInputElement>) => {
    if (!validateEmail && !splitOnComma) return
    const text = e.clipboardData.getData('text')
    const hasSeparators = validateEmail ? /[,\s]/.test(text) : text.includes(',')
    if (!hasSeparators) return
    e.preventDefault()
    const combined = inputValue ? `${inputValue}${text}` : text
    handleAddMany(combined)
  }

  const handleRemove = (tagToRemove: string) => {
    if (disabled) return
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
    if ((validateEmail || splitOnComma) && next.includes(',')) {
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
    <div className={cn('space-y-1', className)}>
      <div
        className={cn(
          'flex min-h-9 w-full flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-3 py-1.5 text-[13px] shadow-xs transition-[color,box-shadow]',
          'focus-within:border-ring focus-within:ring-ring/50 focus-within:ring-[3px]',
          'dark:bg-input/30',
          disabled && 'cursor-not-allowed opacity-50',
        )}
      >
        {lockedTags.map((tag) => (
          <Badge
            key={`locked-${tag}`}
            variant="secondary"
            className="h-6 shrink-0 px-2 py-0 text-[12px] font-normal"
          >
            <span className="font-mono">{tag}</span>
          </Badge>
        ))}
        {value.map((tag) => (
          <Badge
            key={tag}
            variant="secondary"
            className="h-6 shrink-0 gap-1 px-2 py-0 text-[12px] font-normal"
          >
            <span className="font-mono">{tag}</span>
            <button
              type="button"
              onClick={() => handleRemove(tag)}
              disabled={disabled}
              className="rounded-full text-muted-foreground hover:bg-muted-foreground/20 hover:text-foreground disabled:pointer-events-none"
              aria-label={`Remove ${tag}`}
            >
              <X className="h-3 w-3" />
            </button>
          </Badge>
        ))}
        <Input
          id={id}
          type="text"
          value={inputValue}
          onChange={(e) => handleInputChange(e.target.value)}
          onKeyDown={handleKeyDown}
          onPaste={handlePaste}
          onBlur={handleBlur}
          disabled={disabled}
          placeholder={value.length === 0 ? placeholder : ''}
          className="h-6 min-w-[120px] flex-1 border-0 bg-transparent p-0 text-[13px] shadow-none focus-visible:ring-0 focus-visible:ring-offset-0"
        />
      </div>
      {error ? <p className="text-[12px] text-red-500">{error}</p> : null}
    </div>
  )
}
