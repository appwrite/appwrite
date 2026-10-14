import { useSyncExternalStore } from 'react'

/**
 * Timeline generation is queued server-side and emits no realtime events, so
 * remember which videos have a queued timeline and poll until it appears.
 * Entries expire so a failed worker job does not poll forever.
 */
const PENDING_TIMELINE_TTL_MS = 10 * 60 * 1000

const pending = new Map<string, number>()
const listeners = new Set<() => void>()

function pendingKey(projectId: string, videoId: string): string {
  return `${projectId}:${videoId}`
}

function emit() {
  for (const listener of listeners) listener()
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function markTimelinePending(projectId: string, videoId: string) {
  pending.set(pendingKey(projectId, videoId), Date.now())
  emit()
}

export function clearTimelinePending(projectId: string, videoId: string) {
  if (pending.delete(pendingKey(projectId, videoId))) emit()
}

export function isTimelinePending(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
): boolean {
  if (!projectId || !videoId) return false
  const key = pendingKey(projectId, videoId)
  const queuedAt = pending.get(key)
  if (queuedAt === undefined) return false
  if (Date.now() - queuedAt > PENDING_TIMELINE_TTL_MS) {
    pending.delete(key)
    return false
  }
  return true
}

export function useTimelinePending(
  projectId: string | null | undefined,
  videoId: string | null | undefined,
): boolean {
  return useSyncExternalStore(
    subscribe,
    () => isTimelinePending(projectId, videoId),
    () => false,
  )
}
