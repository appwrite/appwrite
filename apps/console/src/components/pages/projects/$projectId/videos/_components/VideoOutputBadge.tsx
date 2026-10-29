import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { getVideoStreamingFormatStyle } from '@/lib/videos/output-format'

export function VideoFormatLabel({
  format,
  children,
  className,
}: {
  format: string
  children: ReactNode
  className?: string
}) {
  const style = getVideoStreamingFormatStyle(format)
  return (
    <span
      className={cn(
        style?.labelClassName,
        !style && 'text-foreground',
        className,
      )}
    >
      {children}
    </span>
  )
}

export function VideoOutputBadge({
  output,
  label,
  className,
}: {
  output: string
  label?: ReactNode
  className?: string
}) {
  const style = getVideoStreamingFormatStyle(output)
  const text = label ?? style?.shortLabel ?? output

  if (!style) {
    return (
      <Badge variant="info" className={cn('text-[10px] uppercase', className)}>
        {text}
      </Badge>
    )
  }

  return (
    <Badge
      variant="outline"
      className={cn('text-[10px] uppercase', style.badgeClassName, className)}
    >
      {text}
    </Badge>
  )
}
