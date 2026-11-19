import type { LucideIcon } from 'lucide-react'
import { getSpriteId, SPRITE_URL, spriteIcons } from '@/lib/icons/sprite'
import { cn } from '@/lib/utils'

type SpriteIconProps = {
  icon: LucideIcon
  className?: string
  strokeWidth?: number
}

/** A Lucide icon drawn from the shared sprite; falls back to inline markup for icons outside it. */
export function SpriteIcon({
  icon: Icon,
  className,
  strokeWidth,
}: SpriteIconProps) {
  const id = getSpriteId(Icon)
  if (!spriteIcons.has(id)) {
    return <Icon className={className} strokeWidth={strokeWidth} aria-hidden />
  }
  return (
    <svg
      className={cn('sprite-icon lucide', `lucide-${id}`, className)}
      style={strokeWidth ? { strokeWidth } : undefined}
      aria-hidden
    >
      <use href={`${SPRITE_URL}#${id}`} />
    </svg>
  )
}
