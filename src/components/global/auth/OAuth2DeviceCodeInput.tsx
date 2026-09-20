'use client'

import type { FormEvent } from 'react'
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot,
} from '@/components/ui/input-otp'
import { cn } from '@/lib/utils'

/** Characters allowed in OAuth2 device user codes (no vowels or ambiguous glyphs). */
export const OAUTH2_DEVICE_USER_CODE_CHARSET =
  'BCDFGHJKLMNPQRSTVWXZ23456789' as const

export const OAUTH2_DEVICE_CODE_LENGTH = 6

const DEVICE_CODE_CHAR_SET = new Set(
  OAUTH2_DEVICE_USER_CODE_CHARSET.split(''),
)

/** Keep only allowed device user code characters, uppercase, max length. */
export function normalizeUserCode(value: string): string {
  return value
    .toUpperCase()
    .split('')
    .filter((char) => DEVICE_CODE_CHAR_SET.has(char))
    .join('')
    .slice(0, OAUTH2_DEVICE_CODE_LENGTH)
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
  const normalized = normalizeUserCode(value)

  const handleBeforeInput = (event: FormEvent<HTMLInputElement>) => {
    if (readOnly) return
    const nativeEvent = event.nativeEvent
    if (!('data' in nativeEvent) || nativeEvent.data == null) return
    const chunk = String(nativeEvent.data).toUpperCase()
    if (chunk.length === 0) return
    if (chunk.split('').every((char) => DEVICE_CODE_CHAR_SET.has(char))) return
    event.preventDefault()
  }

  return (
    <div className={cn('w-full', className)}>
      <InputOTP
        id={id}
        maxLength={OAUTH2_DEVICE_CODE_LENGTH}
        value={normalized}
        pasteTransformer={normalizeUserCode}
        onBeforeInput={handleBeforeInput}
        onChange={(next) => {
          if (readOnly) return
          onChange?.(normalizeUserCode(next))
        }}
        disabled={disabled}
        readOnly={readOnly}
        autoFocus={autoFocus}
        inputMode="text"
        autoComplete="off"
        autoCapitalize="characters"
        spellCheck={false}
        aria-invalid={ariaInvalid}
        containerClassName="w-full justify-center"
      >
        <InputOTPGroup className="flex-1">
          <InputOTPSlot index={0} className="h-16 w-full text-2xl font-mono" />
          <InputOTPSlot index={1} className="h-16 w-full text-2xl font-mono" />
          <InputOTPSlot index={2} className="h-16 w-full text-2xl font-mono" />
        </InputOTPGroup>
        <InputOTPSeparator />
        <InputOTPGroup className="flex-1">
          <InputOTPSlot index={3} className="h-16 w-full text-2xl font-mono" />
          <InputOTPSlot index={4} className="h-16 w-full text-2xl font-mono" />
          <InputOTPSlot index={5} className="h-16 w-full text-2xl font-mono" />
        </InputOTPGroup>
      </InputOTP>
    </div>
  )
}
