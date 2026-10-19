import { Channel, type RealtimeResponseEvent } from '@appwrite.io/console'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'

type PresenceRealtimeHandler = (event: RealtimeResponseEvent<unknown>) => void

const RELEASE_DEBOUNCE_MS = 300

let retainCount = 0
let unregister: (() => Promise<void>) | null = null
let attachPromise: Promise<void> | null = null
let releaseTimer: ReturnType<typeof setTimeout> | null = null
let dispatch: PresenceRealtimeHandler = () => undefined

async function attachPresencesListener(): Promise<void> {
  if (releaseTimer) {
    clearTimeout(releaseTimer)
    releaseTimer = null
  }

  if (unregister) return
  if (attachPromise) {
    await attachPromise
    return
  }

  attachPromise = (async () => {
    unregister = await registerConsoleRealtimeListener([Channel.presences()], (event) => {
      dispatch(event)
    })
  })()

  try {
    await attachPromise
  } finally {
    attachPromise = null
  }
}

async function detachPresencesListener(): Promise<void> {
  if (unregister) {
    await unregister()
    unregister = null
  }
}

/**
 * Keep a single shared `presences` realtime subscription while Init presence is
 * active. Ref-counted with debounced teardown so React Strict Mode remounts do
 * not subscribe and immediately unsubscribe the same channel.
 */
export function retainInitPresencesRealtimeListener(
  handler: PresenceRealtimeHandler,
): () => void {
  dispatch = handler
  retainCount += 1

  void attachPresencesListener()

  let released = false
  return () => {
    if (released) return
    released = true
    retainCount = Math.max(0, retainCount - 1)
    if (retainCount > 0) return

    if (releaseTimer) {
      clearTimeout(releaseTimer)
    }
    releaseTimer = setTimeout(() => {
      releaseTimer = null
      if (retainCount === 0) {
        void detachPresencesListener()
      }
    }, RELEASE_DEBOUNCE_MS)
  }
}
