import { Ghost } from 'lucide-react'

import { cn } from '@/lib/utils'

type AvatarSize = 'xs' | 'sm' | 'md' | 'lg'

interface InitialsAvatarProps {
  name?: string
  size?: AvatarSize
  className?: string
}

const sizeClasses: Record<AvatarSize, string> = {
  xs: 'h-5 w-5 text-[9px]',
  sm: 'h-6 w-6 text-[10px]',
  md: 'h-8 w-8 text-[11px]',
  lg: 'h-10 w-10 text-[13px]',
}

function getInitials(name?: string): string {
  if (!name) return '?'

  // Remove special characters, emojis, and keep only letters and spaces
  const cleaned = name.replace(/[^\p{L}\s]/gu, '').trim()

  if (!cleaned) return '?'

  const parts = cleaned.split(/\s+/).filter((part) => part.length > 0)

  if (parts.length === 0) return '?'

  if (parts.length === 1) {
    // Get first letter from the single part
    const firstLetter = parts[0].match(/\p{L}/u)?.[0]
    return firstLetter ? firstLetter.toUpperCase() : '?'
  }

  // Get first letter from first and last parts
  const firstLetter = parts[0].match(/\p{L}/u)?.[0]
  const lastLetter = parts[parts.length - 1].match(/\p{L}/u)?.[0]

  if (!firstLetter || !lastLetter) return '?'

  return (firstLetter + lastLetter).toUpperCase()
}

export function InitialsAvatar({
  name,
  size = 'md',
  className,
}: InitialsAvatarProps) {
  const trimmedName = name?.trim() || ''
  const initials = getInitials(trimmedName)
  const isAnonymous = trimmedName.length === 0

  return (
    <div
      className={cn(
        'flex shrink-0 items-center justify-center rounded-full bg-zinc-200 font-medium text-zinc-600 dark:bg-accent dark:text-muted-foreground',
        sizeClasses[size],
        className,
      )}
    >
      {isAnonymous ? <Ghost className="h-4 w-4" /> : initials}
    </div>
  )
}
