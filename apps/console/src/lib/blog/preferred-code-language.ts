import { useSyncExternalStore } from 'react'

/**
 * Page-wide code language choice for `{% multicode %}` cards. Picking a
 * language in one card switches every card that has it; cards without it
 * keep their own selection. Persisted under the same localStorage key the
 * previous website used so existing readers keep their preference.
 */
const STORAGE_KEY = 'preferredPlatform'

const listeners = new Set<() => void>()
let cached: string | null | undefined

function read(): string | null {
  if (typeof window === 'undefined') return null
  try {
    return window.localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

function getSnapshot(): string | null {
  if (cached === undefined) cached = read()
  return cached
}

function getServerSnapshot(): string | null {
  return null
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  const onStorage = (event: StorageEvent) => {
    if (event.key !== STORAGE_KEY) return
    cached = event.newValue
    listener()
  }
  window.addEventListener('storage', onStorage)
  return () => {
    listeners.delete(listener)
    window.removeEventListener('storage', onStorage)
  }
}

export function setPreferredCodeLanguage(languageId: string): void {
  cached = languageId
  try {
    window.localStorage.setItem(STORAGE_KEY, languageId)
  } catch {
    // Private mode or disabled storage: the in-memory value still syncs the page.
  }
  for (const listener of listeners) listener()
}

export function usePreferredCodeLanguage(): string | null {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot)
}
