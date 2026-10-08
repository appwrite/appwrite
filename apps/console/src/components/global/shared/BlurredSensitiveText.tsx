import { useMemo, type HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'
import { scrambleSensitiveText } from '@/lib/scramble-sensitive-text'

type BlurredSensitiveTextProps = {
  value: string
  /** When true, replaces `value` with same-length random text, then applies blur. */
  blurred?: boolean
  /** Extra classes applied only while blurred (defaults to spreadsheet-style blur). */
  blurClassName?: string
} & Omit<HTMLAttributes<HTMLSpanElement>, 'children'>

/**
 * Renders sensitive text safely for screenshots / privacy toggles.
 * CSS blur alone is reversible; we scramble first so the real value is not in the DOM.
 */
export function BlurredSensitiveText({
  value,
  blurred = false,
  blurClassName = 'select-none blur-[5px] transition-[filter] duration-150',
  className,
  title,
  ...props
}: BlurredSensitiveTextProps) {
  const visible = useMemo(
    () => (blurred ? scrambleSensitiveText(value) : value),
    [blurred, value],
  )

  return (
    <span
      {...props}
      className={cn(blurred && blurClassName, className)}
      title={blurred ? undefined : title}
      aria-hidden={blurred ? true : props['aria-hidden']}
    >
      {visible}
    </span>
  )
}
