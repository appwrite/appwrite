'use client'

import { REGEXP_ONLY_DIGITS_AND_CHARS } from 'input-otp'
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot,
} from '@/components/ui/input-otp'
import { cn } from '@/lib/utils'

export const OAUTH2_DEVICE_CODE_LENGTH = 6

/** Keep only the characters device user codes are built from. */
export function normalizeUserCode(value: string): string {
  return value
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
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

/**
 * Six-slot device user code input, matching the console OTP layout.
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
    <InputOTP
      id={id}
      maxLength={OAUTH2_DEVICE_CODE_LENGTH}
      value={normalizeUserCode(value)}
      onChange={(next) => {
        if (readOnly) return
        onChange?.(normalizeUserCode(next))
      }}
      disabled={disabled || readOnly}
      autoFocus={autoFocus}
      inputMode="text"
      autoComplete="one-time-code"
      spellCheck={false}
      pushPasswordManagerStrategy="none"
      pattern={REGEXP_ONLY_DIGITS_AND_CHARS}
      pasteTransformer={normalizeUserCode}
      aria-invalid={ariaInvalid}
      containerClassName={cn('w-full justify-center', className)}
    >
      <InputOTPGroup className="flex-1">
        <InputOTPSlot
          index={0}
          className="h-16 w-full font-mono text-2xl uppercase"
        />
        <InputOTPSlot
          index={1}
          className="h-16 w-full font-mono text-2xl uppercase"
        />
        <InputOTPSlot
          index={2}
          className="h-16 w-full font-mono text-2xl uppercase"
        />
      </InputOTPGroup>
      <InputOTPSeparator />
      <InputOTPGroup className="flex-1">
        <InputOTPSlot
          index={3}
          className="h-16 w-full font-mono text-2xl uppercase"
        />
        <InputOTPSlot
          index={4}
          className="h-16 w-full font-mono text-2xl uppercase"
        />
        <InputOTPSlot
          index={5}
          className="h-16 w-full font-mono text-2xl uppercase"
        />
      </InputOTPGroup>
    </InputOTP>
  )
}
