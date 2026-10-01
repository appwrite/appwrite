import { useEffect, useMemo, useRef, useState } from 'react'
import { Ghost } from 'lucide-react'

import { sdk } from '@/lib/appwrite/sdk'
import { useAvatarEmailHash } from '@/lib/avatar-email-hash'
import { getConsoleAccountFromSingleton } from '@/lib/console-account-get'
import {
  resolveScreenshotModeUserPhotoSrc,
  subscribeScreenshotMode,
} from '@/lib/screenshot-mode'
import {
  getUserPhotoVersion,
  subscribeUserPhotoVersion,
  withUserPhotoVersion,
} from '@/lib/user-photo'
import { cn } from '@/lib/utils'

type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl'

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
   * Resolve `userId` against this project's users (`sdk.forProject`) instead
   * of the console project. Required for Auth users, team members, and
   * activity actors; `useCurrentUser` is ignored in project scope.
   */
  projectId?: string
  /**
   * Resolve via the signed-in console user (OAuth photo chain). Use only when
   * the account ID is not available yet; prefer `userId` when you have it.
   */
  useCurrentUser?: boolean
  /** When true, screenshot mode replaces this avatar with the demo user photo. */
  isCurrentUser?: boolean
  /** Used only for initials fallback when the photo is unavailable. */
  name?: string
  size?: AvatarSize
  className?: string
}

interface EmailAvatarProps {
  /** Raw email address; hashed client-side so only the SHA-256 hash reaches the API. */
  email?: string
  /** Used for server-side initials and the local initials fallback. */
  name?: string
  size?: AvatarSize
  className?: string
}

const sizeClasses: Record<AvatarSize, string> = {
  xs: 'h-5 w-5 text-[9px]',
  sm: 'h-6 w-6 text-[10px]',
  md: 'h-8 w-8 text-[11px]',
  lg: 'h-10 w-10 text-[13px]',
  xl: 'h-20 w-20 text-[20px]',
}

/** Request 2x pixels so avatars stay sharp on retina displays. */
const sizePixels: Record<AvatarSize, number> = {
  xs: 40,
  sm: 48,
  md: 64,
  lg: 80,
  xl: 160,
}

const loadedPhotoSrcs = new Set<string>()

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
        'flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-200 font-medium leading-none text-zinc-600 dark:bg-accent dark:text-muted-foreground',
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
 * signed-in session user (`useCurrentUser`). Add `projectId` to resolve a
 * customer project's user instead of a console user.
 *
 * The current console user's photo URL carries the user photo version (see
 * `@/lib/user-photo`) so it reloads right after an upload or removal.
 */
export function PhotoAvatar({
  userId,
  projectId,
  useCurrentUser = false,
  isCurrentUser = false,
  name,
  size = 'md',
  className,
}: PhotoAvatarProps) {
  const [failed, setFailed] = useState(false)
  const [screenshotModeEpoch, setScreenshotModeEpoch] = useState(0)
  const [photoVersion, setPhotoVersion] = useState(getUserPhotoVersion)
  const trimmedUserId = userId?.trim() || ''
  const trimmedProjectId = projectId?.trim() || ''
  const pixels = sizePixels[size]

  useEffect(() => {
    return subscribeScreenshotMode(() => {
      setScreenshotModeEpoch((epoch) => epoch + 1)
    })
  }, [])

  useEffect(() => subscribeUserPhotoVersion(setPhotoVersion), [])

  const src = useMemo(() => {
    // Project users are never the console account: in project scope, ignore
    // the console-current-user flags and the account ID comparison so the
    // screenshot-mode demo photo never replaces a project user's avatar.
    const consoleScoped = !trimmedProjectId
    const currentUserId = consoleScoped
      ? getConsoleAccountFromSingleton()?.$id
      : undefined
    const screenshotSrc = resolveScreenshotModeUserPhotoSrc({
      userId: trimmedUserId,
      useCurrentUser: consoleScoped && useCurrentUser,
      isCurrentUser: consoleScoped && isCurrentUser,
      currentUserId,
    })
    if (screenshotSrc) return screenshotSrc

    const isSelf =
      consoleScoped &&
      (isCurrentUser ||
        useCurrentUser ||
        (!!trimmedUserId && trimmedUserId === currentUserId))

    if (trimmedUserId) {
      const avatars = trimmedProjectId
        ? sdk.forProject(trimmedProjectId).avatars
        : sdk.forConsole.avatars
      const url = avatars.getPhoto({
        width: pixels,
        height: pixels,
        userId: trimmedUserId,
      })
      return isSelf ? withUserPhotoVersion(url, photoVersion) : url
    }

    if (useCurrentUser && consoleScoped) {
      return withUserPhotoVersion(
        sdk.forConsole.avatars.getPhoto({ width: pixels, height: pixels }),
        photoVersion,
      )
    }

    return null
  }, [
    pixels,
    trimmedUserId,
    trimmedProjectId,
    useCurrentUser,
    isCurrentUser,
    screenshotModeEpoch,
    photoVersion,
  ])

  const [loaded, setLoaded] = useState(() =>
    src ? loadedPhotoSrcs.has(src) : false,
  )
  const [activeSrc, setActiveSrc] = useState(src)
  const imageRef = useRef<HTMLImageElement | null>(null)
  if (src !== activeSrc) {
    setActiveSrc(src)
    setFailed(false)
    setLoaded(src ? loadedPhotoSrcs.has(src) : false)
  }

  useEffect(() => {
    const image = imageRef.current
    if (image?.complete && image.naturalWidth > 0 && src) {
      loadedPhotoSrcs.add(src)
      setLoaded(true)
    }
  }, [src])

  if (failed || !src) {
    return <InitialsAvatar name={name} size={size} className={className} />
  }

  return (
    <span
      className={cn(
        'relative flex shrink-0 overflow-hidden rounded-full bg-zinc-200 dark:bg-accent',
        sizeClasses[size],
        className,
      )}
    >
      <InitialsAvatar
        name={name}
        size={size}
        className="pointer-events-none absolute inset-0 h-full w-full"
      />
      <img
        ref={imageRef}
        src={src}
        alt=""
        width={pixels}
        height={pixels}
        decoding="async"
        onLoad={(event) => {
          if (event.currentTarget.naturalWidth > 0) {
            loadedPhotoSrcs.add(src)
            setLoaded(true)
          }
        }}
        onError={() => setFailed(true)}
        className={cn(
          'relative z-[1] h-full w-full min-h-0 min-w-0 rounded-full object-cover motion-reduce:transition-none',
          'transition-opacity duration-300 ease-out',
          loaded ? 'opacity-100' : 'opacity-0',
        )}
      />
    </span>
  )
}

/**
 * Avatar for an email address without a known user ID, via
 * `avatars.getPhoto({ emailHash })` (Gravatar/Libravatar lookup).
 */
export function EmailAvatar({
  email,
  name,
  size = 'md',
  className,
}: EmailAvatarProps) {
  const [failed, setFailed] = useState(false)
  const emailHash = useAvatarEmailHash(email)
  const pixels = sizePixels[size]

  const src = useMemo(() => {
    if (!emailHash) return null
    return sdk.forConsole.avatars.getPhoto({
      width: pixels,
      height: pixels,
      emailHash,
      ...(name?.trim() ? { name: name.trim() } : {}),
    })
  }, [emailHash, name, pixels])

  useEffect(() => {
    setFailed(false)
  }, [src])

  if (failed || !src) {
    return (
      <InitialsAvatar name={name || email} size={size} className={className} />
    )
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
