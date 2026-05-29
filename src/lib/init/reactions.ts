import type { LucideIcon } from 'lucide-react'
import { Eye, Flame, Heart, PartyPopper, Rocket, Sparkles } from 'lucide-react'
import { formatInitPresenceActivityDisplay } from '@/lib/init/init-presence-activity'

export { formatInitPresenceActivityDisplay }

export interface InitReaction {
  id: string
  icon: LucideIcon
  label: string
  /** Natural presence status shown beside the user's name. */
  activity: string
}

export const INIT_REACTIONS: InitReaction[] = [
  { id: 'fire', icon: Flame, label: 'Fire', activity: 'Feeling the hype' },
  { id: 'celebrate', icon: PartyPopper, label: 'Celebrate', activity: 'Celebrating' },
  { id: 'mind-blown', icon: Sparkles, label: 'Mind blown', activity: 'Mind blown' },
  { id: 'heart', icon: Heart, label: 'Love it', activity: 'Loving it' },
  { id: 'rocket', icon: Rocket, label: 'Ship it', activity: 'Ready to ship' },
  { id: 'eyes', icon: Eye, label: 'Watching', activity: 'Watching closely' },
]

const REACTION_BY_ID = new Map(INIT_REACTIONS.map((reaction) => [reaction.id, reaction]))
const REACTION_BY_ACTIVITY = new Map(
  INIT_REACTIONS.map((reaction) => [reaction.activity, reaction]),
)

const STORAGE_PREFIX = 'console.init.reaction.'

/** How long a reaction stays visible before resetting. */
export const INIT_REACTION_DURATION_MS = 5_000

export type StoredInitReaction = {
  reactionId: string
  expiresAt: number
}

export function getInitReactionById(reactionId: string): InitReaction | undefined {
  return REACTION_BY_ID.get(reactionId)
}

export function getInitReactionActivity(reactionId: string): string | undefined {
  return REACTION_BY_ID.get(reactionId)?.activity
}

export function getInitReactionStorageKey(eventId: string) {
  return `${STORAGE_PREFIX}${eventId}`
}

function parseStoredInitReaction(raw: string): StoredInitReaction | null {
  try {
    const parsed = JSON.parse(raw) as Partial<StoredInitReaction>
    if (
      typeof parsed.reactionId === 'string' &&
      typeof parsed.expiresAt === 'number'
    ) {
      return { reactionId: parsed.reactionId, expiresAt: parsed.expiresAt }
    }
  } catch {
    // legacy plain reaction id string
  }

  if (REACTION_BY_ID.has(raw)) {
    return { reactionId: raw, expiresAt: Date.now() + INIT_REACTION_DURATION_MS }
  }

  return null
}

export function readStoredInitReaction(eventId: string): StoredInitReaction | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = localStorage.getItem(getInitReactionStorageKey(eventId))
    if (!raw) return null

    const stored = parseStoredInitReaction(raw)
    if (!stored) {
      localStorage.removeItem(getInitReactionStorageKey(eventId))
      return null
    }

    if (Date.now() >= stored.expiresAt) {
      localStorage.removeItem(getInitReactionStorageKey(eventId))
      return null
    }

    return stored
  } catch {
    return null
  }
}

export function writeStoredInitReaction(eventId: string, reactionId: string | null) {
  if (typeof window === 'undefined') return
  try {
    const key = getInitReactionStorageKey(eventId)
    if (reactionId) {
      const payload: StoredInitReaction = {
        reactionId,
        expiresAt: Date.now() + INIT_REACTION_DURATION_MS,
      }
      localStorage.setItem(key, JSON.stringify(payload))
    } else {
      localStorage.removeItem(key)
    }
  } catch {
    // ignore quota / privacy mode
  }
}

export function parseInitReactingActivity(activity: string): InitReaction | null {
  const trimmed = activity.trim()
  const byActivity = REACTION_BY_ACTIVITY.get(trimmed)
  if (byActivity) return byActivity

  const legacyMatch = trimmed.match(/^Reacting:\s*(.+)$/)
  if (legacyMatch) {
    return getInitReactionById(legacyMatch[1].trim()) ?? null
  }

  return null
}

export function buildInitReactingActivity(reactionId: string): string {
  return getInitReactionActivity(reactionId) ?? 'On Init'
}

/** Minimum online users sharing a reaction before the confetti burst. */
export const INIT_REACTION_CONFETTI_THRESHOLD = 5

/** Wait time before the same reaction can trigger confetti again. */
export const INIT_REACTION_CONFETTI_COOLDOWN_MS = 6_000

export function getInitReactionCounts(
  users: readonly { activity: string }[],
): Map<string, number> {
  const counts = new Map<string, number>()
  for (const user of users) {
    const reaction = parseInitReactingActivity(user.activity)
    if (!reaction) continue
    counts.set(reaction.id, (counts.get(reaction.id) ?? 0) + 1)
  }
  return counts
}
