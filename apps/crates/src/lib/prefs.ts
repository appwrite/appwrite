import { useSyncExternalStore } from 'react'

// Per-reader preferences, kept in this browser: migration mode (PHP and the
// conversion's status next to the Rust docs) and the theme. The server renders
// the defaults (the Rust docs alone, the system theme); the stored choice
// applies once the page hydrates.

type Key = 'migration' | 'theme'
const DEFAULTS: Record<Key, string> = { migration: 'off', theme: 'system' }
const listeners = new Set<() => void>()

function read(key: Key): string {
  try {
    return localStorage.getItem(`utopia-docs:${key}`) ?? DEFAULTS[key]
  } catch {
    return DEFAULTS[key]
  }
}

export function write(key: Key, value: string) {
  try {
    localStorage.setItem(`utopia-docs:${key}`, value)
  } catch {
    // Storage can be unavailable (private windows); the choice lasts for this page.
  }
  memory[key] = value
  if (key === 'theme') applyTheme(value)
  listeners.forEach((l) => l())
}

const memory: Partial<Record<Key, string>> = {}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function usePref(key: Key) {
  return useSyncExternalStore(
    subscribe,
    () => memory[key] ?? read(key),
    () => DEFAULTS[key],
  )
}

/** Migration mode: PHP twins, PHP signatures, conversion status and sync, in yellow cards. */
export const useMigration = () => usePref('migration') === 'on'
export const setMigration = (on: boolean) => write('migration', on ? 'on' : 'off')

export const useTheme = () => usePref('theme') as 'light' | 'dark' | 'system'

export function applyTheme(theme: string) {
  const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
  document.documentElement.classList.toggle('dark', dark)
}

/** Runs before the first paint so a stored or system dark theme never flashes light. */
export const THEME_SCRIPT = `(function(){try{var t=localStorage.getItem('utopia-docs:theme')||'system';var d=t==='dark'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.documentElement.classList.add('dark')}catch(e){}})()`
