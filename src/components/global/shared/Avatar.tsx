import { useEffect, useMemo, useState } from 'react'
import { Ghost } from 'lucide-react'

import { sdk } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'

type AvatarSize = 'xs' | 'sm' | 'md' | 'lg'

interface InitialsAvatarProps {
  name?: string
  size?: AvatarSize
  className?: string
}

interface PhotoAvatarProps {
  /**
   * User ID for `avatars.getPhoto({ userId })`. Prefer this for every user,
   * including the signed-in account. Never omit for other users - `getPhoto`
   * with no identity params resolves the signed-in session user.
   */
  userId?: string
  /**
   * Resolve via the signed-in console user (OAuth photo chain). Use only when
   * the account ID is not available yet; prefer `userId` when you have it.
   */
  useCurrentUser?: boolean
  /** Used only for initials fallback when the photo is unavailable. */
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

/** Request 2x pixels so avatars stay sharp on retina displays. */
const sizePixels: Record<AvatarSize, number> = {
  xs: 40,
  sm: 48,
  md: 64,
  lg: 80,
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

/**
 * Profile photo via `avatars.getPhoto({ userId })`.
 *
 * Pass `userId` only. Calling `getPhoto` with no identity params resolves the
 * signed-in session user (`useCurrentUser`).
 */
export function PhotoAvatar({
  userId,
  useCurrentUser = false,
  name,
  size = 'md',
  className,
}: PhotoAvatarProps) {
  const [failed, setFailed] = useState(false)
  const trimmedUserId = userId?.trim() || ''
  const pixels = sizePixels[size]

  const src = useMemo(() => {
    if (trimmedUserId) {
      return sdk.forConsole.avatars.getPhoto({
        width: pixels,
        height: pixels,
        userId: trimmedUserId,
      })
    }

    if (useCurrentUser) {
      return sdk.forConsole.avatars.getPhoto({
        width: pixels,
        height: pixels,
      })
    }

    return null
  }, [pixels, trimmedUserId, useCurrentUser])

  useEffect(() => {
    setFailed(false)
  }, [src])

  if (failed || !src) {
    return <InitialsAvatar name={name} size={size} className={className} />
  }

  return (
    <img
      src={src}
      alt=""
      width={pixels}
      height={pixels}
      decoding="async"
      onError={() => setFailed(true)}
      className={cn(
        'shrink-0 rounded-full bg-zinc-200 object-cover dark:bg-accent',
        sizeClasses[size],
        className,
      )}
    />
  )
}
