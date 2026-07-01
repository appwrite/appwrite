import { cn } from '@/lib/utils'

type StartTruncatedTextProps = {
  text: string
  className?: string
  title?: string
}

/**
 * Truncates overflowing text with an ellipsis on the left, keeping the end visible.
 * Uses direction: rtl on the container so text-overflow applies at the start only.
 */
export function StartTruncatedText({
  text,
  className,
  title,
}: StartTruncatedTextProps) {
  return (
    <span
      dir="rtl"
      className={cn(
        'block w-full min-w-0 max-w-full overflow-hidden text-ellipsis whitespace-nowrap text-start',
        className,
      )}
      title={title ?? text}
    >
      <span dir="ltr">{text}</span>
    </span>
  )
}
