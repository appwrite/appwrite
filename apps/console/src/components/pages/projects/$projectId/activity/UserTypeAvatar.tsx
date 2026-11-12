import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import {
  EmailAvatar,
  InitialsAvatar,
  PhotoAvatar,
} from '@/components/global/shared/Avatar'
import { Key, Server, Ghost } from '@/lib/icons'
import { isRegularUserType } from '@/components/pages/projects/$projectId/activity/activity-utils'
import { useT } from '@/lib/i18n/translate'

const avatarFrame =
  'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-border/50 shadow-sm'

/**
 * Photo via `avatars.getPhoto`, same as account and org members.
 * Project users resolve against the viewed project; console admins
 * resolve against the console account. Email is a Gravatar fallback
 * when the user id is missing or `getPhoto({ userId })` fails.
 */
function ActorPhotoAvatar({
  userId,
  projectId,
  email,
  name,
  className,
}: {
  userId?: string
  projectId?: string
  email?: string
  name: string
  className?: string
}) {
  const trimmedUserId = userId?.trim() || ''
  const trimmedEmail = email?.trim() || ''

  if (trimmedUserId) {
    return (
      <PhotoAvatar
        projectId={projectId}
        userId={trimmedUserId}
        email={trimmedEmail || undefined}
        name={name}
        size="sm"
        className={className}
      />
    )
  }

  if (trimmedEmail) {
    return (
      <EmailAvatar
        email={trimmedEmail}
        name={name}
        size="sm"
        className={className}
      />
    )
  }

  return <InitialsAvatar name={name} size="sm" className={className} />
}

/**
 * Activity actor avatar: `avatars.getPhoto` for project users and console
 * admins (same as account / members); glyph badges for API keys and system.
 * All variants share the same outer size and frame.
 */
export function UserTypeAvatar({
  actorType,
  actorName,
  actorId,
  actorEmail,
  projectId,
  className,
}: {
  actorType: string | undefined | null
  actorName: string
  /** User ID for `avatars.getPhoto` — project user or console admin. */
  actorId?: string | null
  /** Human email for Gravatar/Libravatar when the user photo is unavailable. */
  actorEmail?: string | null
  /**
   * Viewed project. Passed only for regular project users so `getPhoto`
   * resolves against that project's users, not the console account.
   */
  projectId?: string
  /** Merged with the frame; e.g. `shadow-none` for the activity table actor column. */
  className?: string
}) {
  const t = useT()
  const frameClass = cn(avatarFrame, className)
  const userId = actorId?.trim() || ''
  const email = actorEmail?.trim() || ''

  if (isRegularUserType(actorType)) {
    return (
      <ActorPhotoAvatar
        userId={userId}
        projectId={projectId}
        email={email}
        name={actorName}
        className={frameClass}
      />
    )
  }

  const normalized = (actorType ?? '').toLowerCase()

  if (normalized === 'admin') {
    return (
      <ActorPhotoAvatar
        userId={userId}
        email={email}
        name={actorName}
        className={frameClass}
      />
    )
  }

  if (normalized === 'guest' && email) {
    return (
      <EmailAvatar
        email={email}
        name={actorName}
        size="sm"
        className={frameClass}
      />
    )
  }

  let icon: ReactNode
  let tone: string
  let label: string

  if (normalized === 'guest') {
    icon = <Ghost className="h-3.5 w-3.5" />
    tone = 'bg-sky-500/10 text-sky-600 dark:text-sky-400'
    label = 'Guest'
  } else if (
    normalized === 'keyproject' ||
    normalized === 'keyaccount' ||
    normalized === 'keyorganization' ||
    normalized.startsWith('key')
  ) {
    icon = <Key className="h-3.5 w-3.5" />
    tone = 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
    if (normalized === 'keyproject') label = 'Project API key'
    else if (normalized === 'keyaccount') label = 'Account API key'
    else if (normalized === 'keyorganization') label = 'Partners API key'
    else label = 'API key'
  } else {
    icon = <Server className="h-3.5 w-3.5" />
    tone = 'bg-slate-500/10 text-slate-600 dark:text-slate-400'
    label = 'System'
  }

  return (
    <div
      className={cn(avatarFrame, tone, className)}
      aria-label={t(label)}
      title={t(label)}
    >
      {icon}
    </div>
  )
}
