export interface InitReaction {
  id: string
  emoji: string
  label: string
}

export const INIT_REACTIONS: InitReaction[] = [
  { id: 'fire', emoji: '🔥', label: 'Fire' },
  { id: 'celebrate', emoji: '🎉', label: 'Celebrate' },
  { id: 'mind-blown', emoji: '🤯', label: 'Mind blown' },
  { id: 'heart', emoji: '❤️', label: 'Love it' },
  { id: 'rocket', emoji: '🚀', label: 'Ship it' },
  { id: 'eyes', emoji: '👀', label: 'Watching' },
]

const STORAGE_PREFIX = 'console.init.reaction.'

export function getInitReactionStorageKey(eventId: string) {
  return `${STORAGE_PREFIX}${eventId}`
}

export function readStoredInitReaction(eventId: string): string | null {
  if (typeof window === 'undefined') return null
  try {
    return localStorage.getItem(getInitReactionStorageKey(eventId))
  } catch {
    return null
  }
}

export function writeStoredInitReaction(eventId: string, reactionId: string | null) {
  if (typeof window === 'undefined') return
  try {
    const key = getInitReactionStorageKey(eventId)
    if (reactionId) {
      localStorage.setItem(key, reactionId)
    } else {
      localStorage.removeItem(key)
    }
  } catch {
    // ignore quota / privacy mode
  }
}
