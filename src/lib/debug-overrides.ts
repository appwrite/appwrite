import { useState, useEffect } from 'react'

const DEBUG_OVERRIDE_EVENT = 'debugOverridesChange'

export const DEBUG_OVERRIDE_KEYS = {
  showNativeAppBar: 'debug:showNativeAppBar',
  showAIAssistant: 'debug:showAIAssistant',
  showSuccessTeamCard: 'debug:showSuccessTeamCard',
  mockCloudStatusAlert: 'debug:mockCloudStatusAlert',
  showFullscreenLoader: 'debug:showFullscreenLoader',
} as const

/** Overrides that are not persisted to localStorage (reset on reload). */
const EPHEMERAL_OVERRIDE_KEYS = new Set<keyof DebugOverrides>([
  'showFullscreenLoader',
])

const ephemeralOverrides: Partial<DebugOverrides> = {}

export type MockCloudStatusAlert =
  | 'live'
  | 'operational'
  | 'degraded'
  | 'downtime'
  | 'maintenance'

export type DebugOverrides = {
  showNativeAppBar: boolean
  /** When true, the AI assistant is shown regardless of profile (experimental). Default false. */
  showAIAssistant: boolean
  /** When true, the success team card is shown on organization overview (custom plans). Default false. */
  showSuccessTeamCard: boolean
  /** Mock Appwrite Cloud status alert state for design review in debug mode. */
  mockCloudStatusAlert: MockCloudStatusAlert
  /** When true, the fullscreen loader is always shown (for preview). Default false. */
  showFullscreenLoader: boolean
}

const isBrowser = typeof window !== 'undefined'

function readBooleanFromStorage(key: string, defaultValue = false) {
  if (!isBrowser) return defaultValue
  const raw = localStorage.getItem(key)
  if (raw === null) return defaultValue
  return raw === 'true'
}

function readStringFromStorage<T extends string>(
  key: string,
  allowedValues: readonly T[],
  defaultValue: T,
): T {
  if (!isBrowser) return defaultValue
  const raw = localStorage.getItem(key)
  if (raw === null) return defaultValue
  return allowedValues.includes(raw as T) ? (raw as T) : defaultValue
}

export function loadDebugOverrides(): DebugOverrides {
  return {
    showNativeAppBar: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showNativeAppBar,
    ),
    showAIAssistant: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showAIAssistant,
      false,
    ),
    showSuccessTeamCard: readBooleanFromStorage(
      DEBUG_OVERRIDE_KEYS.showSuccessTeamCard,
      false,
    ),
    mockCloudStatusAlert: readStringFromStorage(
      DEBUG_OVERRIDE_KEYS.mockCloudStatusAlert,
      ['live', 'operational', 'degraded', 'downtime', 'maintenance'] as const,
      'live',
    ),
    showFullscreenLoader:
      ephemeralOverrides.showFullscreenLoader ?? false,
  }
}

export function setDebugOverride<K extends keyof DebugOverrides>(
  key: K,
  value: DebugOverrides[K],
) {
  if (!isBrowser) return
  if (EPHEMERAL_OVERRIDE_KEYS.has(key)) {
    ;(ephemeralOverrides as Record<K, DebugOverrides[K]>)[key] = value
    window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
    return
  }
  const storageKey = DEBUG_OVERRIDE_KEYS[key]
  if (typeof value === 'boolean') {
    localStorage.setItem(storageKey, value ? 'true' : 'false')
  } else if (typeof value === 'string') {
    localStorage.setItem(storageKey, value)
  } else if (value) {
    localStorage.setItem(storageKey, 'true')
  } else {
    localStorage.removeItem(storageKey)
  }
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

export function resetDebugOverrides() {
  if (!isBrowser) return
  EPHEMERAL_OVERRIDE_KEYS.forEach((key) => {
    delete ephemeralOverrides[key]
  })
  Object.values(DEBUG_OVERRIDE_KEYS).forEach((key) => {
    localStorage.removeItem(key)
  })
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

export function subscribeToDebugOverrides(
  callback: (overrides: DebugOverrides) => void,
) {
  if (!isBrowser) return () => undefined

  const handler = () => callback(loadDebugOverrides())

  window.addEventListener(DEBUG_OVERRIDE_EVENT, handler as EventListener)
  window.addEventListener('storage', handler)

  return () => {
    window.removeEventListener(DEBUG_OVERRIDE_EVENT, handler as EventListener)
    window.removeEventListener('storage', handler)
  }
}

export function useDebugOverrides(): DebugOverrides {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  useEffect(() => {
    return subscribeToDebugOverrides(setOverrides)
  }, [])
  return overrides
}
