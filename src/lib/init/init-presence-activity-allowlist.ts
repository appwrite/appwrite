import {
  INIT_PRESENCE_ACTIVITY_LEFT,
  INIT_PRESENCE_ACTIVITY_OFFLINE,
  INIT_PRESENCE_ACTIVITY_ON_INIT,
  buildInitCheckingScheduleActivity,
  buildInitCustomizingTicketActivity,
  buildInitExploringActivity,
  buildInitPlayingWithJoolActivity,
  buildInitReadingDayActivity,
  buildInitRecordingTicketActivity,
  buildInitViewingDayActivity,
  buildInitViewingTicketActivity,
  buildInitWaitingForDayActivity,
} from '@/lib/init/init-presence-activity'
import { INIT_REACTIONS, getInitReactionActivity } from '@/lib/init/reactions'
import type { LaunchEvent } from '@/lib/init/types'

/** Exact activity strings the Init UI may show for a given event. */
export function buildInitPresenceActivityAllowlist(
  event: LaunchEvent,
): ReadonlySet<string> {
  const allowed = new Set<string>([
    INIT_PRESENCE_ACTIVITY_ON_INIT,
    INIT_PRESENCE_ACTIVITY_LEFT,
    INIT_PRESENCE_ACTIVITY_OFFLINE,
    buildInitViewingTicketActivity(),
    buildInitCustomizingTicketActivity(),
    buildInitRecordingTicketActivity(),
    buildInitCheckingScheduleActivity(),
    buildInitPlayingWithJoolActivity(),
  ])

  for (const reaction of INIT_REACTIONS) {
    allowed.add(reaction.activity)
  }

  for (const item of event.getInvolved) {
    allowed.add(buildInitExploringActivity(item.title))
  }

  for (const day of event.days) {
    allowed.add(buildInitWaitingForDayActivity(day.day))
    allowed.add(buildInitViewingDayActivity(day.day, day.title))
    allowed.add(buildInitReadingDayActivity(day.day, { title: day.title }))
    allowed.add(buildInitCheckingScheduleActivity(day.day))
  }

  return allowed
}

function normalizeInitPresenceActivity(activity: string): string {
  const trimmed = activity.trim()
  const legacyMatch = trimmed.match(/^Reacting:\s*(.+)$/)
  if (!legacyMatch) return trimmed

  const reactionActivity = getInitReactionActivity(legacyMatch[1].trim())
  return reactionActivity ?? trimmed
}

export function isAllowedInitPresenceActivity(
  activity: string,
  allowlist: ReadonlySet<string>,
): boolean {
  const normalized = normalizeInitPresenceActivity(activity)
  if (!normalized) return false
  return allowlist.has(normalized)
}

export function sanitizeInitPresenceActivity(
  activity: string | undefined | null,
  allowlist: ReadonlySet<string>,
): string {
  if (!activity) return INIT_PRESENCE_ACTIVITY_ON_INIT
  const normalized = normalizeInitPresenceActivity(activity)
  if (!isAllowedInitPresenceActivity(normalized, allowlist)) {
    return INIT_PRESENCE_ACTIVITY_ON_INIT
  }
  return normalized
}
