import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Key, Server, ShieldUser, Ghost } from '@/lib/icons'
import { isRegularUserType } from '@/components/pages/projects/$projectId/activity/activity-utils'
import { useT } from '@/lib/i18n/translate'

const avatarFrame =
  'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-border/50 shadow-sm'

/**
 * Activity actor avatar: same rules as the activity table - text initials for
 * regular project users; glyph badges for admins, API keys, and system actors.
 * All variants share the same outer size and frame.
 */
export function UserTypeAvatar({
  actorType,
  actorName,
  className,
}: {
  actorType: string | undefined | null
  actorName: string
  /** Merged with the frame; e.g. `shadow-none` for the activity table actor column. */
  className?: string
}) {
  const t = useT()
  if (isRegularUserType(actorType)) {
    return (
      <InitialsAvatar
        name={actorName}
        size="sm"
        className={cn(avatarFrame, className)}
      />
    )
  }

  const normalized = (actorType ?? '').toLowerCase()
  let icon: ReactNode
  let tone: string
  let label: string

  if (normalized === 'admin') {
    icon = <ShieldUser className="h-3.5 w-3.5" />
    tone = 'bg-violet-500/10 text-violet-600 dark:text-violet-400'
    label = 'Admin'
  } else if (normalized === 'guest') {
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
    else if (normalized === 'keyorganization') label = 'Organization API key'
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
