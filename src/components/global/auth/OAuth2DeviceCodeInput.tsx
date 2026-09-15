'use client'

import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

// Projects choose their own user code length; the server clamps it to 6..12 and
// Cloud's console project issues 8. The page cannot read that setting -- it runs
// before sign-in -- so accept up to the maximum and let the server judge.
export const OAUTH2_DEVICE_CODE_MAX_LENGTH = 12

/** Keep only the characters device user codes are built from. */
export function normalizeUserCode(value: string): string {
  return value
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
    .slice(0, OAUTH2_DEVICE_CODE_MAX_LENGTH)
}

type OAuth2DeviceCodeInputProps = {
  id?: string
  value: string
  onChange?: (value: string) => void
  disabled?: boolean
  readOnly?: boolean
  autoFocus?: boolean
  className?: string
  'aria-invalid'?: boolean
}

/**
 * Device user code input. A fixed slot count cannot hold a code whose length
 * varies by project, so this is a plain field that normalises what it is given.
 */
export function OAuth2DeviceCodeInput({
  id,
  value,
  onChange,
  disabled = false,
  readOnly = false,
  autoFocus = false,
  className,
  'aria-invalid': ariaInvalid,
}: OAuth2DeviceCodeInputProps) {
  return (
    <Input
      id={id}
      type="text"
      value={normalizeUserCode(value)}
      onChange={(event) => {
        if (readOnly) return
        onChange?.(normalizeUserCode(event.currentTarget.value))
      }}
      disabled={disabled}
      readOnly={readOnly}
      autoFocus={autoFocus}
      placeholder="XXXXXXXX"
      inputMode="text"
      // Not one-time-code: the code is shown on another device, so SMS autofill
      // would only ever offer the wrong value.
      autoComplete="off"
      autoCapitalize="characters"
      spellCheck={false}
      aria-invalid={ariaInvalid}
      className={cn(
        'h-16 text-center font-mono text-2xl tracking-[0.3em] uppercase',
        className,
      )}
    />
  )
}
