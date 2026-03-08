import { useState, useEffect } from 'react'

const DEBUG_OVERRIDE_EVENT = 'debugOverridesChange'

export const DEBUG_OVERRIDE_KEYS = {
  showNativeAppBar: 'debug:showNativeAppBar',
  showAIAssistant: 'debug:showAIAssistant',
} as const

export type DebugOverrides = {
  showNativeAppBar: boolean
  /** When true, the AI assistant is shown regardless of profile (experimental). Default false. */
  showAIAssistant: boolean
}

const isBrowser = typeof window !== 'undefined'

function readBooleanFromStorage(key: string, defaultValue = false) {
  if (!isBrowser) return defaultValue
  const raw = localStorage.getItem(key)
  if (raw === null) return defaultValue
  return raw === 'true'
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
  }
}

export function setDebugOverride<K extends keyof DebugOverrides>(
  key: K,
  value: DebugOverrides[K],
) {
  if (!isBrowser) return
  const storageKey = DEBUG_OVERRIDE_KEYS[key]
  if (typeof value === 'boolean') {
    localStorage.setItem(storageKey, value ? 'true' : 'false')
  } else if (value) {
    localStorage.setItem(storageKey, 'true')
  } else {
    localStorage.removeItem(storageKey)
  }
  window.dispatchEvent(new CustomEvent(DEBUG_OVERRIDE_EVENT))
}

export function resetDebugOverrides() {
  if (!isBrowser) return
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
